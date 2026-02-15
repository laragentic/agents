# Quick Reference: Streaming Callbacks with Event Streams

All examples use Laravel's `response()->eventStream()` which sends Server-Sent Events (SSE).

## Core Pattern

```php
use Illuminate\Http\StreamedEvent;

return response()->eventStream(function () use ($agent) {
    $agent
        ->onAfterAction(fn($tool, $args, $result, $i) =>
            yield new StreamedEvent(event: 'action', data: ['tool' => $tool, 'result' => $result]))
        ->onLoopComplete(fn($response, $iterations) =>
            yield new StreamedEvent(event: 'complete', data: ['text' => $response->text]));

    // Use the streaming variant — yields propagate from callbacks
    yield from $agent->reactLoopStream('Hello!');
});
```

> **Important:** Always use `reactLoopStream()` / `planExecuteStream()` inside `eventStream()` closures. The streaming variants propagate `yield` values from callbacks. Without them, callback yields stay in their own scope.

## ReAct Loop Callbacks

```php
use Illuminate\Http\StreamedEvent;

$agent
    // Loop lifecycle
    ->onLoopStart(fn(string $prompt) =>
        yield new StreamedEvent(event: 'start', data: ['prompt' => $prompt]))
    ->onLoopComplete(fn($response, int $iterations) =>
        yield new StreamedEvent(event: 'complete', data: ['text' => $response->text, 'iterations' => $iterations]))
    ->onMaxIterationsReached(fn($response, int $iterations) =>
        yield new StreamedEvent(event: 'max_iterations', data: ['iterations' => $iterations]))
    
    // Iteration lifecycle
    ->onIterationStart(fn(int $iteration) =>
        yield new StreamedEvent(event: 'iteration', data: ['number' => $iteration, 'stage' => 'start']))
    ->onIterationEnd(fn(int $iteration, $response) =>
        yield new StreamedEvent(event: 'iteration', data: ['number' => $iteration, 'stage' => 'end']))
    
    // Thought phase (LLM reasoning)
    ->onBeforeThought(fn(string $prompt, int $i) =>
        yield new StreamedEvent(event: 'thinking', data: ['iteration' => $i]))
    ->onAfterThought(fn($response, int $i) =>
        yield new StreamedEvent(event: 'thought', data: ['text' => $response->text, 'iteration' => $i]))
    
    // Action phase (tool execution)
    ->onBeforeAction(fn(string $tool, array $args, int $i) =>
        yield new StreamedEvent(event: 'action', data: ['tool' => $tool, 'stage' => 'start']))
    ->onAfterAction(fn(string $tool, array $args, string $result, int $i) =>
        yield new StreamedEvent(event: 'action', data: ['tool' => $tool, 'result' => $result, 'stage' => 'complete']))
    
    // Observation phase (results fed back)
    ->onObservation(fn(string $observation, int $i) =>
        yield new StreamedEvent(event: 'observation', data: ['text' => $observation]));

yield from $agent->reactLoopStream('Hello!');
```

## Plan-Execute Loop Callbacks

```php
use Illuminate\Http\StreamedEvent;

$agent
    // Loop lifecycle
    ->onLoopStart(fn(string $task) =>
        yield new StreamedEvent(event: 'start', data: ['task' => $task]))
    ->onLoopComplete(fn($response, int $steps) =>
        yield new StreamedEvent(event: 'complete', data: ['text' => $response->text, 'steps' => $steps]))
    ->onMaxStepsReached(fn($response, int $steps) =>
        yield new StreamedEvent(event: 'max_steps', data: ['steps' => $steps]))
    
    // Planning phase
    ->onPlanCreated(fn(array $steps) =>
        yield new StreamedEvent(event: 'plan', data: ['steps' => $steps, 'count' => count($steps)]))
    
    // Execution phase
    ->onBeforeStep(fn(int $num, string $desc, int $total) =>
        yield new StreamedEvent(event: 'step', data: ['number' => $num, 'description' => $desc, 'stage' => 'start']))
    ->onAfterStep(fn(int $num, string $desc, $response, int $total) =>
        yield new StreamedEvent(event: 'step', data: ['number' => $num, 'stage' => 'complete']))
    
    // Replanning phase
    ->onReplan(fn(array $newSteps, int $replanCount) =>
        yield new StreamedEvent(event: 'replan', data: ['attempt' => $replanCount, 'steps' => $newSteps]))
    
    // Synthesis phase
    ->onBeforeSynthesis(fn(array $stepResults) =>
        yield new StreamedEvent(event: 'synthesis', data: ['stage' => 'start']))
    ->onAfterSynthesis(fn($response) =>
        yield new StreamedEvent(event: 'synthesis', data: ['stage' => 'complete', 'text' => $response->text]));

yield from $agent->planExecuteStream('Research Laravel AI SDK');
```

## Essential Patterns

### Pattern: Basic Event Stream

```php
return response()->eventStream(function () use ($agent) {
    $agent
        ->onAfterAction(fn($tool, $args, $result, $i) =>
            yield new StreamedEvent(event: 'action', data: ['tool' => $tool, 'result' => $result]));

    yield from $agent->reactLoopStream('Hello');
});
```

### Pattern: Progress Tracking

```php
$completed = 0;
$total = 0;

$agent
    ->onPlanCreated(function (array $steps) use (&$total) {
        $total = count($steps);
        yield new StreamedEvent(event: 'plan', data: ['total' => $total]);
    })
    ->onAfterStep(function () use (&$completed, &$total) {
        $completed++;
        yield new StreamedEvent(event: 'progress', data: [
            'completed' => $completed,
            'total' => $total,
            'percentage' => round(($completed / $total) * 100, 1),
        ]);
    });

yield from $agent->planExecuteStream('Research AI trends');
```

### Pattern: Error Handling

```php
return response()->eventStream(function () use ($agent, $message) {
    try {
        $agent
            ->onLoopComplete(fn($r, $i) =>
                yield new StreamedEvent(event: 'complete', data: ['text' => $r->text]));

        yield from $agent->reactLoopStream($message);
    } catch (\Throwable $e) {
        yield new StreamedEvent(event: 'error', data: ['message' => $e->getMessage()]);
    }
});
```

### Pattern: Custom End-of-Stream Signal

```php
return response()->eventStream(function () use ($agent) {
    $agent->onLoopComplete(fn($r) =>
        yield new StreamedEvent(event: 'complete', data: $r->text));
    
    yield from $agent->reactLoopStream('Hello');
}, endStreamWith: new StreamedEvent(event: 'update', data: '</stream>'));
```

## JavaScript Consumption

### EventSource (Recommended)

```javascript
const source = new EventSource('/chat?message=Hello');

source.addEventListener('action', (e) => {
    const data = JSON.parse(e.data);
    console.log('Tool:', data.tool, 'Result:', data.result);
});

source.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('Done!', data.text);
    source.close();
});

// Laravel sends </stream> as an "update" event when the generator finishes
source.addEventListener('update', (e) => {
    if (e.data === '</stream>') {
        source.close();
    }
});
```

### React (`@laravel/stream-react`)

```tsx
import { useEventStream } from '@laravel/stream-react';

function Chat() {
    const { message } = useEventStream('/chat?message=Hello');
    return <div>{message}</div>;
}
```

With options:

```tsx
const { message } = useEventStream('/chat?message=Hello', {
    eventName: 'update',
    onMessage: (msg) => console.log(msg),
    onError: (err) => console.error(err),
    onComplete: () => console.log('Done'),
    endSignal: '</stream>',
    glue: ' ',
});
```

### Vue (`@laravel/stream-vue`)

```vue
<script setup>
import { useEventStream } from '@laravel/stream-vue';

const { message } = useEventStream('/chat?message=Hello');
</script>

<template>
    <div>{{ message }}</div>
</template>
```

## `StreamedEvent` Quick Reference

```php
use Illuminate\Http\StreamedEvent;

// Named event with object data (JSON-encoded automatically)
yield new StreamedEvent(event: 'action', data: ['tool' => 'search']);

// Named event with string data
yield new StreamedEvent(event: 'update', data: 'Thinking...');

// Plain string (sent as default "update" event)
yield 'Hello world';
```

## Key Notes

- `eventStream()` sets `Content-Type: text/event-stream` automatically
- `eventStream()` sends `</stream>` when the Generator finishes
- `eventStream()` handles flushing and Nginx buffering automatically
- `EventSource` (JS) only supports GET requests — use query parameters
- For POST, use Fetch API with manual SSE parsing

## References

- [Laravel Event Streams (SSE)](https://laravel.com/docs/12.x/responses#event-streams-sse)
- [ReAct Loop Tutorial](streaming-react-loop.md)
- [Plan-Execute Tutorial](streaming-plan-execute-loop.md)
