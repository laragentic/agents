<?php

declare(strict_types=1);

use Laragentic\Runs\RunStatus;

test('terminal statuses are correctly identified', function () {
    expect(RunStatus::Completed->isTerminal())->toBeTrue();
    expect(RunStatus::Failed->isTerminal())->toBeTrue();
    expect(RunStatus::Cancelled->isTerminal())->toBeTrue();

    expect(RunStatus::Pending->isTerminal())->toBeFalse();
    expect(RunStatus::Running->isTerminal())->toBeFalse();
    expect(RunStatus::Paused->isTerminal())->toBeFalse();
});

test('resumable statuses are correctly identified', function () {
    expect(RunStatus::Paused->isResumable())->toBeTrue();
    expect(RunStatus::Running->isResumable())->toBeTrue();
    expect(RunStatus::Pending->isResumable())->toBeTrue();

    expect(RunStatus::Completed->isResumable())->toBeFalse();
    expect(RunStatus::Failed->isResumable())->toBeFalse();
    expect(RunStatus::Cancelled->isResumable())->toBeFalse();
});

test('enum values match expected database strings', function () {
    expect(RunStatus::Pending->value)->toBe('pending');
    expect(RunStatus::Running->value)->toBe('running');
    expect(RunStatus::Completed->value)->toBe('completed');
    expect(RunStatus::Failed->value)->toBe('failed');
    expect(RunStatus::Cancelled->value)->toBe('cancelled');
    expect(RunStatus::Paused->value)->toBe('paused');
});

test('can be constructed from string value', function () {
    expect(RunStatus::from('pending'))->toBe(RunStatus::Pending);
    expect(RunStatus::from('running'))->toBe(RunStatus::Running);
    expect(RunStatus::from('completed'))->toBe(RunStatus::Completed);
    expect(RunStatus::from('cancelled'))->toBe(RunStatus::Cancelled);
    expect(RunStatus::from('paused'))->toBe(RunStatus::Paused);
});
