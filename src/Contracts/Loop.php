<?php

declare(strict_types=1);

namespace Laragentic\Contracts;

use Laragentic\Loops\LoopResult;

interface Loop
{
    /**
     * Execute the agentic loop with the given prompt.
     */
    public function loop(string $prompt): LoopResult;

    /**
     * Set the maximum number of iterations for the loop.
     */
    public function maxIterations(int $max): static;
}
