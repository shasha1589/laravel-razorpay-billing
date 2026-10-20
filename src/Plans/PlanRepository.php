<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Plans;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Shasha1589\RazorpayBilling\Client\Exceptions\UnexpectedResponseException;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Invoices\GstCalculator;

/**
 * Maps config plan keys to Razorpay plan IDs.
 *
 * Razorpay plans are tagged with a `plan_key` note when created, so the
 * mapping can always be rebuilt from Razorpay; the cache only saves API calls.
 */
class PlanRepository
{
    public const NOTE_KEY = 'plan_key';

    private const CACHE_PREFIX = 'razorpay-billing:plan:';

    public function __construct(
        private readonly RazorpayClient $client,
        private readonly Config $config,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * @return array<string, PlanDefinition>
     */
    public function definitions(): array
    {
        $plans = $this->config->get('razorpay-billing.plans', []);

        if (! is_array($plans)) {
            throw new InvalidPlanException('razorpay-billing.plans must be an array.');
        }

        $definitions = [];
        $gst = GstCalculator::fromConfig($this->config);

        foreach ($plans as $key => $plan) {
            if (! is_string($key) || ! is_array($plan)) {
                throw new InvalidPlanException('Each plan must be defined as a string key => array.');
            }

            $definitions[$key] = PlanDefinition::fromConfig($key, $plan, $this->currency(), $gst->chargeAmount(...));
        }

        return $definitions;
    }

    public function definition(string $key): PlanDefinition
    {
        return $this->definitions()[$key] ?? throw new InvalidPlanException("Plan [{$key}] is not defined in razorpay-billing.plans.");
    }

    /**
     * The Razorpay plan ID for a config key, or null if it hasn't been synced
     * (or the Razorpay plan no longer matches the definition).
     */
    public function razorpayId(string $key): ?string
    {
        $cached = $this->store()->get(self::CACHE_PREFIX.$key);

        if (is_string($cached)) {
            return $cached;
        }

        $definition = $this->definition($key);
        $id = $this->findMatching($definition, $this->remotePlansByKey()[$key] ?? []);

        if ($id !== null) {
            $this->store()->forever(self::CACHE_PREFIX.$key, $id);
        }

        return $id;
    }

    /**
     * Creates configured plans missing from Razorpay. Existing plans are never
     * modified: Razorpay plans are immutable.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/create-plan
     *
     * @return list<PlanSyncResult>
     */
    public function sync(bool $dryRun = false): array
    {
        $definitions = $this->definitions();

        if ($definitions === []) {
            return [];
        }

        $remote = $this->remotePlansByKey();
        $results = [];

        foreach ($definitions as $key => $definition) {
            $existing = $remote[$key] ?? [];
            $id = $this->findMatching($definition, $existing);

            if ($id !== null) {
                $this->store()->forever(self::CACHE_PREFIX.$key, $id);
                $results[] = new PlanSyncResult($key, PlanSyncStatus::Exists, $id);
            } elseif ($existing !== []) {
                $this->store()->forget(self::CACHE_PREFIX.$key);
                $results[] = new PlanSyncResult($key, PlanSyncStatus::Conflict, $this->id($existing[0]));
            } elseif ($dryRun) {
                $results[] = new PlanSyncResult($key, PlanSyncStatus::WouldCreate);
            } else {
                $id = $this->id($this->client->plans()->create($definition->toRazorpayPayload()));
                $this->store()->forever(self::CACHE_PREFIX.$key, $id);
                $results[] = new PlanSyncResult($key, PlanSyncStatus::Created, $id);
            }
        }

        return $results;
    }

    /**
     * The config key for a Razorpay plan ID, or null if it isn't a configured
     * plan. Checks the cache first, then Razorpay.
     */
    public function keyForRazorpayId(string $planId): ?string
    {
        $definitions = $this->definitions();

        foreach (array_keys($definitions) as $key) {
            if ($this->store()->get(self::CACHE_PREFIX.$key) === $planId) {
                return $key;
            }
        }

        if ($definitions === []) {
            return null;
        }

        foreach ($this->remotePlansByKey() as $key => $plans) {
            if (isset($definitions[$key]) && $this->findMatching($definitions[$key], $plans) === $planId) {
                $this->store()->forever(self::CACHE_PREFIX.$key, $planId);

                return $key;
            }
        }

        return null;
    }

    public function forget(string $key): void
    {
        $this->store()->forget(self::CACHE_PREFIX.$key);
    }

    /**
     * @return array<string, non-empty-list<array<string, mixed>>>
     */
    private function remotePlansByKey(): array
    {
        $byKey = [];

        foreach ($this->client->plans()->cursor() as $plan) {
            $notes = $plan['notes'] ?? null;
            $key = is_array($notes) ? ($notes[self::NOTE_KEY] ?? null) : null;

            if (is_string($key)) {
                $byKey[$key][] = $plan;
            }
        }

        return $byKey;
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function findMatching(PlanDefinition $definition, array $plans): ?string
    {
        foreach ($plans as $plan) {
            if ($definition->matches($plan)) {
                return $this->id($plan);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function id(array $plan): string
    {
        return is_string($plan['id'] ?? null) ? $plan['id'] : throw new UnexpectedResponseException('Razorpay returned a plan without an id.');
    }

    private function currency(): string
    {
        $currency = $this->config->get('razorpay-billing.currency', 'INR');

        return is_string($currency) ? $currency : 'INR';
    }

    private function store(): Cache
    {
        $store = $this->config->get('razorpay-billing.plan_cache_store');

        return $this->cache->store(is_string($store) ? $store : null);
    }
}
