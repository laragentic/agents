<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ReAct Loop Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the default behavior of the ReAct (Reasoning +
    | Acting) agentic loop. Each option can be overridden per-agent using
    | fluent methods like ->maxIterations(5) on the agent instance.
    |
    */

    'react' => [

        /*
        |--------------------------------------------------------------------------
        | Maximum Iterations
        |--------------------------------------------------------------------------
        |
        | The maximum number of reasoning/action cycles the ReAct loop will
        | execute before stopping. This prevents runaway loops and controls
        | token consumption. Set to null to allow unlimited iterations.
        |
        */

        'max_iterations' => (int) env('AGENTIC_MAX_ITERATIONS', 10),

        /*
        |--------------------------------------------------------------------------
        | Throw on Max Iterations
        |--------------------------------------------------------------------------
        |
        | When true, a MaxIterationsExceededException will be thrown if the
        | loop reaches the maximum iteration count. When false, the last
        | response from the LLM will be returned instead.
        |
        */

        'throw_on_max_iterations' => (bool) env('AGENTIC_THROW_ON_MAX_ITERATIONS', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Plan-Execute Loop Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the default behavior of the Plan-Execute agentic
    | loop which separates planning from execution. Each option can be
    | overridden per-agent using fluent methods on the agent instance.
    |
    */

    'plan_execute' => [

        /*
        |--------------------------------------------------------------------------
        | Maximum Steps
        |--------------------------------------------------------------------------
        |
        | The maximum number of plan steps the loop will execute. This prevents
        | runaway plans and controls token consumption. The plan itself will be
        | truncated to this limit if it exceeds it.
        |
        */

        'max_steps' => (int) env('AGENTIC_PLAN_MAX_STEPS', 10),

        /*
        |--------------------------------------------------------------------------
        | Allow Replanning
        |--------------------------------------------------------------------------
        |
        | When true, the loop can adaptively revise the plan if a step fails.
        | The LLM will create a new plan accounting for completed steps and
        | the failure. When false, steps execute without revision.
        |
        */

        'allow_replan' => (bool) env('AGENTIC_PLAN_ALLOW_REPLAN', true),

        /*
        |--------------------------------------------------------------------------
        | Maximum Replanning Attempts
        |--------------------------------------------------------------------------
        |
        | The maximum number of times the plan can be revised during a single
        | plan-execute loop. This prevents infinite replanning cycles.
        |
        */

        'max_replans' => (int) env('AGENTIC_PLAN_MAX_REPLANS', 3),

        /*
        |--------------------------------------------------------------------------
        | Throw on Max Steps
        |--------------------------------------------------------------------------
        |
        | When true, a MaxStepsExceededException will be thrown if the loop
        | reaches the maximum step count. When false, the last response
        | will be returned instead.
        |
        */

        'throw_on_max_steps' => (bool) env('AGENTIC_PLAN_THROW_ON_MAX_STEPS', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Chain-of-Thought Loop Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the default behavior of the Chain-of-Thought (CoT)
    | reasoning loop which uses iterative self-reflection. The agent reasons
    | through problems, evaluates its understanding, and continues until
    | confident or max iterations reached.
    |
    */

    'chain_of_thought' => [

        /*
        |--------------------------------------------------------------------------
        | Maximum Reasoning Iterations
        |--------------------------------------------------------------------------
        |
        | The maximum number of reasoning iterations the CoT loop will execute
        | before stopping. Each iteration includes reasoning, optional tool
        | calls, and self-evaluation. This prevents runaway loops and controls
        | token consumption.
        |
        */

        'max_reasoning_iterations' => (int) env('AGENTIC_COT_MAX_ITERATIONS', 5),

        /*
        |--------------------------------------------------------------------------
        | Throw on Max Iterations
        |--------------------------------------------------------------------------
        |
        | When true, a MaxIterationsExceededException will be thrown if the
        | loop reaches the maximum iteration count without the agent expressing
        | confidence. When false, the last response will be returned instead.
        |
        */

        'throw_on_max_iterations' => (bool) env('AGENTIC_COT_THROW_ON_MAX', false),

        /*
        |--------------------------------------------------------------------------
        | Require Confidence Check
        |--------------------------------------------------------------------------
        |
        | When true, the loop looks for explicit confidence markers in the
        | agent's responses to determine when reasoning is complete. When false,
        | the loop terminates as soon as the agent provides a response without
        | tool calls. Default is true for better reasoning control.
        |
        */

        'require_confidence_check' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Skills Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the Agent Skills System which allows agents to
    | dynamically load specialized instructions and resources based on the
    | task at hand. Following the agentskills.io specification.
    |
    */

    'skills' => [

        /*
        |--------------------------------------------------------------------------
        | Enable Skills System
        |--------------------------------------------------------------------------
        |
        | When true, agents can use the HasAgentSkills trait to load and
        | resolve skills. When false, skill-related methods are disabled.
        |
        */

        'enabled' => (bool) env('AGENTIC_SKILLS_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Skills Base Path
        |--------------------------------------------------------------------------
        |
        | The directory where skill folders are stored. Each skill should be
        | in its own subdirectory with a SKILL.md file and optional scripts/,
        | references/, and assets/ subdirectories.
        |
        */

        'path' => env('AGENTIC_SKILLS_PATH', app_path('Skills')),

        /*
        |--------------------------------------------------------------------------
        | Auto-Resolution Enabled
        |--------------------------------------------------------------------------
        |
        | When true, agents will automatically resolve and load relevant skills
        | based on the query content. When false, skills must be manually loaded
        | via ->withSkill() or ->withSkills().
        |
        */

        'auto_resolve' => (bool) env('AGENTIC_SKILLS_AUTO_RESOLVE', false),

        /*
        |--------------------------------------------------------------------------
        | Resolution Threshold
        |--------------------------------------------------------------------------
        |
        | The minimum relevance score (0.0 - 1.0) required for a skill to be
        | auto-loaded. Higher values are more selective, lower values are more
        | inclusive. Typical range: 0.2 - 0.5.
        |
        */

        'resolution_threshold' => (float) env('AGENTIC_SKILLS_THRESHOLD', 0.3),

        /*
        |--------------------------------------------------------------------------
        | Resolution Limit
        |--------------------------------------------------------------------------
        |
        | The maximum number of skills to auto-load for a single query. This
        | prevents context overload by limiting the number of active skills.
        |
        */

        'resolution_limit' => (int) env('AGENTIC_SKILLS_LIMIT', 3),

    ],

    /*
    |--------------------------------------------------------------------------
    | Durable Runs Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the durable runs system, which provides run IDs,
    | checkpointing, resume-after-crash, cancellation, and tool idempotency
    | for agentic loops. Add the HasDurableRuns trait to any agent to opt in.
    |
    */

    'runs' => [

        /*
        |--------------------------------------------------------------------------
        | Cancellation Poll Frequency
        |--------------------------------------------------------------------------
        |
        | How many iterations to wait between database polls for a cancellation
        | signal. 1 = check every iteration (safest). Higher values reduce DB
        | load for long-running loops with many fast iterations.
        |
        */

        'cancellation_poll_every' => (int) env('AGENTIC_RUNS_CANCEL_POLL', 1),

        /*
        |--------------------------------------------------------------------------
        | Tool Idempotency
        |--------------------------------------------------------------------------
        |
        | When true (default), completed tool calls are stored in
        | agent_tool_calls and replayed on resume instead of re-executing.
        | Disable only for fully read-only, stateless tools where re-execution
        | is safe and you want to avoid the extra DB writes.
        |
        */

        'tool_idempotency' => (bool) env('AGENTIC_RUNS_IDEMPOTENCY', true),

        /*
        |--------------------------------------------------------------------------
        | Pruning
        |--------------------------------------------------------------------------
        |
        | Runs and their checkpoints older than `prune_after_days` can be
        | cleaned up by running the artisan command:
        |
        |     php artisan agentic:prune-runs
        |
        | Set to null to disable automatic pruning entirely.
        |
        */

        'prune_after_days' => (int) env('AGENTIC_RUNS_PRUNE_DAYS', 30),

    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Client Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the Model Context Protocol (MCP) client which
    | enables agents to connect to MCP servers and consume their tools,
    | resources, and prompts. Supports both stdio and Streamable HTTP
    | transports targeting the 2025-11-25 protocol revision.
    |
    */

    'mcp' => [

        /*
        |--------------------------------------------------------------------------
        | Enable MCP Client
        |--------------------------------------------------------------------------
        |
        | When true, agents can use the HasMcpClients trait to connect to
        | MCP servers. When false, MCP-related methods are disabled.
        |
        */

        'enabled' => (bool) env('AGENTIC_MCP_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Default Client Capabilities
        |--------------------------------------------------------------------------
        |
        | Controls which capabilities the client declares during initialization.
        | These tell the server what the client supports.
        |
        */

        'capabilities' => [
            'roots' => true,
            'sampling' => true,
            'sampling_tools' => true,
            'elicitation_form' => true,
            'elicitation_url' => true,
        ],

        /*
        |--------------------------------------------------------------------------
        | Client Info
        |--------------------------------------------------------------------------
        |
        | Identification sent to MCP servers during initialization.
        |
        */

        'client_info' => [
            'name' => 'laragentic',
            'version' => '1.0.0',
        ],

        /*
        |--------------------------------------------------------------------------
        | Transport Defaults
        |--------------------------------------------------------------------------
        |
        | Default settings for each transport type.
        |
        */

        'transports' => [
            'stdio' => [
                'shutdown_timeout' => 5,      // seconds before SIGTERM
                'sigterm_timeout' => 3,        // seconds before SIGKILL
            ],
            'http' => [
                'timeout' => 30,              // request timeout in seconds
                'sse_reconnect_delay' => 1000, // milliseconds
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Protocol Settings
        |--------------------------------------------------------------------------
        |
        | Settings for the MCP protocol layer.
        |
        */

        'protocol' => [
            'version' => '2025-11-25',
            'request_timeout' => 30,          // seconds
        ],

        /*
        |--------------------------------------------------------------------------
        | Sampling Settings
        |--------------------------------------------------------------------------
        |
        | Controls how the client handles sampling/createMessage requests
        | from MCP servers.
        |
        */

        'sampling' => [
            'auto_approve' => (bool) env('AGENTIC_MCP_SAMPLING_AUTO_APPROVE', false),
            'max_iterations' => 10,            // tool loop limit
            'rate_limit' => 60,                // requests per minute
        ],

        /*
        |--------------------------------------------------------------------------
        | Logging
        |--------------------------------------------------------------------------
        |
        | Controls how MCP server log messages are forwarded.
        |
        */

        'logging' => [
            'channel' => env('AGENTIC_MCP_LOG_CHANNEL', 'stack'),
            'default_level' => 'info',
        ],

    ],

];
