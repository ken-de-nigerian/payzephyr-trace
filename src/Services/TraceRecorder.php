<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Services;

use Illuminate\Support\Str;
use PayZephyr\Trace\Contracts\TraceRecorderInterface;
use PayZephyr\Trace\DataTransferObjects\TraceData;
use PayZephyr\Trace\Enums\TraceDirection;
use PayZephyr\Trace\Enums\TraceEvent;
use PayZephyr\Trace\Jobs\RecordTraceEventJob;
use PayZephyr\Trace\Models\TraceEvent as TraceEventModel;

/**
 * Core trace recording service
 * Implements TraceRecorderInterface following Open-Closed Principle
 */
class TraceRecorder implements TraceRecorderInterface
{
    public function __construct(
        private readonly PayloadRedactor $redactor
    ) {}

    /**
     * Record a trace event
     */
    public function record(TraceData $data): ?TraceEventModel
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Redact sensitive fields from payload
        $redactedPayload = $this->redactor->redact($data->payload);

        // Create new TraceData with redacted payload
        $redactedData = new TraceData(
            paymentId: $data->paymentId,
            event: $data->event,
            direction: $data->direction,
            payload: $redactedPayload,
            provider: $data->provider,
            correlationId: $data->correlationId,
            metadata: $data->metadata,
            httpMethod: $data->httpMethod,
            httpUrl: $data->httpUrl,
            httpStatusCode: $data->httpStatusCode,
            responseTimeMs: $data->responseTimeMs,
        );

        // Record async or sync based on config
        if ($this->shouldRecordAsync()) {
            return $this->recordAsync($redactedData);
        }

        return $this->recordSync($redactedData);
    }

    /**
     * Record event synchronously
     */
    private function recordSync(TraceData $data): TraceEventModel
    {
        return TraceEventModel::create($data->toArray());
    }

    /**
     * Record event asynchronously via queue
     */
    private function recordAsync(TraceData $data): ?TraceEventModel
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
     * Infer direction based on event type
     */
    private function inferDirection(TraceEvent $event): TraceDirection
    {
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
        $data = new TraceData(
            paymentId: $paymentId,
            event: TraceEvent::PAYMENT_INITIATED,
            direction: TraceDirection::INTERNAL,
            payload: $payload,
            provider: $provider,
        );

        return $this->record($data);
    }

    /**
     * Record payment completed event
     */
    public function paymentCompleted(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEventModel
    {
        $data = new TraceData(
            paymentId: $paymentId,
            event: TraceEvent::PAYMENT_COMPLETED,
            direction: TraceDirection::INTERNAL,
            payload: $payload,
            provider: $provider,
            correlationId: $correlationId,
        );

        return $this->record($data);
    }

    /**
     * Record payment failed event
     */
    public function paymentFailed(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEventModel
    {
        $data = new TraceData(
            paymentId: $paymentId,
            event: TraceEvent::PAYMENT_FAILED,
            direction: TraceDirection::INTERNAL,
            payload: $payload,
            provider: $provider,
            correlationId: $correlationId,
        );

        return $this->record($data);
    }

    /**
     * Record retry scheduled event
     */
    public function retryScheduled(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEventModel
    {
        $data = new TraceData(
            paymentId: $paymentId,
            event: TraceEvent::RETRY_SCHEDULED,
            direction: TraceDirection::INTERNAL,
            payload: $payload,
            provider: $provider,
            correlationId: $correlationId,
        );

        return $this->record($data);
    }

    /**
     * Record retry executed event
     */
    public function retryExecuted(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEventModel
    {
        $data = new TraceData(
            paymentId: $paymentId,
            event: TraceEvent::RETRY_EXECUTED,
            direction: TraceDirection::INTERNAL,
            payload: $payload,
            provider: $provider,
            correlationId: $correlationId,
        );

        return $this->record($data);
    }
}
