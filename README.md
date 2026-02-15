# Laragentic Agents

Agentic loops and AI agent capabilities for the [Laravel AI SDK](https://laravel.com/docs/12.x/ai-sdk).

## About

The Laravel AI SDK provides agents, tools, streaming, and conversations — but it does not provide autonomous agentic loops. Laragentic Agents fills that gap by adding:

- **ReAct Loop** — Iterative Thought → Action → Observation cycles that let agents autonomously call tools and reason about results
- **Plan-Execute Loop** — Systematic plan-then-execute pattern that breaks complex tasks into steps, executes each sequentially, and synthesizes a final answer
- **Lifecycle Callbacks** — Hook into every phase of any loop to stream progress, log activity, or implement custom logic
- **Configurable Limits** — Set maximum iterations/steps to prevent runaway loops and control token usage
- **Adaptive Replanning** — The Plan-Execute loop can revise its plan mid-execution if a step fails

## Requirements

- PHP 8.2+
- Laravel 12.x
- [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`)

## Installation

```bash
composer require laragentic/agents
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=agentic-config
```

## Quick Start: Streaming Agent Progress

Agentic loops can take time to complete. Stream real-time progress to users using Laravel's [Event Streams (SSE)](https://laravel.com/docs/12.x/responses#event-streams-sse) with loop callbacks.

Each loop trait provides a streaming variant — `reactLoopStream()` and `planExecuteStream()` — that returns a Generator. Callback `yield` values propagate to the parent Generator, making them compatible with `response()->eventStream()`.

**First, create a `ChatAgent`** — a standard Laravel AI SDK agent with the `ReActLoop` trait:

```php
<?php

namespace App\Agents;

use App\Ai\Tools\WeatherTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Loops\ReActLoop;

class ChatAgent implements Agent, HasTools
{
    use Promptable, ReActLoop;

    public function instructions(): string
    {
        return 'You are a helpful assistant that can answer questions and use tools.';
    }

    public function tools(): iterable
    {
        return [new WeatherTool];
    }
}
```

**Then stream its progress** via an SSE route:

```php
use App\Agents\ChatAgent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

Route::get('/chat', function () {
    $agent = new ChatAgent;
    
    return response()->eventStream(function () use ($agent) {
        $agent
            ->onBeforeAction(function (string $tool, array $args, int $iteration) {
                yield new StreamedEvent(
                    event: 'action',
                    data: ['tool' => $tool, 'status' => 'calling'],
                );
            })
            ->onAfterAction(function (string $tool, array $args, string $result, int $iteration) {
                yield new StreamedEvent(
                    event: 'action',
                    data: ['tool' => $tool, 'result' => $result, 'status' => 'complete'],
                );
            })
            ->onLoopComplete(function ($response, int $iterations) {
                yield new StreamedEvent(
                    event: 'complete',
                    data: ['text' => $response->text, 'iterations' => $iterations],
                );
            });
        
        // Use the streaming variant — yields propagate from callbacks
        yield from $agent->reactLoopStream(request()->input('message'));
    });
});
```

> **Important:** Use `reactLoopStream()` (not `reactLoop()`) inside `eventStream()` closures. The streaming variant is a Generator that propagates `yield` values from your callbacks. Without it, callback yields stay in their own scope and never reach the HTTP response.

**Consume with JavaScript:**

```javascript
const source = new EventSource('/chat?message=What+is+the+weather+in+Tokyo');

source.addEventListener('action', (e) => {
    const data = JSON.parse(e.data);
    if (data.status === 'calling') console.log(`🔧 Calling ${data.tool}...`);
    if (data.status === 'complete') console.log(`✓ ${data.tool}: ${data.result}`);
});

source.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('✅', data.text);
    source.close();
});

source.addEventListener('</stream>', () => source.close());
```

**Or with React** (`npm install @laravel/stream-react`):

```tsx
import { useEventStream } from '@laravel/stream-react';

function Chat() {
    const { message } = useEventStream('/chat?message=Hello');
    return <div>{message}</div>;
}
```

For more streaming patterns, see the [**Tutorials**](#tutorials) section.

---

## ReAct Loop

The ReAct (Reasoning + Acting) loop follows this cycle:

```
User Goal
  ↓
[THOUGHT]     — LLM reasons about what to do next
  ↓
[ACTION]      — LLM calls one or more tools
  ↓
[OBSERVATION] — Tool results fed back to the LLM
  ↓
Goal finished? → No  → back to THOUGHT (with the observation)
               → Yes → LLM produces final answer
```

**Example:** "What's the weather in Tokyo and should I bring an umbrella?"

1. **Thought**: I need the current weather → calls `weather_api`
2. **Action**: `weather_api(city: "Tokyo")` → Rain expected, 85%
3. **Observation**: Tool result fed back to LLM
4. **Thought**: Rain > 50%, recommend umbrella → no more tools needed
5. **Final Answer**: "Yes, bring an umbrella."

### Quick Start

Add the `ReActLoop` trait to any Laravel AI SDK agent. Your agent must implement `HasTools` so the SDK registers your tools with the LLM provider:

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Loops\ReActLoop;

class SalesCoach implements Agent, HasTools
{
    use Promptable, ReActLoop;

    public function instructions(): string
    {
        return 'You are a sales coach who analyzes transcripts and provides feedback.';
    }

    public function tools(): iterable
    {
        return [new RetrievePreviousTranscripts];
    }
}
```

Then use the agentic loop:

```php
$result = (new SalesCoach)->reactLoop('Analyze this sales transcript...');

echo $result->text();
$conversationId = $result->conversationId;
```

You can specify the provider and model per-call:

```php
$result = (new SalesCoach)->reactLoop(
    prompt: 'Analyze this transcript...',
    provider: 'anthropic',
    model: 'claude-sonnet-4-20250514',
);
```

### Configuration

```php
// Fluent API (per-call)
$result = (new SalesCoach)
    ->maxIterations(5)
    ->reactLoop('Hello!');

// Config (config/agentic.php)
'react' => [
    'max_iterations' => 10,
    'throw_on_max_iterations' => false,
],

// Environment variables
AGENTIC_MAX_ITERATIONS=10
AGENTIC_THROW_ON_MAX_ITERATIONS=false
```

### ReAct Callbacks

```php
$result = (new SalesCoach)
    ->onBeforeAction(function (string $tool, array $args, int $iteration) {
        broadcast(new ToolCallStarted($tool, $args));
    })
    ->onAfterAction(function (string $tool, array $args, string $result, int $iteration) {
        broadcast(new ToolCallCompleted($tool, $result));
    })
    ->onObservation(function (string $observation, int $iteration) {
        broadcast(new ObservationReady($observation, $iteration));
    })
    ->reactLoop('Analyze this transcript...');
```

| Method                   | When                  | Parameters                                                  |
| ------------------------ | --------------------- | ----------------------------------------------------------- |
| `onLoopStart`            | Loop begins           | `string $prompt`                                            |
| `onLoopComplete`         | Final answer produced | `AgentResponse $response, int $totalIterations`             |
| `onMaxIterationsReached` | Limit hit             | `AgentResponse $response, int $totalIterations`             |
| `onIterationStart`       | Each iteration starts | `int $iteration`                                            |
| `onIterationEnd`         | Each iteration ends   | `int $iteration, AgentResponse $response`                   |
| `onBeforeThought`        | Before LLM prompt     | `string $prompt, int $iteration`                            |
| `onAfterThought`         | After LLM responds    | `AgentResponse $response, int $iteration`                   |
| `onBeforeAction`         | Before tool call      | `string $tool, array $args, int $iteration`                 |
| `onAfterAction`          | After tool returns    | `string $tool, array $args, string $result, int $iteration` |
| `onObservation`          | Observation ready     | `string $observation, int $iteration`                       |

### ReAct Result

```php
$result = (new SalesCoach)->reactLoop('Hello!');

$result->text();               // Final response text
$result->conversationId;       // Conversation ID
$result->iterations;           // Total iterations executed
$result->completed();          // true if LLM gave a final answer
$result->reachedMaxIterations; // true if limit was hit
$result->steps;                // Array of LoopStep objects
$result->allToolCalls();       // All tool calls across all iterations
```

### ReAct Customization

```php
class MyAgent implements Agent, HasTools
{
    use Promptable, ReActLoop;

    // Customize how tool results are presented to the LLM
    protected function formatObservation(array $toolCallRecords): string { /* ... */ }

    // Customize when the loop should stop
    protected function loopShouldTerminate(AgentResponse $response): bool { /* ... */ }
}
```

---

## Plan-Execute Loop

The Plan-Execute loop separates planning from execution:

```
User Task
  ↓
[PLAN]       — LLM creates a step-by-step plan
  ↓
[EXECUTE 1]  — LLM executes step 1 (may use tools)
  ↓
[EXECUTE 2]  — LLM executes step 2 (with prior context)
  ↓
  ...        — Continue for each step
  ↓
[SYNTHESIZE] — LLM combines all results into a final answer
```

**Best for:**

- Complex multi-step tasks with sequential dependencies
- Tasks requiring coordination of multiple tools
- Work where you want visibility into the reasoning process
- Scenarios where adaptive replanning adds resilience

### Quick Start

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Loops\PlanExecuteLoop;

class ResearchAgent implements Agent, HasTools
{
    use Promptable, PlanExecuteLoop;

    public function instructions(): string
    {
        return 'You are a research assistant. Create clear plans and execute methodically.';
    }

    public function tools(): iterable
    {
        return [new WebSearch, new Calculator];
    }
}
```

Then use the plan-execute loop:

```php
$result = (new ResearchAgent)->planExecute(
    'Research Q4 sales data, analyze trends, and create an executive summary.',
);

echo $result->text();          // The synthesized final answer
echo $result->stepsExecuted(); // Number of steps completed
```

You can specify the provider and model per-call:

```php
$result = (new ResearchAgent)->planExecute(
    task: 'Research Q4 sales data, analyze trends, and create an executive summary.',
    provider: 'anthropic',
    model: 'claude-sonnet-4-5',
    timeout: 120, // 2 minute timeout
);
```

### Configuration

```php
// Fluent API (per-call)
$result = (new ResearchAgent)
    ->maxSteps(5)
    ->allowReplan()
    ->maxReplans(2)
    ->planExecute('Analyze this data...');

// Config (config/agentic.php)
'plan_execute' => [
    'max_steps' => 10,
    'allow_replan' => true,
    'max_replans' => 3,
    'throw_on_max_steps' => false,
],

// Environment variables
AGENTIC_PLAN_MAX_STEPS=10
AGENTIC_PLAN_ALLOW_REPLAN=true
AGENTIC_PLAN_MAX_REPLANS=3
AGENTIC_PLAN_THROW_ON_MAX_STEPS=false
```

### Adaptive Replanning

When enabled, the loop detects step failures and asks the LLM to create a revised plan:

```php
$result = (new ResearchAgent)
    ->allowReplan()
    ->maxReplans(2)
    ->onReplan(function (array $newSteps, int $replanCount) {
        logger()->warning("Plan revised (attempt {$replanCount})", $newSteps);
    })
    ->planExecute('Analyze sales data. If the database is unavailable, use cached reports.');

$result->wasReplanned(); // true if the plan was revised
$result->replans;        // Number of times the plan was revised
```

### Plan-Execute Callbacks

```php
$result = (new ResearchAgent)
    ->onPlanCreated(function (array $steps) {
        broadcast(new PlanReady($steps));
    })
    ->onBeforeStep(function (int $stepNumber, string $description, int $totalSteps) {
        broadcast(new StepStarted($stepNumber, $description, $totalSteps));
    })
    ->onAfterStep(function (int $stepNumber, string $description, AgentResponse $response, int $totalSteps) {
        broadcast(new StepCompleted($stepNumber, $response->text));
    })
    ->onBeforeSynthesis(function (array $steps) {
        broadcast(new SynthesisStarted(count($steps)));
    })
    ->planExecute('Create a marketing report...');
```

| Method              | When                  | Parameters                                                                       |
| ------------------- | --------------------- | -------------------------------------------------------------------------------- |
| `onLoopStart`       | Loop begins           | `string $task`                                                                   |
| `onLoopComplete`    | Synthesis done        | `AgentResponse $response, int $stepsExecuted`                                    |
| `onPlanCreated`     | Plan parsed           | `array $stepDescriptions`                                                        |
| `onBeforeStep`      | Before step execution | `int $stepNumber, string $description, int $totalSteps`                          |
| `onAfterStep`       | After step execution  | `int $stepNumber, string $description, AgentResponse $response, int $totalSteps` |
| `onReplan`          | Plan revised          | `array $newStepDescriptions, int $replanCount`                                   |
| `onBeforeSynthesis` | Before synthesis      | `array $executedPlanSteps`                                                       |
| `onAfterSynthesis`  | After synthesis       | `AgentResponse $response`                                                        |
| `onMaxStepsReached` | Step limit hit        | `AgentResponse $lastResponse, int $stepsExecuted`                                |

### Plan-Execute Result

```php
$result = (new ResearchAgent)->planExecute('Analyze trends');

$result->text();              // Synthesized final answer
$result->plan;                // Original plan step descriptions
$result->steps;               // Array of PlanStep objects
$result->stepsExecuted();     // Number of steps completed
$result->totalPlannedSteps(); // Total steps in the plan
$result->completed();         // true if all steps + synthesis ran
$result->reachedMaxSteps;     // true if step limit was hit
$result->wasReplanned();      // true if plan was revised
$result->replans;             // Number of revisions
$result->stepResults();       // [{step, description, result}, ...]
```

### Plan-Execute Customization

```php
class MyAgent implements Agent, HasTools
{
    use Promptable, PlanExecuteLoop;

    // Customize the planning prompt
    protected function buildPlanningPrompt(string $task): string { /* ... */ }

    // Customize how each step is executed
    protected function buildStepExecutionPrompt(
        string $task, array $plan, int $stepNumber,
        string $stepDescription, array $previousSteps,
    ): string { /* ... */ }

    // Customize the synthesis prompt
    protected function buildSynthesisPrompt(string $task, array $steps): string { /* ... */ }

    // Customize plan parsing
    protected function parsePlanSteps(string $planText): array { /* ... */ }

    // Customize failure detection for replanning
    protected function shouldReplan(AgentResponse $response): bool { /* ... */ }

    // Customize the replanning prompt when a step fails
    protected function revisePlan(
        string $task, array $originalPlan, array $completedSteps,
        int $failedStepNumber, AgentResponse $failedResponse,
        ?string $provider, ?string $model, ?int $timeout,
    ): array { /* ... */ }
}
```

---

## Choosing a Loop

|                  | ReAct Loop                             | Plan-Execute Loop                      |
| ---------------- | -------------------------------------- | -------------------------------------- |
| **Pattern**      | Thought → Action → Observation         | Plan → Execute Steps → Synthesize      |
| **Best for**     | Tool-driven tasks, real-time reasoning | Multi-step workflows, sequential tasks |
| **Tool calls**   | Each iteration may call tools          | Each step may call tools               |
| **Adaptiveness** | Continuous (every iteration)           | Optional replanning on failure         |
| **Token usage**  | Lower per-iteration                    | Higher (planning + steps + synthesis)  |
| **Visibility**   | Per-iteration callbacks                | Per-step + plan + synthesis callbacks  |

## Testing

### Unit Tests

Unit tests use faked responses and do not require API keys:

```bash
composer test
# or
vendor/bin/pest tests/Unit
```

### Integration Tests

Integration tests make real API calls and require valid API keys in your `.env` file. They are grouped under `integration` and will be skipped automatically if the required key is not set:

```bash
# Run only integration tests
vendor/bin/pest --group=integration

# Run everything
vendor/bin/pest

# Exclude integration tests during development
vendor/bin/pest --exclude-group=integration
```

Required `.env` variables for integration tests:

```env
ANTHROPIC_API_KEY=your-key-here
```

### Test Coverage

```bash
composer test-coverage
```

## Tutorials

Comprehensive streaming examples are available in the [`tutorial/`](tutorial/) folder:

- **[Complete Working Example](tutorial/complete-example.md)** — Ready-to-use chat agent with streaming, tools, and frontend code
- **[Quick Reference](tutorial/quick-reference.md)** — Callback cheat sheet and essential streaming patterns
- **[Streaming ReAct Loop](tutorial/streaming-react-loop.md)** — Stream real-time progress updates from ReAct loops
- **[Streaming Plan-Execute Loop](tutorial/streaming-plan-execute-loop.md)** — Stream planning, execution, and synthesis progress

These tutorials show how to use Laravel's streaming responses with the loop callbacks to provide real-time feedback to users. Perfect for building chat interfaces and long-running agentic tasks.

> **Note:** The tutorial folder is excluded from production installs via `.gitattributes`.

## License

MIT License. See [LICENSE](LICENSE) for details.
