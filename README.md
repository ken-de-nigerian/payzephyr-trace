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

Trace::record([
    'payment_id' => 'pay_12345',
    'provider' => 'stripe',
    'event' => 'payment.initiated',
    'payload' => [
        'amount' => 5000,
        'currency' => 'NGN'
    ]
]);
```

---

## 🌐 Automatic HTTP Tracing

Attach the middleware to your HTTP client (e.g. Guzzle):

```php
new \GuzzleHttp\Client([
    'handler' => tap(\GuzzleHttp\HandlerStack::create(), function ($stack) {
        $stack->push(new \PayZephyr\Trace\Services\Middleware\TraceHttpMiddleware());
    }),
]);
```

All outbound provider requests and responses will now be traced automatically.

---

## 🔔 Webhook Capture

Use the provided trait in your webhook controllers:

```php
use PayZephyr\Trace\Services\Traits\CaptureWebhook;

class StripeWebhookController extends Controller
{
    use CaptureWebhook;

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

---

## 🧵 View Payment Timeline

Use the Artisan command to reconstruct a payment flow:

```bash
php artisan payzephyr:trace pay_12345
```

Example output:

```
12:01:03 payment.initiated
12:01:04 provider.request.sent (stripe)
12:01:06 provider.response.received (stripe)
12:01:10 webhook.received (stripe)
12:01:10 payment.completed
```

---

## ⚙️ Configuration

```php
return [
    // Fields to redact from payloads
    'redact_fields' => ['card_number', 'cvv', 'secret'],

    // How long to retain trace data
    'retention_days' => 90,
];
```

---

## 🧠 When Should You Use This?

- Payment-heavy applications
- Systems with multiple payment providers
- Debugging intermittent or “ghost” failures
- Auditing & post-incident analysis
- Enterprise or high-risk payment flows

---

## 🔗 Relationship to PayZephyr

PayZephyr Trace is **fully standalone**, but it pairs naturally with  
**PayZephyr** — a unified payment abstraction for Laravel.

Use PayZephyr to **route payments**  
Use PayZephyr Trace to **understand what happened**

---

## 🧪 Status

This package is currently **under active development**.  
APIs may evolve, but the core tracing model is stable.

Contributions and feedback are welcome.

---

## 👤 Author

**Nwaneri Chukwunyere Kenneth**  
Laravel Backend Engineer • Open-Source Creator  

- GitHub: https://github.com/ken-de-nigerian  
- Medium: https://medium.com/@ken.de.nigerian  
- LinkedIn: https://www.linkedin.com/in/nwaneri-kenneth-284557171/
