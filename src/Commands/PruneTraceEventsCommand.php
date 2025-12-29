<?php

namespace PayZephyr\Trace\Commands;

use Illuminate\Console\Command;
use PayZephyr\Trace\Models\TraceEvent;

class PruneTraceEventsCommand extends Command
{
    protected $signature = 'payzephyr:trace-prune 
                            {--days= : Number of days to retain (overrides config)}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Prune old trace events based on retention policy';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('trace.retention_days');
        $dryRun = $this->option('dry-run');

        if (!$days) {
            $this->error('Retention days not configured. Set trace.retention_days in config or use --days option.');
            return self::FAILURE;
        }

        $this->info("Pruning trace events older than $days days...");

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

        $deleted = $query->delete();
        $this->info("Successfully pruned {$deleted} trace events.");

        return self::SUCCESS;
    }
}