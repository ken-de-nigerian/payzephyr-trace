<?php

namespace PayZephyr\Trace\Services;

class PayloadRedactor
{
    private const REDACTED_VALUE = '[REDACTED]';

    /**
     * Redact sensitive fields from payload
     */
    public function redact(array $payload): array
    {
        $fieldsToRedact = config('trace.redact_fields', []);

        return $this->redactRecursive($payload, $fieldsToRedact);
    }

    /**
     * Recursively redact sensitive fields
     */
    private function redactRecursive(array $data, array $fields): array
    {
        foreach ($data as $key => $value) {
            // Check if current key should be redacted (case-insensitive)
            if ($this->shouldRedact($key, $fields)) {
                $data[$key] = self::REDACTED_VALUE;
                continue;
            }

            // Recursively process nested arrays
            if (is_array($value)) {
                $data[$key] = $this->redactRecursive($value, $fields);
            }
        }

        return $data;
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