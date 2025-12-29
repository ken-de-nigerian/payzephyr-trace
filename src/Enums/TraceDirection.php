<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Enums;

enum TraceDirection: string
{
    case INTERNAL = 'internal';   // Events within your application
    case OUTBOUND = 'outbound';   // Requests to external providers
    case INBOUND = 'inbound';     // Webhooks or responses from providers

    public function description(): string
    {
        return match($this) {
            self::INTERNAL => 'Internal application event',
            self::OUTBOUND => 'Outbound request to provider',
            self::INBOUND => 'Inbound webhook or response',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::INTERNAL => '•',
            self::OUTBOUND => '→',
            self::INBOUND => '←',
        };
    }
}