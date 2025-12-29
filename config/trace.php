<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trace Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to use for trace data. Set to null to use
    | the default connection. Consider using a separate connection for
    | trace data in high-volume production environments.
    |
    */
    'connection' => env('PAYZEPHYR_TRACE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Trace Table Name
    |--------------------------------------------------------------------------
    |
    | The name of the table where trace events will be stored.
    |
    */
    'table' => env('PAYZEPHYR_TRACE_TABLE', 'payment_trace_events'),
    'table_name' => env('PAYZEPHYR_TRACE_TABLE', 'payment_trace_events'), // Alias for consistency

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | How many days to retain trace data. After this period, old trace
    | events can be pruned. Set to null to keep data indefinitely.
    |
    */
    'retention_days' => env('PAYZEPHYR_TRACE_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Payload Redaction
    |--------------------------------------------------------------------------
    |
    | Fields that should be redacted from trace payloads for security.
    | These fields will be replaced with '[REDACTED]' in stored payloads.
    |
    */
    'redact_fields' => [
        'card_number',
        'cvv',
        'cvc',
        'card_cvv',
        'card_cvc',
        'secret',
        'password',
        'api_key',
        'secret_key',
        'private_key',
        'authorization',
        'token',
        'access_token',
        'refresh_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction Max Depth
    |--------------------------------------------------------------------------
    |
    | Maximum recursion depth for payload redaction to prevent memory
    | exhaustion on circular references or massive nested JSON payloads.
    |
    */
    'redaction_max_depth' => env('PAYZEPHYR_TRACE_REDACTION_MAX_DEPTH', 10),

    /*
    |--------------------------------------------------------------------------
    | Async Recording
    |--------------------------------------------------------------------------
    |
    | Whether to record trace events asynchronously via queue.
    | Recommended for production to minimize performance impact.
    |
    */
    'async' => env('PAYZEPHYR_TRACE_ASYNC', false),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Queue connection and name to use for async trace recording.
    |
    */
    'queue' => [
        'connection' => env('PAYZEPHYR_TRACE_QUEUE_CONNECTION'),
        'name' => env('PAYZEPHYR_TRACE_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch to enable/disable tracing. Useful for temporarily
    | disabling tracing in specific environments.
    |
    */
    'enabled' => env('PAYZEPHYR_TRACE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Provider Identification
    |--------------------------------------------------------------------------
    |
    | Patterns to automatically identify payment providers from URLs.
    | Used by HTTP middleware when provider is not explicitly set.
    |
    */
    'provider_patterns' => [
        'stripe.com' => 'stripe',
        'paystack.co' => 'paystack',
        'flutterwave.com' => 'flutterwave',
        'paypal.com' => 'paypal',
        'braintreepayments.com' => 'paypal',
        'square.com' => 'square',
        'razorpay.com' => 'razorpay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Duplicate Detection Window
    |--------------------------------------------------------------------------
    |
    | Time window in seconds to detect duplicate webhooks.
    | Webhooks with identical provider, event, and payload within this
    | window will be marked as duplicates.
    |
    */
    'webhook_duplicate_window' => 300, // 5 minutes

    /*
    |--------------------------------------------------------------------------
    | Excessive Latency Threshold
    |--------------------------------------------------------------------------
    |
    | Time in milliseconds between request and response that is considered
    | excessive. Used for anomaly detection in timeline analysis.
    |
    */
    'excessive_latency_threshold_ms' => env('PAYZEPHYR_TRACE_EXCESSIVE_LATENCY_THRESHOLD_MS', 5000),
];