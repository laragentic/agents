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
    | MAKER Loop Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the default behavior of the MAKER (Massively
    | Decomposed Agentic Processes with first-to-ahead-by-K Error correction
    | and Red-flagging) loop. MAKER achieves near-zero error rates through
    | task decomposition, multi-agent voting, and uncertainty detection.
    |
    */

    'maker' => [

        /*
        |--------------------------------------------------------------------------
        | Voting K Parameter
        |--------------------------------------------------------------------------
        |
        | The "first-to-ahead-by-K" threshold for consensus. The first answer
        | to achieve a lead of K votes wins. Higher K means more reliable but
        | slower execution.
        |
        | Recommended values:
        | - K=2: Fast, moderate reliability (3-5 votes)
        | - K=3: Balanced (default, 5-7 votes)
        | - K=4: Very reliable but slow (7-9 votes)
        |
        */

        'voting_k' => (int) env('AGENTIC_MAKER_VOTING_K', 3),

        /*
        |--------------------------------------------------------------------------
        | Enable Red-Flagging
        |--------------------------------------------------------------------------
        |
        | When true, the loop detects linguistic markers of uncertainty
        | (e.g., "wait, maybe", "let me reconsider") and circular reasoning
        | patterns. Responses with red flags are discarded and retried.
        |
        */

        'enable_red_flagging' => (bool) env('AGENTIC_MAKER_RED_FLAGGING', true),

        /*
        |--------------------------------------------------------------------------
        | Maximum Decomposition Depth
        |--------------------------------------------------------------------------
        |
        | The maximum recursion depth for task decomposition. This prevents
        | infinite decomposition loops. Tasks at this depth are executed
        | atomically regardless of complexity.
        |
        */

        'max_decomposition_depth' => (int) env('AGENTIC_MAKER_MAX_DEPTH', 10),

        /*
        |--------------------------------------------------------------------------
        | Maximum Iterations
        |--------------------------------------------------------------------------
        |
        | The maximum total steps (decompositions + executions + compositions)
        | before stopping. This prevents runaway loops and controls token
        | consumption.
        |
        */

        'max_iterations' => (int) env('AGENTIC_MAKER_MAX_ITERATIONS', 100),

        /*
        |--------------------------------------------------------------------------
        | Throw on Max Iterations
        |--------------------------------------------------------------------------
        |
        | When true, a MaxIterationsExceededException will be thrown if the
        | loop reaches the maximum iteration count. When false, the last
        | result will be returned instead.
        |
        */

        'throw_on_max_iterations' => (bool) env('AGENTIC_MAKER_THROW_ON_MAX', false),

        /*
        |--------------------------------------------------------------------------
        | Maximum Votes
        |--------------------------------------------------------------------------
        |
        | Safety cap for maximum votes per decision. Prevents pathological
        | cases where consensus is never reached from consuming excessive
        | tokens. After this many votes, the most voted answer wins.
        |
        */

        'max_votes' => (int) env('AGENTIC_MAKER_MAX_VOTES', 7), // Reduced from 15 for speed

    ],

];
