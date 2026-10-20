<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CustomerAlreadyCreated extends LogicException
{
    public static function exists(Model $owner, string $customerId): self
    {
        return new self(class_basename($owner)." is already a Razorpay customer with ID {$customerId}.");
    }
}
