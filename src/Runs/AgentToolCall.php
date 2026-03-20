<?php

declare(strict_types=1);

namespace Laragentic\Runs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores a completed tool call result keyed by idempotency key.
 *
 * When a durable loop is resumed after a crash, any tool calls that
 * were already executed and stored here are not re-executed. Their
 * persisted result is returned immediately instead, making tool
 * execution safe to retry.
 *
 * The idempotency key is derived from:
 *   SHA-256( run_id + ":" + iteration + ":" + tool_name + ":" + stable_json(arguments) )
 */
class AgentToolCall extends Model
{
    protected $table = 'agent_tool_calls';

    protected $fillable = [
        'run_id',
        'idempotency_key',
        'iteration',
        'tool_name',
        'arguments',
        'result',
    ];

    protected $casts = [
        'arguments' => 'array',
    ];

    // ─── Relationships ───────────────────────────────────────────────

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id', 'run_id');
    }

    // ─── Finders ────────────────────────────────────────────────────

    /**
     * Find a stored tool call by its idempotency key.
     *
     * Returns null if no result has been stored yet (first execution).
     */
    public static function findByKey(string $idempotencyKey): ?static
    {
        return static::where('idempotency_key', $idempotencyKey)->first();
    }
}
