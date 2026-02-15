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

];
