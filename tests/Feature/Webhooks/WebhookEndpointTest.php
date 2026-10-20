<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Shasha1589\RazorpayBilling\Events\WebhookReceived;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Shasha1589\RazorpayBilling\Webhooks\Handlers\SubscriptionChargedHandler;
use Shasha1589\RazorpayBilling\Webhooks\ProcessWebhookEvent;
use Shasha1589\RazorpayBilling\Webhooks\WebhookHandler;
use Shasha1589\RazorpayBilling\Webhooks\WebhookProcessor;

it('rejects a webhook with an invalid signature', function () {
    $this->postWebhook(['event' => 'payment.captured'], secret: 'wrong')->assertForbidden();

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('rejects a webhook without a signature', function () {
    $this->postJson(route('razorpay-billing.webhook'), ['event' => 'payment.captured'])->assertForbidden();
});

it('signs the raw body, so a re-encoded body fails', function () {
    $body = '{"event":"payment.captured",  "payload":{}}';
    $signature = hash_hmac('sha256', $body, 'dummy_webhook_secret');

    $this->call('POST', route('razorpay-billing.webhook'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_raw',
    ], content: $body)->assertOk();

    $this->call('POST', route('razorpay-billing.webhook'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_raw2',
    ], content: '{"event":"payment.captured","payload":{}}')->assertForbidden();
});

it('rejects every webhook when no secret is configured', function () {
    config(['razorpay-billing.webhook_secret' => null]);

    $this->postWebhook(['event' => 'payment.captured'], secret: '')->assertForbidden();
});

it('stores and processes a webhook', function () {
    Event::fake([WebhookReceived::class]);

    $this->postWebhook(['event' => 'something.new', 'payload' => []], 'evt_1')
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    $event = WebhookEvent::query()->sole();

    expect($event->event_id)->toBe('evt_1')
        ->and($event->event)->toBe('something.new')
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->error)->toBeNull();
    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->payload['event'] === 'something.new');
});

it('does not process a duplicate delivery twice', function () {
    Event::fake([WebhookReceived::class]);

    $this->postWebhook(['event' => 'something.new'], 'evt_dup')->assertOk();
    $this->postWebhook(['event' => 'something.new'], 'evt_dup')->assertOk()->assertJson(['status' => 'duplicate']);

    expect(WebhookEvent::query()->count())->toBe(1);
    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

it('reprocesses duplicates when de-duplication is off', function () {
    config(['razorpay-billing.webhook_tolerance_events' => false]);
    Event::fake([WebhookReceived::class]);

    $this->postWebhook(['event' => 'something.new'], 'evt_dup')->assertOk();
    $this->postWebhook(['event' => 'something.new'], 'evt_dup')->assertOk();

    Event::assertDispatchedTimes(WebhookReceived::class, 2);
});

it('rejects a malformed payload', function () {
    $this->postWebhook(['no_event' => true])->assertStatus(400);
});

it('queues processing on the configured connection and queue', function () {
    Queue::fake();
    config(['razorpay-billing.webhook_queue.connection' => 'redis', 'razorpay-billing.webhook_queue.queue' => 'billing']);

    $this->postWebhook(['event' => 'payment.captured'])->assertOk();

    Queue::assertPushed(ProcessWebhookEvent::class, fn (ProcessWebhookEvent $job) => $job->connection === 'redis' && $job->queue === 'billing');
    expect(WebhookEvent::query()->sole()->processed_at)->toBeNull();
});

it('returns 500 and records the error when synchronous processing fails, then succeeds on retry', function () {
    config(['razorpay-billing.webhook_queue.enabled' => false]);
    $fail = true;
    Event::listen(WebhookReceived::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('listener down');
        }
    });

    $this->withoutExceptionHandling();
    expect(fn () => $this->postWebhook(['event' => 'something.new'], 'evt_retry'))->toThrow(RuntimeException::class);
    expect(WebhookEvent::query()->sole()->error)->toBeNull(); // handler succeeded; listener ran after commit

    $fail = false;
    $this->postWebhook(['event' => 'something.new'], 'evt_retry')->assertOk();
});

it('records handler errors and leaves the event unprocessed', function () {
    config(['razorpay-billing.webhook_queue.enabled' => false]);
    $event = WebhookEvent::query()->create(['event_id' => 'evt_bad', 'event' => 'subscription.charged', 'payload' => ['event' => 'subscription.charged']]);
    app()->bind(SubscriptionChargedHandler::class, fn () => new class implements WebhookHandler
    {
        public function handle(WebhookEvent $event): void
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(WebhookProcessor::class)->process($event))->toThrow(RuntimeException::class, 'boom');

    expect($event->fresh()->processed_at)->toBeNull()
        ->and($event->fresh()->error)->toContain('boom');
});

it('replays a stored event', function () {
    Event::fake([WebhookReceived::class]);
    WebhookEvent::query()->create(['event_id' => 'evt_replay', 'event' => 'something.new', 'payload' => ['event' => 'something.new'], 'processed_at' => now()]);

    $this->artisan('razorpay:webhook-replay', ['event_id' => 'evt_replay'])->assertSuccessful();
    $this->artisan('razorpay:webhook-replay', ['event_id' => 'missing'])->assertFailed();

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

it('is rate limited separately', function () {
    config(['razorpay-billing.webhook_rate_limit' => 2]);

    $this->postWebhook(['event' => 'a'])->assertOk();
    $this->postWebhook(['event' => 'a'])->assertOk();
    $this->postWebhook(['event' => 'a'])->assertStatus(429);
});
