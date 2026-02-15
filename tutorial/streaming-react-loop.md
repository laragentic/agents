# Streaming ReAct Loop Progress

This tutorial shows how to stream ReAct loop progress to users in real-time using Laravel's [Event Streams (SSE)](https://laravel.com/docs/12.x/responses#event-streams-sse) with the callbacks from the `ReActLoop` trait.

## The Problem

Agentic loops can take several seconds or even minutes to complete. Users need real-time feedback about what the agent is doing:

- "Thinking about the problem..."
- "Calling weather API..."
- "Analyzing results..."

Without streaming, users see nothing until the entire loop completes.

## The Solution

Laravel's `response()->eventStream()` sends Server-Sent Events (SSE) to the browser. By yielding `StreamedEvent` instances from the loop callbacks, we can stream structured progress updates as the agent works.

The `ReActLoop` trait provides `reactLoopStream()` — a Generator method designed for streaming contexts. Unlike `reactLoop()`, it propagates `yield` values from your callbacks to the parent Generator, making them compatible with `eventStream()`.

## Basic Example

Here's a minimal example that streams ReAct loop progress as SSE:

```php
use App\Agents\ExampleAgent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

Route::get('/chat', function () {
    $agent = new ExampleAgent;
    
    return response()->eventStream(function () use ($agent) {
        $agent
            ->onBeforeThought(function (string $prompt, int $iteration) {
                yield new StreamedEvent(
                    event: 'thinking',
                    data: ['iteration' => $iteration],
                );
            })
            ->onAfterAction(function (string $tool, array $args, string $result, int $iteration) {
                yield new StreamedEvent(
                    event: 'action',
                    data: [
                        'tool' => $tool,
                        'result' => $result,
                        'iteration' => $iteration,
                    ],
                );
            })
            ->onObservation(function (string $observation, int $iteration) {
                yield new StreamedEvent(
                    event: 'observation',
                    data: ['iteration' => $iteration],
                );
            })
            ->onLoopComplete(function ($response, int $iterations) {
                yield new StreamedEvent(
                    event: 'complete',
                    data: [
                        'text' => $response->text,
                        'iterations' => $iterations,
                    ],
                );
            });
        
        // Use the streaming variant — yields propagate from callbacks
        yield from $agent->reactLoopStream(request()->input('message', 'Hello!'));
    });
});
```

> **Why `reactLoopStream()`?** PHP generators are scoped to their closure. A `yield` inside a callback creates a Generator for *that callback only* — it doesn't propagate to the parent `eventStream()` closure. `reactLoopStream()` uses `yield from` internally to forward those values.

## Advanced Example: Full Lifecycle Events

Use all available callbacks for detailed progress tracking:

```php
use App\Agents\ExampleAgent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

Route::get('/chat/detailed', function () {
    $agent = new ExampleAgent;
    
    return response()->eventStream(function () use ($agent) {
        $agent
            ->onIterationStart(function (int $iteration) {
                yield new StreamedEvent(
                    event: 'iteration',
                    data: ['number' => $iteration, 'status' => 'started'],
                );
            })
            ->onBeforeThought(function (string $prompt, int $iteration) {
                yield new StreamedEvent(
                    event: 'thought',
                    data: ['iteration' => $iteration, 'stage' => 'thinking'],
                );
            })
            ->onAfterThought(function ($response, int $iteration) {
                yield new StreamedEvent(
                    event: 'thought',
                    data: [
                        'iteration' => $iteration,
                        'stage' => 'complete',
                        'text' => $response->text,
                        'hasToolCalls' => $response->toolCalls->isNotEmpty(),
                    ],
                );
            })
            ->onBeforeAction(function (string $tool, array $args, int $iteration) {
                yield new StreamedEvent(
                    event: 'action',
                    data: [
                        'iteration' => $iteration,
                        'tool' => $tool,
                        'args' => $args,
                        'stage' => 'started',
                    ],
                );
            })
            ->onAfterAction(function (string $tool, array $args, string $result, int $iteration) {
                yield new StreamedEvent(
                    event: 'action',
                    data: [
                        'iteration' => $iteration,
                        'tool' => $tool,
                        'result' => $result,
                        'stage' => 'complete',
                    ],
                );
            })
            ->onObservation(function (string $observation, int $iteration) {
                yield new StreamedEvent(
                    event: 'observation',
                    data: ['iteration' => $iteration, 'text' => $observation],
                );
            })
            ->onLoopComplete(function ($response, int $iterations) {
                yield new StreamedEvent(
                    event: 'complete',
                    data: [
                        'iterations' => $iterations,
                        'text' => $response->text,
                        'conversationId' => $response->conversationId,
                    ],
                );
            })
            ->onMaxIterationsReached(function ($response, int $iterations) {
                yield new StreamedEvent(
                    event: 'max_iterations',
                    data: [
                        'iterations' => $iterations,
                        'text' => $response->text,
                    ],
                );
            });
        
        // Use the streaming variant — yields propagate from callbacks
        yield from $agent->reactLoopStream(request()->input('message', 'Hello!'));
    });
});
```

## Consuming the Event Stream

### JavaScript (EventSource)

```javascript
const source = new EventSource('/chat?message=What+is+the+weather+in+Tokyo');

source.addEventListener('thinking', (e) => {
    const data = JSON.parse(e.data);
    console.log(`🤔 Iteration ${data.iteration}: Thinking...`);
});

source.addEventListener('action', (e) => {
    const data = JSON.parse(e.data);
    if (data.stage === 'started') {
        console.log(`🔧 Calling tool: ${data.tool}`);
    } else {
        console.log(`📊 Tool result: ${data.result}`);
    }
});

source.addEventListener('observation', (e) => {
    const data = JSON.parse(e.data);
    console.log('👁️ Observation:', data.text);
});

source.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('✅ Complete!', data.text);
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

function ChatAgent() {
    const { message } = useEventStream('/chat?message=Hello', {
        onMessage: (msg) => console.log('Received:', msg),
        onComplete: () => console.log('Stream complete'),
    });

    return <div>{message}</div>;
}
```

For more control over individual event types:

```tsx
import { useEventStream } from '@laravel/stream-react';

function ChatAgentDetailed() {
    const { message } = useEventStream('/chat/detailed?message=Hello', {
        eventName: 'complete', // Only concatenate 'complete' events into message
        onMessage: (msg) => {
            // Handle each message as it arrives
            console.log('New message:', msg);
        },
    });

    return <div>{message}</div>;
}
```

### Vue with `@laravel/stream-vue`

```bash
npm install @laravel/stream-vue
```

```vue
<script setup lang="ts">
import { useEventStream } from '@laravel/stream-vue';

const { message } = useEventStream('/chat?message=Hello');
</script>

<template>
    <div>{{ message }}</div>
</template>
```

## Real-World Example: Chat Interface

Here's a complete example with proper error handling and user feedback:

```php
use App\Agents\ExampleAgent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

Route::get('/agent/chat', function () {
    $message = request()->input('message');
    $conversationId = request()->input('conversation_id');
    
    if (empty($message)) {
        return response()->json(['error' => 'Message required'], 400);
    }
    
    $agent = new ExampleAgent;
    
    if ($conversationId) {
        $agent->forConversation($conversationId);
    }
    
    return response()->eventStream(function () use ($agent, $message) {
        try {
            $agent
                ->maxIterations(10)
                ->onIterationStart(function (int $iteration) {
                    yield new StreamedEvent(
                        event: 'progress',
                        data: ['type' => 'iteration_start', 'iteration' => $iteration],
                    );
                })
                ->onBeforeThought(function (string $prompt, int $iteration) {
                    yield new StreamedEvent(
                        event: 'progress',
                        data: ['type' => 'thinking', 'iteration' => $iteration],
                    );
                })
                ->onAfterAction(function (string $tool, array $args, string $result, int $iteration) {
                    yield new StreamedEvent(
                        event: 'progress',
                        data: [
                            'type' => 'tool_result',
                            'tool' => $tool,
                            'iteration' => $iteration,
                        ],
                    );
                })
                ->onLoopComplete(function ($response, int $iterations) {
                    yield new StreamedEvent(
                        event: 'complete',
                        data: [
                            'iterations' => $iterations,
                            'text' => $response->text,
                            'conversationId' => $response->conversationId,
                        ],
                    );
                });
            
            yield from $agent->reactLoopStream($message);
            
        } catch (\Throwable $e) {
            yield new StreamedEvent(
                event: 'error',
                data: ['message' => $e->getMessage()],
            );
        }
    });
});
```

### Customizing the End-of-Stream Signal

By default, Laravel sends a `</stream>` event when the stream ends. You can customize this:

```php
return response()->eventStream(function () use ($agent, $message) {
    $agent->onLoopComplete(function ($response) {
        yield new StreamedEvent(event: 'complete', data: $response->text);
    });
    
    yield from $agent->reactLoopStream($message);
}, endStreamWith: new StreamedEvent(event: 'update', data: '</stream>'));
```

## Important Notes

### How `eventStream()` Works

Laravel's `eventStream()` method:
- Sets the `Content-Type` to `text/event-stream` automatically
- Handles SSE formatting (the `event:` / `data:` / `id:` lines)
- Sends a `</stream>` event when the Generator finishes
- Automatically flushes between each `yield`
- Disables Nginx buffering

### `StreamedEvent` Class

Use `Illuminate\Http\StreamedEvent` to yield structured SSE events:

```php
use Illuminate\Http\StreamedEvent;

// Named event with structured data (auto-JSON-encoded)
yield new StreamedEvent(
    event: 'action',
    data: ['tool' => 'search', 'result' => '...'],
);

// Named event with string data
yield new StreamedEvent(
    event: 'update',
    data: 'Agent is thinking...',
);
```

You can also yield plain strings for simple `update` events:

```php
yield 'Hello'; // Sent as a default "update" event
```

### GET vs POST Routes

`EventSource` in JavaScript only supports GET requests. For POST data, use either:

1. **Query parameters** with a GET route (simple):
   ```php
   Route::get('/chat', fn () => response()->eventStream(...));
   ```
   ```javascript
   new EventSource('/chat?message=Hello');
   ```

2. **Fetch API** with a POST route (for larger payloads):
   ```javascript
   const response = await fetch('/chat', {
       method: 'POST',
       headers: { 'Content-Type': 'application/json' },
       body: JSON.stringify({ message: 'Hello' }),
   });
   // Process as SSE manually or use a library
   ```

## Performance Tips

1. **Minimize data per event** — Stream only essential progress updates
2. **Use named events** — Let the frontend filter by event type
3. **Handle disconnections** — Users may close the browser mid-stream
4. **Set timeouts appropriately** — Long-running agents need higher timeouts

## References

- [Laravel Event Streams (SSE)](https://laravel.com/docs/12.x/responses#event-streams-sse)
- [ReAct Loop Documentation](../README.md#react-loop)
- [Quick Reference: All Callbacks](quick-reference.md)
