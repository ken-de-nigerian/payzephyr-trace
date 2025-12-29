<?php

namespace PayZephyr\Trace\Middleware;

use Closure;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Arr;
use PayZephyr\Trace\Enums\TraceDirection;
use PayZephyr\Trace\Enums\TraceEvent;
use PayZephyr\Trace\Facades\Trace;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware for automatic HTTP request/response tracing
 *
 * Usage:
 * $stack = HandlerStack::create();
 * $stack->push(new TraceHttpMiddleware());
 * $client = new Client(['handler' => $stack]);
 */
class TraceHttpMiddleware
{
    private ?string $paymentId = null;
    private ?string $provider = null;
    private ?string $correlationId = null;

    /**
     * Create middleware with optional context
     */
    public function __construct(?string $paymentId = null, ?string $provider = null, ?string $correlationId = null)
    {
        $this->paymentId = $paymentId;
        $this->provider = $provider;
        $this->correlationId = $correlationId;
    }

    /**
     * Middleware invocation
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            // Extract context from options if provided
            $paymentId = $options['trace_payment_id'] ?? $this->paymentId;
            $provider = $options['trace_provider'] ?? $this->provider;
            $correlationId = $options['trace_correlation_id'] ?? $this->correlationId;

            // Auto-detect provider from URL if not provided
            if (!$provider) {
                $provider = $this->detectProvider($request->getUri()->getHost());
            }

            // Try to extract payment_id from request payload if not provided
            if (!$paymentId) {
                $paymentId = $this->extractPaymentIdFromRequest($request);
            }

            // Skip tracing if no payment ID (this might not be a payment request)
            if (!$paymentId) {
                return $handler($request, $options);
            }

            $startTime = microtime(true);

            // Record outbound request
            Trace::record([
                'payment_id' => $paymentId,
                'provider' => $provider,
                'correlation_id' => $correlationId,
                'event' => TraceEvent::PROVIDER_REQUEST_SENT,
                'direction' => TraceDirection::OUTBOUND,
                'http_method' => $request->getMethod(),
                'http_url' => (string) $request->getUri(),
                'payload' => $this->extractRequestPayload($request),
                'metadata' => [
                    'headers' => $this->sanitizeHeaders($request->getHeaders()),
                ],
            ]);

            // Execute request and handle response/errors
            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use ($paymentId, $provider, $correlationId, $startTime) {
                    $this->recordResponse($response, $paymentId, $provider, $correlationId, $startTime);
                    return $response;
                },
                function (\Exception $exception) use ($paymentId, $provider, $correlationId, $startTime, $request) {
                    $this->recordException($exception, $paymentId, $provider, $correlationId, $startTime, $request);
                    throw $exception;
                }
            );
        };
    }

    /**
     * Record successful response
     */
    private function recordResponse(
        ResponseInterface $response,
        string $paymentId,
        ?string $provider,
        ?string $correlationId,
        float $startTime
    ): void {
        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        Trace::record([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'correlation_id' => $correlationId,
            'event' => TraceEvent::PROVIDER_RESPONSE_RECEIVED,
            'direction' => TraceDirection::INBOUND,
            'http_status_code' => $response->getStatusCode(),
            'response_time_ms' => $responseTime,
            'payload' => $this->extractResponsePayload($response),
            'metadata' => [
                'headers' => $this->sanitizeHeaders($response->getHeaders()),
            ],
        ]);
    }

    /**
     * Record exception/error
     */
    private function recordException(
        \Exception $exception,
        string $paymentId,
        ?string $provider,
        ?string $correlationId,
        float $startTime,
        RequestInterface $request
    ): void {
        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        // Determine event type based on exception
        $event = $this->determineEventFromException($exception);

        $data = [
            'payment_id' => $paymentId,
            'provider' => $provider,
            'correlation_id' => $correlationId,
            'event' => $event,
            'direction' => TraceDirection::INBOUND,
            'response_time_ms' => $responseTime,
            'http_method' => $request->getMethod(),
            'http_url' => (string) $request->getUri(),
            'payload' => [
                'error' => $exception->getMessage(),
                'exception_class' => get_class($exception),
            ],
        ];

        // Add response details if available
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();
            $data['http_status_code'] = $response->getStatusCode();
            $data['payload']['response_body'] = $this->extractResponsePayload($response);
        }

        Trace::record($data);
    }

    /**
     * Determine trace event from exception type
     */
    private function determineEventFromException(\Exception $exception): TraceEvent
    {
        if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
            return TraceEvent::PROVIDER_TIMEOUT;
        }

        if ($exception instanceof RequestException) {
            if ($exception->hasResponse()) {
                return TraceEvent::PROVIDER_ERROR;
            }
            return TraceEvent::PROVIDER_TIMEOUT;
        }

        return TraceEvent::PROVIDER_EXCEPTION;
    }

    /**
     * Extract request payload
     */
    private function extractRequestPayload(RequestInterface $request): array
    {
        $body = (string) $request->getBody();

        // Reset stream position
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }

        // Try to decode JSON
        $decoded = json_decode($body, true);

        return [
            'body' => $decoded ?? $body,
            'content_type' => $request->getHeaderLine('Content-Type'),
        ];
    }

    /**
     * Extract response payload
     */
    private function extractResponsePayload(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        // Reset stream position
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }

        // Try to decode JSON
        $decoded = json_decode($body, true);

        return [
            'body' => $decoded ?? $body,
            'content_type' => $response->getHeaderLine('Content-Type'),
        ];
    }

    /**
     * Sanitize headers (remove authorization, etc.)
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $values) {
            $keyLower = strtolower($key);

            // Redact sensitive headers
            if (in_array($keyLower, ['authorization', 'x-api-key', 'api-key'])) {
                $sanitized[$key] = ['[REDACTED]'];
            } else {
                $sanitized[$key] = $values;
            }
        }

        return $sanitized;
    }

    /**
     * Detect provider from hostname
     */
    private function detectProvider(string $host): ?string
    {
        $patterns = config('trace.provider_patterns', []);

        foreach ($patterns as $pattern => $provider) {
            if (str_contains($host, $pattern)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Extract payment_id from request payload
     * Tries common field names where payment IDs are typically stored
     */
    private function extractPaymentIdFromRequest(RequestInterface $request): ?string
    {
        $body = (string) $request->getBody();

        // Reset stream position
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }

        // Try to decode JSON
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return null;
        }

        // Common payment ID field names to check
        $paymentIdFields = [
            'payment_id',
            'paymentId',
            'payment_intent_id',
            'intent_id',
            'transaction_id',
            'transactionId',
            'reference',
            'metadata.payment_id',
            'data.object.metadata.payment_id',
            'data.metadata.payment_id',
        ];

        foreach ($paymentIdFields as $field) {
            $value = Arr::get($decoded, $field);
            if ($value && is_string($value) && !empty($value)) {
                return $value;
            }
        }

        return null;
    }
}