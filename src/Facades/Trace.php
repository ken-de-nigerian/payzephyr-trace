<?php

namespace PayZephyr\Trace\Facades;

use Illuminate\Support\Facades\Facade;
use PayZephyr\Trace\Models\TraceEvent as TraceEventModel;

/**
 * @method static TraceEventModel|null record(array $data)
 * @method static string startCorrelation()
 * @method static TraceEventModel|null paymentInitiated(string $paymentId, ?string $provider = null, array $payload = [])
 * @method static TraceEventModel|null paymentCompleted(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null)
 * @method static TraceEventModel|null paymentFailed(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null)
 * @method static TraceEventModel|null retryScheduled(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null)
 * @method static TraceEventModel|null retryExecuted(string $paymentId, ?string $provider = null, array $payload = [], ?string $correlationId = null)
 *
 * @see \PayZephyr\Trace\Services\TraceRecorder
 */
class Trace extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'payzephyr.trace';
    }
}