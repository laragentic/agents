<?php

declare(strict_types=1);

namespace Laragentic\Runs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores the complete state of one loop iteration.
 *
 * A checkpoint captures everything needed to reconstruct the loop's
 * internal state so execution can resume from the next iteration
 * after any process failure.
 *
 * The key field for resumption is `next_prompt`: the string that would
 * have been passed as `$currentPrompt` on the following iteration.
 * For ReAct loops this is the formatted observation. For Plan-Execute
 * loops this is the step-execution prompt.
 */
class AgentCheckpoint extends Model
{
    protected $table = 'agent_checkpoints';

    protected $fillable = [
        'run_id',
        'iteration',
        'prompt',
        'response_text',
        'tool_calls',
        'observation',
        'next_prompt',
        'metadata',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'metadata'   => 'array',
    ];

    // ─── Relationships ───────────────────────────────────────────────

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id', 'run_id');
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Deserialise tool_calls into the record format used by loop traits.
     *
     * @return array<int, array{tool: string, arguments: array<string, mixed>, result: string}>
     */
    public function toolCallRecords(): array
    {
        return $this->tool_calls ?? [];
    }
}
