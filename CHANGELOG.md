# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.0] — 2026-02-22

### Added

- **`HasDurableRuns` trait** — mix with `ReActLoop` to persist every execution:
  - Every run is assigned a UUID and written to `agent_runs`.
  - Each iteration's prompt, response, tool calls, and observation are
    checkpointed to `agent_checkpoints`.
  - Resuming with the same `$runId` continues from the last checkpoint,
    surviving process crashes, deployments, and queue retries.
  - Cancellation polling: the loop reads `agent_runs.status` between
    iterations and throws `RunCancelledException` on `Cancelled`.
  - Tool-call idempotency: results are stored in `agent_tool_calls` and
    replayed on resume without re-executing the tool.
  - `$humanReply` parameter: inject a human's answer when resuming a
    paused `AskHuman` run.
- **Three database migrations** (publish via `php artisan vendor:publish --tag=agentic-migrations`):
  - `agent_runs` — lifecycle metadata, status, timestamps, `failure_reason` (longText).
  - `agent_checkpoints` — per-iteration state.
  - `agent_tool_calls` — idempotency records.
- **`RunStatus` enum** — `Pending | Running | Completed | Failed | Cancelled | Paused`.
- **`AgentRun`**, **`AgentCheckpoint`**, **`AgentToolCall`** Eloquent models.
- **`RunCancelledException`**, **`RunNotFoundException`** exceptions.
- Static helpers `HasDurableRuns::cancelRun(string $runId)` and
  `HasDurableRuns::findRun(string $runId)`.
- Fluent configuration: `withCancellationPoll(int)`, `withoutToolIdempotency()`.
- `agentic.runs` config section — `cancellation_poll_every`, `tool_idempotency`,
  `prune_after_days` (reads from `AGENTIC_RUNS_*` env vars).

---

## [0.5.0]

### Added

- `onTextDelta(Closure)` callback — fires for every streamed token across all
  loop variants, enabling token-by-token SSE output.
- `eventStream(Closure): StreamedResponse` helper for Laravel SSE responses.

---

## [0.4.0]

### Added

- **AskHuman** — `AskHuman` tool and `AskHumanSignal` value object. An agent can
  pause mid-loop to request clarification, returning an `AskHumanSignal` in
  the `LoopResult`. Compatible with `HasDurableRuns` resume via `$humanReply`.
- **MCP Client** (`HasMcpClients` trait) — connect agents to any MCP server over
  stdio or Streamable HTTP (protocol 2025-11-25). Supports Tools, Resources,
  Prompts, Roots, and Sampling. `ElicitationHandler` for server-initiated
  elicitation. `agentic.mcp` config section.

---

## [0.3.1]

### Fixed

- Fatal trait method collision when composing multiple loop traits in a single
  agent. Resolved via shared `ExecutesLoopTools` and `HasIterationCallbacks`
  concern traits.

---

## [0.2.0]

### Added

- **Agent Skills System** (`HasAgentSkills` trait) — dynamic skill loading from
  the filesystem with progressive disclosure and auto-discovery. Skills are
  Markdown files with YAML frontmatter. `agentic.skills` config section.

---

## [0.1.0]

### Added

- **Chain-of-Thought Loop** (`ChainOfThoughtLoop` trait) — iterative
  self-reflection: the agent re-examines its own reasoning until it reaches a
  confident answer.

---

## [0.0.3]

### Added

- Examples repository reference in README.

---

## [0.0.2]

### Added

- `ChatAgent` Quick Start example in README.

---

## [0.0.1]

Initial release.

### Added

- **ReAct Loop** (`ReActLoop` trait) — Think → call tools → observe results →
  repeat. Terminates when the model stops requesting tool calls.
- **Plan-Execute Loop** (`PlanExecuteLoop` trait) — Create a plan, execute each
  step, synthesise a final answer.
- `maxIterations(int)` fluent setter and `throw_on_max_iterations` config option
  with `MaxIterationsExceededException`.
- `LoopResult` value object — `text()`, `completed()`, `allToolCalls()`,
  `steps()`, `iterations`, `askHumanSignal`, `reachedMaxIterations`.
- Unified lifecycle callback API across all loop traits:
  `onLoopStart`, `onLoopComplete`, `onIterationStart`, `onIterationEnd`,
  `onBeforeThought`, `onAfterThought`, `onBeforeAction`, `onAfterAction`,
  `onObservation`, `onAskHuman`, `onMaxIterationsReached`.
- `LaragenticServiceProvider` — auto-registers via Laravel package discovery.
  Publishes config (`--tag=agentic-config`) and migrations (`--tag=agentic-migrations`).

### Requirements

- PHP 8.2+
- Laravel 12
- `laravel/ai` ^0.1.5
