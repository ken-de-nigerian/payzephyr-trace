<?php

namespace PayZephyr\Trace\Services;

use Illuminate\Support\Str;
use PayZephyr\Trace\Enums\TraceDirection;
use PayZephyr\Trace\Enums\TraceEvent;
use PayZephyr\Trace\Jobs\RecordTraceEventJob;
use PayZephyr\Trace\Models\TraceEvent as TraceEventModel;

class TraceRecorder
{
    public function __construct(
        private PayloadRedactor $redactor
    ) {}

    /**
     * Record a trace event
     */
    public function record(array $data): ?TraceEventModel
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Normalize the event data
        $normalized = $this->normalizeEventData($data);

        // Redact sensitive fields
        if (!empty($normalized['payload'])) {
            $normalized['payload'] = $this->redactor->redact($normalized['payload']);
        }

        // Record async or sync based on config
        if ($this->shouldRecordAsync()) {
            return $this->recordAsync($normalized);
        }

        return $this->recordSync($normalized);
    }

    /**
     * Record event synchronously
     */
    private function recordSync(array $data): TraceEventModel
    {
        return TraceEventModel::create($data);
    }

    /**
     * Record event asynchronously via queue
     */
    private function recordAsync(array $data): ?TraceEventModel
    {
        $job = new RecordTraceEventJob($data);

        $connection = config('trace.queue.connection');
        $queue = config('trace.queue.name', 'default');

        if ($connection) {
            $job->onConnection($connection);
        }

        dispatch($job->onQueue($queue));

        // Return null since we don't have the model yet
        return null;
    }

    /**
     * Normalize event data to ensure all required fields are present
     */
    private function normalizeEventData(array $data): array
    {
        // Convert string event to enum if necessary
        if (isset($data['event']) && is_string($data['event'])) {
            $data['event'] = $this->normalizeEvent($data['event']);
        }

        // Convert string direction to enum if necessary
        if (isset($data['direction']) && is_string($data['direction'])) {
            $data['direction'] = TraceDirection::from($data['direction']);
        }

        // Ensure direction is set
        if (!isset($data['direction'])) {
            $data['direction'] = $this->inferDirection($data['event'] ?? null);
        }

        // Generate correlation ID if not provided
        if (!isset($data['correlation_id'])) {
            $data['correlation_id'] = $this->generateCorrelationId();
        }

        // Ensure metadata is an array
        if (!isset($data['metadata'])) {
            $data['metadata'] = [];
        }

        return $data;
    }

    /**
     * Normalize event string to TraceEvent enum
     */
    private function normalizeEvent(string $event): TraceEvent
    {
        // Try exact match first
        $enum = TraceEvent::tryFrom($event);

        if ($enum !== null) {
            return $enum;
        }

        // Default to CUSTOM for unknown events
        return TraceEvent::CUSTOM;
    }

    /**
     * Infer direction based on event type
     */
    private function inferDirection(?TraceEvent $event): TraceDirection
    {
        if ($event === null) {
            return TraceDirection::INTERNAL;
        }

        return match ($event) {
            TraceEvent::PROVIDER_REQUEST_SENT => TraceDirection::OUTBOUND,
            TraceEvent::PROVIDER_RESPONSE_RECEIVED,
            TraceEvent::WEBHOOK_RECEIVED,
            TraceEvent::WEBHOOK_DUPLICATE => TraceDirection::INBOUND,
            default => TraceDirection::INTERNAL,
        };
    }

    /**
     * Generate a unique correlation ID
     */
    private function generateCorrelationId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Check if tracing is enabled
     */
    private function isEnabled(): bool
    {
        return config('trace.enabled', true);
    }

    /**
     * Check if events should be recorded asynchronously
     */
    private function shouldRecordAsync(): bool
    {
        return config('trace.async', false);
    }

    /**
     * Start a correlation group (returns correlation ID to use for related events)
     */
    public function startCorrelation(): string
    {
        return $this->generateCorrelationId();
    }

    /**
     * Record payment initiated event
     */
    public function paymentInitiated(string $paymentId, ?string $provider = null, array $payload = []): ?TraceEventModel
    {
        return $this->record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'event' => TraceEvent::PAYMENT_INITIATED,
            'payload' => $payload,
        ]);
    }

    /**
     * Record payment completed event
     */
    public function paymentCompleted(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEventModel
    {
        return $this->record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'event' => TraceEvent::PAYMENT_COMPLETED,
            'correlation_id' => $correlationId,
            'payload' => $payload,
        ]);
    }

    /**
     * Record payment failed event
     */
    public function paymentFailed(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEventModel
    {
        return $this->record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'event' => TraceEvent::PAYMENT_FAILED,
            'correlation_id' => $correlationId,
            'payload' => $payload,
        ]);
    }

    /**
     * Record retry scheduled event
     */
    public function retryScheduled(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEventModel
    {
        return $this->record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'event' => TraceEvent::RETRY_SCHEDULED,
            'correlation_id' => $correlationId,
            'payload' => $payload,
        ]);
    }

    /**
     * Record retry executed event
     */
    public function retryExecuted(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEventModel
    {
        return $this->record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'event' => TraceEvent::RETRY_EXECUTED,
            'correlation_id' => $correlationId,
            'payload' => $payload,
        ]);
    }
}