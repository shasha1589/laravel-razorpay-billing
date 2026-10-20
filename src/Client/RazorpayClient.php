<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Client;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;
use Shasha1589\RazorpayBilling\Client\Exceptions\ApiException;
use Shasha1589\RazorpayBilling\Client\Exceptions\ConnectionException;
use Shasha1589\RazorpayBilling\Client\Exceptions\MissingCredentialsException;
use Shasha1589\RazorpayBilling\Client\Exceptions\UnexpectedResponseException;

/**
 * Thin client over Laravel's HTTP client.
 *
 * @see https://razorpay.com/docs/api/understand
 * @see https://razorpay.com/docs/api/authentication
 */
class RazorpayClient
{
    public const DEFAULT_BASE_URL = 'https://api.razorpay.com/v1';

    /** Maximum `count` accepted by Razorpay collection endpoints. */
    public const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly string $keyId,
        #[\SensitiveParameter] private readonly string $keySecret,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
        private readonly int $retryDelay = 200,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $keyId = $config->get('razorpay-billing.key_id');
        $keySecret = $config->get('razorpay-billing.key_secret');

        if (! is_string($keyId) || $keyId === '' || ! is_string($keySecret) || $keySecret === '') {
            throw new MissingCredentialsException('Razorpay API keys are not configured. Set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET.');
        }

        $api = $config->get('razorpay-billing.api', []);
        $api = is_array($api) ? $api : [];

        return new self(
            $keyId,
            $keySecret,
            is_string($api['base_url'] ?? null) ? $api['base_url'] : self::DEFAULT_BASE_URL,
            is_int($api['timeout'] ?? null) ? $api['timeout'] : 30,
            is_int($api['retries'] ?? null) ? $api['retries'] : 2,
            is_int($api['retry_delay'] ?? null) ? $api['retry_delay'] : 200,
        );
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function customers(): Customers
    {
        return new Customers($this);
    }

    public function plans(): Plans
    {
        return new Plans($this);
    }

    public function invoices(): Invoices
    {
        return new Invoices($this);
    }

    public function orders(): Orders
    {
        return new Orders($this);
    }

    public function payments(): Payments
    {
        return new Payments($this);
    }

    public function subscriptions(): Subscriptions
    {
        return new Subscriptions($this);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, $data === [] ? [] : ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function put(string $path, array $data = []): array
    {
        return $this->send('PUT', $path, $data === [] ? [] : ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function patch(string $path, array $data = []): array
    {
        return $this->send('PATCH', $path, $data === [] ? [] : ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function delete(string $path, array $data = []): array
    {
        return $this->send('DELETE', $path, $data === [] ? [] : ['json' => $data]);
    }

    /**
     * Lazily walks every page of a collection endpoint using count/skip.
     *
     * @see https://razorpay.com/docs/api/understand#pagination
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function paginate(string $path, array $query = []): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $query) {
            $skip = 0;

            do {
                $page = $this->get($path, [...$query, 'count' => self::MAX_PAGE_SIZE, 'skip' => $skip]);
                $items = $page['items'] ?? [];

                if (! is_array($items)) {
                    throw new UnexpectedResponseException("Expected an items array from {$path}.");
                }

                foreach ($items as $item) {
                    if (is_array($item)) {
                        /** @var array<string, mixed> $item */
                        yield $item;
                    }
                }

                $skip += self::MAX_PAGE_SIZE;
            } while (count($items) === self::MAX_PAGE_SIZE);
        });
    }

    /**
     * GETs are retried on connection errors and 5xx responses. Every method is
     * retried on 429, since a throttled request was not processed.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $options): array
    {
        $attempts = max(1, $this->retries + 1);

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->request()->send($method, ltrim($path, '/'), $options);
            } catch (HttpConnectionException $e) {
                if ($method === 'GET' && $attempt < $attempts) {
                    $this->backoff($attempt);

                    continue;
                }

                throw new ConnectionException("Could not connect to Razorpay: {$e->getMessage()}", 0, $e);
            }

            if ($response->successful()) {
                return $this->decode($response, $path);
            }

            if ($attempt < $attempts && $this->shouldRetry($method, $response)) {
                $this->backoff($attempt);

                continue;
            }

            throw ApiException::fromResponse($response);
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withBasicAuth($this->keyId, $this->keySecret)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    private function shouldRetry(string $method, Response $response): bool
    {
        return $response->status() === 429
            || ($method === 'GET' && $response->serverError());
    }

    private function backoff(int $attempt): void
    {
        $delay = $this->retryDelay * (2 ** ($attempt - 1));

        Sleep::for($delay + random_int(0, intdiv($delay, 2)))->milliseconds();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $path): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new UnexpectedResponseException("Razorpay returned a non-JSON response for {$path}.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
