# Complete Working Example: Chat Agent with Event Streams

This is a complete, copy-paste ready example showing how to build a streaming chat interface with Laragentic loops using Laravel's Event Streams (SSE).

## File Structure

```
app/
├── Agents/
│   └── ChatAgent.php
├── Tools/
│   ├── WeatherTool.php
│   └── SearchTool.php
routes/
└── web.php
```

## 1. Create Your Agent

**`app/Agents/ChatAgent.php`**

```php
<?php

namespace App\Agents;

use App\Tools\SearchTool;
use App\Tools\WeatherTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;
use Laravel\Ai\Concerns\RemembersConversations;
use Laragentic\Loops\ReActLoop;

class ChatAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;
    use ReActLoop;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are a helpful AI assistant with access to tools.
        
        - Use the weather tool to get current weather information
        - Use the search tool to look up factual information
        - Always provide clear, accurate answers
        - If you're unsure, use your tools to find the answer
        INSTRUCTIONS;
    }

    public function tools(): iterable
    {
        return [
            new WeatherTool,
            new SearchTool,
        ];
    }

    public function model(): string
    {
        return 'claude-opus-4-6'; // Latest Anthropic model
    }
}
```

## 2. Create Example Tools

**`app/Tools/WeatherTool.php`**

```php
<?php

namespace App\Tools;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class WeatherTool implements Tool
{
    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Get current weather information for a city';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'city' => [
                    'type' => 'string',
                    'description' => 'The city name',
                ],
            ],
            'required' => ['city'],
        ];
    }

    public function handle(Request $request): string
    {
        $city = $request->input('city');
        
        // In production, call a real weather API
        $conditions = ['Sunny', 'Cloudy', 'Rainy', 'Partly Cloudy'];
        $temps = [15, 18, 22, 25, 28];
        
        $condition = $conditions[array_rand($conditions)];
        $temp = $temps[array_rand($temps)];
        
        return "Current weather in {$city}: {$condition}, {$temp}°C";
    }
}
```

**`app/Tools/SearchTool.php`**

```php
<?php

namespace App\Tools;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SearchTool implements Tool
{
    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return 'Search for factual information on the internet';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(Request $request): string
    {
        $query = $request->input('query');
        
        // In production, call a real search API (Google, Bing, etc.)
        return "Search results for '{$query}': [Mock search results would appear here]";
    }
}
```

## 3. Add Streaming Routes

**`routes/web.php`**

```php
<?php

use App\Agents\ChatAgent;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

// Basic SSE endpoint — simplest possible streaming
Route::get('/chat', function () {
    $agent = new ChatAgent;
    
    return response()->eventStream(function () use ($agent) {
        $agent
            ->maxIterations(10)
            ->onBeforeThought(fn($prompt, $i) =>
                yield new StreamedEvent(event: 'thinking', data: ['iteration' => $i]))
            ->onBeforeAction(fn($tool, $args, $i) =>
                yield new StreamedEvent(event: 'action', data: ['tool' => $tool, 'status' => 'calling']))
            ->onAfterAction(fn($tool, $args, $result, $i) =>
                yield new StreamedEvent(event: 'action', data: ['tool' => $tool, 'result' => $result, 'status' => 'complete']))
            ->onLoopComplete(function ($response, $iterations) {
                yield new StreamedEvent(
                    event: 'complete',
                    data: [
                        'text' => $response->text,
                        'iterations' => $iterations,
                        'conversationId' => $response->conversationId,
                    ],
                );
            });
        
        // Use the streaming variant — yields propagate from callbacks
        yield from $agent->reactLoopStream(request()->input('message', 'Hello!'));
    });
});

// Detailed SSE endpoint — all lifecycle events
Route::get('/chat/detailed', function () {
    $agent = new ChatAgent;
    $conversationId = request()->input('conversation_id');
    
    if ($conversationId) {
        $agent->forConversation($conversationId);
    }
    
    return response()->eventStream(function () use ($agent) {
        try {
            $agent
                ->maxIterations(10)
                ->onLoopStart(function (string $prompt) {
                    yield new StreamedEvent(
                        event: 'start',
                        data: ['prompt' => $prompt, 'timestamp' => now()->toIso8601String()],
                    );
                })
                ->onIterationStart(function (int $iteration) {
                    yield new StreamedEvent(
                        event: 'iteration',
                        data: ['number' => $iteration, 'stage' => 'start'],
                    );
                })
                ->onBeforeThought(function (string $prompt, int $iteration) {
                    yield new StreamedEvent(
                        event: 'thinking',
                        data: ['iteration' => $iteration],
                    );
                })
                ->onAfterThought(function ($response, int $iteration) {
                    yield new StreamedEvent(
                        event: 'thought',
                        data: [
                            'iteration' => $iteration,
                            'text' => $response->text,
                            'hasToolCalls' => $response->toolCalls->isNotEmpty(),
                        ],
                    );
                })
                ->onBeforeAction(function (string $tool, array $args, int $iteration) {
                    yield new StreamedEvent(
                        event: 'action',
                        data: [
                            'tool' => $tool,
                            'args' => $args,
                            'iteration' => $iteration,
                            'stage' => 'start',
                        ],
                    );
                })
                ->onAfterAction(function (string $tool, array $args, string $result, int $iteration) {
                    yield new StreamedEvent(
                        event: 'action',
                        data: [
                            'tool' => $tool,
                            'result' => $result,
                            'iteration' => $iteration,
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
                ->onIterationEnd(function (int $iteration, $response) {
                    yield new StreamedEvent(
                        event: 'iteration',
                        data: ['number' => $iteration, 'stage' => 'end'],
                    );
                })
                ->onLoopComplete(function ($response, int $iterations) {
                    yield new StreamedEvent(
                        event: 'complete',
                        data: [
                            'iterations' => $iterations,
                            'text' => $response->text,
                            'conversationId' => $response->conversationId,
                            'usage' => [
                                'promptTokens' => $response->usage?->promptTokens,
                                'completionTokens' => $response->usage?->completionTokens,
                                'totalTokens' => $response->usage?->totalTokens,
                            ],
                        ],
                    );
                })
                ->onMaxIterationsReached(function ($response, int $iterations) {
                    yield new StreamedEvent(
                        event: 'max_iterations',
                        data: ['iterations' => $iterations, 'text' => $response?->text],
                    );
                });
            
            yield from $agent->reactLoopStream(request()->input('message', 'Hello!'));
            
        } catch (\Throwable $e) {
            yield new StreamedEvent(
                event: 'error',
                data: ['message' => $e->getMessage(), 'timestamp' => now()->toIso8601String()],
            );
        }
    });
});
```

### Why `reactLoopStream()`?

The `reactLoopStream()` method is a Generator that propagates `yield` values from your callbacks to the parent `eventStream()` closure. Without it, PHP would treat each callback's `yield` as creating a separate Generator — the values would never reach the HTTP response.

- Use `reactLoopStream()` (and `planExecuteStream()`) inside `eventStream()` closures
- Use `reactLoop()` (and `planExecute()`) everywhere else (returns `LoopResult` / `PlanResult` directly)

## 4. Frontend Consumption

### cURL (Quick Testing)

```bash
# Basic stream
curl -N "http://localhost:8000/chat?message=What+is+the+weather+in+Tokyo"

# Detailed stream
curl -N "http://localhost:8000/chat/detailed?message=What+is+the+weather+in+Tokyo"
```

The `-N` flag disables cURL's output buffering, so you see events in real time.

### JavaScript (EventSource)

```javascript
const message = encodeURIComponent('What is the weather in Tokyo?');
const source = new EventSource(`/chat?message=${message}`);

source.addEventListener('thinking', (e) => {
    console.log('🤔 Agent is thinking...');
});

source.addEventListener('action', (e) => {
    const data = JSON.parse(e.data);
    if (data.status === 'calling') {
        console.log(`🔧 Calling tool: ${data.tool}`);
    } else {
        console.log(`📊 Result: ${data.result}`);
    }
});

source.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('✅ Complete!', data.text);
    console.log('Conversation ID:', data.conversationId);
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

Simple usage — `useEventStream` automatically concatenates messages:

```tsx
import { useEventStream } from '@laravel/stream-react';

function Chat() {
    const { message } = useEventStream('/chat?message=Hello');
    
    return <div>{message}</div>;
}
```

With options for more control:

> **Important:** `useEventStream` creates an `EventSource` connection immediately when called. Never pass an empty string as the URL — the browser resolves `''` to the current page URL, causing errors. Use a child component that only mounts when a real URL is available.

```tsx
import { useEventStream } from '@laravel/stream-react';
import { useState } from 'react';

// Child component — only rendered when URL is set
function StreamConsumer({ url }: { url: string }) {
    const { message } = useEventStream(url, {
        eventName: 'update',
        onMessage: (event) => console.log('New:', event.data),
        onComplete: () => console.log('Done'),
        endSignal: '</stream>',
    });

    return <pre>{message}</pre>;
}

function ChatInterface() {
    const [input, setInput] = useState('');
    const [url, setUrl] = useState('');

    const handleSubmit = () => {
        setUrl(`/chat?message=${encodeURIComponent(input)}`);
    };

    return (
        <div>
            <input value={input} onChange={(e) => setInput(e.target.value)} />
            <button onClick={handleSubmit}>Send</button>
            {url && <StreamConsumer url={url} />}
        </div>
    );
}
```

### Vue with `@laravel/stream-vue`

```bash
npm install @laravel/stream-vue
```

```vue
<!-- StreamConsumer.vue — only rendered when URL is set -->
<script setup lang="ts">
import { useEventStream } from '@laravel/stream-vue';

const props = defineProps<{ url: string }>();

const { message } = useEventStream(props.url, {
    onMessage: (event) => console.log('New:', event.data),
    onComplete: () => console.log('Done'),
});
</script>

<template>
    <pre>{{ message }}</pre>
</template>
```

```vue
<!-- ChatInterface.vue -->
<script setup lang="ts">
import { ref } from 'vue';
import StreamConsumer from './StreamConsumer.vue';

const input = ref('');
const url = ref('');

const handleSubmit = () => {
    url.value = `/chat?message=${encodeURIComponent(input.value)}`;
};
</script>

<template>
    <div>
        <input v-model="input" @keyup.enter="handleSubmit" />
        <button @click="handleSubmit">Send</button>
        <StreamConsumer v-if="url" :url="url" />
    </div>
</template>
```

## 5. Testing

```bash
# Start Laravel server
php artisan serve

# Test basic event stream (use -N to disable buffering)
curl -N "http://localhost:8000/chat?message=What+is+the+weather+in+Tokyo"

# Test detailed event stream
curl -N "http://localhost:8000/chat/detailed?message=What+is+the+weather+in+Tokyo"
```

## Important Configuration

**`config/ai.php`**

```php
return [
    'default' => 'anthropic',
    
    'drivers' => [
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-opus-4-6',
        ],
    ],
];
```

**`.env`**

```env
ANTHROPIC_API_KEY=your-key-here
```

## Production Considerations

1. **Authentication** — Add authentication middleware to your routes
2. **Rate Limiting** — Add rate limiting to prevent abuse
3. **Error Handling** — Implement comprehensive error handling (see detailed route example)
4. **Logging** — Log agent activity for debugging and monitoring
5. **Timeouts** — Set appropriate timeouts for long-running tasks
6. **Conversation Storage** — Consider using Redis for conversation storage
7. **Real APIs** — Replace mock tools with real API integrations

## How `eventStream()` Works

Laravel's `eventStream()` method handles everything for you:

- Sets `Content-Type: text/event-stream` automatically
- Formats events in the SSE wire format (`event:` / `data:` lines)
- Flushes between each `yield` automatically
- Disables Nginx output buffering
- Sends a `</stream>` event when the Generator finishes

You just `yield` `StreamedEvent` instances and Laravel handles the rest.

## Next Steps

- Read the [ReAct Loop Tutorial](streaming-react-loop.md) for more advanced patterns
- Read the [Plan-Execute Loop Tutorial](streaming-plan-execute-loop.md) for multi-step tasks
- Check the [Quick Reference](quick-reference.md) for callback cheat sheet
