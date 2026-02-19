# Laragentic Agents

**Add one trait. Get autonomous AI agents.**

Laragentic extends the [Laravel AI SDK](https://laravel.com/docs/12.x/ai-sdk) with agentic loops — letting your agents autonomously reason, call tools, and iterate until they reach an answer.

```php
class ChatAgent implements Agent, HasTools
{
    use Promptable, ReActLoop; // ← add this trait

    // ... your existing agent code
}

$result = (new ChatAgent)->reactLoop('What is the weather in Tokyo?');

echo $result->text(); // "It's 22°C and partly cloudy in Tokyo."
```

Your agent now thinks, calls tools on its own, reads the results, and keeps going until it has a complete answer — all in one method call.

---

## See It In Action

### ReAct Loop: Autonomous Reasoning + Acting

The agent thinks, searches, calculates, and synthesizes — all on its own.

![ReAct Loop Demo](tutorial/images/react-loop.png)

### Plan-Execute Loop: Multi-Step Planning & Synthesis

Watch the agent create a plan, execute each step, and synthesize the final answer.

![Plan-Execute Loop](tutorial/images/plan-execute-loop.png)

### Complete Chat Agent with Tools

A full conversational agent with weather lookup and calculator capabilities.

![Complete Chat Example](tutorial/images/complete-chat-example.png)

> **Want to try these examples?** Clone the **[laragentic-app-examples](https://github.com/laragentic/laragentic-app-examples)** repository — a complete Laravel app with all demos ready to run.

---

## Why Laragentic?

The Laravel AI SDK gives you agents, tools, streaming, and conversations. But when you call `prompt()`, the agent responds once — if it wants to call a tool, inspect the result, and reason further, you have to write that loop yourself.

Laragentic adds that missing piece:

| What you get                 | What it does                                                         |
| ---------------------------- | -------------------------------------------------------------------- |
| **ReAct Loop**               | Think → call tools → observe results → repeat until done             |
| **Plan-Execute Loop**        | Create a plan → execute each step → synthesize a final answer        |
| **Chain-of-Thought Loop**    | Reason iteratively → evaluate understanding → continue until confident|
| **Agent Skills System**      | Dynamic skill loading with progressive disclosure and auto-discovery  |
| **Lifecycle Callbacks**      | Hook into every phase to stream progress, log, or broadcast          |
| **Configurable Limits**      | Set max iterations/steps to control cost and prevent runaway loops   |
| **Adaptive Replanning**      | The Plan-Execute loop revises its plan mid-execution if a step fails |

**Zero configuration required.** Add `use ReActLoop` to your agent, call `->reactLoop()`, and it works.

---

## Requirements

- PHP 8.2+
- Laravel 12.x
- [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`)

## Installation

```bash
composer require laragentic/agents
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=agentic-config
```

---

## Quick Start

This guide assumes you have a Laravel 12 app with [Inertia](https://inertiajs.com/), React, and the [Laravel AI SDK](https://laravel.com/docs/12.x/ai-sdk) already installed.

You'll create three files to get a working agent that calls tools autonomously and streams real-time progress to a frontend:

| File                          | What it does                           |
| ----------------------------- | -------------------------------------- |
| `app/Tools/WeatherTool.php`   | A tool the agent can call              |
| `app/Agents/ChatAgent.php`    | An agent with the ReAct loop           |
| `resources/js/pages/chat.tsx` | A React frontend that streams progress |

### Step 1: Create a Tool

Tools implement `Laravel\Ai\Contracts\Tool`. They have a name, a description (so the LLM knows when to use them), a JSON schema for parameters, and a `handle()` method that does the work.

Create `app/Tools/WeatherTool.php`:

```php
<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class WeatherTool implements Tool
{
    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): Stringable|string
    {
        return 'Get the current weather for a given city.';
    }

    public function handle(Request $request): Stringable|string
    {
        $city = $request['city'] ?? 'Unknown';

        // Replace with a real API call (e.g. OpenWeatherMap)
        return "Weather in {$city}: Partly Cloudy, 22°C, Humidity: 65%";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()
                ->description('The city name, e.g. "Tokyo", "London"')
                ->required(),
        ];
    }
}
```

### Step 2: Create an Agent

Add the `ReActLoop` trait to a standard Laravel AI SDK agent. Your agent must implement `HasTools` so the SDK registers your tools with the LLM.

Create `app/Agents/ChatAgent.php`:

```php
<?php

namespace App\Agents;

use App\Tools\WeatherTool;
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

That's it — `ChatAgent` can now autonomously call `get_weather`, read the result, and produce a final answer.

### Step 3: Add Routes

Add two routes to `routes/web.php` — one serves the frontend page, the other streams agent progress via [Server-Sent Events](https://laravel.com/docs/12.x/responses#event-streams-sse).

Use `reactLoopStream()` inside `response()->eventStream()` — this streaming variant propagates `yield` values from your callbacks to the HTTP response.

```php
<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Agents\ChatAgent;
use Illuminate\Http\StreamedEvent;

Route::get('/', function () {
    return Inertia::render('chat');
});
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

### Step 4: Create the Frontend

Install the Laravel stream React hook:

```bash
npm install @laravel/stream-react
```

Create `resources/js/pages/chat.tsx`:

```tsx
import { useEffect, useState } from "react";

export default function Chat() {
  const [actions, setActions] = useState<
    { tool: string; status: string; result?: string }[]
  >([]);
  const [response, setResponse] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const eventSource = new EventSource(
      "/chat?message=What+is+the+weather+in+Tokyo",
    );

    eventSource.addEventListener("action", (e) => {
      const data = JSON.parse(e.data);
      setActions((prev) => [...prev, data]);
    });

    eventSource.addEventListener("complete", (e) => {
      const data = JSON.parse(e.data);
      setResponse(data.text);
      eventSource.close();
    });

    eventSource.onerror = (e) => {
      console.error("EventSource error", e);
      setError("Connection error");
      eventSource.close();
    };

    return () => eventSource.close();
  }, []);

  return (
    <div>
      <h1>Chat</h1>

      {actions.length > 0 && (
        <div>
          <h3>Tool calls:</h3>
          {actions.map((a, i) => (
            <div key={i}>
              <strong>{a.tool}</strong> — {a.status}
              {a.result && <pre>{a.result}</pre>}
            </div>
          ))}
        </div>
      )}

      {response && (
        <div>
          <strong>Response:</strong> {response}
        </div>
      )}

      {error && <div>Error: {error}</div>}

      {!response && !error && <p>Loading...</p>}
    </div>
  );
}
```

Visit `http://localhost:8000` and you'll see your agent reason through the question, call the weather tool, and stream the final answer — all in real time.

For more streaming patterns and complete examples, see the [**Tutorials**](#tutorials) section.

---

## ReAct Loop

The ReAct (Reasoning + Acting) loop gives your agent an autonomous think-act-observe cycle:

```
User Goal
  ↓
[THOUGHT]     — LLM reasons about what to do next
  ↓
[ACTION]      — LLM calls one or more tools
  ↓
[OBSERVATION] — Tool results fed back to the LLM
  ↓
Goal finished? → No  → back to THOUGHT
               → Yes → LLM produces final answer
```

**Example:** "What's the weather in Tokyo and should I bring an umbrella?"

1. **Thought** — I need the current weather → calls `get_weather`
2. **Action** — `get_weather(city: "Tokyo")` → Rain expected, 85%
3. **Observation** — Tool result fed back to LLM
4. **Thought** — Rain > 50%, recommend umbrella → no more tools needed
5. **Final Answer** — "Yes, bring an umbrella."

### Usage

```php
// Basic
$result = (new ChatAgent)->reactLoop('What is the weather in Tokyo?');

echo $result->text();
```

```php
// With a specific provider and model
$result = (new ChatAgent)->reactLoop(
    prompt: 'What is the weather in Tokyo?',
    provider: 'anthropic',
    model: 'claude-sonnet-4-5',
);
```

### Configuration

Override the max iterations per-call, in config, or via environment variables:

```php
// Fluent API (per-call)
$result = (new ChatAgent)
    ->maxIterations(5)
    ->reactLoop('Hello!');
```

```php
// config/agentic.php
'react' => [
    'max_iterations' => 10,
    'throw_on_max_iterations' => false,
],
```

```env
AGENTIC_MAX_ITERATIONS=10
AGENTIC_THROW_ON_MAX_ITERATIONS=false
```

### Callbacks

Hook into any phase of the loop. Callbacks are optional — the loop works perfectly without them.

```php
$result = (new ChatAgent)
    ->onBeforeAction(function (string $tool, array $args, int $iteration) {
        broadcast(new ToolCallStarted($tool, $args));
    })
    ->onAfterAction(function (string $tool, array $args, string $result, int $iteration) {
        broadcast(new ToolCallCompleted($tool, $result));
    })
    ->onObservation(function (string $observation, int $iteration) {
        broadcast(new ObservationReady($observation, $iteration));
    })
    ->reactLoop('What is the weather in Tokyo?');
```

| Callback                 | When it fires         | Parameters                                                  |
| ------------------------ | --------------------- | ----------------------------------------------------------- |
| `onLoopStart`            | Loop begins           | `string $prompt`                                            |
| `onLoopComplete`         | Final answer produced | `AgentResponse $response, int $totalIterations`             |
| `onMaxIterationsReached` | Iteration limit hit   | `AgentResponse $response, int $totalIterations`             |
| `onIterationStart`       | Each iteration starts | `int $iteration`                                            |
| `onIterationEnd`         | Each iteration ends   | `int $iteration, AgentResponse $response`                   |
| `onBeforeThought`        | Before LLM prompt     | `string $prompt, int $iteration`                            |
| `onAfterThought`         | After LLM responds    | `AgentResponse $response, int $iteration`                   |
| `onBeforeAction`         | Before tool call      | `string $tool, array $args, int $iteration`                 |
| `onAfterAction`          | After tool returns    | `string $tool, array $args, string $result, int $iteration` |
| `onObservation`          | Observation ready     | `string $observation, int $iteration`                       |

### Result

The `reactLoop()` method returns a `LoopResult` that wraps the final `AgentResponse` with loop metadata:

```php
$result = (new ChatAgent)->reactLoop('Hello!');

$result->text();               // Final response text
$result->conversationId;       // Conversation ID
$result->iterations;           // Total iterations executed
$result->completed();          // true if LLM gave a final answer
$result->reachedMaxIterations; // true if iteration limit was hit
$result->steps;                // Array of LoopStep objects
$result->allToolCalls();       // All tool calls across all iterations
```

### Customization

Override these methods on your agent to customize loop behavior:

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

The Plan-Execute loop separates planning from execution — ideal for complex, multi-step tasks:

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

### Usage

```php
<?php

namespace App\Agents;

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

```php
$result = (new ResearchAgent)->planExecute(
    'Research Q4 sales data, analyze trends, and create an executive summary.',
);

echo $result->text();          // The synthesized final answer
echo $result->stepsExecuted(); // Number of steps completed
```

```php
// With a specific provider and model
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
```

```php
// config/agentic.php
'plan_execute' => [
    'max_steps' => 10,
    'allow_replan' => true,
    'max_replans' => 3,
    'throw_on_max_steps' => false,
],
```

```env
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

### Callbacks

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

| Callback            | When it fires         | Parameters                                                                       |
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

### Result

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

### Customization

Override these methods on your agent to customize planning, execution, and synthesis:

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

## Chain-of-Thought Loop

The Chain-of-Thought (CoT) loop implements iterative self-reflection reasoning where the agent progressively builds understanding until confident:

```
User Problem
  ↓
[REASONING ITERATION 1]
  ├─ Step-by-step analysis of problem
  ├─ Identify what's known and unknown
  ├─ Call tools if information needed
  └─ Self-evaluate: "Do I understand enough to solve this?"
  ↓
Confident? → No  → [REASONING ITERATION 2]
           → Yes → [FINAL ANSWER]
```

**Best for:**

- Complex reasoning problems requiring deep analysis
- Mathematical, logical, or analytical tasks
- Scenarios where understanding must build progressively
- Problems where agent should evaluate its own confidence

### Usage

```php
<?php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Loops\ChainOfThoughtLoop;

class MathAgent implements Agent, HasTools
{
    use Promptable, ChainOfThoughtLoop;

    public function instructions(): string
    {
        return 'You are a mathematics expert. Think through problems step by step, '
             . 'evaluate your understanding, and provide clear reasoning.';
    }

    public function tools(): iterable
    {
        return [new CalculatorTool, new WebSearchTool];
    }
}
```

```php
// Basic usage - agent iterates until confident
$result = (new MathAgent)->chainOfThought(
    'A train leaves Tokyo at 9am traveling 80km/h. Another leaves Osaka at 10am '
    . 'traveling 100km/h toward Tokyo. They are 400km apart. When do they meet?'
);

echo $result->text();                // Final answer with complete reasoning
echo $result->reasoningIterations;   // Number of iterations taken (e.g., 3)

// Get all reasoning steps
foreach ($result->steps as $step) {
    echo "Iteration {$step->iteration}: {$step->reasoning()}\n";
    if ($step->hasToolCalls()) {
        echo "Tools used: " . count($step->toolCalls) . "\n";
    }
}
```

```php
// With specific provider and model
$result = (new MathAgent)->chainOfThought(
    prompt: 'Analyze this complex problem...',
    provider: 'anthropic',
    model: 'claude-opus-4-6',
);
```

### Configuration

Override the max reasoning iterations per-call, in config, or via environment variables:

```php
// Fluent API (per-call)
$result = (new MathAgent)
    ->maxReasoningIterations(10)
    ->chainOfThought('Complex problem...');
```

```php
// config/agentic.php
'chain_of_thought' => [
    'max_reasoning_iterations' => 5,
    'throw_on_max_iterations' => false,
    'require_confidence_check' => true,
],
```

```env
AGENTIC_COT_MAX_ITERATIONS=5
AGENTIC_COT_THROW_ON_MAX=false
```

### Callbacks

Hook into any phase of the reasoning loop. Callbacks are optional — the loop works perfectly without them.

```php
$result = (new MathAgent)
    ->onIterationStart(function (int $iteration) {
        broadcast(new ReasoningIterationStarted($iteration));
    })
    ->onAfterReasoning(function (AgentResponse $response, int $iteration) {
        broadcast(new ReasoningStepComplete($response->text, $iteration));
    })
    ->onBeforeAction(function (string $tool, array $args, int $iteration) {
        broadcast(new ToolCallStarted($tool, $args, $iteration));
    })
    ->onReflection(function (string $reflectionPrompt, int $iteration) {
        logger()->info("Iteration {$iteration}: Entering reflection phase");
    })
    ->chainOfThought('Complex problem requiring deep analysis...');
```

| Callback                 | When it fires              | Parameters                                                  |
| ------------------------ | -------------------------- | ----------------------------------------------------------- |
| `onLoopStart`            | Loop begins                | `string $prompt`                                            |
| `onLoopComplete`         | Final answer produced      | `AgentResponse $response, int $totalIterations`             |
| `onMaxIterationsReached` | Iteration limit hit        | `AgentResponse $response, int $totalIterations`             |
| `onIterationStart`       | Each iteration starts      | `int $iteration`                                            |
| `onIterationEnd`         | Each iteration ends        | `int $iteration, AgentResponse $response`                   |
| `onBeforeReasoning`      | Before LLM reasoning       | `string $prompt, int $iteration`                            |
| `onAfterReasoning`       | After LLM responds         | `AgentResponse $response, int $iteration`                   |
| `onBeforeAction`         | Before tool call           | `string $tool, array $args, int $iteration`                 |
| `onAfterAction`          | After tool returns         | `string $tool, array $args, string $result, int $iteration` |
| `onReflection`           | Building reflection prompt | `string $reflectionPrompt, int $iteration`                  |

### Result

The `chainOfThought()` method returns a `CoTResult` that wraps the final `AgentResponse` with reasoning metadata:

```php
$result = (new MathAgent)->chainOfThought('Problem...');

$result->text();               // Final response text
$result->conversationId();     // Conversation ID
$result->reasoningIterations;  // Total iterations executed
$result->completed();          // true if agent became confident
$result->reachedMaxIterations; // true if iteration limit was hit
$result->steps;                // Array of CoTStep objects
$result->allToolCalls();       // All tool calls across all iterations
$result->reasoningSteps();     // Array of reasoning text from each step
$result->confidentStep();      // The step where agent became confident
```

### Customization

Override these methods on your agent to customize reasoning behavior:

```php
class MyAgent implements Agent, HasTools
{
    use Promptable, ChainOfThoughtLoop;

    // Customize initial reasoning prompt
    protected function buildInitialPrompt(string $userProblem): string { /* ... */ }

    // Customize reflection prompt between iterations
    protected function buildReflectionPrompt(?string $observation): string { /* ... */ }

    // Customize when reasoning is considered complete
    protected function isReasoningComplete(AgentResponse $response): bool { /* ... */ }

    // Customize how tool results are presented
    protected function formatObservation(array $toolCallRecords): string { /* ... */ }
}
```

---

## Agent Skills System

The Agent Skills System brings dynamic, context-aware instruction loading to your agents. Following the [agentskills.io](https://agentskills.io) specification, this system enables progressive disclosure — loading specialized knowledge only when needed, minimizing context usage while maximizing agent effectiveness.

### What are Agent Skills?

An Agent Skill is a self-contained package of specialized instructions and resources that teaches an agent how to perform a specific task exceptionally well. Think of skills as expert domain knowledge that can be loaded on-demand.

**Key Benefits:**

- ✅ **Progressive Disclosure** — Load only what you need, when you need it
- ✅ **Modular Knowledge** — Organize specialized instructions into reusable skills
- ✅ **Auto-Discovery** — Agents automatically find relevant skills for tasks
- ✅ **Context Efficiency** — Reduce token usage with targeted skill loading
- ✅ **Easy Sharing** — Share skills across teams and projects

### Quick Start

#### 1. Add the Trait

```php
use Laragentic\Skills\HasAgentSkills;

class MyAgent implements Agent, HasTools
{
    use Promptable, ReActLoop, HasAgentSkills;

    protected function baseInstructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function instructions(?string $query = null): string
    {
        return $this->enhanceInstructionsWithSkills(
            $this->baseInstructions(),
            $query ?? ''
        );
    }
}
```

#### 2. Load Skills

**Manual Loading:**
```php
$agent = new MyAgent;
$agent->withSkill('code-review');
$result = $agent->reactLoop('Review this PHP code for security issues');
```

**Auto-Resolution:**
```php
$agent = new MyAgent;
$agent->autoResolveSkills(threshold: 0.3, limit: 3);
$result = $agent->reactLoop('Analyze this code for vulnerabilities');
// Automatically loads 'code-review' skill based on query relevance
```

### Example Skills

Laragentic includes three production-ready skills in the `examples/skills/` directory:

#### code-review
Comprehensive code review for security, performance, and best practices.
- OWASP Top 10 security analysis
- Performance bottleneck detection
- SOLID principles enforcement
- Language-specific guidance (PHP, JavaScript, Python)

#### data-analysis
Expert data analysis, statistical modeling, and insight generation.
- Exploratory data analysis (EDA)
- Statistical testing and hypothesis validation
- Visualization recommendations
- Actionable business insights

#### api-testing
API endpoint testing, validation, and test suite generation.
- HTTP method testing (GET, POST, PUT, DELETE, etc.)
- JSON schema validation
- Security testing (SQL injection, XSS, auth issues)
- Performance analysis and error handling assessment

### Creating Custom Skills

Skills are stored in `app/Skills/` (configurable) with this structure:

```
app/Skills/
  my-skill/
    SKILL.md          # Required: metadata + instructions
    scripts/          # Optional: executable scripts
    references/       # Optional: reference documents
    assets/           # Optional: images, diagrams
```

**SKILL.md Format:**

```markdown
---
name: my-skill
description: Brief description of what this skill does
tags: [relevant, tags, here]
version: 1.0.0
author: Your Name
---

# My Skill Title

You are an expert in [domain]. Your task is to [specific task].

## Guidelines

1. [Guideline 1]
2. [Guideline 2]

## Output Format

Provide your response in this structure:
- [Section 1]
- [Section 2]

## Best Practices

- [Practice 1]
- [Practice 2]
```

**Example:**

```bash
mkdir -p app/Skills/security-audit
```

Create `app/Skills/security-audit/SKILL.md`:

```markdown
---
name: security-audit
description: Perform comprehensive security audits on applications
tags: [security, audit, vulnerability, penetration-testing]
version: 1.0.0
---

# Security Audit Skill

You are a cybersecurity expert conducting thorough security audits...

[Detailed instructions here]
```

Then use it:

```php
$result = (new MyAgent)
    ->withSkill('security-audit')
    ->reactLoop('Audit this application for security vulnerabilities');
```

### Auto-Resolution

Let agents automatically discover and load relevant skills based on query content:

```php
$agent = (new MyAgent)->autoResolveSkills(
    threshold: 0.3,  // Minimum relevance score (0.0 - 1.0)
    limit: 3         // Maximum skills to load
);

// Query mentions "security" → auto-loads code-review skill
$result = $agent->reactLoop('Review this code for security issues');

// Query mentions "data analysis" → auto-loads data-analysis skill
$result = $agent->reactLoop('Analyze this dataset for trends');
```

**How Relevance Scoring Works:**

The resolver calculates a relevance score (0.0 - 1.0) for each skill:
- **Exact name match** in query: +1.0
- **Partial name match**: +0.5
- **Description keyword match**: +0.7 per keyword
- **Tag match**: +0.5 per tag

Skills above the threshold are loaded automatically.

### Integration with Loops

Skills work seamlessly with all Laragentic loops:

```php
// ReAct Loop with Skills
$result = (new MyAgent)
    ->withSkill('code-review')
    ->reactLoop('Review this code for security issues');

// Plan-Execute Loop with Skills
$result = (new MyAgent)
    ->withSkill('data-analysis')
    ->planExecute('Analyze Q4 sales data and generate insights');

// Chain-of-Thought Loop with Skills
$result = (new MyAgent)
    ->withSkill('api-testing')
    ->chainOfThought('How should we test this REST API?');
```

### Callbacks

Track skill loading and resolution with callbacks:

```php
$agent = (new MyAgent)
    ->autoResolveSkills()
    ->onSkillLoaded(function ($skill) {
        Log::info('Skill loaded', ['skill' => $skill->name()]);
    })
    ->onSkillResolved(function ($skills, $query) {
        Log::info('Skills resolved', [
            'count' => count($skills),
            'names' => array_map(fn($s) => $s->name(), $skills),
            'query' => $query,
        ]);
    });
```

### Configuration

Configure the Skills system in `config/agentic.php`:

```php
'skills' => [
    'enabled' => true,
    'path' => app_path('Skills'),
    'auto_resolve' => false,
    'resolution_threshold' => 0.3,
    'resolution_limit' => 3,
],
```

Environment variables:

```env
AGENTIC_SKILLS_ENABLED=true
AGENTIC_SKILLS_PATH=/path/to/skills
AGENTIC_SKILLS_AUTO_RESOLVE=false
AGENTIC_SKILLS_THRESHOLD=0.3
AGENTIC_SKILLS_LIMIT=3
```

### Progressive Disclosure

The Skills System minimizes context usage through three levels:

**Level 1: Skill Index** (No skills loaded)
```
Available Skills:
- code-review: Analyze code for security and performance
- data-analysis: Analyze datasets and generate insights
- api-testing: Test API endpoints and validate responses
```
Token usage: **Minimal** (just metadata)

**Level 2: Full Instructions** (Skills loaded)
```
# Active Skills

## Skill: code-review

You are an expert code reviewer...
[Full detailed instructions]
```
Token usage: **As needed** (only loaded skills)

**Level 3: Resources** (On demand)
Scripts and references loaded only when explicitly requested.

### Streaming with Skills

Skills work seamlessly with streaming responses:

```php
$agent = (new MyAgent)
    ->autoResolveSkills()
    ->onSkillLoaded(function ($skill) {
        yield new StreamedEvent('skill-loaded', [
            'skill' => $skill->name()
        ]);
    });

return response()->eventStream(function () use ($agent) {
    yield from $agent->reactLoopStream(request('query'));
});
```

### Documentation

- **[Complete Tutorial](tutorial/agent-skills-system.md)** — Comprehensive guide with examples and diagrams
- **[Skills README](SKILLS_README.md)** — System overview and quick reference
- **[Example Skills](examples/skills/)** — Three production-ready skills

**Learn more:** See the [complete Skills tutorial](tutorial/agent-skills-system.md) for advanced patterns, troubleshooting, and detailed examples.

---

## Asking the Human for Clarification

Any loop can pause mid-execution and ask the human for input by registering the built-in `AskHumanTool`. When the LLM determines it needs clarification, it calls this tool with the questions it wants to ask — structured however it sees fit. The loop detects the signal, fires `onAskHuman`, and terminates immediately without a second LLM call.

### Setup

Add `AskHumanTool` to your agent's tools:

```php
use Laragentic\Tools\AskHumanTool;

class MyAgent implements Agent
{
    use Promptable, ReActLoop;

    public function tools(): iterable
    {
        return [
            new AskHumanTool(),   // <-- give the LLM the ability to ask
            new WeatherTool(),
            // ...
        ];
    }
}
```

### Handling the Signal

```php
use Laragentic\Signals\AskHumanSignal;

$result = (new MyAgent)
    ->onAskHuman(function (AskHumanSignal $signal, int $iteration) {
        // Fires immediately when the LLM calls ask_human.
        // Use this to broadcast to your frontend, store pending state, etc.
        broadcast(new HumanInputRequired($signal->toArray()));
    })
    ->reactLoop('What should I wear today?');

// Check which outcome occurred
if ($result->askedHuman()) {
    // Return the questions to the frontend as JSON
    return response()->json($result->askHumanSignal->toArray());
}

if ($result->completed()) {
    return response()->json(['answer' => $result->text()]);
}
```

### Question Modes

The LLM decides at runtime what questions to ask and in what format. You never write question code — the `AskHumanTool` schema teaches the LLM its options.

**Free-text** (default — for open-ended input):

```
// LLM sends: {"mode": "free_text", "question": "What city should I check?"}
```

**Structured** (when the LLM knows the shape of the answer):

```
// LLM sends:
{
  "mode": "structured",
  "questions": [
    {"type": "single_choice",   "question": "Preferred unit?",  "options": ["Celsius", "Fahrenheit"]},
    {"type": "multiple_choice", "question": "Features needed?", "options": ["Auth", "Payments", "Notifications"]},
    {"type": "free_text",       "question": "Any other preferences?"}
  ]
}
```

The `AskHumanSignal::toArray()` payload your frontend receives:

```php
// Free-text
[
    'mode'      => 'free_text',
    'question'  => 'What city should I check?',
    'questions' => [],
]

// Structured
[
    'mode'      => 'structured',
    'question'  => null,
    'questions' => [
        ['type' => 'single_choice',   'question' => 'Preferred unit?',  'options' => ['Celsius', 'Fahrenheit']],
        ['type' => 'multiple_choice', 'question' => 'Features needed?', 'options' => ['Auth', 'Payments', 'Notifications']],
        ['type' => 'free_text',       'question' => 'Any other preferences?', 'options' => []],
    ],
]
```

### Key Behaviours

- `onAskHuman` fires; `onLoopComplete` does **not** fire (these are mutually exclusive)
- `$result->askedHuman()` returns `true`; `$result->completed()` returns `false`
- `$result->askHumanSignal` holds the full `AskHumanSignal` object
- No second LLM call is made — the loop exits cleanly after firing the callback
- Works identically on all three loops: `reactLoop`, `chainOfThought`, and `planExecute`

---

## Choosing a Loop

|                  | ReAct Loop                             | Plan-Execute Loop                      | Chain-of-Thought Loop                  |
| ---------------- | -------------------------------------- | -------------------------------------- | -------------------------------------- |
| **Pattern**      | Thought → Action → Observation         | Plan → Execute Steps → Synthesize      | Reasoning → Self-Eval → Confidence     |
| **Best for**     | Tool-driven tasks, real-time reasoning | Multi-step workflows, sequential tasks | Deep analysis, progressive reasoning   |
| **Tool calls**   | Each iteration may call tools          | Each step may call tools               | Each iteration may call tools          |
| **Adaptiveness** | Continuous (every iteration)           | Optional replanning on failure         | Continuous self-reflection             |
| **Token usage**  | Lower per-iteration                    | Higher (planning + steps + synthesis)  | Medium (iterative reasoning)           |
| **Visibility**   | Per-iteration callbacks                | Per-step + plan + synthesis callbacks  | Per-iteration + reflection callbacks   |

**When to use each:**

- **ReAct Loop** — Tool-heavy tasks requiring rapid action and observation (weather queries, data lookups, calculations)
- **Plan-Execute Loop** — Complex workflows with sequential dependencies (research reports, multi-stage analysis)
- **Chain-of-Thought Loop** — Deep reasoning problems requiring progressive understanding (math proofs, legal analysis, complex decision-making)

**Use multiple loops together** — an agent can `use ReActLoop, PlanExecuteLoop, ChainOfThoughtLoop` and choose which loop to call:

```php
class HybridAgent implements Agent, HasTools
{
    use Promptable, ReActLoop, PlanExecuteLoop, ChainOfThoughtLoop;

    public function solve(string $problem, string $approach)
    {
        return match($approach) {
            'action' => $this->reactLoop($problem),      // Fast tool-driven
            'workflow' => $this->planExecute($problem),  // Multi-step plan
            'reasoning' => $this->chainOfThought($problem), // Deep analysis
        };
    }
}

// Quick tool-driven question
$result = (new HybridAgent)->reactLoop('What is the weather in Tokyo?');

// Complex multi-step task
$result = (new HybridAgent)->planExecute('Research Q4 sales and create a report.');

// Deep reasoning problem
$result = (new HybridAgent)->chainOfThought('Analyze the trade-offs between approaches A and B.');
```

---

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

## Working Examples

Want to see complete working code? Check out our **[Examples Repository](https://github.com/laragentic/laragentic-app-examples)** — a full Laravel application with:

- ✅ ReAct Loop demo with streaming UI
- ✅ Plan-Execute Loop demo with real-time progress
- ✅ Complete chat agent with tools
- ✅ Production-ready React frontends
- ✅ Server-Sent Events (SSE) streaming
- ✅ Ready to clone and run

**[→ View Examples on GitHub](https://github.com/laragentic/laragentic-app-examples)**

## Tutorials

Comprehensive streaming examples and documentation are available in the [`tutorial/`](tutorial/) folder:

- **[Complete Working Example](tutorial/complete-example.md)** — Ready-to-use chat agent with streaming, tools, and frontend code
- **[Quick Reference](tutorial/quick-reference.md)** — Callback cheat sheet and essential streaming patterns
- **[Streaming ReAct Loop](tutorial/streaming-react-loop.md)** — Stream real-time progress updates from ReAct loops
- **[Streaming Plan-Execute Loop](tutorial/streaming-plan-execute-loop.md)** — Stream planning, execution, and synthesis progress
- **[Streaming Chain-of-Thought Loop](tutorial/streaming-chain-of-thought-loop.md)** — Stream iterative self-reflection and deep reasoning

> **Note:** The tutorial folder is excluded from production installs via `.gitattributes`.

## 📚 New to PHP or Laravel?

**[Code with PHP](https://codewithphp.com)** is a free, comprehensive learning platform I created to help developers master modern PHP and Laravel from first principles.

🎯 **Learn by Building**: Create your own router, ORM, and MVC framework before touching Laravel  
🚀 **Modern PHP 8.4**: Property hooks, asymmetric visibility, and latest features  
💼 **Production-Ready**: PSR standards and real-world best practices  
🔄 **Multiple Paths**: Dedicated series for TypeScript, Java, Python, and Ruby developers

**Popular Series:**

- **PHP Basics** — Master modern PHP from zero to building your own blog
- **Build a CRM with Laravel 12** — Complete CRM system with authentication, teams, and pipelines
- **AI/ML for PHP Developers** — Build intelligent applications with machine learning
- **Claude for PHP Developers** — Integrate Claude AI into your applications

[**Start Learning at codewithphp.com →**](https://codewithphp.com)

---

## Our Other Packages

Explore our comprehensive suite of PHP packages for AI development:

### 🤖 Agentic AI & Claude Integration

- **[claude-php/claude-php-agent](https://github.com/claude-php/claude-php-agent)** — Comprehensive agentic framework with extensive agent types (ReAct, Plan-Execute, Reflection, Hierarchical, MAKER, and more), advanced patterns, MCP server integration, and detailed tutorials
- **[claude-php/Claude-PHP-SDK](https://github.com/claude-php/Claude-PHP-SDK)** — PHP SDK for Claude with complete 1-for-1 functionality of the Official Python SDK, including tool use, vision, streaming, and batch processing
- **[claude-php/Claude-PHP-SDK-Laravel](https://github.com/claude-php/Claude-PHP-SDK-Laravel)** — Official Laravel integration for the Claude PHP SDK with service providers, facades, and comprehensive documentation

### 🔧 Developer Tools & Automation

- **[dalehurley/phpbot](https://github.com/dalehurley/phpbot)** — PHP CLI AI assistant that turns natural-language requests into concrete actions with multi-tier routing, on-device Apple Intelligence support, and auto-created reusable skills
- **[dalehurley/cross-vendor-dmf](https://github.com/dalehurley/cross-vendor-dmf)** — Cross-Vendor Dynamic Model Fusion (DMF) framework for vendor-agnostic AI orchestration using Claude Opus 4.5, OpenAI GPT-5.1, and Google Gemini 3 Pro

### 🌐 Model Context Protocol (MCP)

- **[dalehurley/php-mcp-sdk](https://github.com/dalehurley/php-mcp-sdk)** — PHP implementation of the Model Context Protocol (MCP) with complete MCP 2025-06-18 specification support, enabling seamless integration between LLM applications and external data sources
- **[dalehurley/laravel-php-mcp-sdk](https://github.com/dalehurley/laravel-php-mcp-sdk)** — Comprehensive Laravel wrapper around the PHP MCP SDK with full MCP 2025-06-18 specification support, multiple transport types, and Laravel-native integration

### 📚 Learning Resources

- **[dalehurley/codewithphp](https://github.com/dalehurley/codewithphp)** — Comprehensive, open-source learning platform with tutorial-based resources for modern PHP development, featuring hands-on reproducible tutorials across multiple series including AI/ML, Laravel CRM, and more

## License

MIT License. See [LICENSE](LICENSE) for details.
