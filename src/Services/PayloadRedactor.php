<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Services;

/**
 * Standalone service for payload redaction
 * Implements recursive redaction with depth limits to prevent memory exhaustion
 */
class PayloadRedactor
{
    private const REDACTED_VALUE = '[REDACTED]';
    private const DEFAULT_MAX_DEPTH = 10;

    /**
     * Redact sensitive fields from payload
     */
    public function redact(array $payload, ?int $maxDepth = null): array
    {
        $fieldsToRedact = config('trace.redact_fields', []);
        $maxDepth = $maxDepth ?? config('trace.redaction_max_depth', self::DEFAULT_MAX_DEPTH);

        return $this->redactRecursive($payload, $fieldsToRedact, $maxDepth, 0);
    }

    /**
     * Recursively redact sensitive fields with depth protection
     */
    private function redactRecursive(array $data, array $fields, int $maxDepth, int $currentDepth): array
    {
        // Prevent infinite recursion and memory exhaustion
        if ($currentDepth >= $maxDepth) {
            return ['[REDACTED: Max depth reached]'];
        }

        $redacted = [];

        foreach ($data as $key => $value) {
            // Check if current key should be redacted (case-insensitive)
            if ($this->shouldRedact($key, $fields)) {
                $redacted[$key] = self::REDACTED_VALUE;
                continue;
            }

            // Recursively process nested arrays
            if (is_array($value)) {
                $redacted[$key] = $this->redactRecursive($value, $fields, $maxDepth, $currentDepth + 1);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * Check if a key should be redacted
     */
    private function shouldRedact(string $key, array $fields): bool
    {
        $keyLower = strtolower($key);

        foreach ($fields as $field) {
            $fieldLower = strtolower($field);

            // Exact match
            if ($keyLower === $fieldLower) {
                return true;
            }

            // Contains match (e.g., "card_number" matches "number" if configured)
            if (str_contains($keyLower, $fieldLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redact specific patterns from string (e.g., card numbers, API keys)
     */
    public function redactPatterns(string $text): string
    {
        // Card numbers (any 13-19 digit sequence)
        $text = preg_replace('/\b\d{13,19}\b/', self::REDACTED_VALUE, $text);

        // API keys (common patterns)
        $text = preg_replace('/sk_live_[a-zA-Z0-9]{24,}/', self::REDACTED_VALUE, $text);
        $text = preg_replace('/sk_test_[a-zA-Z0-9]{24,}/', self::REDACTED_VALUE, $text);

        // Authorization headers
        $text = preg_replace('/Bearer\s+[a-zA-Z0-9_\-\.]+/', 'Bearer ' . self::REDACTED_VALUE, $text);

        return $text;
    }
}
