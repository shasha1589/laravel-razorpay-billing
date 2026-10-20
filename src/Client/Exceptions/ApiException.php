<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Client\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * A non-2xx response from Razorpay.
 *
 * @see https://razorpay.com/docs/errors
 */
class ApiException extends RazorpayException
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    final public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $field = null,
        public readonly ?string $reason = null,
        public readonly ?string $source = null,
        public readonly ?string $step = null,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $class = match (true) {
            $response->status() === 400 => BadRequestException::class,
            $response->status() === 401 => AuthenticationException::class,
            $response->status() === 404 => NotFoundException::class,
            $response->status() === 429 => RateLimitException::class,
            $response->serverError() => ServerException::class,
            default => self::class,
        };

        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $string = static fn (string $key): ?string => isset($error[$key]) && is_string($error[$key]) ? $error[$key] : null;
        $metadata = isset($error['metadata']) && is_array($error['metadata']) ? $error['metadata'] : [];

        /** @var array<string, mixed> $metadata */
        return new $class(
            $string('description') ?? "Razorpay request failed with HTTP {$response->status()}.",
            $response->status(),
            $string('code'),
            $string('field'),
            $string('reason'),
            $string('source'),
            $string('step'),
            $metadata,
        );
    }
}
