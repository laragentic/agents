<?php

declare(strict_types=1);

namespace Laragentic\Signals;

enum QuestionType: string
{
    case FreeText = 'free_text';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
}
