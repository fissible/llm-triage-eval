<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('golden_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_id')->unique();          // gs-0001
            $table->string('weak_label');                 // rule-based baseline
            $table->string('gold_label');                 // human ground truth (editable)
            $table->boolean('reviewed')->default(false);
            $table->text('note')->nullable();
            // Promoted for filtering/search; full evidence kept in `input`.
            $table->string('app')->nullable();
            $table->string('env')->nullable();
            $table->string('error_type')->nullable();
            $table->string('correlation_id')->nullable();
            $table->text('message')->nullable();
            $table->string('source_file')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('input');
            $table->timestamps();

            $table->index(['env', 'gold_label']);
            $table->index('reviewed');
        });

        Schema::create('eval_runs', function (Blueprint $table) {
            $table->id();
            $table->string('report_path')->unique();      // idempotent import
            $table->string('prompt_version');
            $table->string('provider');
            $table->string('model');
            $table->string('golden_set')->nullable();
            $table->unsignedInteger('n');
            $table->float('llm_accuracy');
            $table->float('baseline_accuracy');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->boolean('fully_reviewed')->default(false);
            $table->json('per_category');
            $table->json('confusion');
            $table->timestamp('ran_at');
            $table->timestamps();
        });

        Schema::create('eval_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eval_run_id')->constrained()->cascadeOnDelete();
            $table->string('case_id');
            $table->string('gold');
            $table->string('predicted');
            $table->string('baseline');
            $table->boolean('correct');
            $table->text('rationale')->nullable();
            $table->timestamps();

            $table->index(['eval_run_id', 'correct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_results');
        Schema::dropIfExists('eval_runs');
        Schema::dropIfExists('golden_cases');
    }
};
