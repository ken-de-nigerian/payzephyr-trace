<?php

namespace PayZephyr\Trace\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PayZephyr\Trace\Enums\TraceDirection;
use PayZephyr\Trace\Enums\TraceEvent;
use PayZephyr\Trace\Facades\Trace;
use PayZephyr\Trace\Models\TraceEvent as TraceEventModel;
use PayZephyr\Trace\TraceServiceProvider;

class TraceRecorderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TraceServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Trace' => Trace::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app['config']->set('trace.enabled', true);
        $app['config']->set('trace.async', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    /** @test */
    public function it_can_record_a_basic_trace_event(): void
    {
        $event = Trace::record([
            'payment_id' => 'pay_123',
            'provider' => 'stripe',
            'event' => TraceEvent::PAYMENT_INITIATED,
            'payload' => ['amount' => 5000]
        ]);

        $this->assertInstanceOf(TraceEventModel::class, $event);
        $this->assertEquals('pay_123', $event->payment_id);
        $this->assertEquals('stripe', $event->provider);
        $this->assertEquals(TraceEvent::PAYMENT_INITIATED, $event->event);
        $this->assertEquals(['amount' => 5000], $event->payload);
    }

    /** @test */
    public function it_automatically_infers_direction_for_outbound_events(): void
    {
        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PROVIDER_REQUEST_SENT,
        ]);

        $this->assertEquals(TraceDirection::OUTBOUND, $event->direction);
    }

    /** @test */
    public function it_automatically_infers_direction_for_inbound_events(): void
    {
        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::WEBHOOK_RECEIVED,
        ]);

        $this->assertEquals(TraceDirection::INBOUND, $event->direction);
    }

    /** @test */
    public function it_automatically_infers_direction_for_internal_events(): void
    {
        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        $this->assertEquals(TraceDirection::INTERNAL, $event->direction);
    }

    /** @test */
    public function it_generates_correlation_id_automatically(): void
    {
        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        $this->assertNotNull($event->correlation_id);
        $this->assertIsString($event->correlation_id);
    }

    /** @test */
    public function it_can_use_custom_correlation_id(): void
    {
        $correlationId = 'custom-correlation-123';

        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
            'correlation_id' => $correlationId,
        ]);

        $this->assertEquals($correlationId, $event->correlation_id);
    }

    /** @test */
    public function it_redacts_sensitive_fields_from_payload(): void
    {
        config(['trace.redact_fields' => ['card_number', 'cvv']]);

        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
            'payload' => [
                'card_number' => '4242424242424242',
                'cvv' => '123',
                'amount' => 5000,
            ]
        ]);

        $this->assertEquals('[REDACTED]', $event->payload['card_number']);
        $this->assertEquals('[REDACTED]', $event->payload['cvv']);
        $this->assertEquals(5000, $event->payload['amount']);
    }

    /** @test */
    public function it_redacts_nested_sensitive_fields(): void
    {
        config(['trace.redact_fields' => ['secret']]);

        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
            'payload' => [
                'user' => [
                    'email' => 'user@example.com',
                    'secret' => 'super-secret-value',
                ],
                'amount' => 5000,
            ]
        ]);

        $this->assertEquals('[REDACTED]', $event->payload['user']['secret']);
        $this->assertEquals('user@example.com', $event->payload['user']['email']);
    }

    /** @test */
    public function it_does_not_record_when_tracing_is_disabled(): void
    {
        config(['trace.enabled' => false]);

        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        $this->assertNull($event);
        $this->assertEquals(0, TraceEventModel::count());
    }

    /** @test */
    public function it_can_start_a_correlation_group(): void
    {
        $correlationId = Trace::startCorrelation();

        $this->assertIsString($correlationId);
        $this->assertNotEmpty($correlationId);
    }

    /** @test */
    public function it_accepts_string_event_names(): void
    {
        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => 'payment.initiated',
        ]);

        $this->assertEquals(TraceEvent::PAYMENT_INITIATED, $event->event);
    }

    /** @test */
    public function it_handles_unknown_event_names_as_custom(): void
    {
        $event = Trace::record([
            'payment_id' => 'pay_123',
            'event' => 'unknown.event.type',
        ]);

        $this->assertEquals(TraceEvent::CUSTOM, $event->event);
    }
}