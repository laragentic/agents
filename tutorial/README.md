# Laragentic Tutorials

This folder contains working examples and tutorials for using Laragentic's agentic loops with Laravel's [Event Streams (SSE)](https://laravel.com/docs/12.x/responses#event-streams-sse).

## Contents

- **[complete-example.md](complete-example.md)** — Complete working example with agent, tools, routes, and frontend code
- **[quick-reference.md](quick-reference.md)** — Quick reference card for streaming callbacks
- **[streaming-react-loop.md](streaming-react-loop.md)** — Stream ReAct loop progress to users with `eventStream()`
- **[streaming-plan-execute-loop.md](streaming-plan-execute-loop.md)** — Stream Plan-Execute loop progress with callbacks

## Quick Start

If you're new to streaming agentic loops, start with the [**Complete Example**](complete-example.md). It provides a ready-to-use chat agent with SSE streaming that you can copy and paste into your Laravel application.

## Running the Examples

Each tutorial contains Laravel route examples that you can copy into your application's `routes/web.php` file.

The examples use `response()->eventStream()` with `StreamedEvent` to send Server-Sent Events as the agent works. Inside `eventStream()` closures, use the streaming variants — `reactLoopStream()` and `planExecuteStream()` — which propagate `yield` values from callbacks to the HTTP response. This provides structured, real-time progress updates that can be consumed by `EventSource` in JavaScript or `@laravel/stream-react` / `@laravel/stream-vue` on the frontend.

## Prerequisites

- Laravel 12.x or higher
- Laravel AI SDK (`laravel/ai`) installed and configured
- Laragentic package installed
- An AI provider configured (Anthropic, OpenAI, etc.)

## Example Agent Setup

All tutorials assume you have a basic agent class like this:

```php
<?php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;
use Laravel\Ai\Concerns\RemembersConversations;
use Laragentic\Loops\ReActLoop;
use Laragentic\Loops\PlanExecuteLoop;

class ExampleAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;
    use ReActLoop, PlanExecuteLoop;

    public function instructions(): string
    {
        return 'You are a helpful AI assistant.';
    }

    public function tools(): iterable
    {
        return [
            // Your tools here
        ];
    }
}
```

## Note

This tutorial folder is excluded from production installs via `.gitattributes`. It's only available when you clone the repository or work on the package locally.
