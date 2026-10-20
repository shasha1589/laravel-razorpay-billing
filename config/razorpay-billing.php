<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API credentials
    |--------------------------------------------------------------------------
    |
    | Key ID and secret from the Razorpay Dashboard (Account & Settings → API
    | Keys). Use test-mode keys outside production. The webhook secret is the
    | value you entered when creating the webhook in the Dashboard.
    |
    */

    'key_id' => env('RAZORPAY_KEY_ID'),

    'key_secret' => env('RAZORPAY_KEY_SECRET'),

    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    |
    | base_url:    Razorpay API base URL.
    | timeout:     request timeout in seconds.
    | retries:     extra attempts for GET requests that fail with a connection
    |              error or 5xx, and for any request throttled with a 429.
    |              Other writes are never retried, to avoid duplicates.
    | retry_delay: initial backoff in milliseconds; doubles on every attempt,
    |              with random jitter.
    |
    */

    'api' => [
        'base_url' => env('RAZORPAY_API_URL', 'https://api.razorpay.com/v1'),
        'timeout' => 30,
        'retries' => 2,
        'retry_delay' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Billable model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model that uses the Billable trait (a User, Team,
    | Organization, ...). Webhooks use it to resolve the billable owner.
    |
    */

    'billable_model' => env('RAZORPAY_BILLABLE_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | ISO 4217 currency code used for plans, orders and invoices. All amounts
    | handled by this package are integers in the smallest unit (paise).
    |
    */

    'currency' => 'INR',

    /*
    |--------------------------------------------------------------------------
    | Route path
    |--------------------------------------------------------------------------
    |
    | URI prefix for the package routes: POST {path}/webhook and
    | POST {path}/verify.
    |
    */

    'path' => 'razorpay',

    /*
    |--------------------------------------------------------------------------
    | Checkout verify route middleware
    |--------------------------------------------------------------------------
    |
    | Middleware for POST {path}/verify. The default `web` group includes
    | CSRF protection; the bundled JS helper sends the token from a
    | <meta name="csrf-token"> tag. Add `auth` if only signed-in users check out.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Run migrations
    |--------------------------------------------------------------------------
    |
    | Load the package migrations automatically. Set to false if you publish
    | them (`vendor:publish --tag=razorpay-billing-migrations`) to customise.
    |
    */

    'run_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Keep past-due subscriptions valid
    |--------------------------------------------------------------------------
    |
    | When a charge fails Razorpay retries it and the subscription is
    | `pending`. If true, pending subscriptions still count as valid
    | (subscribed() returns true) while the retries run.
    |
    */

    'keep_past_due_active' => false,

    /*
    |--------------------------------------------------------------------------
    | Webhook event de-duplication
    |--------------------------------------------------------------------------
    |
    | When true, every webhook's X-Razorpay-Event-Id is stored and repeated
    | deliveries of an already processed event are acknowledged without
    | being processed again.
    |
    */

    'webhook_tolerance_events' => true,

    /*
    |--------------------------------------------------------------------------
    | Webhook processing queue
    |--------------------------------------------------------------------------
    |
    | Webhooks are stored, then processed by a queued job so Razorpay gets a
    | fast 200. Set `enabled` to false to process them during the request
    | (a failure then returns 500 and Razorpay retries the delivery).
    |
    */

    'webhook_queue' => [
        'enabled' => env('RAZORPAY_WEBHOOK_QUEUE', true),
        'connection' => env('RAZORPAY_WEBHOOK_QUEUE_CONNECTION'),
        'queue' => env('RAZORPAY_WEBHOOK_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook rate limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute accepted on the webhook route, per client IP,
    | separately from your app's own limits.
    |
    */

    'webhook_rate_limit' => 600,

    /*
    |--------------------------------------------------------------------------
    | UPI AutoPay maximum amount (paise)
    |--------------------------------------------------------------------------
    |
    | UPI-only subscription checkouts above this amount are refused; offer
    | card or bank e-mandate instead. Default ₹15,000. Verify the current
    | limit with Razorpay / NPCI for your account.
    |
    */

    'upi_autopay_max_amount' => 15000_00,

    /*
    |--------------------------------------------------------------------------
    | Upcoming charge notice (hours)
    |--------------------------------------------------------------------------
    |
    | razorpay:upcoming-charges fires an UpcomingCharge event this many hours
    | before a subscription's charge_at, so you can send a pre-debit reminder.
    |
    */

    'upcoming_charge_notice_hours' => 48,

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Plan key => Razorpay plan definition. Synced with
    | `php artisan razorpay:sync-plans`. Razorpay plans are immutable: to
    | change a price, add a new key instead of editing an existing one.
    | Amounts are in paise.
    |
    */

    'plans' => [
        // 'pro-monthly' => [
        //     'name' => 'Pro',
        //     'period' => 'monthly',          // daily, weekly, monthly, quarterly, yearly
        //     'interval' => 1,                // daily plans need at least 7
        //     'amount' => 1999900,            // paise
        //     'currency' => 'INR',            // optional, defaults to `currency`
        //     'description' => null,          // optional
        //     'methods' => ['card', 'emandate', 'upi'],
        //     'total_count' => 120,           // optional default number of billing cycles
        //     'notes' => [],                  // optional, up to 14 string pairs
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan ID cache store
    |--------------------------------------------------------------------------
    |
    | Cache store holding the plan key => Razorpay plan ID map. Null uses the
    | default store. If the cache is cleared the map is rebuilt from Razorpay
    | (plans are tagged with a `plan_key` note), so no plan is created twice.
    |
    */

    'plan_cache_store' => env('RAZORPAY_PLAN_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | GST invoices (optional module)
    |--------------------------------------------------------------------------
    |
    | Generates local GST invoices for captured payments. Confirm invoice
    | format and tax treatment with a chartered accountant.
    |
    */

    'invoices' => [

        // Generate a local invoice for every captured payment.
        'enabled' => false,

        // Your business details printed on the invoice.
        'seller' => [
            'legal_name' => null,
            'gstin' => null,
            'address' => null,
            'state_code' => null, // two-digit GST state code
        ],

        // Services Accounting Code for the service sold.
        'sac_code' => null,

        // GST rate in whole percent.
        'gst_rate' => 18,

        // Whether plan and charge() amounts already include GST. When false
        // (and this module is enabled), GST is added before the amount is
        // sent to Razorpay. Invoices always split the amount actually paid.
        // Changing this after `razorpay:sync-plans` requires new plan keys.
        'prices_include_tax' => false,

        // Billing state code that marks a foreign customer: the supply is a
        // zero-rated export of services. Confirm the code with your CA.
        'export_state_code' => '96',

        // Letter of Undertaking reference printed on export invoices.
        'lut_reference' => null,

        // Invoice numbers look like {prefix}/{FY}/{sequence}; the sequence
        // resets each Indian financial year (April–March).
        'number_prefix' => 'INV',

        // Class implementing InvoiceRenderer used to produce PDFs (optional).
        'renderer' => null,
    ],

];
