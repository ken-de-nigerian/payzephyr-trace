<?php

namespace PayZephyr\Trace\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PayZephyr\Trace\Enums\TraceEvent;
use PayZephyr\Trace\Facades\Trace;
use PayZephyr\Trace\Services\TraceTimelineBuilder;
use PayZephyr\Trace\TraceServiceProvider;

class TimelineBuilderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TraceServiceProvider::class];
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
    public function it_builds_a_timeline_for_a_payment(): void
    {
        // Create trace events
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PROVIDER_REQUEST_SENT,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_COMPLETED,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $this->assertEquals(3, $timeline->events->count());
        $this->assertEquals('pay_123', $timeline->paymentId);
    }

    /** @test */
    public function it_orders_events_chronologically(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_COMPLETED,
        ]);

        sleep(1);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $first = $timeline->events->first();
        $last = $timeline->events->last();

        $this->assertEquals(TraceEvent::PAYMENT_COMPLETED, $first->event);
        $this->assertEquals(TraceEvent::PAYMENT_INITIATED, $last->event);
    }

    /** @test */
    public function it_filters_events_by_provider(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'provider' => 'stripe',
            'event' => TraceEvent::PROVIDER_REQUEST_SENT,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'provider' => 'paystack',
            'event' => TraceEvent::PROVIDER_REQUEST_SENT,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $stripeEvents = $timeline->forProvider('stripe');
        $paystackEvents = $timeline->forProvider('paystack');

        $this->assertEquals(1, $stripeEvents->count());
        $this->assertEquals(1, $paystackEvents->count());
    }

    /** @test */
    public function it_identifies_terminal_events(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_COMPLETED,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $terminal = $timeline->terminal();

        $this->assertNotNull($terminal);
        $this->assertEquals(TraceEvent::PAYMENT_COMPLETED, $terminal->event);
    }

    /** @test */
    public function it_detects_successful_payments(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_COMPLETED,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $this->assertTrue($timeline->succeeded());
        $this->assertFalse($timeline->failed());
    }

    /** @test */
    public function it_detects_failed_payments(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_FAILED,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $this->assertTrue($timeline->failed());
        $this->assertFalse($timeline->succeeded());
    }

    /** @test */
    public function it_calculates_payment_duration(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        sleep(1);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_COMPLETED,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $duration = $timeline->duration();

        $this->assertGreaterThan(900, $duration); // At least 900ms
        $this->assertLessThan(1200, $duration); // Less than 1.2s
    }

    /** @test */
    public function it_identifies_error_events(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PROVIDER_TIMEOUT,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PROVIDER_ERROR,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $errors = $timeline->errors();

        $this->assertEquals(2, $errors->count());
    }

    /** @test */
    public function it_formats_timeline_as_text(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_COMPLETED,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->build('pay_123');

        $text = $timeline->toText();

        $this->assertStringContainsString('Payment Timeline: pay_123', $text);
        $this->assertStringContainsString('payment.initiated', $text);
        $this->assertStringContainsString('payment.completed', $text);
        $this->assertStringContainsString('Summary:', $text);
    }

    /** @test */
    public function detailed_timeline_analyzes_issues(): void
    {
        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PAYMENT_INITIATED,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::PROVIDER_TIMEOUT,
        ]);

        Trace::record([
            'payment_id' => 'pay_123',
            'event' => TraceEvent::WEBHOOK_DUPLICATE,
        ]);

        $builder = app(TraceTimelineBuilder::class);
        $timeline = $builder->buildDetailed('pay_123');

        $issues = $timeline->analyze();

        $this->assertCount(2, $issues);
        $this->assertEquals('timeout', $issues[0]['type']);
        $this->assertEquals('duplicate_webhook', $issues[1]['type']);
    }
}