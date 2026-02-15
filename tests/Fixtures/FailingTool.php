<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class FailingTool implements Tool
{
    public function name(): string
    {
        return 'failing_tool';
    }

    public function description(): Stringable|string
    {
        return 'A tool that always throws an exception.';
    }

    public function handle(Request $request): Stringable|string
    {
        throw new RuntimeException('Something went wrong');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
