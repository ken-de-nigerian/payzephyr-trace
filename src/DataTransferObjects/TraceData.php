<?php

declare(strict_types=1);

namespace PayZephyr\Trace\DataTransferObjects;

use PayZephyr\Trace\Enums\TraceDirection;
use PayZephyr\Trace\Enums\TraceEvent;

/**
 * Data Transfer Object for trace events
 * Ensures type safety and data integrity
 */
final class TraceData
{
    public function __construct(
        public readonly string $paymentId,
        public readonly TraceEvent $event,
        public readonly TraceDirection $direction,
        public readonly array $payload = [],
        public readonly ?string $provider = null,
        public readonly ?string $correlationId = null,
        public readonly array $metadata = [],
        public readonly ?string $httpMethod = null,
        public readonly ?string $httpUrl = null,
        public readonly ?int $httpStatusCode = null,
        public readonly ?int $responseTimeMs = null,
    ) {}

    /**
     * Create from array (for backward compatibility during migration)
     */
    public static function fromArray(array $data): self
    {
        // Normalize event
        $event = $data['event'] ?? TraceEvent::CUSTOM;
        if (is_string($event)) {
            $event = TraceEvent::tryFrom($event) ?? TraceEvent::CUSTOM;
        }

        // Normalize direction
        $direction = $data['direction'] ?? TraceDirection::INTERNAL;
        if (is_string($direction)) {
            $direction = TraceDirection::from($direction);
        }

        return new self(
            paymentId: $data['payment_id'] ?? throw new \InvalidArgumentException('payment_id is required'),
            event: $event,
            direction: $direction,
            payload: $data['payload'] ?? [],
            provider: $data['provider'] ?? null,
            correlationId: $data['correlation_id'] ?? null,
            metadata: $data['metadata'] ?? [],
            httpMethod: $data['http_method'] ?? null,
            httpUrl: $data['http_url'] ?? null,
            httpStatusCode: $data['http_status_code'] ?? null,
            responseTimeMs: $data['response_time_ms'] ?? null,
        );
    }

    /**
     * Convert to array for database storage
     */
    public function toArray(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'event' => $this->event->value,
            'direction' => $this->direction->value,
            'payload' => $this->payload,
            'provider' => $this->provider,
            'correlation_id' => $this->correlationId,
            'metadata' => $this->metadata,
            'http_method' => $this->httpMethod,
            'http_url' => $this->httpUrl,
            'http_status_code' => $this->httpStatusCode,
            'response_time_ms' => $this->responseTimeMs,
        ];
    }
}

