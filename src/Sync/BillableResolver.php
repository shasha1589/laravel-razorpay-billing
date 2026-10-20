<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Shasha1589\RazorpayBilling\Billable;

/**
 * Finds the billable model from the `billable_type` / `billable_id` notes this
 * package adds to Razorpay subscriptions and orders.
 */
final class BillableResolver
{
    /**
     * @param  array<mixed>  $notes
     */
    public function fromNotes(array $notes): ?Model
    {
        $type = $notes['billable_type'] ?? null;
        $id = $notes['billable_id'] ?? null;

        if (! is_string($type) || ! is_string($id) || $id === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! in_array(Billable::class, class_uses_recursive($class), true)) {
            return null;
        }

        return $class::query()->find($id);
    }
}
