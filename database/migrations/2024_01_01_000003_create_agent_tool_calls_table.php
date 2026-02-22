<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the agent_tool_calls table.
     *
     * Stores every tool call result keyed by an idempotency key so that
     * if a run is resumed after a crash, completed tool calls are not
     * re-executed — their stored result is replayed instead.
     *
     * The idempotency_key is derived from:
     *   SHA-256( run_id + ":" + iteration + ":" + tool_name + ":" + stable_json(arguments) )
     *
     * This means the same tool with the same arguments in the same
     * iteration of the same run always maps to the same key.
     */
    public function up(): void
    {
        Schema::create('agent_tool_calls', function (Blueprint $table) {
            $table->id();

            // ── Ownership ───────────────────────────────────────────────
            $table->string('run_id');
            $table->foreign('run_id')
                ->references('run_id')
                ->on('agent_runs')
                ->cascadeOnDelete();

            // ── Idempotency ─────────────────────────────────────────────
            $table->string('idempotency_key')->unique();

            // ── Context ─────────────────────────────────────────────────
            $table->unsignedInteger('iteration');
            $table->string('tool_name');
            $table->json('arguments');

            // ── Result ──────────────────────────────────────────────────
            $table->longText('result');

            $table->timestamps();

            $table->index('run_id');
            $table->index(['run_id', 'iteration']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
    }
};
