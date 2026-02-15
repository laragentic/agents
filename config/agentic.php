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

];
