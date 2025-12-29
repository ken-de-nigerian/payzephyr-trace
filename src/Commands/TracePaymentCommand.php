<?php

namespace PayZephyr\Trace\Commands;

use Illuminate\Console\Command;
use PayZephyr\Trace\Services\TraceTimelineBuilder;
use PayZephyr\Trace\Services\Timeline;
use PayZephyr\Trace\Services\DetailedTimeline;

class TracePaymentCommand extends Command
{
    protected $signature = 'payzephyr:trace 
                            {payment_id : The payment ID to trace}
                            {--detailed : Show detailed analysis}
                            {--json : Output as JSON}
                            {--provider= : Filter by provider}';

    protected $description = 'Reconstruct the timeline of a payment transaction';

    public function __construct(
        private TraceTimelineBuilder $timelineBuilder
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $paymentId = $this->argument('payment_id');
        $detailed = $this->option('detailed');
        $json = $this->option('json');
        $provider = $this->option('provider');

        $this->info("Reconstructing payment timeline for: {$paymentId}");
        $this->newLine();

        // Build timeline
        $timeline = $detailed
            ? $this->timelineBuilder->buildDetailed($paymentId)
            : $this->timelineBuilder->build($paymentId);

        // Filter by provider if requested
        if ($provider) {
            $filteredEvents = $timeline->forProvider($provider);
            $timeline = $detailed
                ? new DetailedTimeline($paymentId, $filteredEvents)
                : new Timeline($paymentId, $filteredEvents);
        }

        // Output as JSON if requested
        if ($json) {
            $this->outputJson($timeline);
            return self::SUCCESS;
        }

        // Output as text
        $this->outputText($timeline, $detailed);

        return self::SUCCESS;
    }

    private function outputText($timeline, bool $detailed): void
    {
        if ($timeline->events->isEmpty()) {
            $this->warn('No trace events found for this payment.');
            return;
        }

        // Display timeline
        foreach ($timeline->events as $event) {
            $icon = $event->getDirectionIcon();
            $time = $event->created_at->format('H:i:s.v');
            $eventName = $event->event->value;
            $provider = $event->provider ? " [{$event->provider}]" : '';

            // Color code based on event type
            $color = $this->getEventColor($event);

            $line = "{$time} {$icon} {$eventName}{$provider}";

            $this->{$color}($line);

            // Show response time if available
            if ($event->response_time_ms !== null) {
                $this->line("         └─ Response time: {$event->response_time_ms}ms");
            }

            // Show HTTP status if available
            if ($event->http_status_code !== null) {
                $statusColor = $event->http_status_code >= 400 ? 'error' : 'info';
                $this->{$statusColor}("         └─ HTTP {$event->http_status_code}");
            }
        }

        $this->newLine();
        $this->displaySummary($timeline);

        // Show analysis if detailed
        if ($detailed && method_exists($timeline, 'analyze')) {
            $this->displayAnalysis($timeline);
        }
    }

    private function displaySummary($timeline): void
    {
        $this->line(str_repeat('─', 80));
        $this->info('Summary:');
        $this->line("  Total Events: {$timeline->events->count()}");
        $this->line("  Errors: {$timeline->errors()->count()}");

        if ($duration = $timeline->duration()) {
            $seconds = round($duration / 1000, 2);
            $this->line("  Duration: {$duration}ms ({$seconds}s)");
        }

        if ($terminal = $timeline->terminal()) {
            $status = $timeline->succeeded() ? 'COMPLETED' : 'FAILED';
            $color = $timeline->succeeded() ? 'info' : 'error';
            $this->{$color}("  Final Status: {$status}");
        } else {
            $this->warn('  Final Status: INCOMPLETE (no terminal event)');
        }
    }

    private function displayAnalysis($timeline): void
    {
        $issues = $timeline->analyze();

        if (empty($issues)) {
            $this->newLine();
            $this->info('✓ No issues detected');
            return;
        }

        $this->newLine();
        $this->line(str_repeat('─', 80));
        $this->error('Issues Detected:');
        $this->newLine();

        foreach ($issues as $issue) {
            $severity = strtoupper($issue['severity']);
            $icon = $issue['severity'] === 'high' ? '⚠️' : 'ℹ️';

            $this->line("{$icon}  [{$severity}] {$issue['message']}");
        }
    }

    private function outputJson($timeline): void
    {
        $data = [
            'payment_id' => $timeline->paymentId,
            'events' => $timeline->events->map(function ($event) {
                return [
                    'id' => $event->id,
                    'timestamp' => $event->created_at->toIso8601String(),
                    'event' => $event->event->value,
                    'direction' => $event->direction->value,
                    'provider' => $event->provider,
                    'http_status' => $event->http_status_code,
                    'response_time_ms' => $event->response_time_ms,
                    'payload' => $event->payload,
                ];
            })->toArray(),
            'summary' => [
                'total_events' => $timeline->events->count(),
                'errors' => $timeline->errors()->count(),
                'duration_ms' => $timeline->duration(),
                'succeeded' => $timeline->succeeded(),
                'failed' => $timeline->failed(),
            ],
        ];

        if (method_exists($timeline, 'analyze')) {
            $data['issues'] = $timeline->analyze();
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    private function getEventColor($event): string
    {
        if ($event->isError()) {
            return 'error';
        }

        if ($event->isTerminal()) {
            return $event->event->value === 'payment.completed' ? 'info' : 'warn';
        }

        return 'line';
    }
}