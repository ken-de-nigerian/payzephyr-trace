<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PayZephyr\Trace\DataTransferObjects\TraceData;
use PayZephyr\Trace\Models\TraceEvent;

/**
 * Queue job for async trace event recording
 */
class RecordTraceEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly TraceData $traceData
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        TraceEvent::create($this->traceData->toArray());
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Log the failure but don't throw - we don't want trace recording
        // failures to break the application
        logger()->error('Failed to record trace event', [
            'payment_id' => $this->traceData->paymentId,
            'event' => $this->traceData->event->value,
            'exception' => $exception->getMessage(),
        ]);
    }
}
