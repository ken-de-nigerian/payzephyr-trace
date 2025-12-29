<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Contracts;

use PayZephyr\Trace\DataTransferObjects\TraceData;
use PayZephyr\Trace\Models\TraceEvent;

/**
 * Interface for trace recording services
 * Follows Open-Closed Principle - implementations can be swapped without changing client code
 */
interface TraceRecorderInterface
{
    /**
     * Record a trace event
     */
    public function record(TraceData $data): ?TraceEvent;

    /**
     * Start a correlation group (returns correlation ID to use for related events)
     */
    public function startCorrelation(): string;

    /**
     * Record payment initiated event
     */
    public function paymentInitiated(string $paymentId, ?string $provider = null, array $payload = []): ?TraceEvent;

    /**
     * Record payment completed event
     */
    public function paymentCompleted(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEvent;

    /**
     * Record payment failed event
     */
    public function paymentFailed(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEvent;

    /**
     * Record retry scheduled event
     */
    public function retryScheduled(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEvent;

    /**
     * Record retry executed event
     */
    public function retryExecuted(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null): ?TraceEvent;
}

