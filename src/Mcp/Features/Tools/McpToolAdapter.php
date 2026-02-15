<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Adapts an MCP tool definition to the Laravel AI Tool interface.
 *
 * This allows MCP tools to seamlessly integrate with Laragentic's
 * tool execution system across all loop types.
 */
class McpToolAdapter implements Tool
{
    public function __construct(
        private readonly ToolDefinition $definition,
        private readonly McpToolProxy $proxy,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function description(): Stringable|string
    {
        return $this->definition->description;
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->proxy->call(
            $this->definition->name,
            $request->toArray(),
        );

        return (string) $result;
    }

    public function schema(JsonSchema $schema): array
    {
        $schemaDefinitions = [];

        foreach ($this->definition->properties() as $name => $property) {
            $type = $property['type'] ?? 'string';
            $description = $property['description'] ?? '';
            $isRequired = in_array($name, $this->definition->required(), true);

            $builder = match ($type) {
                'integer' => $schema->integer(),
                'number' => $schema->number(),
                'boolean' => $schema->boolean(),
                'array' => $schema->array(),
                default => $schema->string(),
            };

            if ($description) {
                $builder = $builder->description($description);
            }

            if ($isRequired) {
                $builder = $builder->required();
            }

            $schemaDefinitions[$name] = $builder;
        }

        return $schemaDefinitions;
    }

    /**
     * Get the underlying MCP tool definition.
     */
    public function toolDefinition(): ToolDefinition
    {
        return $this->definition;
    }
}
