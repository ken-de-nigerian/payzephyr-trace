<?php

declare(strict_types=1);

namespace PayZephyr\Trace\Middleware;

use Closure;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use PayZephyr\Trace\DataTransferObjects\TraceData;
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
            $traceData = new TraceData(
                paymentId: $paymentId,
                event: TraceEvent::PROVIDER_REQUEST_SENT,
                direction: TraceDirection::OUTBOUND,
                payload: $this->extractRequestPayload($request),
                provider: $provider,
                correlationId: $correlationId,
                metadata: [
                    'headers' => $this->sanitizeHeaders($request->getHeaders()),
                ],
                httpMethod: $request->getMethod(),
                httpUrl: (string) $request->getUri(),
            );
            Trace::record($traceData);

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

        $traceData = new TraceData(
            paymentId: $paymentId,
            event: TraceEvent::PROVIDER_RESPONSE_RECEIVED,
            direction: TraceDirection::INBOUND,
            payload: $this->extractResponsePayload($response),
            provider: $provider,
            correlationId: $correlationId,
            metadata: [
                'headers' => $this->sanitizeHeaders($response->getHeaders()),
            ],
            httpStatusCode: $response->getStatusCode(),
            responseTimeMs: $responseTime,
        );
        Trace::record($traceData);
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

        $payload = [
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
        ];

        $httpStatusCode = null;

        // Add response details if available
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();
            $httpStatusCode = $response->getStatusCode();
            $payload['response_body'] = $this->extractResponsePayload($response);
        }

        $traceData = new TraceData(
            paymentId: $paymentId,
            event: $event,
            direction: TraceDirection::INBOUND,
            payload: $payload,
            provider: $provider,
            correlationId: $correlationId,
            httpMethod: $request->getMethod(),
            httpUrl: (string) $request->getUri(),
            httpStatusCode: $httpStatusCode,
            responseTimeMs: $responseTime,
        );
        Trace::record($traceData);
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