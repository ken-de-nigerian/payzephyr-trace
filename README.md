# PayZephyr Trace

**End-to-end payment tracing & forensic analysis for Laravel applications.**

PayZephyr Trace is a standalone Laravel package that records, correlates, and reconstructs everything that happens during a payment lifecycle — from request initiation to provider responses, retries, and webhooks.

It helps answer questions like:

- *Why did this payment fail?*
- *Did the provider respond late or not at all?*
- *Was the webhook duplicated or delayed?*
- *What exactly happened, step by step?*

---

## ✨ Features

- 📍 **Full payment lifecycle tracing**
- 🔗 **Correlation across providers** (Stripe, Paystack, etc.)
- 🌐 **Automatic HTTP request & response logging**
- 🔔 **Webhook capture & duplicate detection**
- 🧵 **Timeline reconstruction per payment**
- 🛠 **Artisan command for quick inspection**
- 🔒 **Payload redaction for sensitive fields**

---

## 📦 Installation

```bash
composer require ken-denigerian/payzephyr-trace
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=payzephyr-trace-config
```

Run migrations:

```bash
php artisan migrate
```

---

## 🚀 Basic Usage

### Recording Events Manually

```php
use PayZephyr\Trace\Facades\Trace;
use PayZephyr\Trace\Enums\TraceEvent;

// Basic recording
Trace::record([
    'payment_id' => 'pay_12345',
    'provider' => 'stripe',
    'event' => TraceEvent::PAYMENT_INITIATED,
    'payload' => [
        'amount' => 5000,
        'currency' => 'NGN'
    ]
]);

// Convenience methods
Trace::paymentInitiated('pay_12345', 'stripe', ['amount' => 5000]);
Trace::paymentCompleted('pay_12345', 'stripe', ['reference' => 'ref_123']);
Trace::paymentFailed('pay_12345', 'stripe', ['error' => 'Insufficient funds']);

// With correlation ID for grouping related events
$correlationId = Trace::startCorrelation();
Trace::record([
    'payment_id' => 'pay_12345',
    'provider' => 'stripe',
    'event' => TraceEvent::PROVIDER_REQUEST_SENT,
    'correlation_id' => $correlationId,
    'payload' => [...]
]);
```

---

## 🌐 Automatic HTTP Tracing

Attach the middleware to your HTTP client (e.g. Guzzle):

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use PayZephyr\Trace\Middleware\TraceHttpMiddleware;

$stack = HandlerStack::create();
$stack->push(new TraceHttpMiddleware());

$client = new Client([
    'handler' => $stack,
    'base_uri' => 'https://api.stripe.com/v1/',
]);
```

When making requests, pass trace context via options:

```php
$response = $client->post('payment_intents', [
    'trace_payment_id' => 'pay_12345',
    'trace_provider' => 'stripe',
    'trace_correlation_id' => Trace::startCorrelation(),
    'json' => [...],
]);
```

All outbound provider requests and responses will now be traced automatically.

---

## 🔔 Webhook Capture

Use the provided trait in your webhook controllers:

```php
use PayZephyr\Trace\Traits\CapturesWebhooks;

class StripeWebhookController extends Controller
{
    use CapturesWebhooks;

    public function handle(Request $request)
    {
        $this->captureWebhook(
            paymentId: $request->input('data.object.metadata.payment_id'),
            provider: 'stripe',
            payload: $request->all()
        );

        // Handle webhook logic
    }
}
```

The trait automatically:
- Detects duplicate webhooks
- Records webhook timing and source
- Captures validation failures
- Tracks processing errors

---

## 🧵 View Payment Timeline

### Artisan Command

Use the Artisan command to reconstruct a payment flow:

```bash
php artisan payzephyr:trace pay_12345
```

With detailed analysis:

```bash
php artisan payzephyr:trace pay_12345 --detailed
```

Filter by provider:

```bash
php artisan payzephyr:trace pay_12345 --provider=stripe
```

Output as JSON:

```bash
php artisan payzephyr:trace pay_12345 --json
```

Example output:

```
12:01:03.123 • payment.initiated
12:01:04.456 → provider.request.sent [stripe]
         └─ Response time: 1234ms
         └─ HTTP 200
12:01:05.690 ← provider.response.received [stripe]
12:01:10.234 ← webhook.received [stripe]
12:01:10.567 • payment.completed

────────────────────────────────────────────────────────────────────────────────
Summary:
  Total Events: 5
  Errors: 0
  Duration: 7444ms (7.44s)
  Final Status: COMPLETED
```

### PHP API

```php
use PayZephyr\Trace\Services\TraceTimelineBuilder;

$builder = app(TraceTimelineBuilder::class);

// Simple timeline
$timeline = $builder->build('pay_12345');

// Check if payment succeeded
if ($timeline->succeeded()) {
    // Payment completed successfully
}

// Get all errors
$errors = $timeline->errors();

// Get duration
$duration = $timeline->duration(); // milliseconds

// Detailed timeline with analysis
$detailed = $builder->buildDetailed('pay_12345');
$issues = $detailed->analyze(); // Array of detected issues
```

---

## ⚙️ Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=payzephyr-trace-config
```

Key configuration options:

```php
return [
    // Database connection (null = default)
    'connection' => env('PAYZEPHYR_TRACE_CONNECTION', null),

    // Table name
    'table' => env('PAYZEPHYR_TRACE_TABLE', 'payment_trace_events'),

    // Data retention (days)
    'retention_days' => env('PAYZEPHYR_TRACE_RETENTION_DAYS', 90),

    // Fields to redact from payloads
    'redact_fields' => [
        'card_number', 'cvv', 'cvc', 'secret',
        'api_key', 'authorization', 'token',
    ],

    // Async recording (recommended for production)
    'async' => env('PAYZEPHYR_TRACE_ASYNC', false),

    // Queue configuration
    'queue' => [
        'connection' => env('PAYZEPHYR_TRACE_QUEUE_CONNECTION', null),
        'name' => env('PAYZEPHYR_TRACE_QUEUE_NAME', 'default'),
    ],

    // Master switch
    'enabled' => env('PAYZEPHYR_TRACE_ENABLED', true),

    // Provider auto-detection patterns
    'provider_patterns' => [
        'stripe.com' => 'stripe',
        'paystack.co' => 'paystack',
        // Add your providers...
    ],

    // Webhook duplicate detection window (seconds)
    'webhook_duplicate_window' => 300,
];
```

---

## 🔄 Data Retention & Cleanup

Automatically prune old trace events:

```bash
php artisan payzephyr:trace-prune
```

Dry run to see what would be deleted:

```bash
php artisan payzephyr:trace-prune --dry-run
```

Override retention days:

```bash
php artisan payzephyr:trace-prune --days=30
```

Schedule in your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('payzephyr:trace-prune')->daily();
}
```

## 🧠 When Should You Use This?

- Payment-heavy applications
- Systems with multiple payment providers
- Debugging intermittent or "ghost" failures
- Auditing & post-incident analysis
- Enterprise or high-risk payment flows
- When you need to answer: "What happened to this payment?"

---

## 🔗 Relationship to PayZephyr

PayZephyr Trace is **fully standalone**, but it pairs naturally with  
**PayZephyr** — a unified payment abstraction for Laravel.

Use PayZephyr to **route payments**  
Use PayZephyr Trace to **understand what happened**

---

## 📚 Event Taxonomy

The package tracks a comprehensive set of payment events:

**Payment Lifecycle:**
- `payment.initiated` - Payment flow started
- `payment.completed` - Payment succeeded
- `payment.failed` - Payment failed
- `payment.cancelled` - Payment cancelled
- `payment.refunded` - Payment refunded

**Provider Communication:**
- `provider.request.sent` - Request sent to provider
- `provider.response.received` - Response received
- `provider.timeout` - Request timed out
- `provider.error` - Provider returned error
- `provider.exception` - Exception during communication

**Webhooks:**
- `webhook.received` - Webhook received
- `webhook.duplicate` - Duplicate webhook detected
- `webhook.validation_failed` - Signature validation failed
- `webhook.processing_failed` - Processing error

**Retries:**
- `retry.scheduled` - Retry scheduled
- `retry.executed` - Retry executed
- `retry.abandoned` - Retries abandoned

**Authentication:**
- `auth.required` - 3DS/auth required
- `auth.completed` - Auth completed
- `auth.failed` - Auth failed

**Verification:**
- `verification.started` - Verification started
- `verification.completed` - Verification completed
- `verification.failed` - Verification failed

**Custom Events:**
- `custom` - Custom event (extensible)

## 🔒 Security & Privacy

- **Automatic redaction** of sensitive fields (card numbers, CVV, API keys)
- **Configurable redaction** via `redact_fields` config
- **Pattern-based redaction** for card numbers and API keys
- **Header sanitization** (authorization headers are redacted)
- **No secrets logged** by default

## 🧪 Status

This package is **production-ready** and actively maintained.  
The core tracing model is stable.

Contributions and feedback are welcome.

---

## 👤 Author

**Nwaneri Chukwunyere Kenneth**  
Laravel Backend Engineer • Open-Source Creator  

- GitHub: https://github.com/ken-de-nigerian  
- Medium: https://medium.com/@ken.de.nigerian  
- LinkedIn: https://www.linkedin.com/in/nwaneri-kenneth-284557171/
