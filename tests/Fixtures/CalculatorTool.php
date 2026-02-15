<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CalculatorTool implements Tool
{
    public function name(): string
    {
        return 'calculator';
    }

    public function description(): Stringable|string
    {
        return 'Performs basic arithmetic calculations. Provide a mathematical expression as a string (e.g. "2 + 3", "847 * 293", "100 / 4").';
    }

    public function handle(Request $request): Stringable|string
    {
        $expression = $request['expression'] ?? '';

        // Only allow safe characters: digits, operators, spaces, decimal points, parentheses
        if (! preg_match('/^[\d\s\+\-\*\/\.\(\)]+$/', $expression)) {
            return 'Error: Invalid expression. Only numbers and +, -, *, / operators are allowed.';
        }

        try {
            $result = eval("return (float)($expression);");

            // Format: remove trailing zeros for clean output
            return rtrim(rtrim(number_format((float) $result, 10, '.', ''), '0'), '.');
        } catch (\Throwable $e) {
            return "Error: Could not evaluate expression '{$expression}'.";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expression' => $schema->string()
                ->description('A mathematical expression to evaluate, e.g. "847 * 293"')
                ->required(),
        ];
    }
}
