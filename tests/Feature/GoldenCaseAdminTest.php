<?php

namespace Tests\Feature;

use App\Models\GoldenCase;
use App\Models\LogEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoldenCaseAdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeCase(array $overrides = []): GoldenCase
    {
        return GoldenCase::create(array_merge([
            'case_id' => 'gs-0001',
            'weak_label' => 'downstream_timeout',
            'gold_label' => 'downstream_timeout',
            'reviewed' => false,
            'app' => 'orders-papi',
            'env' => 'prod',
            'error_type' => 'ORDERS-PROCESS-API:TIMEOUT',
            'correlation_id' => 'abc-123-def',
            'message' => 'Timeout exceeded',
            'input' => ['message' => 'Timeout exceeded', 'stack_top' => []],
        ], $overrides));
    }

    public function test_index_page_renders(): void
    {
        $user = User::factory()->create();
        $this->makeCase();

        $this->actingAs($user)
            ->get('/admin/golden-cases')
            ->assertOk();
    }

    public function test_edit_page_renders_with_env_pill(): void
    {
        $user = User::factory()->create();
        $case = $this->makeCase(['env' => 'prod']);

        $this->actingAs($user)
            ->get("/admin/golden-cases/{$case->getKey()}/edit")
            ->assertOk()
            ->assertSee('Environment')  // the env TextEntry pill label
            ->assertSee('prod');
    }

    public function test_apply_env_to_siblings_updates_unknowns_from_same_file(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $this->makeCase(['case_id' => 'gs-a', 'source_file' => 'f.log', 'env' => 'prod']);
        $b = $this->makeCase(['case_id' => 'gs-b', 'source_file' => 'f.log', 'env' => 'unknown']);
        $c = $this->makeCase(['case_id' => 'gs-c', 'source_file' => 'other.log', 'env' => 'unknown']);

        \Livewire\Livewire::test(\App\Filament\Resources\GoldenCases\Pages\EditGoldenCase::class, ['record' => $a->getKey()])
            ->call('applyEnvToSiblings', sourceFile: 'f.log', env: 'prod');

        $this->assertSame('prod', $b->fresh()->env);      // same file, was unknown -> updated
        $this->assertSame('unknown', $c->fresh()->env);   // different file -> untouched
    }

    public function test_bulk_set_environment_updates_selected_cases(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $this->makeCase(['case_id' => 'gs-a', 'env' => 'unknown']);
        $b = $this->makeCase(['case_id' => 'gs-b', 'env' => 'unknown']);

        \Livewire\Livewire::test(\App\Filament\Resources\GoldenCases\Pages\ListGoldenCases::class)
            ->callTableBulkAction('setEnv', [$a->getKey(), $b->getKey()], ['env' => 'prod']);

        $this->assertSame('prod', $a->fresh()->env);
        $this->assertSame('prod', $b->fresh()->env);
    }

    public function test_delete_action_renders_in_the_footer(): void
    {
        $user = User::factory()->create();
        $case = $this->makeCase(['case_id' => 'gs-del']);

        // Delete lives in the form footer (a schema Actions component, modal-capable);
        // it's not a page action, so we assert it renders rather than callAction() it.
        $this->actingAs($user)
            ->get("/admin/golden-cases/{$case->getKey()}/edit")
            ->assertOk()
            ->assertSee('Delete case');
    }

    public function test_edit_shows_position_from_review_queue(): void
    {
        $user = User::factory()->create();
        $a = $this->makeCase(['case_id' => 'gs-a']);
        $b = $this->makeCase(['case_id' => 'gs-b']);
        $c = $this->makeCase(['case_id' => 'gs-c']);

        $this->actingAs($user)
            ->withSession(['golden.review_queue' => [$a->id, $b->id, $c->id]])
            ->get("/admin/golden-cases/{$b->id}/edit")
            ->assertOk()
            ->assertSee('Case 2 of 3')
            ->assertSee('Previous')
            ->assertSee('Next');
    }

    public function test_dashboard_renders(): void
    {
        $user = User::factory()->create();
        $this->makeCase();

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_eval_run_view_renders_confusion_matrix(): void
    {
        $user = User::factory()->create();
        $run = \App\Models\EvalRun::create([
            'report_path' => 'test-report.json',
            'prompt_version' => 'v3', 'provider' => 'ollama', 'model' => 'gemma3:12b',
            'n' => 4, 'llm_accuracy' => 0.75, 'baseline_accuracy' => 0.5,
            'prompt_tokens' => 100, 'completion_tokens' => 10, 'cost_usd' => 0, 'fully_reviewed' => true,
            'per_category' => ['db_timeout' => ['support' => 2, 'precision' => 1.0, 'recall' => 0.5]],
            'confusion' => ['db_timeout' => ['db_timeout' => 1, 'downstream_timeout' => 1]],
            'ran_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/admin/eval-runs/{$run->id}")
            ->assertOk()
            ->assertSee('Confusion matrix')
            ->assertSee('Per-category');
    }

    public function test_commands_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/commands')
            ->assertOk()
            ->assertSee('triage:eval');
    }

    public function test_command_stream_rejects_unknown_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/triage/command-stream?key=rm-rf-everything')
            ->assertStatus(400);
    }

    public function test_add_to_golden_promotes_a_chain_event(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $e = LogEvent::create([
            'correlation_id' => 'zzz', 'app' => 'inventory-sapi', 'env' => 'prod',
            'error_type' => 'INVENTORY-SAPI:INSERT', 'weak_label' => 'db_constraint_violation',
            'occurred_at' => now(), 'message' => 'ERROR: duplicate key value violates unique constraint',
            'error_detail' => [['code' => 'INVENTORY-SAPI:INSERT', 'description' => 'duplicate key value violates unique constraint "person_person_guid_key"']],
        ]);
        $before = GoldenCase::count();

        \Livewire\Livewire::test(\App\Filament\Resources\LogEvents\Pages\ListLogEvents::class)
            ->callTableAction('add_to_golden', $e->getKey());

        $this->assertSame($before + 1, GoldenCase::count());
        $g = GoldenCase::latest('id')->first();
        $this->assertSame('inventory-sapi', $g->app);
        $this->assertSame('db_constraint_violation', $g->weak_label);
        $this->assertNotEmpty($g->input['error_detail']);
    }

    public function test_chain_link_filters_correlations_to_one_transaction(): void
    {
        $user = User::factory()->create();
        LogEvent::create(['correlation_id' => 'aaa', 'app' => 'app-x', 'occurred_at' => now(), 'message' => 'ALPHA-event']);
        LogEvent::create(['correlation_id' => 'bbb', 'app' => 'app-y', 'occurred_at' => now(), 'message' => 'BRAVO-event']);

        $this->actingAs($user)
            ->get('/admin/log-events?correlation=aaa')
            ->assertOk()
            ->assertSee('ALPHA-event')
            ->assertDontSee('BRAVO-event');
    }

    public function test_ingest_logs_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/ingest-logs')
            ->assertOk()
            ->assertSee('Download Logs');
    }

    public function test_correlations_page_renders_a_cross_app_chain(): void
    {
        $user = User::factory()->create();
        $cid = 'chain-001';
        LogEvent::create(['correlation_id' => $cid, 'app' => 'crm-events-papi', 'env' => 'prod', 'error_type' => 'ORDERS-PROCESS-API:TIMEOUT', 'weak_label' => 'downstream_500_cascade', 'occurred_at' => now(), 'message' => 'downstream 500']);
        LogEvent::create(['correlation_id' => $cid, 'app' => 'orders-papi', 'env' => 'prod', 'error_type' => 'INVENTORY-SAPI:TIMEOUT', 'weak_label' => 'db_timeout', 'occurred_at' => now()->addSecond(), 'message' => 'LegacyDB timeout']);
        // A golden case matching the second chain event (correlation + app + error_type).
        $this->makeCase(['case_id' => 'gs-0099', 'correlation_id' => $cid, 'app' => 'orders-papi', 'error_type' => 'INVENTORY-SAPI:TIMEOUT']);

        $this->actingAs($user)
            ->get('/admin/log-events')
            ->assertOk()
            ->assertSee('orders-papi')
            ->assertSee('gs-0099'); // golden badge back-link renders in the chain
    }
}
