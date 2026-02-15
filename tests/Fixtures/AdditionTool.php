<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AdditionTool implements Tool
{
    public function name(): string
    {
        return 'add_numbers';
    }

    public function description(): Stringable|string
    {
        return 'Add two numbers together.';
    }

    public function handle(Request $request): Stringable|string
    {
        $a = $request['a'] ?? 0;
        $b = $request['b'] ?? 0;

        return (string) ($a + $b);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'a' => $schema->integer()->required(),
            'b' => $schema->integer()->required(),
        ];
    }
}
