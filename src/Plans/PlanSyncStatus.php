<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Plans;

enum PlanSyncStatus: string
{
    case Created = 'created';
    case WouldCreate = 'would create';
    case Exists = 'exists';
    case Conflict = 'conflict';
}
