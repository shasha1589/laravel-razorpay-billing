<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Plans;

final class PlanSyncResult
{
    public function __construct(
        public readonly string $key,
        public readonly PlanSyncStatus $status,
        public readonly ?string $razorpayId = null,
    ) {}
}
