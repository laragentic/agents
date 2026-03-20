<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the agent_checkpoints table.
     *
     * Each row captures the complete state at the end of one loop
     * iteration — enough to reconstruct the loop's internal state and
     * resume execution after a crash, redeploy, or human pause.
     *
     * Schema invariant: iteration numbers are unique per run_id.
     */
    public function up(): void
    {
        Schema::create('agent_checkpoints', function (Blueprint $table) {
            $table->id();

            // ── Ownership ───────────────────────────────────────────────
            $table->string('run_id');
            $table->foreign('run_id')
                ->references('run_id')
                ->on('agent_runs')
                ->cascadeOnDelete();

            // ── Position ────────────────────────────────────────────────
            $table->unsignedInteger('iteration');

            // ── Thought ─────────────────────────────────────────────────
            $table->longText('prompt');           // Prompt sent to LLM this iteration
            $table->longText('response_text');    // LLM's response text

            // ── Action ──────────────────────────────────────────────────
            // JSON array of {tool, arguments, result} records
            $table->json('tool_calls')->nullable();

            // ── Observation ─────────────────────────────────────────────
            $table->longText('observation')->nullable();   // Formatted observation string
            $table->longText('next_prompt')->nullable();   // Prompt for the NEXT iteration (=observation for ReAct)

            // ── Extra ───────────────────────────────────────────────────
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['run_id', 'iteration']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_checkpoints');
    }
};
