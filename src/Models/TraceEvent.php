<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PayZephyr\Trace\Enums\TraceDirection;
use PayZephyr\Trace\Enums\TraceEvent as TraceEventEnum;

class TraceEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'event' => TraceEventEnum::class,
        'direction' => TraceDirection::class,
        'payload' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('trace.table_name', config('trace.table', 'payment_trace_events'));
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnectionName(): ?string
    {
        return config('trace.connection');
    }

    /**
     * Scope to filter by payment ID
     */
    public function scopeForPayment(Builder $query, string $paymentId): Builder
    {
        return $query->where('payment_id', $paymentId);
    }

    /**
     * Scope to filter by provider
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope to filter by correlation ID
     */
    public function scopeForCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_id', $correlationId);
    }

    /**
     * Scope to get events in chronological order
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('created_at')->orderBy('id');
    }

    /**
     * Scope to get events in reverse chronological order
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Scope to get only error events
     */
    public function scopeErrors(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (TraceEventEnum::cases() as $event) {
                if ($event->isError()) {
                    $q->orWhere('event', $event->value);
                }
            }
        });
    }

    /**
     * Scope to get events within a time window
     */
    public function scopeWithinWindow(Builder $query, int $seconds): Builder
    {
        return $query->where('created_at', '>=', now()->subSeconds($seconds));
    }

    /**
     * Scope to prune old records
     */
    public function scopeOlderThan(Builder $query, int $days): Builder
    {
        return $query->where('created_at', '<', now()->subDays($days));
    }

    /**
     * Check if this event is an error
     */
    public function isError(): bool
    {
        return $this->event->isError();
    }

    /**
     * Check if this event is terminal
     */
    public function isTerminal(): bool
    {
        return $this->event->isTerminal();
    }

    /**
     * Get the event description
     */
    public function getEventDescription(): string
    {
        return $this->event->description();
    }

    /**
     * Get the direction icon
     */
    public function getDirectionIcon(): string
    {
        return $this->direction->icon();
    }

    /**
     * Format for display in timeline
     */
    public function formatForTimeline(): string
    {
        $time = $this->created_at->format('H:i:s.v');
        $icon = $this->getDirectionIcon();
        $event = $this->event->value;
        $provider = $this->provider ? " ({$this->provider})" : '';

        return "{$time} {$icon} {$event}{$provider}";
    }
}
