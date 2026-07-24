<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // All parsed error events across apps — the substrate for tracing a single
        // transaction (correlation id) through the integration layer to its root.
        Schema::create('log_events', function (Blueprint $table) {
            $table->id();
            $table->string('correlation_id')->nullable()->index();
            $table->string('app')->nullable();
            $table->string('env')->nullable();
            $table->string('error_type')->nullable();
            $table->string('weak_label')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->text('message')->nullable();
            $table->text('root_exception')->nullable();
            $table->string('resource_url')->nullable();
            $table->string('http_status')->nullable();
            $table->json('error_detail')->nullable();
            $table->string('source_file')->nullable();

            $table->index(['correlation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_events');
    }
};
