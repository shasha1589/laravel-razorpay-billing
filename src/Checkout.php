<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Shasha1589\RazorpayBilling\Plans\PlanDefinition;

/**
 * Data for Razorpay Standard Checkout. Pass it to the bundled JS helper
 * (`RazorpayBilling.checkout(@json($checkout))`) or use options() directly.
 *
 * @see https://razorpay.com/docs/payments/subscriptions/integration-guide
 * @see https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/integration-steps
 * @see https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/configure-payment-methods/sample-code
 *
 * @implements Arrayable<string, mixed>
 */
final class Checkout implements Arrayable, JsonSerializable
{
    /** Methods Checkout can hide by identifier; emandate is controlled from the Dashboard. */
    private const HIDEABLE_METHODS = ['card', 'upi'];

    private ?string $name = null;

    private ?string $description = null;

    private ?string $image = null;

    private ?string $themeColor = null;

    /**
     * @param  array{name?: string, email?: string, contact?: string}  $prefill
     * @param  array<string, string>  $notes
     * @param  list<string>  $methods
     */
    public function __construct(
        private readonly string $keyId,
        private readonly ?string $subscriptionId = null,
        private readonly ?string $orderId = null,
        private readonly array $prefill = [],
        private readonly array $notes = [],
        private readonly array $methods = PlanDefinition::METHODS,
        private readonly ?string $shortUrl = null,
        private readonly ?int $amount = null,
        private readonly ?string $currency = null,
    ) {}

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function image(string $url): self
    {
        $this->image = $url;

        return $this;
    }

    public function themeColor(string $hex): self
    {
        $this->themeColor = $hex;

        return $this;
    }

    public function subscriptionId(): ?string
    {
        return $this->subscriptionId;
    }

    public function orderId(): ?string
    {
        return $this->orderId;
    }

    /** Razorpay-hosted authorisation link, useful for emails or redirects. */
    public function shortUrl(): ?string
    {
        return $this->shortUrl;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * Options for `new Razorpay(options)`.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $appName = config('app.name');

        $options = array_filter([
            'key' => $this->keyId,
            'subscription_id' => $this->subscriptionId,
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'name' => $this->name ?? (is_string($appName) ? $appName : null),
            'description' => $this->description,
            'image' => $this->image,
            'prefill' => $this->prefill === [] ? null : $this->prefill,
            'notes' => $this->notes === [] ? null : $this->notes,
            'theme' => $this->themeColor === null ? null : ['color' => $this->themeColor],
        ], fn ($value) => $value !== null);

        $hidden = array_values(array_diff(self::HIDEABLE_METHODS, $this->methods));

        if ($hidden !== []) {
            $options['config'] = [
                'display' => [
                    'hide' => array_map(fn (string $method) => ['method' => $method], $hidden),
                    'preferences' => ['show_default_blocks' => true],
                ],
            ];
        }

        return $options;
    }

    /**
     * @return array{options: array<string, mixed>, verify_url: string, short_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'options' => $this->options(),
            'verify_url' => route('razorpay-billing.verify'),
            'short_url' => $this->shortUrl,
        ];
    }

    /**
     * @return array{options: array<string, mixed>, verify_url: string, short_url: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
