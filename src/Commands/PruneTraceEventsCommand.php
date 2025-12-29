<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Commands;

use Illuminate\Console\Command;
use PayZephyr\Trace\Models\TraceEvent;

/**
 * Command to prune old trace events
 * Uses chunking to avoid database locks when deleting millions of records
 */
class PruneTraceEventsCommand extends Command
{
    protected $signature = 'payzephyr:trace-prune 
                            {--days= : Number of days to retain (overrides config)}
                            {--dry-run : Show what would be deleted without deleting}
                            {--chunk=1000 : Number of records to delete per chunk}';

    protected $description = 'Prune old trace events based on retention policy';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('trace.retention_days');
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) ($this->option('chunk') ?? 1000);

        if (!$days) {
            $this->error('Retention days not configured. Set trace.retention_days in config or use --days option.');
            return self::FAILURE;
        }

        $this->info("Pruning trace events older than {$days} days...");

        $query = TraceEvent::olderThan($days);
        $count = $query->count();

        if ($count === 0) {
            $this->info('No trace events to prune.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] Would delete {$count} trace events");

            // Show sample of what would be deleted
            $samples = $query->limit(5)->get();
            $this->newLine();
            $this->line('Sample records that would be deleted:');
            foreach ($samples as $sample) {
                $this->line("  - {$sample->payment_id} ({$sample->created_at->format('Y-m-d H:i:s')})");
            }

            return self::SUCCESS;
        }

        if (!$this->confirm("Delete {$count} trace events?", true)) {
            $this->info('Pruning cancelled.');
            return self::SUCCESS;
        }

        // Use chunking to avoid database locks
        $this->info("Deleting in chunks of {$chunkSize} records...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $totalDeleted = 0;

        $query->chunkById($chunkSize, function ($events) use (&$totalDeleted, $bar) {
            $deleted = $events->count();
            TraceEvent::whereIn('id', $events->pluck('id'))->delete();
            $totalDeleted += $deleted;
            $bar->advance($deleted);
        });

        $bar->finish();
        $this->newLine();
        $this->info("Successfully pruned {$totalDeleted} trace events.");

        return self::SUCCESS;
    }
}
