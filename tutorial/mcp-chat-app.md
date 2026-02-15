# Building an MCP-Powered Chat App with Laragentic

## Elicitation, Skills, ReAct Loop & the Laravel AI SDK

A full-stack tutorial that combines Laragentic's agent system with MCP servers to build a chat application that can pause for user input, handle OAuth flows, and resume seamlessly.

## Table of Contents

1. [What You'll Build](#what-youll-build)
2. [Prerequisites](#prerequisites)
3. [Part 1: Project Setup](#part-1-project-setup)
4. [Part 2: Build the MCP Server (Demo)](#part-2-build-the-mcp-server-demo)
5. [Part 3: Create the Agent Skill](#part-3-create-the-agent-skill)
6. [Part 4: Build the Laragentic Agent](#part-4-build-the-laragentic-agent)
7. [Part 5: Build the Backend API](#part-5-build-the-backend-api)
8. [Part 6: Build the React Frontend](#part-6-build-the-react-frontend)
9. [Part 7: The ReAct Loop in Action](#part-7-the-react-loop-in-action)
10. [Part 8: Configuration Reference](#part-8-configuration-reference)
11. [Part 9: Testing](#part-9-testing)
12. [Part 10: Deployment Checklist](#part-10-deployment-checklist)
13. [Summary](#summary)

---

## What You'll Build

A full-stack AI chat application where:

- A **React frontend** renders a chat UI with support for dynamic forms, URL consent prompts, and streaming responses
- A **Laravel backend** runs a Laragentic agent that connects to MCP servers via the MCP Client
- The agent uses the **ReAct loop** (Reason + Act) to decide when to call MCP tools
- **Agent Skills** provide domain-specific instructions loaded progressively into the agent's context
- When an MCP server needs user input, **Elicitation** pauses the conversation, renders a form or URL prompt in React, and resumes after the user responds
- When an MCP server needs third-party authorization, the **URL Elicitation Required Error** (`-32042`) flow redirects the user to an OAuth page, then retries the original tool call

The end result is a chat app where the AI can say "I need your API key to continue — please fill out this form" or "I need you to authorize access to your GitHub — click here," and the conversation seamlessly continues once the user completes the action.

---

## Prerequisites

- PHP 8.2+ with Laravel 12.x installed
- Node.js 20+ with npm/pnpm
- Composer
- PostgreSQL (recommended) or MySQL
- The Laravel AI SDK (`laravel/ai`) installed and configured
- The Laragentic package with the MCP Client and Agent Skills system implemented
- At least one MCP server to connect to (we'll build a demo one with `laravel/mcp`)

---

## Part 1: Project Setup

### 1.1 Install Dependencies

```bash
# Laravel project (if starting fresh)
laravel new mcp-chat-app
cd mcp-chat-app

# Install Laravel AI SDK
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate

# Install Laravel MCP (for building our demo MCP server)
composer require laravel/mcp
php artisan vendor:publish --provider="Laravel\Mcp\McpServiceProvider"

# Install Laragentic (your agent framework)
composer require laragentic/laragentic

# Frontend
npm install react react-dom @vitejs/plugin-react
npm install ai  # Vercel AI SDK for React streaming hooks
```

### 1.2 Configure AI Providers

In your `.env`:

```env
ANTHROPIC_API_KEY=sk-ant-...
AGENTIC_SKILLS_ENABLED=true
AGENTIC_SKILLS_PATH=app/Skills
AGENTIC_MCP_ENABLED=true
```

### 1.3 Create the Database Tables

The Laravel AI SDK's `RemembersConversations` trait requires the `agent_conversations` and `agent_conversation_messages` tables. Ensure you've run `php artisan migrate` after publishing the AI SDK migrations.

---

## Part 2: Build the MCP Server (Demo)

We'll build a small MCP server that exposes tools requiring elicitation. This simulates a real-world server that needs user credentials or authorization to function.

### 2.1 Create the MCP Server

```bash
php artisan make:mcp-server ProjectManager
```

### 2.2 Create Tools That Trigger Elicitation

Create a tool that requires a user's project preferences before it can execute:

```php
<?php
// app/Mcp/Tools/CreateProjectTool.php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateProjectTool extends Tool
{
    protected string $description = 'Create a new project with the given name and settings.';

    public function schema($schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'template' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        $name = $request->string('name');
        $template = $request->string('template', 'default');

        // In a real app, this would create the project
        return Response::text("Project '{$name}' created with template '{$template}'.");
    }
}
```

Create a tool that needs a third-party API key (triggers elicitation):

```php
<?php
// app/Mcp/Tools/DeployProjectTool.php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class DeployProjectTool extends Tool
{
    protected string $description = 'Deploy the project to a cloud provider. Requires cloud credentials.';

    public function schema($schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'environment' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $userId = $request->user()?->id;

        // Check if user has stored cloud credentials
        $credentials = cache()->get("cloud_credentials:{$userId}");

        if (! $credentials) {
            // This will be caught by the Laragentic MCP Client
            // and surfaced as an elicitation request to the React frontend
            return Response::error(
                'Cloud credentials required. Please provide your deployment API key.'
            );
        }

        return Response::text(
            "Project {$request->string('project_id')} deployed to {$request->string('environment')}."
        );
    }
}
```

### 2.3 Register Tools on the Server

```php
<?php
// app/Mcp/Servers/ProjectManager.php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\DeployProjectTool;
use Laravel\Mcp\Server;

class ProjectManager extends Server
{
    protected array $tools = [
        CreateProjectTool::class,
        DeployProjectTool::class,
    ];
}
```

### 2.4 Register the MCP Server Route

```php
<?php
// routes/ai.php

use App\Mcp\Servers\ProjectManager;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/project-manager', ProjectManager::class)
    ->middleware('auth:sanctum');
```

---

## Part 3: Create the Agent Skill

### 3.1 Create the Skill Directory

```bash
mkdir -p app/Skills/project-management
```

### 3.2 Write the SKILL.md

```markdown
---
name: project-management
description: "Guides the agent through project creation, configuration, and deployment workflows. Knows about project templates, environment setup, and CI/CD pipelines."
tags: [devops, projects, deployment, ci-cd]
version: 1.0.0
---

# Project Management Skill

## Instructions

You are assisting with project management tasks. Follow these guidelines:

### Project Creation
- Always ask for a project name before creating
- Suggest appropriate templates based on the user's description
- Available templates: `default`, `api-only`, `fullstack`, `microservice`

### Deployment
- Before deploying, confirm the target environment (staging, production)
- If cloud credentials are missing, explain that the user needs to provide them
- When an elicitation form appears, guide the user through filling it out
- After credentials are provided, retry the deployment automatically

### Error Handling
- If a tool returns an error about missing credentials, inform the user clearly
- Suggest the specific information needed
- Never fabricate credentials or skip authorization steps

## Context
This skill works with the ProjectManager MCP server which exposes:
- `create-project`: Creates a new project (requires name, optional template)
- `deploy-project`: Deploys to cloud (requires project_id, environment, and cloud credentials)
```

---

## Part 4: Build the Laragentic Agent

### 4.1 Create the Chat Agent

```php
<?php
// app/Ai/Agents/ProjectChatAgent.php

namespace App\Ai\Agents;

use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Concerns\RemembersConversations;
use Laragentic\Skills\HasAgentSkills;
use Laragentic\Mcp\HasMcpClients;
use Laragentic\Mcp\McpServer;
use Laragentic\Loops\ReActLoop;
use Stringable;

#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-5-20250929')]
#[MaxSteps(15)]
#[Temperature(0.3)]
class ProjectChatAgent implements Agent, Conversational, HasTools
{
    use Promptable,
        RemembersConversations,
        HasAgentSkills,
        HasMcpClients,
        ReActLoop;

    public function __construct(public User $user) {}

    /**
     * System instructions for the agent.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are a helpful project management assistant. You help users create,
        configure, and deploy software projects.

        When you need information from the user that a tool or MCP server requires,
        explain clearly what is needed and why. If an elicitation form is presented,
        guide the user through completing it.

        Always think step-by-step before taking actions:
        1. Understand what the user wants
        2. Determine which tools are needed
        3. Check if you have all required information
        4. Execute the action
        5. Report the result

        If a tool fails due to missing credentials or authorization:
        - Explain the situation to the user
        - If an elicitation is triggered, wait for the user to complete it
        - Then retry the original action
        INSTRUCTIONS;
    }

    /**
     * Define which MCP servers to connect to.
     */
    public function mcpServers(): array
    {
        return [
            // Connect to our Laravel MCP server via HTTP
            McpServer::http(
                name: 'project-manager',
                url: config('app.url') . '/mcp/project-manager',
                headers: [
                    'Authorization' => 'Bearer ' . $this->user->createToken('mcp')->plainTextToken,
                ],
            ),
        ];
    }

    /**
     * Define filesystem roots the MCP servers can access.
     */
    public function mcpRoots(): array
    {
        return [
            new \Laragentic\Mcp\Features\Roots\Root(
                uri: 'file://' . base_path(),
                name: 'Project Root',
            ),
        ];
    }

    /**
     * Combine native Laravel AI SDK tools with MCP tools.
     * MCP tools are automatically discovered via HasMcpClients.
     */
    public function tools(): iterable
    {
        return [
            // Native tools (Laravel AI SDK)
            new \App\Ai\Tools\ListUserProjects,

            // MCP tools are merged in automatically by the HasMcpClients trait
        ];
    }
}
```

### 4.2 Create a Native Tool (Laravel AI SDK)

```php
<?php
// app/Ai/Tools/ListUserProjects.php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListUserProjects implements Tool
{
    public function description(): Stringable|string
    {
        return 'List all projects belonging to the current user.';
    }

    public function handle(Request $request): Stringable|string
    {
        // In a real app, query the database
        return json_encode([
            ['id' => 'proj_1', 'name' => 'My API', 'status' => 'active'],
            ['id' => 'proj_2', 'name' => 'Frontend App', 'status' => 'deploying'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
```

---

## Part 5: Build the Backend API

### 5.1 Chat Controller

```php
<?php
// app/Http/Controllers/ChatController.php

namespace App\Http\Controllers;

use App\Ai\Agents\ProjectChatAgent;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**
     * Start a new conversation or continue an existing one.
     * Returns a streaming SSE response using the Vercel AI SDK protocol.
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'conversation_id' => 'nullable|string',
        ]);

        $user = $request->user();

        $agent = ProjectChatAgent::make(user: $user)
            ->withSkill('project-management')      // Load the skill
            ->autoResolveSkills();                  // Also auto-resolve if other skills match

        // Continue existing conversation or start new one
        if ($conversationId = $request->input('conversation_id')) {
            $agent->continue($conversationId, as: $user);
        } else {
            $agent->forUser($user);
        }

        // Stream the response using Vercel AI SDK protocol
        // This is compatible with the `useChat` hook in React
        return $agent
            ->stream($request->input('message'))
            ->usingVercelDataProtocol();
    }

    /**
     * Handle elicitation form submission.
     * Called when the user completes a form that an MCP server requested.
     */
    public function submitElicitation(Request $request)
    {
        $request->validate([
            'elicitation_id' => 'required|string',
            'conversation_id' => 'required|string',
            'action' => 'required|in:accept,decline,cancel',
            'data' => 'nullable|array',
        ]);

        $user = $request->user();

        $agent = ProjectChatAgent::make(user: $user)
            ->withSkill('project-management')
            ->continue($request->input('conversation_id'), as: $user);

        // Submit the elicitation response back to the MCP server
        $agent->mcpClient('project-manager')
            ->elicitation()
            ->respond(
                elicitationId: $request->input('elicitation_id'),
                action: $request->input('action'),
                data: $request->input('data'),
            );

        // Resume the conversation — the agent will retry the failed tool call
        return $agent
            ->stream('I have submitted the requested information. Please continue with what you were doing.')
            ->usingVercelDataProtocol();
    }

    /**
     * Handle URL elicitation callback.
     * Called after the user completes an out-of-band action (e.g., OAuth).
     */
    public function elicitationCallback(Request $request)
    {
        $request->validate([
            'elicitation_id' => 'required|string',
            'conversation_id' => 'required|string',
        ]);

        $user = $request->user();

        $agent = ProjectChatAgent::make(user: $user)
            ->withSkill('project-management')
            ->continue($request->input('conversation_id'), as: $user);

        // Notify the MCP server that the out-of-band action is complete
        $agent->mcpClient('project-manager')
            ->elicitation()
            ->notifyComplete($request->input('elicitation_id'));

        // Resume conversation
        return $agent
            ->stream('I have completed the authorization. Please retry the previous action.')
            ->usingVercelDataProtocol();
    }
}
```

### 5.2 Routes

```php
<?php
// routes/api.php

use App\Http\Controllers\ChatController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/chat', [ChatController::class, 'chat']);
    Route::post('/chat/elicitation', [ChatController::class, 'submitElicitation']);
    Route::post('/chat/elicitation-callback', [ChatController::class, 'elicitationCallback']);
});
```

---

## Part 6: Build the React Frontend

### 6.1 Project Structure

```
resources/js/
├── app.jsx
├── components/
│   ├── Chat.jsx              # Main chat component
│   ├── MessageList.jsx       # Renders messages
│   ├── MessageInput.jsx      # User input
│   ├── ElicitationForm.jsx   # Dynamic form for MCP form elicitation
│   ├── ElicitationUrl.jsx    # URL consent prompt for MCP URL elicitation
│   └── ToolCallIndicator.jsx # Shows when the agent is calling tools
```

### 6.2 Main Chat Component

This uses the Vercel AI SDK's `useChat` hook to connect to the Laravel streaming endpoint:

```jsx
// resources/js/components/Chat.jsx

import { useChat } from 'ai/react';
import { useState, useCallback } from 'react';
import MessageList from './MessageList';
import MessageInput from './MessageInput';
import ElicitationForm from './ElicitationForm';
import ElicitationUrl from './ElicitationUrl';

export default function Chat() {
    const [conversationId, setConversationId] = useState(null);
    const [elicitation, setElicitation] = useState(null);

    const { messages, input, handleInputChange, handleSubmit, isLoading, setMessages } = useChat({
        api: '/api/chat',
        body: { conversation_id: conversationId },
        onResponse(response) {
            // Extract conversation ID from response headers
            const newConvId = response.headers.get('X-Conversation-Id');
            if (newConvId) setConversationId(newConvId);
        },
        onFinish(message) {
            // Check if the message contains an elicitation request
            // The backend embeds these as structured data annotations
            if (message.data?.elicitation) {
                setElicitation(message.data.elicitation);
            }
        },
    });

    const handleElicitationSubmit = useCallback(async (elicitationId, action, data) => {
        setElicitation(null);

        // Submit form data to the backend
        const response = await fetch('/api/chat/elicitation', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${window.authToken}`,
            },
            body: JSON.stringify({
                elicitation_id: elicitationId,
                conversation_id: conversationId,
                action,
                data,
            }),
        });

        // The response is a stream — the agent continues the conversation
        // We need to manually append the streamed response
        if (response.ok) {
            // Trigger a new chat round with the elicitation result
            // The useChat hook will handle the streaming automatically
        }
    }, [conversationId]);

    const handleUrlElicitationComplete = useCallback(async (elicitationId) => {
        setElicitation(null);

        await fetch('/api/chat/elicitation-callback', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${window.authToken}`,
            },
            body: JSON.stringify({
                elicitation_id: elicitationId,
                conversation_id: conversationId,
            }),
        });
    }, [conversationId]);

    return (
        <div className="flex flex-col h-screen max-w-3xl mx-auto">
            <header className="p-4 border-b">
                <h1 className="text-xl font-semibold">Project Manager AI</h1>
                <p className="text-sm text-gray-500">
                    Powered by Laragentic + MCP + Laravel AI SDK
                </p>
            </header>

            <MessageList messages={messages} isLoading={isLoading} />

            {/* Elicitation overlays */}
            {elicitation?.mode === 'form' && (
                <ElicitationForm
                    elicitation={elicitation}
                    onSubmit={handleElicitationSubmit}
                    onCancel={() => handleElicitationSubmit(
                        elicitation.elicitationId, 'cancel', null
                    )}
                />
            )}

            {elicitation?.mode === 'url' && (
                <ElicitationUrl
                    elicitation={elicitation}
                    onComplete={handleUrlElicitationComplete}
                    onCancel={() => handleElicitationSubmit(
                        elicitation.elicitationId, 'cancel', null
                    )}
                />
            )}

            <MessageInput
                input={input}
                onChange={handleInputChange}
                onSubmit={handleSubmit}
                isLoading={isLoading}
                disabled={elicitation !== null}
            />
        </div>
    );
}
```

### 6.3 Elicitation Form Component

This renders a dynamic form based on the JSON Schema provided by the MCP server's `requestedSchema`:

```jsx
// resources/js/components/ElicitationForm.jsx

import { useState } from 'react';

export default function ElicitationForm({ elicitation, onSubmit, onCancel }) {
    const { elicitationId, message, requestedSchema } = elicitation;
    const properties = requestedSchema?.properties || {};
    const required = requestedSchema?.required || [];

    const [formData, setFormData] = useState(() => {
        // Initialize with defaults from schema
        const defaults = {};
        Object.entries(properties).forEach(([key, schema]) => {
            if (schema.default !== undefined) {
                defaults[key] = schema.default;
            } else if (schema.type === 'boolean') {
                defaults[key] = false;
            } else {
                defaults[key] = '';
            }
        });
        return defaults;
    });

    const [errors, setErrors] = useState({});

    const validate = () => {
        const newErrors = {};
        required.forEach(key => {
            if (!formData[key] && formData[key] !== 0 && formData[key] !== false) {
                newErrors[key] = 'This field is required';
            }
        });
        // Additional schema validation (minLength, pattern, etc.)
        Object.entries(properties).forEach(([key, schema]) => {
            const val = formData[key];
            if (val && schema.minLength && val.length < schema.minLength) {
                newErrors[key] = `Minimum ${schema.minLength} characters`;
            }
            if (val && schema.maxLength && val.length > schema.maxLength) {
                newErrors[key] = `Maximum ${schema.maxLength} characters`;
            }
            if (val && schema.pattern && !new RegExp(schema.pattern).test(val)) {
                newErrors[key] = 'Invalid format';
            }
            if (val && schema.minimum !== undefined && Number(val) < schema.minimum) {
                newErrors[key] = `Minimum value: ${schema.minimum}`;
            }
            if (val && schema.maximum !== undefined && Number(val) > schema.maximum) {
                newErrors[key] = `Maximum value: ${schema.maximum}`;
            }
        });
        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (validate()) {
            onSubmit(elicitationId, 'accept', formData);
        }
    };

    const renderField = (key, schema) => {
        const label = schema.title || key;
        const desc = schema.description;
        const isRequired = required.includes(key);

        // Enum: render select
        if (schema.enum) {
            return (
                <div key={key} className="space-y-1">
                    <label className="block text-sm font-medium">
                        {label} {isRequired && <span className="text-red-500">*</span>}
                    </label>
                    {desc && <p className="text-xs text-gray-500">{desc}</p>}
                    <select
                        value={formData[key]}
                        onChange={e => setFormData(prev => ({ ...prev, [key]: e.target.value }))}
                        className="w-full border rounded px-3 py-2"
                    >
                        <option value="">Select...</option>
                        {schema.enum.map(opt => (
                            <option key={opt} value={opt}>{opt}</option>
                        ))}
                    </select>
                    {errors[key] && <p className="text-xs text-red-500">{errors[key]}</p>}
                </div>
            );
        }

        // Boolean: render checkbox
        if (schema.type === 'boolean') {
            return (
                <div key={key} className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={formData[key]}
                        onChange={e => setFormData(prev => ({
                            ...prev, [key]: e.target.checked
                        }))}
                    />
                    <label className="text-sm font-medium">{label}</label>
                    {desc && <p className="text-xs text-gray-500">{desc}</p>}
                </div>
            );
        }

        // Number / Integer: render number input
        if (schema.type === 'number' || schema.type === 'integer') {
            return (
                <div key={key} className="space-y-1">
                    <label className="block text-sm font-medium">
                        {label} {isRequired && <span className="text-red-500">*</span>}
                    </label>
                    {desc && <p className="text-xs text-gray-500">{desc}</p>}
                    <input
                        type="number"
                        value={formData[key]}
                        min={schema.minimum}
                        max={schema.maximum}
                        step={schema.type === 'integer' ? 1 : 'any'}
                        onChange={e => setFormData(prev => ({
                            ...prev, [key]: Number(e.target.value)
                        }))}
                        className="w-full border rounded px-3 py-2"
                    />
                    {errors[key] && <p className="text-xs text-red-500">{errors[key]}</p>}
                </div>
            );
        }

        // String: render text input (with format handling)
        const inputType = schema.format === 'email' ? 'email'
            : schema.format === 'uri' ? 'url'
            : schema.format === 'date' ? 'date'
            : schema.format === 'date-time' ? 'datetime-local'
            : 'text';

        return (
            <div key={key} className="space-y-1">
                <label className="block text-sm font-medium">
                    {label} {isRequired && <span className="text-red-500">*</span>}
                </label>
                {desc && <p className="text-xs text-gray-500">{desc}</p>}
                <input
                    type={inputType}
                    value={formData[key]}
                    onChange={e => setFormData(prev => ({ ...prev, [key]: e.target.value }))}
                    className="w-full border rounded px-3 py-2"
                    placeholder={schema.title || key}
                />
                {errors[key] && <p className="text-xs text-red-500">{errors[key]}</p>}
            </div>
        );
    };

    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h2 className="text-lg font-semibold mb-2">Information Required</h2>
                <p className="text-sm text-gray-600 mb-4">{message}</p>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {Object.entries(properties).map(([key, schema]) =>
                        renderField(key, schema)
                    )}

                    <div className="flex gap-2 justify-end pt-2">
                        <button
                            type="button"
                            onClick={() => onSubmit(elicitationId, 'decline', null)}
                            className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded"
                        >
                            Decline
                        </button>
                        <button
                            type="button"
                            onClick={onCancel}
                            className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700"
                        >
                            Submit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
```

### 6.4 URL Elicitation Component

For OAuth flows and sensitive data entry where the MCP server redirects the user to an external URL:

```jsx
// resources/js/components/ElicitationUrl.jsx

import { useState } from 'react';

export default function ElicitationUrl({ elicitation, onComplete, onCancel }) {
    const { elicitationId, message, url } = elicitation;
    const [hasOpened, setHasOpened] = useState(false);

    // Parse the URL to highlight the domain for security
    const parsedUrl = new URL(url);
    const domain = parsedUrl.hostname;

    const handleOpen = () => {
        // Open in a new window — per MCP spec, must be a secure context
        // the app cannot inspect the content
        window.open(url, '_blank', 'noopener,noreferrer');
        setHasOpened(true);
    };

    const handleComplete = () => {
        onComplete(elicitationId);
    };

    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h2 className="text-lg font-semibold mb-2">Authorization Required</h2>
                <p className="text-sm text-gray-600 mb-4">{message}</p>

                {/* Show the full URL and highlight domain per MCP security spec */}
                <div className="bg-gray-50 rounded p-3 mb-4 break-all">
                    <p className="text-xs text-gray-500 mb-1">You will be directed to:</p>
                    <p className="text-sm">
                        <span className="font-semibold text-blue-700">{domain}</span>
                        <span className="text-gray-500">{parsedUrl.pathname}{parsedUrl.search}</span>
                    </p>
                </div>

                <div className="bg-amber-50 border border-amber-200 rounded p-3 mb-4">
                    <p className="text-xs text-amber-800">
                        This will open in a new window. Complete the authorization there,
                        then return here and click "I've completed this."
                    </p>
                </div>

                <div className="flex gap-2 justify-end">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded"
                    >
                        Cancel
                    </button>

                    {!hasOpened ? (
                        <button
                            type="button"
                            onClick={handleOpen}
                            className="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700"
                        >
                            Open Authorization Page
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={handleComplete}
                            className="px-4 py-2 text-sm bg-green-600 text-white rounded hover:bg-green-700"
                        >
                            I've Completed This
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
```

### 6.5 Message List with Tool Call Indicators

```jsx
// resources/js/components/MessageList.jsx

import { useRef, useEffect } from 'react';

export default function MessageList({ messages, isLoading }) {
    const bottomRef = useRef(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    return (
        <div className="flex-1 overflow-y-auto p-4 space-y-4">
            {messages.map((msg) => (
                <div
                    key={msg.id}
                    className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                >
                    <div
                        className={`max-w-[80%] rounded-lg px-4 py-2 ${
                            msg.role === 'user'
                                ? 'bg-blue-600 text-white'
                                : 'bg-gray-100 text-gray-900'
                        }`}
                    >
                        {/* Show tool calls if present */}
                        {msg.toolInvocations?.map((tool, i) => (
                            <div key={i} className="text-xs bg-gray-200 rounded px-2 py-1 mb-2">
                                <span className="font-mono">
                                    {tool.state === 'call' && '... '}
                                    {tool.state === 'result' && '>>> '}
                                    {tool.toolName}
                                </span>
                                {tool.state === 'result' && (
                                    <span className="text-gray-500 ml-2">completed</span>
                                )}
                            </div>
                        ))}

                        <div className="whitespace-pre-wrap">{msg.content}</div>
                    </div>
                </div>
            ))}

            {isLoading && (
                <div className="flex justify-start">
                    <div className="bg-gray-100 rounded-lg px-4 py-2">
                        <div className="flex items-center gap-1">
                            <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" />
                            <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce [animation-delay:0.1s]" />
                            <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce [animation-delay:0.2s]" />
                        </div>
                    </div>
                </div>
            )}

            <div ref={bottomRef} />
        </div>
    );
}
```

### 6.6 Message Input

```jsx
// resources/js/components/MessageInput.jsx

export default function MessageInput({ input, onChange, onSubmit, isLoading, disabled }) {
    return (
        <form onSubmit={onSubmit} className="p-4 border-t">
            <div className="flex gap-2">
                <input
                    value={input}
                    onChange={onChange}
                    placeholder={disabled
                        ? 'Complete the form above to continue...'
                        : 'Ask me about your projects...'
                    }
                    disabled={isLoading || disabled}
                    className="flex-1 border rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50"
                />
                <button
                    type="submit"
                    disabled={isLoading || disabled || !input.trim()}
                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                >
                    Send
                </button>
            </div>
        </form>
    );
}
```

---

## Part 7: The ReAct Loop in Action

### How It Works End-to-End

When the user sends "Deploy my API project to staging," here is the full flow:

```
User -> "Deploy my API project to staging"
  |
  v
React useChat -> POST /api/chat (SSE stream)
  |
  v
ChatController -> ProjectChatAgent
  |
  |-- Skill loaded: project-management (instructions injected)
  |-- MCP Client connected: project-manager (tools discovered)
  |
  v
ReAct Loop -- Step 1 (Reason):
  "The user wants to deploy. I should use the deploy-project tool.
   I need the project_id and environment."
  |
  v
ReAct Loop -- Step 2 (Act):
  -> Calls MCP tool: deploy-project { project_id: "proj_1", environment: "staging" }
  |
  v
MCP Server -> Tool returns error: "Cloud credentials required"
  |
  v
ReAct Loop -- Step 3 (Reason):
  "The deployment failed because cloud credentials are missing.
   I need to trigger an elicitation to get the user's API key."
  |
  v
MCP Client -> Sends elicitation/create (form mode) with schema:
  { "api_key": { "type": "string", "title": "Cloud API Key", "required": true } }
  |
  v
Backend streams to React: message with elicitation data embedded
  |
  v
React -> ElicitationForm renders with "Cloud API Key" field
  |
  v
User fills in API key -> clicks Submit
  |
  v
React -> POST /api/chat/elicitation { action: "accept", data: { api_key: "..." } }
  |
  v
ChatController -> submits elicitation response to MCP server
  -> resumes agent stream
  |
  v
ReAct Loop -- Step 4 (Act):
  -> Retries MCP tool: deploy-project { project_id: "proj_1", environment: "staging" }
  |
  v
MCP Server -> Tool succeeds: "Project proj_1 deployed to staging."
  |
  v
ReAct Loop -- Step 5 (Reason):
  "Deployment succeeded. I should inform the user."
  |
  v
Agent streams: "Your API project has been successfully deployed to staging!"
```

### URL Elicitation Required Error Flow (`-32042`)

For OAuth scenarios (e.g., the MCP server needs GitHub authorization):

```
Agent calls MCP tool -> Server returns error -32042:
{
    "code": -32042,
    "message": "Authorization required",
    "data": {
        "elicitations": [{
            "mode": "url",
            "elicitationId": "abc-123",
            "url": "https://mcp-server.example.com/connect?elicitationId=abc-123",
            "message": "Please authorize access to your GitHub repositories."
        }]
    }
}
  |
  v
MCP Client catches -32042 -> surfaces as URL elicitation to the agent
  |
  v
Agent streams message: "I need you to authorize GitHub access."
  + elicitation data embedded in stream
  |
  v
React -> ElicitationUrl component renders
  -> Shows domain: "mcp-server.example.com"
  -> User clicks "Open Authorization Page"
  -> New window opens to OAuth flow
  |
  v
User completes OAuth in new window -> redirected back to MCP server
  |
  v
MCP Server sends: notifications/elicitation/complete { elicitationId: "abc-123" }
  |
  v
User clicks "I've Completed This" in React
  |
  v
React -> POST /api/chat/elicitation-callback
  |
  v
Agent retries the original tool call -> succeeds
  |
  v
Conversation continues normally
```

---

## Part 8: Configuration Reference

### 8.1 Laragentic Config (`config/agentic.php`)

```php
return [
    'skills' => [
        'enabled' => env('AGENTIC_SKILLS_ENABLED', true),
        'path' => env('AGENTIC_SKILLS_PATH', app_path('Skills')),
        'auto_resolve' => env('AGENTIC_SKILLS_AUTO_RESOLVE', false),
        'resolution_threshold' => env('AGENTIC_SKILLS_THRESHOLD', 0.3),
    ],

    'mcp' => [
        'enabled' => env('AGENTIC_MCP_ENABLED', true),
        'capabilities' => [
            'roots' => true,
            'sampling' => true,
            'sampling_tools' => true,
            'elicitation_form' => true,
            'elicitation_url' => true,
        ],
        'client_info' => [
            'name' => 'laragentic',
            'version' => '1.0.0',
        ],
        'protocol' => [
            'version' => '2025-11-25',
            'request_timeout' => 30,
        ],
        'sampling' => [
            'auto_approve' => env('AGENTIC_MCP_SAMPLING_AUTO_APPROVE', false),
            'max_iterations' => 10,
        ],
    ],
];
```

### 8.2 Laravel AI SDK Config (`config/ai.php`)

Ensure your provider is configured:

```php
'providers' => [
    'anthropic' => [
        'driver' => 'anthropic',
        'key' => env('ANTHROPIC_API_KEY'),
    ],
],
```

---

## Part 9: Testing

### 9.1 Test the Agent with Faked Responses

```php
<?php
// tests/Feature/ChatTest.php

use App\Ai\Agents\ProjectChatAgent;
use Laravel\Ai\Prompts\AgentPrompt;

test('agent responds to project creation request', function () {
    ProjectChatAgent::fake([
        'I will create a new project called "My API" using the default template.',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/chat', [
            'message' => 'Create a new project called My API',
        ]);

    $response->assertOk();

    ProjectChatAgent::assertPrompted(fn (AgentPrompt $p) =>
        $p->contains('Create a new project')
    );
});
```

### 9.2 Test Elicitation Flow

```php
test('elicitation form submission resumes conversation', function () {
    $response = $this->actingAs($user)
        ->postJson('/api/chat/elicitation', [
            'elicitation_id' => 'test-elicitation-123',
            'conversation_id' => 'conv-456',
            'action' => 'accept',
            'data' => ['api_key' => 'test-key-789'],
        ]);

    $response->assertOk();
});
```

---

## Part 10: Deployment Checklist

- [ ] Set `AGENTIC_MCP_SAMPLING_AUTO_APPROVE=false` in production (require human-in-the-loop)
- [ ] Use HTTPS for all MCP server URLs
- [ ] Configure Sanctum/Passport tokens with proper scopes for MCP access
- [ ] Set appropriate `MaxSteps` on your agent (prevents runaway tool loops)
- [ ] Rate limit the `/api/chat` endpoint
- [ ] Store conversation IDs securely — they give access to full chat history
- [ ] Validate elicitation URLs in the React frontend (show full domain, warn on suspicious URLs)
- [ ] Monitor MCP server logs for failed tool calls and elicitation timeouts
- [ ] Test the full elicitation to retry flow end-to-end before shipping

---

## Summary

This tutorial demonstrated how to combine five systems into a cohesive chat application:

1. **Laravel AI SDK** — Agent definitions, streaming, conversations, tool execution
2. **Laragentic ReAct Loop** — Step-by-step reasoning over tool calls
3. **Laragentic Agent Skills** — Domain-specific instructions loaded progressively
4. **Laragentic MCP Client** — Connects to MCP servers, discovers tools, handles elicitation
5. **React Frontend** — Renders chat, dynamic forms, and URL consent prompts

The key architectural insight is that **elicitation is a pause/resume mechanism**: the MCP server says "I need something from the user," the conversation pauses, the React UI renders the appropriate prompt (form or URL), the user responds, and the conversation resumes exactly where it left off with the agent retrying the failed operation. This creates a seamless experience where AI-driven workflows can request real user input without breaking the conversational flow.
