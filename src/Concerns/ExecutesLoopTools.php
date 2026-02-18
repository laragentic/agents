<?php

declare(strict_types=1);

namespace Laragentic\Concerns;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\Request;

/**
 * Provides tool execution helpers that are shared across loop traits
 * (ReActLoop, ChainOfThoughtLoop).
 *
 * Extracting these into a shared concern prevents PHP fatal errors
 * when multiple loop traits are composed on the same agent class,
 * because PHP de-duplicates traits that appear in multiple used traits.
 */
trait ExecutesLoopTools
{
    use HasCallbacks;

    /**
     * Format tool call results into an observation string.
     *
     * Returns a plain list of tool results keyed by tool name.
     * Loop-specific traits may wrap this with additional context
     * (e.g. ReActLoop adds guidance text via buildReActObservation()).
     *
     * Override this method to customize how tool results are formatted.
     *
     * @param  array<int, array{tool: string, arguments: array<string, mixed>, result: string}>  $toolCallRecords
     */
    protected function formatObservation(array $toolCallRecords): string
    {
        $parts = [];

        foreach ($toolCallRecords as $record) {
            $parts[] = "[{$record['tool']}]: {$record['result']}";
        }

        return implode("\n", $parts);
    }

    /**
     * Execute all tool calls from the LLM response, firing lifecycle
     * callbacks before and after each call.
     *
     * @return array<int, array{tool: string, arguments: array<string, mixed>, result: string}>
     */
    protected function executeLoopToolCalls(AgentResponse $response, int $iteration): array
    {
        $tools = $this->resolveToolMap();
        $records = [];

        foreach ($response->toolCalls as $toolCall) {
            $toolName = $toolCall->name;
            $arguments = $toolCall->arguments;

            $this->fireCallbacks('beforeAction', $toolName, $arguments, $iteration);

            $result = $this->executeLoopTool($tools, $toolName, $arguments);

            $this->fireCallbacks('afterAction', $toolName, $arguments, $result, $iteration);

            $records[] = [
                'tool' => $toolName,
                'arguments' => $arguments,
                'result' => $result,
            ];
        }

        return $records;
    }

    /**
     * Execute a single tool by name with the given arguments.
     *
     * Tools receive a Laravel\Ai\Tools\Request instance, matching the
     * signature defined by the Laravel\Ai\Contracts\Tool interface.
     */
    protected function executeLoopTool(array $tools, string $toolName, array $arguments): string
    {
        $tool = $tools[$toolName] ?? null;

        if ($tool === null) {
            return "Error: Tool '{$toolName}' not found.";
        }

        try {
            return (string) $tool->handle(new Request($arguments));
        } catch (\Throwable $e) {
            return "Error executing tool '{$toolName}': {$e->getMessage()}";
        }
    }

    /**
     * Build a name-keyed map of available tools from the agent.
     *
     * Follows the Laravel AI SDK convention: uses $tool->name() if the
     * method exists, otherwise falls back to the class basename.
     *
     * @return array<string, Tool>
     */
    protected function resolveToolMap(): array
    {
        $tools = [];

        if (method_exists($this, 'tools')) {
            foreach ($this->tools() as $tool) {
                if ($tool instanceof Tool) {
                    $name = method_exists($tool, 'name')
                        ? call_user_func([$tool, 'name'])
                        : class_basename($tool);

                    $tools[$name] = $tool;
                }
            }
        }

        return $tools;
    }
}
