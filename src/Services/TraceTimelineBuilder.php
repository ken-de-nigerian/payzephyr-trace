<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Services;

use Illuminate\Support\Collection;
use PayZephyr\Trace\Models\TraceEvent;

class TraceTimelineBuilder
{
    /**
     * Build a timeline for a payment
     */
    public function build(string $paymentId): Timeline
    {
        $events = TraceEvent::forPayment($paymentId)
            ->chronological()
            ->get();

        return new Timeline($paymentId, $events);
    }

    /**
     * Build timeline with detailed analysis
     */
    public function buildDetailed(string $paymentId): DetailedTimeline
    {
        $events = TraceEvent::forPayment($paymentId)
            ->chronological()
            ->get();

        return new DetailedTimeline($paymentId, $events);
    }
}

/**
 * Timeline representation
 */
class Timeline
{
    public function __construct(
        public string $paymentId,
        public Collection $events
    ) {}

    /**
     * Get all events
     */
    public function all(): Collection
    {
        return $this->events;
    }

    /**
     * Get events by provider
     */
    public function forProvider(string $provider): Collection
    {
        return $this->events->where('provider', $provider);
    }

    /**
     * Get error events
     */
    public function errors(): Collection
    {
        return $this->events->filter(fn(TraceEvent $e) => $e->isError());
    }

    /**
     * Get terminal event (if any)
     */
    public function terminal(): ?TraceEvent
    {
        return $this->events->first(fn(TraceEvent $e) => $e->isTerminal());
    }

    /**
     * Check if payment completed successfully
     */
    public function succeeded(): bool
    {
        return $this->terminal()?->event->value === 'payment.completed';
    }

    /**
     * Check if payment failed
     */
    public function failed(): bool
    {
        return $this->terminal()?->event->value === 'payment.failed';
    }

    /**
     * Get duration in milliseconds
     */
    public function duration(): ?int
    {
        if ($this->events->isEmpty()) {
            return null;
        }

        $first = $this->events->first();
        $last = $this->events->last();

        return $last->created_at->diffInMilliseconds($first->created_at);
    }

    /**
     * Format timeline as text
     */
    public function toText(): string
    {
        if ($this->events->isEmpty()) {
            return "No trace events found for payment: {$this->paymentId}";
        }

        $lines = ["Payment Timeline: {$this->paymentId}", str_repeat('=', 80), ""];

        foreach ($this->events as $event) {
            $lines[] = $event->formatForTimeline();
        }

        $lines[] = "";
        $lines[] = $this->getSummary();

        return implode("\n", $lines);
    }

    /**
     * Get timeline summary
     */
    private function getSummary(): string
    {
        $summary = [];

        $summary[] = "Summary:";
        $summary[] = "- Total Events: {$this->events->count()}";
        $summary[] = "- Errors: {$this->errors()->count()}";

        if ($duration = $this->duration()) {
            $summary[] = "- Duration: {$duration}ms";
        }

        if ($terminal = $this->terminal()) {
            $summary[] = "- Status: {$terminal->event->value}";
        }

        return implode("\n", $summary);
    }
}

/**
 * Detailed timeline with analysis
 */
class DetailedTimeline extends Timeline
{
    /**
     * Analyze the timeline for issues
     */
    public function analyze(): array
    {
        $issues = [];

        // Check for timeouts
        $timeouts = $this->events->filter(function (TraceEvent $e) {
            return $e->event->value === 'provider.timeout';
        });

        if ($timeouts->isNotEmpty()) {
            $issues[] = [
                'type' => 'timeout',
                'severity' => 'high',
                'message' => "Provider timeout detected ({$timeouts->count()} occurrence(s))",
                'events' => $timeouts->pluck('id')->toArray(),
            ];
        }

        // Check for duplicate webhooks
        $duplicates = $this->events->filter(function (TraceEvent $e) {
            return $e->event->value === 'webhook.duplicate';
        });

        if ($duplicates->isNotEmpty()) {
            $issues[] = [
                'type' => 'duplicate_webhook',
                'severity' => 'medium',
                'message' => "Duplicate webhooks received ({$duplicates->count()} occurrence(s))",
                'events' => $duplicates->pluck('id')->toArray(),
            ];
        }

        // Check for slow responses
        $slowResponses = $this->events->filter(function (TraceEvent $e) {
            return $e->response_time_ms !== null && $e->response_time_ms > 5000;
        });

        if ($slowResponses->isNotEmpty()) {
            $issues[] = [
                'type' => 'slow_response',
                'severity' => 'medium',
                'message' => "Slow provider responses detected (>5s)",
                'events' => $slowResponses->pluck('id')->toArray(),
            ];
        }

        // Check for missing response after request
        $requests = $this->events->where('event.value', 'provider.request.sent');
        $responses = $this->events->whereIn('event.value', [
            'provider.response.received',
            'provider.error',
            'provider.timeout',
        ]);

        if ($requests->count() > $responses->count()) {
            $issues[] = [
                'type' => 'missing_response',
                'severity' => 'high',
                'message' => "Request sent but no response recorded",
            ];
        }

        return $issues;
    }

    /**
     * Get grouped events by correlation ID
     */
    public function groupedByCorrelation(): Collection
    {
        return $this->events->groupBy('correlation_id');
    }

    /**
     * Get retry attempts
     */
    public function retries(): Collection
    {
        return $this->events->filter(function (TraceEvent $e) {
            return str_starts_with($e->event->value, 'retry.');
        });
    }

    /**
     * Detect anomalies in the timeline
     * Flags orphaned requests and excessive latency
     */
    public function detectAnomalies(): array
    {
        $anomalies = [];

        // Detect orphaned requests (outgoing without incoming)
        $orphanedRequests = $this->detectOrphanedRequests();
        if (!empty($orphanedRequests)) {
            $anomalies = array_merge($anomalies, $orphanedRequests);
        }

        // Detect excessive latency
        $excessiveLatency = $this->detectExcessiveLatency();
        if (!empty($excessiveLatency)) {
            $anomalies = array_merge($anomalies, $excessiveLatency);
        }

        return $anomalies;
    }

    /**
     * Detect orphaned requests (outgoing without corresponding incoming)
     */
    private function detectOrphanedRequests(): array
    {
        $anomalies = [];

        // Get all outbound requests
        $outboundRequests = $this->events->filter(function (TraceEvent $event) {
            return $event->direction->value === 'outbound'
                && $event->event->value === 'provider.request.sent';
        });

        foreach ($outboundRequests as $request) {
            $correlationId = $request->correlation_id;
            $requestTime = $request->created_at;

            // Look for corresponding response within a reasonable time window (e.g., 60 seconds)
            $hasResponse = $this->events->contains(function (TraceEvent $event) use ($correlationId, $requestTime) {
                if ($event->correlation_id !== $correlationId) {
                    return false;
                }

                $isResponse = in_array($event->event->value, [
                    'provider.response.received',
                    'provider.error',
                    'provider.timeout',
                    'provider.exception',
                ]);

                if (!$isResponse) {
                    return false;
                }

                // Response should be after request and within 60 seconds
                $timeDiff = $event->created_at->diffInSeconds($requestTime);
                return $timeDiff >= 0 && $timeDiff <= 60;
            });

            if (!$hasResponse) {
                $anomalies[] = [
                    'type' => 'orphaned_request',
                    'severity' => 'high',
                    'message' => "Orphaned request detected: Request sent at {$requestTime->format('Y-m-d H:i:s')} but no response received",
                    'event_id' => $request->id,
                    'correlation_id' => $correlationId,
                    'provider' => $request->provider,
                    'timestamp' => $requestTime->toIso8601String(),
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Detect excessive latency between request and response
     */
    private function detectExcessiveLatency(): array
    {
        $anomalies = [];
        $threshold = config('trace.excessive_latency_threshold_ms', 5000);

        // Group events by correlation ID to match requests with responses
        $groupedByCorrelation = $this->events->groupBy('correlation_id');

        foreach ($groupedByCorrelation as $correlationId => $events) {
            $request = $events->first(function (TraceEvent $event) {
                return $event->event->value === 'provider.request.sent'
                    && $event->direction->value === 'outbound';
            });

            if (!$request) {
                continue;
            }

            $response = $events->first(function (TraceEvent $event) {
                return in_array($event->event->value, [
                    'provider.response.received',
                    'provider.error',
                    'provider.timeout',
                ]);
            });

            if (!$response) {
                continue;
            }

            // Calculate latency
            $latency = $response->created_at->diffInMilliseconds($request->created_at);

            if ($latency > $threshold) {
                $anomalies[] = [
                    'type' => 'excessive_latency',
                    'severity' => 'medium',
                    'message' => "Excessive latency detected: {$latency}ms (threshold: {$threshold}ms)",
                    'request_id' => $request->id,
                    'response_id' => $response->id,
                    'correlation_id' => $correlationId,
                    'provider' => $request->provider ?? $response->provider,
                    'latency_ms' => $latency,
                    'threshold_ms' => $threshold,
                    'request_timestamp' => $request->created_at->toIso8601String(),
                    'response_timestamp' => $response->created_at->toIso8601String(),
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Format detailed timeline as text with analysis
     */
    public function toDetailedText(): string
    {
        $text = $this->toText();

        $issues = $this->analyze();
        $anomalies = $this->detectAnomalies();

        if (!empty($issues)) {
            $text .= "\n\n" . str_repeat('=', 80) . "\n";
            $text .= "Issues Detected:\n\n";

            foreach ($issues as $issue) {
                $text .= "⚠️  [{$issue['severity']}] {$issue['message']}\n";
            }
        }

        if (!empty($anomalies)) {
            $text .= "\n\n" . str_repeat('=', 80) . "\n";
            $text .= "Anomalies Detected:\n\n";

            foreach ($anomalies as $anomaly) {
                $text .= "🔍 [{$anomaly['severity']}] {$anomaly['message']}\n";
            }
        }

        return $text;
    }
}