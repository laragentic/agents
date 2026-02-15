# Quick Reference

Essential patterns and callback reference for Laragentic agents.

## Visual Examples

### ReAct Loop: Autonomous Tool Use

![ReAct Loop](images/react-loop.png)

### Complete Chat Agent with Streaming

![Chat Agent](images/complete-chat-example.png)

---

## ReAct Loop Callbacks

### Complete Callback Reference

| Callback | Parameters | When It Fires | Use Case |
|----------|-----------|---------------|----------|
| `onLoopStart` | `string $prompt` | Before loop begins | Log request, set up tracking |
| `onIterationStart` | `int $iteration` | Start of each iteration | Track iteration count |
| `onBeforeThought` | `string $prompt, int $iteration` | Before agent reasons | Show "thinking" indicator |
| `onAfterThought` | `AgentResponse $response, int $iteration` | After agent reasons | Display reasoning, check tool calls |
| `onBeforeAction` | `string $tool, array $args, int $iteration` | Before tool execution | Show tool being called |
| `onAfterAction` | `string $tool, array $args, string $result, int $iteration` | After tool execution | Display tool result |
| `onObservation` | `string $observation, int $iteration` | After formatting tool result | Show agent's understanding |
| `onIterationEnd` | `int $iteration` | End of each iteration | Mark iteration complete |
| `onLoopComplete` | `AgentResponse $response, int $iterations` | Loop finishes successfully | Display final answer |
| `onMaxIterationsReached` | `?AgentResponse $response, int $iterations` | Max iterations hit | Handle timeout |

### Callback Signatures

```php
// Loop lifecycle
->onLoopStart(function (string $prompt) {
    // Called once at the start
})

->onLoopComplete(function (AgentResponse $response, int $iterations) {
    // Called once at the end
})

// Iteration lifecycle
->onIterationStart(function (int $iteration) {
    // iteration = 1, 2, 3, ...
})

->onIterationEnd(function (int $iteration) {
    // iteration = 1, 2, 3, ...
})

// Thought phase
->onBeforeThought(function (string $prompt, int $iteration) {
    // prompt = user's message or formatted observation
})

->onAfterThought(function (AgentResponse $response, int $iteration) {
    // $response->text = agent's reasoning
    // $response->toolCalls = tools the agent wants to use
})

// Action phase
->onBeforeAction(function (string $tool, array $args, int $iteration) {
    // tool = 'get_weather', args = ['city' => 'Tokyo']
})

->onAfterAction(function (string $tool, array $args, string $result, int $iteration) {
    // result = tool's return value as string
})

// Observation phase
->onObservation(function (string $observation, int $iteration) {
    // observation = formatted tool result(s)
})

// Error handling
->onMaxIterationsReached(function (?AgentResponse $response, int $iterations) {
    // response may be null if no progress made
})
```

## Plan-Execute Loop Callbacks

| Callback | Parameters | When It Fires |
|----------|-----------|---------------|
| `onLoopStart` | `string $task` | Before planning begins |
| `onPlanCreated` | `array $steps` | After initial plan created |
| `onBeforeStep` | `int $stepNumber, string $description, int $totalSteps` | Before executing a step |
| `onAfterStep` | `int $stepNumber, string $description, AgentResponse $response, int $totalSteps` | After executing a step |
| `onReplan` | `array $newSteps, int $replanCount` | When replanning occurs |
| `onBeforeSynthesis` | `array $stepResults` | Before synthesizing results |
| `onAfterSynthesis` | `AgentResponse $response` | After synthesis complete |
| `onLoopComplete` | `AgentResponse $response, int $totalSteps` | Loop finishes |
| `onMaxStepsReached` | `AgentResponse $response, int $stepsExecuted` | Max steps hit |

## Essential Patterns

### 1. Minimal Agent (5 lines)

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laragentic\Loops\ReActLoop;

class MyAgent implements Agent
{
    use Promptable, ReActLoop;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
```

### 2. Add Streaming (3 lines)

```php
Route::get('/stream', function () {
    return response()->eventStream(function () {
        $agent = new MyAgent;
        yield from $agent->reactLoopStream('Hello!');
    });
});
```

### 3. Multi-Tool Agent

```php
use Laravel\Ai\Contracts\HasTools;

class MyAgent implements Agent, HasTools
{
    use Promptable, ReActLoop;

    public function instructions(): string
    {
        return 'Use the available tools to answer questions.';
    }

    public function tools(): iterable
    {
        return [
            new WeatherTool,
            new SearchTool,
            new CalculatorTool,
        ];
    }
}
```

### 4. Conversational Agent

```php
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Concerns\RemembersConversations;

class MyAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations, ReActLoop;

    // ...
}

// Usage
$agent = new MyAgent;
$agent->withConversation($conversationId); // Resume conversation
$response = $agent->reactLoop('Follow-up question');
$conversationId = $response->conversationId; // Save for next message
```

### 5. Configure Limits

```php
$agent = new MyAgent;
$agent->maxIterations(15); // Default is 10
$result = $agent->reactLoop('Complex task');
```

### 6. Track Tool Usage

```php
$toolsUsed = [];

$agent
    ->onAfterAction(function (string $tool, array $args, string $result) use (&$toolsUsed) {
        $toolsUsed[] = $tool;
    })
    ->onLoopComplete(function ($response) use (&$toolsUsed) {
        logger()->info('Tools used: ' . implode(', ', $toolsUsed));
    });

yield from $agent->reactLoopStream('What is 2+2 and the weather in Tokyo?');
```

### 7. Stream Progress with SSE

```php
Route::get('/stream', function () {
    $agent = new MyAgent;

    return response()->eventStream(function () use ($agent) {
        $agent
            ->onBeforeAction(fn ($tool) => yield new StreamedEvent(
                event: 'tool',
                data: ['name' => $tool, 'status' => 'calling']
            ))
            ->onAfterAction(fn ($tool, $args, $result) => yield new StreamedEvent(
                event: 'tool',
                data: ['name' => $tool, 'result' => $result, 'status' => 'complete']
            ))
            ->onLoopComplete(fn ($response) => yield new StreamedEvent(
                event: 'complete',
                data: ['text' => $response->text]
            ));

        yield from $agent->reactLoopStream(request('message'));
    });
});
```

### 8. Handle Max Iterations

```php
$agent
    ->maxIterations(5)
    ->onMaxIterationsReached(function ($response, int $iterations) {
        logger()->warning("Agent reached max iterations ({$iterations})");
        yield new StreamedEvent(
            event: 'error',
            data: ['message' => 'Task took too long, stopped after ' . $iterations . ' iterations']
        );
    });
```

### 9. Custom Observation Formatting

```php
class MyAgent implements Agent
{
    use Promptable, ReActLoop;

    protected function formatObservation(Collection $toolCalls): string
    {
        // Custom formatting for tool results
        $observations = $toolCalls->map(function ($call) {
            return "{$call->name}: {$call->result}";
        });

        return "Here are the results:\n" . $observations->implode("\n");
    }
}
```

### 10. Early Termination

```php
class MyAgent implements Agent
{
    use Promptable, ReActLoop;

    protected function loopShouldTerminate(AgentResponse $response): bool
    {
        // Stop if agent says "DONE"
        if (str_contains($response->text, 'DONE')) {
            return true;
        }

        // Otherwise use default logic (no tool calls = done)
        return $response->toolCalls->isEmpty();
    }
}
```

## Frontend Patterns

### Vanilla JavaScript with EventSource

```javascript
const source = new EventSource('/stream?message=Hello');

source.addEventListener('action', (e) => {
    const data = JSON.parse(e.data);
    console.log('Tool called:', data.tool);
});

source.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('Final answer:', data.text);
    source.close();
});

source.addEventListener('error', () => {
    console.error('Stream error');
    source.close();
});
```

### React with useEventStream

```tsx
import { useEventStream } from '@laravel/stream-react';

function MyComponent() {
    const [url, setUrl] = useState('');
    const [events, setEvents] = useState([]);

    useEventStream(url, {
        eventName: ['action', 'complete'],
        onMessage: (event) => {
            setEvents(prev => [...prev, event]);
        },
        onComplete: () => {
            console.log('Stream finished');
        },
    });

    return <div>{/* Render events */}</div>;
}
```

### Fetch API (Non-Streaming)

```javascript
// For non-streaming responses
const response = await fetch('/api/agent', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message: 'Hello' }),
});

const data = await response.json();
console.log(data.text);
```

## Common Snippets

### Log All Events

```php
$agent
    ->onLoopStart(fn ($prompt) => logger()->info('Loop started', ['prompt' => $prompt]))
    ->onBeforeThought(fn ($prompt, $i) => logger()->info("Iteration {$i}: thinking"))
    ->onAfterAction(fn ($tool, $args, $result, $i) => logger()->info("Iteration {$i}: {$tool}", compact('args', 'result')))
    ->onLoopComplete(fn ($response, $i) => logger()->info('Loop complete', ['iterations' => $i]));
```

### Broadcast to WebSockets

```php
$agent
    ->onAfterAction(function ($tool, $args, $result) {
        broadcast(new AgentActionComplete($tool, $result));
        yield new StreamedEvent(event: 'action', data: compact('tool', 'result'));
    });
```

### Cache Expensive Tool Results

```php
class CachedWeatherTool implements Tool
{
    public function handle(Request $request): string
    {
        $city = $request['city'];
        
        return Cache::remember("weather.{$city}", 300, function () use ($city) {
            return $this->fetchWeatherFromAPI($city);
        });
    }
}
```

### Rate Limit Tool Calls

```php
class RateLimitedSearchTool implements Tool
{
    public function handle(Request $request): string
    {
        RateLimiter::attempt(
            'search:' . request()->ip(),
            $perMinute = 10,
            function () use ($request) {
                return $this->performSearch($request['query']);
            },
            $decaySeconds = 60
        );
    }
}
```

## Configuration

### Package Config (`config/agentic.php`)

```php
return [
    'react' => [
        'max_iterations' => env('AGENTIC_MAX_ITERATIONS', 10),
        'throw_on_max_iterations' => env('AGENTIC_THROW_ON_MAX_ITERATIONS', false),
    ],

    'plan_execute' => [
        'max_steps' => env('AGENTIC_PLAN_MAX_STEPS', 10),
        'allow_replan' => env('AGENTIC_PLAN_ALLOW_REPLAN', true),
        'max_replans' => env('AGENTIC_PLAN_MAX_REPLANS', 3),
        'throw_on_max_steps' => env('AGENTIC_PLAN_THROW_ON_MAX_STEPS', false),
    ],
];
```

### Environment Variables

```env
# ReAct Loop
AGENTIC_MAX_ITERATIONS=10
AGENTIC_THROW_ON_MAX_ITERATIONS=false

# Plan-Execute Loop
AGENTIC_PLAN_MAX_STEPS=10
AGENTIC_PLAN_ALLOW_REPLAN=true
AGENTIC_PLAN_MAX_REPLANS=3
AGENTIC_PLAN_THROW_ON_MAX_STEPS=false
```

## Troubleshooting

### "Stream Failed" Error (But Results Work)

**Cause:** Bug in `@laravel/stream-react` v0.3.10 - throws error on normal stream closure.

**Solution:** Wrap the error handler to filter out the library bug:

```tsx
function StreamListener({ url, onEvent, onComplete, onError }) {
    const handleError = (error?: any) => {
        // Known library bug on stream close
        if (error?.message?.includes('startsWith') || error?.type === 'error') {
            onComplete(); // Treat as success
        } else {
            onError(); // Real error
        }
    };

    useEventStream(url, {
        eventName: ['action', 'complete'],
        onMessage: onEvent,
        onComplete,
        onError: handleError,
    });
    return null;
}
```

### Agent Loops Forever

**Cause:** Agent not detecting when task is complete.

**Solution:** Improve agent instructions to indicate completion clearly:

```php
public function instructions(): string
{
    return <<<'INSTRUCTIONS'
    Use the tools to gather information, then provide a final answer.
    When you have all the information needed, respond with your answer WITHOUT calling more tools.
    INSTRUCTIONS;
}
```

### Tool Not Being Called

**Cause:** Tool description unclear or agent instructions don't mention it.

**Solution:** Improve tool description and explicitly mention in instructions:

```php
public function description(): string
{
    return 'Get current weather for ANY city worldwide. Use this for ALL weather questions.';
}

public function instructions(): string
{
    return 'For weather questions, ALWAYS use the get_weather tool. Do not guess weather information.';
}
```

### Streaming Not Working

**Cause:** Server buffering responses.

**Solution:** Disable output buffering:

```php
Route::get('/stream', function () {
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', 'off');
    
    return response()->eventStream(/* ... */);
});
```

### Conversation Not Persisting

**Cause:** Not passing conversation ID correctly.

**Solution:** Ensure ID is passed in subsequent requests:

```php
// First message
$response = $agent->reactLoop('Hello');
$conversationId = $response->conversationId; // Save this!

// Follow-up
$agent->withConversation($conversationId);
$response = $agent->reactLoop('Follow-up');
```

### Empty Response

**Cause:** Agent may be hitting max iterations without completing.

**Solution:** Increase max iterations or simplify the task:

```php
$agent->maxIterations(20); // Increase limit
```

## Performance Tips

1. **Limit tool calls:** Each tool call is an API request. Design tools to return comprehensive information in one call.

2. **Cache tool results:** Use Laravel's cache for expensive operations:
   ```php
   Cache::remember("tool.{$cacheKey}", 300, fn() => $this->expensiveOperation());
   ```

3. **Use appropriate models:** Smaller models (e.g., GPT-4o-mini) are faster and cheaper for simple tasks.

4. **Set reasonable limits:** Default max_iterations=10 is usually sufficient. Don't set it too high.

5. **Optimize streaming:** Only yield events the frontend actually needs. Too many events = slower UI.

## Next Steps

- **[Complete Working Example](complete-example.md)** — Full chat agent with streaming
- **[Streaming ReAct Loop](streaming-react-loop.md)** — Deep dive into ReAct patterns
- **[Streaming Plan-Execute Loop](streaming-plan-execute-loop.md)** — Multi-step planning
