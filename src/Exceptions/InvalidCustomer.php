<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvalidCustomer extends LogicException
{
    public static function notYetCreated(Model $owner): self
    {
        return new self(class_basename($owner).' is not a Razorpay customer yet. See createAsRazorpayCustomer().');
    }
}
