<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the agent_runs table.
     *
     * Each row represents one durable agent run — a named, resumable
     * execution of an agentic loop that survives process crashes,
     * deploys, and queue retries.
     */
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();

            // ── Identity ────────────────────────────────────────────────
            $table->string('run_id')->unique();            // UUID supplied or auto-generated
            $table->string('agent_class');                 // FQCN of the agent (for introspection)
            $table->string('loop_type')->default('react'); // react | plan_execute | chain_of_thought

            // ── Lifecycle ───────────────────────────────────────────────
            // pending → running → completed | failed | cancelled | paused
            $table->string('status')->default('pending');

            // ── Payload ─────────────────────────────────────────────────
            $table->longText('initial_prompt');            // Original user prompt
            $table->longText('final_response')->nullable(); // Last LLM text on completion/failure
            $table->json('metadata')->nullable();           // Caller-supplied bag (user_id, job_id, …)

            // ── Timing ──────────────────────────────────────────────────
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->longText('failure_reason')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('agent_class');
            $table->index('loop_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
