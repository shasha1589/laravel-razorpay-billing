<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Shasha1589\RazorpayBilling\Billable;

class User extends Model
{
    use Billable;

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];
}
