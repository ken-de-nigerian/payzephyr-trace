<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = config('trace.connection');
        $tableName = config('trace.table_name', config('trace.table', 'payment_trace_events'));

        Schema::connection($connection)->create($tableName, function (Blueprint $table) {
            $table->id();

            // Core identifiers
            $table->string('payment_id')->index(); // Reference field - indexed for high-speed lookups
            $table->string('provider')->nullable()->index();
            $table->uuid('correlation_id')->nullable()->index(); // Indexed for incident analysis

            // Event details
            $table->string('event')->index();
            $table->string('direction'); // internal, inbound, outbound

            // Payload and context
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();

            // HTTP details (for request/response events)
            $table->string('http_method')->nullable();
            $table->text('http_url')->nullable();
            $table->integer('http_status_code')->nullable();
            $table->integer('response_time_ms')->nullable();

            // Timestamps
            $table->timestamps();

            // Composite indexes for common queries and high-speed lookups during incident analysis
            $table->index(['payment_id', 'created_at']);
            $table->index(['correlation_id', 'created_at']);
            $table->index(['provider', 'event']);
            $table->index(['created_at']); // For retention cleanup
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = config('trace.connection');
        $tableName = config('trace.table_name', config('trace.table', 'payment_trace_events'));

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
