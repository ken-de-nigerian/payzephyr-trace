# PayZephyr Trace

**End-to-end payment tracing & forensic analysis for Laravel applications.**

[![Latest Version](https://img.shields.io/packagist/v/ken-denigerian/payzephyr-trace.svg)](https://packagist.org/packages/ken-denigerian/payzephyr-trace)
[![License](https://img.shields.io/packagist/l/ken-denigerian/payzephyr-trace.svg)](https://github.com/ken-de-nigerian/payzephyr-trace/blob/main/LICENSE)

PayZephyr Trace is a standalone Laravel package that records, correlates, and reconstructs everything that happens during a payment lifecycle — from request initiation to provider responses, retries, and webhooks.

It helps answer questions like:

- *Why did this payment fail?*
- *Did the provider respond late or not at all?*
- *Was the webhook duplicated or delayed?*
- *What exactly happened, step by step?*

---

## ✨ Features

- 📍 **Full payment lifecycle tracing** - Track every event from initiation to completion
- 🔗 **Correlation across providers** - Link related events with correlation IDs
- 🌐 **Automatic HTTP request & response logging** - Zero-configuration Guzzle middleware
- 🔔 **Webhook capture & duplicate detection** - One-line trait integration
- 🧵 **Timeline reconstruction per payment** - Visualize payment flows chronologically
- 🛠 **Artisan command for quick inspection** - `payzephyr:trace {payment_id}`
- 🔒 **Payload redaction for sensitive fields** - PCI-compliant by default
- ⚡ **Async recording support** - Queue-based for production performance
- 🎯 **Production-ready** - Built for 2am debugging sessions

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

## 🚀 Quick Start

### 1. Manual Event Recording

```php
use PayZephyr\Trace\Facades\Trace;
use PayZephyr\Trace\Enums\TraceEvent;

Trace::record([
    'payment_id' => 'pay_12345',
    'provider' => 'stripe',
    'event' => TraceEvent::PAYMENT_INITIATED,
    'payload' => [
        'amount' => 5000,
        'currency' => 'NGN'
    ]
]);
```

### 2. Automatic HTTP Tracing

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use PayZephyr\Trace\Middleware\TraceHttpMiddleware;

$stack = HandlerStack::create();
$stack->push(new TraceHttpMiddleware());

$client = new Client(['handler' => $stack]);

// Make request - automatically traced
$response = $client->post('https://api.stripe.com/v1/payment_intents', [
    'trace_payment_id' => 'pay_12345',  // Required
    'trace_provider' => 'stripe',        // Optional (auto-detected)
    'json' => ['amount' => 5000]
]);
```

### 3. Webhook Capture

```php
use PayZephyr\Trace\Traits\CapturesWebhooks;

class StripeWebhookController extends Controller
{
    use CapturesWebhooks;

    public function handle(Request $request)
    {
        // One line - automatically captures and detects duplicates
        $this->captureWebhook(
            paymentId: $request->input('data.object.metadata.payment_id'),
            provider: 'stripe',
            payload: $request->all()
        );

        // Handle webhook logic...
    }
}
```

### 4. View Payment Timeline

```bash
php artisan payzephyr:trace pay_12345
```

Output:
```
Payment Timeline: pay_12345
================================================================================

12:01:03.245 • payment.initiated
12:01:03.891 → provider.request.sent [stripe]
         └─ Response time: 1245ms
12:01:05.136 ← provider.response.received [stripe]
         └─ HTTP 200
12:01:10.453 ← webhook.received [stripe]
12:01:10.678 • payment.completed

────────────────────────────────────────────────────────────────────────────────
Summary:
  Total Events: 5
  Errors: 0
  Duration: 7433ms (7.43s)
  Final Status: COMPLETED
```

---

## 📖 Documentation

### Configuration

All configuration is in `config/trace.php`:

```php
return [
    // Master switch
    'enabled' => env('PAYZEPHYR_TRACE_ENABLED', true),
    
    // Async recording (recommended for production)
    'async' => env('PAYZEPHYR_TRACE_ASYNC', false),
    
    // Data retention
    'retention_days' => env('PAYZEPHYR_TRACE_RETENTION_DAYS', 90),
    
    // Sensitive fields to redact
    'redact_fields' => [
        'card_number',
        'cvv',
        'secret',
        'password',
        'api_key',
    ],
    
    // Provider auto-detection patterns
    'provider_patterns' => [
        'stripe.com' => 'stripe',
        'paystack.co' => 'paystack',
    ],
];
```

### Event Types

PayZephyr Trace includes comprehensive event taxonomy:

**Payment Lifecycle:**
- `payment.initiated`
- `payment.completed`
- `payment.failed`
- `payment.cancelled`
- `payment.refunded`

**Provider Communication:**
- `provider.request.sent`
- `provider.response.received`
- `provider.timeout`
- `provider.error`
- `provider.exception`

**Webhooks:**
- `webhook.received`
- `webhook.duplicate`
- `webhook.validation_failed`
- `webhook.processing_failed`

**Retries:**
- `retry.scheduled`
- `retry.executed`
- `retry.abandoned`

**Authentication:**
- `auth.required` (3DS)
- `auth.completed`
- `auth.failed`

**Verification:**
- `verification.started`
- `verification.completed`
- `verification.failed`

### Complete Service Example

```php
namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use PayZephyr\Trace\Facades\Trace;
use PayZephyr\Trace\Enums\TraceEvent;
use PayZephyr\Trace\Middleware\TraceHttpMiddleware;

class PaymentService
{
    private Client $client;

    public function __construct()
    {
        $stack = HandlerStack::create();
        $stack->push(new TraceHttpMiddleware());
        
        $this->client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.stripe.com/v1/',
        ]);
    }

    public function charge(string $paymentId, int $amount): array
    {
        // Record initiation
        Trace::record([
            'payment_id' => $paymentId,
            'provider' => 'stripe',
            'event' => TraceEvent::PAYMENT_INITIATED,
            'payload' => compact('amount')
        ]);

        $correlationId = Trace::startCorrelation();

        try {
            // HTTP automatically traced
            $response = $this->client->post('payment_intents', [
                'trace_payment_id' => $paymentId,
                'trace_provider' => 'stripe',
                'trace_correlation_id' => $correlationId,
                'json' => ['amount' => $amount]
            ]);

            $data = json_decode($response->getBody(), true);

            // Record success
            Trace::record([
                'payment_id' => $paymentId,
                'provider' => 'stripe',
                'event' => TraceEvent::PAYMENT_COMPLETED,
                'correlation_id' => $correlationId,
            ]);

            return $data;

        } catch (\Exception $e) {
            // Error automatically traced by middleware
            
            Trace::record([
                'payment_id' => $paymentId,
                'provider' => 'stripe',
                'event' => TraceEvent::PAYMENT_FAILED,
                'correlation_id' => $correlationId,
                'payload' => ['error' => $e->getMessage()]
            ]);

            throw $e;
        }
    }
}
```

### Programmatic Timeline Access

```php
use PayZephyr\Trace\Services\TraceTimelineBuilder;

$builder = app(TraceTimelineBuilder::class);
$timeline = $builder->build('pay_12345');

// Check status
if ($timeline->succeeded()) {
    // Payment completed successfully
}

if ($timeline->failed()) {
    // Payment failed
}

// Get events
$allEvents = $timeline->all();
$errors = $timeline->errors();
$stripeEvents = $timeline->forProvider('stripe');

// Metrics
$durationMs = $timeline->duration();
$terminal = $timeline->terminal();
```

### Detailed Analysis

```php
$timeline = $builder->buildDetailed('pay_12345');

// Analyze for issues
$issues = $timeline->analyze();

foreach ($issues as $issue) {
    // [
    //     'type' => 'timeout',
    //     'severity' => 'high',
    //     'message' => 'Provider timeout detected',
    //     'events' => [1, 2, 3]
    // ]
}
```

### Artisan Commands

**View timeline:**
```bash
php artisan payzephyr:trace pay_12345
php artisan payzephyr:trace pay_12345 --detailed
php artisan payzephyr:trace pay_12345 --provider=stripe
php artisan payzephyr:trace pay_12345 --json
```

**Prune old data:**
```bash
php artisan payzephyr:trace-prune
php artisan payzephyr:trace-prune --dry-run
php artisan payzephyr:trace-prune --days=30
```

---

## 🏭 Production Setup

### 1. Enable Async Recording

```env
PAYZEPHYR_TRACE_ASYNC=true
PAYZEPHYR_TRACE_QUEUE_CONNECTION=redis
PAYZEPHYR_TRACE_QUEUE_NAME=default
```

### 2. Separate Database (Optional)

For high-volume applications:

```php
// config/database.php
'connections' => [
    'trace_db' => [
        'driver' => 'mysql',
        'host' => env('TRACE_DB_HOST'),
        // ...
    ],
],

// config/trace.php
'connection' => 'trace_db',
```

### 3. Schedule Pruning

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('payzephyr:trace-prune')
             ->daily()
             ->at('02:00');
}
```

### 4. Configure Retention

```env
PAYZEPHYR_TRACE_RETENTION_DAYS=90
```

---

## 🔐 Security & Privacy

PayZephyr Trace is PCI-compliant by default:

- **Automatic field redaction** for sensitive data
- **Configurable redaction rules**
- **Pattern-based redaction** for card numbers, API keys
- **Header sanitization** for authorization tokens

Redacted fields include:
- `card_number`, `cvv`, `cvc`
- `password`, `secret`, `api_key`
- `authorization`, `token`
- Custom fields via config

---

## 🧪 Testing

```bash
composer test
```

---

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

---

## 🐛 Troubleshooting

### Events not being recorded

1. Check if tracing is enabled: `config('trace.enabled')`
2. If async, ensure queue workers are running
3. Check database connection
4. Verify migrations have run

### HTTP tracing not working

1. Ensure middleware is added to handler stack
2. Verify `trace_payment_id` is provided in options
3. Check provider patterns in config

### Timeline command shows no events

1. Verify payment ID is correct
2. Check database table exists
3. Ensure events were recorded (check logs)

---

## 📚 Use Cases

### When You Should Use PayZephyr Trace

- **Payment-heavy applications** - E-commerce, fintech, SaaS billing
- **Multiple payment providers** - Stripe, Paystack, Flutterwave, etc.
- **Debugging intermittent failures** - "It worked yesterday"
- **Post-incident analysis** - Root cause investigations
- **Audit requirements** - Compliance and record-keeping
- **High-risk payment flows** - Large transactions, critical payments

### What You Can Debug

- Payment timeouts and where they occurred
- Duplicate webhook handling
- Retry logic effectiveness
- Provider response delays
- 3DS authentication flows
- Webhook delivery issues
- Race conditions in payment processing

---

## 🔗 Ecosystem

PayZephyr Trace is **fully standalone**, but it pairs naturally with:

**[PayZephyr](https://github.com/ken-de-nigerian/payzephyr)** - Unified payment abstraction for Laravel

Use **PayZephyr** to route payments  
Use **PayZephyr Trace** to understand what happened

Together, they provide a complete payment solution.

---

## 📈 Roadmap

- [ ] Dashboard UI for timeline visualization
- [ ] Real-time monitoring alerts
- [ ] Metrics and analytics
- [ ] Custom event support improvements
- [ ] More provider integrations
- [ ] Performance optimizations

---

## 📜 License

PayZephyr Trace is open-sourced software licensed under the [MIT license](LICENSE).

---

## 👤 Author

**Nwaneri Chukwunyere Kenneth**  
Laravel Backend Engineer • Open-Source Creator

- GitHub: [@ken-de-nigerian](https://github.com/ken-de-nigerian)
- Medium: [@ken.de.nigerian](https://medium.com/@ken.de.nigerian)
- LinkedIn: [nwaneri-kenneth](https://www.linkedin.com/in/nwaneri-kenneth-284557171/)

---

## 💡 Philosophy

This is not just a logger.

PayZephyr Trace is built for the moment at 2am when production is down, payments are failing, and you need answers **now**.

Every design decision prioritizes:
- **Clarity** over cleverness
- **Completeness** over brevity
- **Reliability** over features

Because when money is involved, you can't afford to guess.

---

## ⭐ Support

If PayZephyr Trace helped you debug a payment issue, please:
- Star the repository
- Share with other Laravel developers
- Report issues you encounter
- Contribute improvements

Your feedback makes this package better for everyone.

---

**Built with ❤️ for the Laravel community**