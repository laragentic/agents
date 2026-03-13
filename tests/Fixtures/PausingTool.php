<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laragentic\Contracts\PausesLoop;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PausingTool implements Tool
{
    public function name(): string
    {
        return 'interactive_widget';
    }

    public function description(): Stringable|string
    {
        return 'Opens an interactive widget that requires user input.';
    }

    public function handle(Request $request): Stringable|string
    {
        return new PausingToolResult([
            'type' => 'widget_render',
            'name' => $request['name'] ?? 'default',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
        ];
    }
}

class PausingToolResult implements PausesLoop
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data) {}

    public function __toString(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR);
    }
}
