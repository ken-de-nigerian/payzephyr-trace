<?php

namespace PayZephyr\Trace\Traits;

use Illuminate\Http\Request;
use PayZephyr\Trace\Enums\TraceDirection;
use PayZephyr\Trace\Enums\TraceEvent;
use PayZephyr\Trace\Facades\Trace;
use PayZephyr\Trace\Models\TraceEvent as TraceEventModel;

/**
 * Trait for automatically capturing webhooks in controllers
 *
 * Usage:
 * class StripeWebhookController extends Controller
 * {
 *     use CapturesWebhooks;
 *
 *     public function handle(Request $request)
 *     {
 *         $this->captureWebhook(
 *             paymentId: $request->input('data.object.metadata.payment_id'),
 *             provider: 'stripe',
 *             payload: $request->all()
 *         );
 *
 *         // Process webhook...
 *     }
 * }
 */
trait CapturesWebhooks
{
    /**
     * Capture a webhook event
     */
    protected function captureWebhook(
        ?string $paymentId,
        string $provider,
        array $payload,
        ?string $correlationId = null,
        ?Request $request = null
    ): ?TraceEventModel {
        // Skip if no payment ID
        if (!$paymentId) {
            return null;
        }

        // Check for duplicate
        $isDuplicate = $this->isDuplicateWebhook($paymentId, $provider, $payload);

        $event = $isDuplicate ? TraceEvent::WEBHOOK_DUPLICATE : TraceEvent::WEBHOOK_RECEIVED;

        // Get request details if available
        $request = $request ?? request();
        $metadata = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
        ];

        return Trace::record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'correlation_id' => $correlationId,
            'event' => $event,
            'direction' => TraceDirection::INBOUND,
            'payload' => $payload,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Check if this webhook is a duplicate
     */
    private function isDuplicateWebhook(string $paymentId, string $provider, array $payload): bool
    {
        $window = config('trace.webhook_duplicate_window', 300);

        // Generate a simple hash of the payload for comparison
        $payloadHash = md5(json_encode($payload));

        // Check for recent identical webhooks
        $exists = TraceEventModel::forPayment($paymentId)
            ->forProvider($provider)
            ->where('event', TraceEvent::WEBHOOK_RECEIVED->value)
            ->withinWindow($window)
            ->get()
            ->contains(function (TraceEventModel $event) use ($payloadHash) {
                $existingHash = md5(json_encode($event->payload));
                return $existingHash === $payloadHash;
            });

        return $exists;
    }

    /**
     * Capture webhook validation failure
     */
    protected function captureWebhookValidationFailure(
        ?string $paymentId,
        string $provider,
        string $reason,
        ?array $payload = null
    ): ?TraceEventModel {
        if (!$paymentId) {
            return null;
        }

        return Trace::record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'event' => TraceEvent::WEBHOOK_VALIDATION_FAILED,
            'direction' => TraceDirection::INBOUND,
            'payload' => [
                'reason' => $reason,
                'webhook_payload' => $payload,
            ],
        ]);
    }

    /**
     * Capture webhook processing failure
     */
    protected function captureWebhookProcessingFailure(
        string $paymentId,
        string $provider,
        \Throwable $exception,
        ?array $payload = null
    ): TraceEventModel {
        return Trace::record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'event' => TraceEvent::WEBHOOK_PROCESSING_FAILED,
            'direction' => TraceDirection::INBOUND,
            'payload' => [
                'error' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'trace' => $exception->getTraceAsString(),
                'webhook_payload' => $payload,
            ],
        ]);
    }

    /**
     * Sanitize headers to remove sensitive data
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $values) {
            $keyLower = strtolower($key);

            // Redact sensitive headers
            if (in_array($keyLower, ['authorization', 'x-api-key', 'api-key', 'stripe-signature'])) {
                $sanitized[$key] = ['[REDACTED]'];
            } else {
                $sanitized[$key] = $values;
            }
        }

        return $sanitized;
    }
}