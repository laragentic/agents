# Streaming Plan-Execute Loop Progress

This tutorial shows how to stream Plan-Execute loop progress to users in real-time using Laravel's [Event Streams (SSE)](https://laravel.com/docs/12.x/responses#event-streams-sse) with the callbacks from the `PlanExecuteLoop` trait.

## The Problem

Plan-Execute loops involve multiple distinct phases:

1. **Planning** — LLM creates a multi-step plan
2. **Execution** — Each step is executed sequentially
3. **Synthesis** — All results are combined into a final answer

Without streaming, users wait in silence while the agent works through potentially many steps.

## The Solution

Use `response()->eventStream()` to send Server-Sent Events for each phase, so users can follow along with the agent's work in real time.

The `PlanExecuteLoop` trait provides `planExecuteStream()` — a Generator method designed for streaming contexts. Unlike `planExecute()`, it propagates `yield` values from your callbacks to the parent Generator, making them compatible with `eventStream()`.

## Basic Example

Here's a minimal example that streams Plan-Execute loop progress:

```php
use App\Agents\ExampleAgent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

Route::get('/research', function () {
    $agent = new ExampleAgent;
    
    return response()->eventStream(function () use ($agent) {
        $agent
            ->allowReplan()
            ->maxSteps(8)
            ->onPlanCreated(function (array $steps) {
                yield new StreamedEvent(
                    event: 'plan',
                    data: ['steps' => $steps, 'count' => count($steps)],
                );
            })
            ->onBeforeStep(function (int $stepNumber, string $description, int $totalSteps) {
                yield new StreamedEvent(
                    event: 'step',
                    data: [
                        'stage' => 'start',
                        'number' => $stepNumber,
                        'description' => $description,
                        'total' => $totalSteps,
                    ],
                );
            })
            ->onAfterStep(function (int $stepNumber, string $description, $response, int $totalSteps) {
                yield new StreamedEvent(
                    event: 'step',
                    data: [
                        'stage' => 'complete',
                        'number' => $stepNumber,
                        'total' => $totalSteps,
                    ],
                );
            })
            ->onBeforeSynthesis(function (array $stepResults) {
                yield new StreamedEvent(
                    event: 'synthesis',
                    data: ['stage' => 'start', 'stepCount' => count($stepResults)],
                );
            })
            ->onLoopComplete(function ($response, int $totalSteps) {
                yield new StreamedEvent(
                    event: 'complete',
                    data: [
                        'text' => $response->text,
                        'stepsExecuted' => $totalSteps,
                    ],
                );
            });
        
        // Use the streaming variant — yields propagate from callbacks
        yield from $agent->planExecuteStream(request()->input('task', 'Research Laravel AI SDK'));
    });
});
```

> **Why `planExecuteStream()`?** PHP generators are scoped to their closure. A `yield` inside a callback creates a Generator for *that callback only* — it doesn't propagate to the parent `eventStream()` closure. `planExecuteStream()` uses `yield from` internally to forward those values.

## Advanced Example: Full Lifecycle with Replanning

This example includes adaptive replanning support and all available callbacks:

```php
use App\Agents\ExampleAgent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

Route::get('/research/detailed', function () {
    $agent = new ExampleAgent;
    
    return response()->eventStream(function () use ($agent) {
        $agent
            ->allowReplan()
            ->maxSteps(10)
            ->maxReplans(2)
            ->onLoopStart(function (string $task) {
                yield new StreamedEvent(
                    event: 'start',
                    data: ['task' => $task],
                );
            })
            ->onPlanCreated(function (array $steps) {
                yield new StreamedEvent(
                    event: 'plan',
                    data: [
                        'type' => 'initial',
                        'steps' => $steps,
                        'count' => count($steps),
                    ],
                );
            })
            ->onBeforeStep(function (int $stepNumber, string $description, int $totalSteps) {
                yield new StreamedEvent(
                    event: 'step',
                    data: [
                        'stage' => 'start',
                        'number' => $stepNumber,
                        'description' => $description,
                        'total' => $totalSteps,
                    ],
                );
            })
            ->onAfterStep(function (int $stepNumber, string $description, $response, int $totalSteps) {
                yield new StreamedEvent(
                    event: 'step',
                    data: [
                        'stage' => 'complete',
                        'number' => $stepNumber,
                        'description' => $description,
                        'result' => $response->text,
                        'total' => $totalSteps,
                    ],
                );
            })
            ->onReplan(function (array $newSteps, int $replanCount) {
                yield new StreamedEvent(
                    event: 'replan',
                    data: [
                        'attempt' => $replanCount,
                        'newSteps' => $newSteps,
                        'count' => count($newSteps),
                    ],
                );
            })
            ->onBeforeSynthesis(function (array $stepResults) {
                yield new StreamedEvent(
                    event: 'synthesis',
                    data: ['stage' => 'start', 'stepCount' => count($stepResults)],
                );
            })
            ->onAfterSynthesis(function ($response) {
                yield new StreamedEvent(
                    event: 'synthesis',
                    data: ['stage' => 'complete', 'text' => $response->text],
                );
            })
            ->onLoopComplete(function ($response, int $totalSteps) {
                yield new StreamedEvent(
                    event: 'complete',
                    data: [
                        'stepsExecuted' => $totalSteps,
                        'text' => $response->text,
                        'conversationId' => $response->conversationId,
                    ],
                );
            })
            ->onMaxStepsReached(function ($response, int $stepsExecuted) {
                yield new StreamedEvent(
                    event: 'max_steps',
                    data: [
                        'stepsExecuted' => $stepsExecuted,
                        'text' => $response->text,
                    ],
                );
            });
        
        // Use the streaming variant — yields propagate from callbacks
        yield from $agent->planExecuteStream(request()->input('task', 'Research Laravel AI SDK'));
    });
});
```

## Consuming the Event Stream

### JavaScript (EventSource)

```javascript
const source = new EventSource('/research?task=Compare+React+and+Vue');

source.addEventListener('plan', (e) => {
    const data = JSON.parse(e.data);
    console.log(`📋 Plan (${data.count} steps):`, data.steps);
});

source.addEventListener('step', (e) => {
    const data = JSON.parse(e.data);
    if (data.stage === 'start') {
        console.log(`▶️  Step ${data.number}/${data.total}: ${data.description}`);
    } else {
        console.log(`✓ Step ${data.number} complete:`, data.result);
    }
});

source.addEventListener('replan', (e) => {
    const data = JSON.parse(e.data);
    console.log(`🔄 Replanning (attempt ${data.attempt})...`);
    console.log('New plan:', data.newSteps);
});

source.addEventListener('synthesis', (e) => {
    const data = JSON.parse(e.data);
    if (data.stage === 'start') {
        console.log('🔄 Synthesizing results...');
    } else {
        console.log('✅ Synthesis complete:', data.text);
    }
});

source.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log(`✅ Complete in ${data.stepsExecuted} steps!`);
    console.log('Final answer:', data.text);
    source.close();
});

// Laravel sends </stream> as an "update" event when the generator finishes
source.addEventListener('update', (e) => {
    if (e.data === '</stream>') {
        source.close();
    }
});
```

### React with `@laravel/stream-react`

```bash
npm install @laravel/stream-react
```

```tsx
import { useEventStream } from '@laravel/stream-react';

function ResearchAgent() {
    const { message } = useEventStream('/research?task=Compare+React+and+Vue', {
        onMessage: (msg) => console.log('Progress:', msg),
        onComplete: () => console.log('Research done!'),
    });

    return (
        <div>
            <pre>{message}</pre>
        </div>
    );
}
```

### Vue with `@laravel/stream-vue`

```bash
npm install @laravel/stream-vue
```

```vue
<script setup lang="ts">
import { useEventStream } from '@laravel/stream-vue';

const { message } = useEventStream('/research?task=Compare+React+and+Vue', {
    onMessage: (msg) => console.log('Progress:', msg),
});
</script>

<template>
    <div>
        <pre>{{ message }}</pre>
    </div>
</template>
```

## Real-World Example: Research Dashboard

Here's a complete example with detailed progress tracking and error handling:

```php
use App\Agents\ResearchAgent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

Route::get('/research/dashboard', function () {
    $task = request()->input('task');
    $userId = auth()->id();
    
    if (empty($task)) {
        return response()->json(['error' => 'Task required'], 400);
    }
    
    $agent = new ResearchAgent;
    $agent->forUser($userId);
    
    $completedSteps = 0;
    $totalSteps = 0;
    
    return response()->eventStream(function () use ($agent, $task, &$completedSteps, &$totalSteps) {
        try {
            $agent
                ->allowReplan()
                ->maxSteps(12)
                ->maxReplans(3)
                ->onLoopStart(function () use ($task) {
                    yield new StreamedEvent(
                        event: 'start',
                        data: ['task' => $task, 'timestamp' => now()->toIso8601String()],
                    );
                })
                ->onPlanCreated(function (array $steps) use (&$totalSteps) {
                    $totalSteps = count($steps);
                    yield new StreamedEvent(
                        event: 'plan',
                        data: ['steps' => $steps, 'count' => count($steps)],
                    );
                })
                ->onBeforeStep(function (int $stepNumber, string $description) use (&$completedSteps, &$totalSteps) {
                    $percentage = $totalSteps > 0
                        ? round(($completedSteps / $totalSteps) * 100, 1)
                        : 0;
                    
                    yield new StreamedEvent(
                        event: 'step',
                        data: [
                            'stage' => 'start',
                            'number' => $stepNumber,
                            'description' => $description,
                            'progress' => [
                                'completed' => $completedSteps,
                                'total' => $totalSteps,
                                'percentage' => $percentage,
                            ],
                        ],
                    );
                })
                ->onAfterStep(function (int $stepNumber, string $description, $response) use (&$completedSteps) {
                    $completedSteps++;
                    yield new StreamedEvent(
                        event: 'step',
                        data: [
                            'stage' => 'complete',
                            'number' => $stepNumber,
                            'description' => $description,
                        ],
                    );
                })
                ->onReplan(function (array $newSteps, int $replanCount) use (&$totalSteps, &$completedSteps) {
                    $totalSteps = count($newSteps);
                    $completedSteps = 0;
                    yield new StreamedEvent(
                        event: 'replan',
                        data: [
                            'attempt' => $replanCount,
                            'newSteps' => $newSteps,
                            'count' => count($newSteps),
                        ],
                    );
                })
                ->onBeforeSynthesis(function (array $stepResults) {
                    yield new StreamedEvent(
                        event: 'synthesis',
                        data: ['stage' => 'start', 'stepCount' => count($stepResults)],
                    );
                })
                ->onLoopComplete(function ($response, int $totalSteps) {
                    yield new StreamedEvent(
                        event: 'complete',
                        data: [
                            'stepsExecuted' => $totalSteps,
                            'text' => $response->text,
                            'conversationId' => $response->conversationId,
                            'timestamp' => now()->toIso8601String(),
                        ],
                    );
                });
            
            yield from $agent->planExecuteStream($task);
            
        } catch (\Throwable $e) {
            yield new StreamedEvent(
                event: 'error',
                data: [
                    'message' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                ],
            );
        }
    });
});
```

## Phase-Specific Callbacks

The Plan-Execute loop has callbacks for each distinct phase:

| Phase | Callback | Receives |
|-------|----------|----------|
| **Planning** | `onPlanCreated` | `array $steps` |
| **Execution** | `onBeforeStep` | `int $stepNumber, string $description, int $totalSteps` |
| **Execution** | `onAfterStep` | `int $stepNumber, string $description, AgentResponse $response, int $totalSteps` |
| **Replanning** | `onReplan` | `array $newSteps, int $replanCount` |
| **Synthesis** | `onBeforeSynthesis` | `array $stepResults` |
| **Synthesis** | `onAfterSynthesis` | `AgentResponse $response` |
| **Completion** | `onLoopComplete` | `AgentResponse $response, int $totalSteps` |
| **Limits** | `onMaxStepsReached` | `AgentResponse $response, int $stepsExecuted` |

## Progress Tracking Pattern

Track progress as steps complete:

```php
$completedSteps = 0;
$totalSteps = 0;

$agent
    ->onPlanCreated(function (array $steps) use (&$totalSteps) {
        $totalSteps = count($steps);
        yield new StreamedEvent(
            event: 'plan',
            data: ['total' => $totalSteps],
        );
    })
    ->onAfterStep(function () use (&$completedSteps, &$totalSteps) {
        $completedSteps++;
        $percentage = round(($completedSteps / $totalSteps) * 100, 1);
        
        yield new StreamedEvent(
            event: 'progress',
            data: [
                'completed' => $completedSteps,
                'total' => $totalSteps,
                'percentage' => $percentage,
            ],
        );
    });

// Use the streaming variant inside eventStream()
yield from $agent->planExecuteStream($task);
```

## Important Notes

### How `eventStream()` Works

Laravel's `eventStream()` method:
- Sets `Content-Type: text/event-stream` automatically
- Handles SSE formatting (the `event:` / `data:` lines)
- Sends a `</stream>` event when the Generator finishes
- Automatically flushes between each `yield`
- Disables Nginx buffering

### Replanning Resets Progress

When replanning occurs, the step counter typically resets because the plan changes:

```php
->onReplan(function (array $newSteps) use (&$completedSteps, &$totalSteps) {
    $completedSteps = 0; // Reset
    $totalSteps = count($newSteps); // New total
})
```

### Synthesis is a Separate Phase

After all steps execute, synthesis combines results. This is a distinct phase with its own callbacks.

### GET vs POST Routes

`EventSource` only supports GET. For POST data, pass parameters as query strings or use the Fetch API:

```php
// GET route with query params (works with EventSource)
Route::get('/research', fn () => response()->eventStream(...));
```

```javascript
new EventSource('/research?task=Compare+React+and+Vue');
```

## Performance Tips

1. **Stream summaries, not full results** — Send full step results only if needed
2. **Track progress** — Users appreciate knowing "Step 3 of 8"
3. **Show replanning** — Let users know when the plan changes
4. **Use named events** — Let the frontend filter and handle event types

## References

- [Laravel Event Streams (SSE)](https://laravel.com/docs/12.x/responses#event-streams-sse)
- [Plan-Execute Loop Documentation](../README.md#plan-execute-loop)
- [Quick Reference: All Callbacks](quick-reference.md)
