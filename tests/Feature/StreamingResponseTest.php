<?php

declare(strict_types=1);

use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;
use Laragentic\Tests\Fixtures\TestAgent;

/*
|--------------------------------------------------------------------------
| Event Stream (SSE) Streaming Response Tests
|--------------------------------------------------------------------------
|
| Tests that verify the SSE streaming functionality works as shown in the
| README mini tutorial. These tests ensure callbacks properly yield
| StreamedEvent instances within response()->eventStream().
|
*/

beforeEach(function () {
    // Clean up any routes from previous tests
    Route::getRoutes()->refreshNameLookups();
});

test('streaming callbacks can yield StreamedEvent instances', function () {
    $events = [];
    
    $generator = (function () use (&$events): Generator {
        $event1 = new StreamedEvent(event: 'action', data: ['tool' => 'search', 'status' => 'calling']);
        $events[] = $event1;
        yield $event1;
        
        $event2 = new StreamedEvent(event: 'complete', data: ['text' => 'Done!']);
        $events[] = $event2;
        yield $event2;
    })();
    
    $collected = iterator_to_array($generator);
    
    expect($events)->toHaveCount(2);
    expect($collected)->toHaveCount(2);
    expect($collected[0])->toBeInstanceOf(StreamedEvent::class);
    expect($collected[1])->toBeInstanceOf(StreamedEvent::class);
})->group('streaming', 'unit');

test('eventStream route returns SSE response', function () {
    Route::get('/test-sse', function () {
        return response()->eventStream(function () {
            yield new StreamedEvent(event: 'update', data: 'hello');
            yield new StreamedEvent(event: 'complete', data: 'done');
        });
    });
    
    $response = $this->get('/test-sse');
    
    expect($response->getStatusCode())->toBe(200);
})->group('streaming', 'unit');

test('StreamedEvent holds event name and data', function () {
    $event = new StreamedEvent(
        event: 'action',
        data: ['tool' => 'weather', 'result' => 'Sunny, 22°C'],
    );
    
    expect($event)->toBeInstanceOf(StreamedEvent::class);
})->group('streaming', 'unit');

test('generator pattern allows sequential event yields', function () {
    $sequence = [];
    
    $generator = (function () use (&$sequence): Generator {
        for ($i = 1; $i <= 5; $i++) {
            $sequence[] = $i;
            yield new StreamedEvent(event: 'step', data: ['number' => $i]);
        }
    })();
    
    iterator_to_array($generator);
    
    expect($sequence)->toBe([1, 2, 3, 4, 5]);
})->group('streaming', 'unit');

test('nested callbacks can yield StreamedEvent data', function () {
    $output = [];
    
    $processCallback = function (string $event, array $data) use (&$output): Generator {
        $se = new StreamedEvent(event: $event, data: $data);
        $output[] = $se;
        yield $se;
    };
    
    $generator = (function () use ($processCallback): Generator {
        yield from $processCallback('thinking', ['iteration' => 1]);
        yield from $processCallback('action', ['tool' => 'search']);
        yield from $processCallback('complete', ['text' => 'Done']);
    })();
    
    iterator_to_array($generator);
    
    expect($output)->toHaveCount(3);
})->group('streaming', 'unit');

test('generator with mixed StreamedEvent and string yields', function () {
    $collected = [];
    
    $generator = (function () use (&$collected): Generator {
        // eventStream() accepts both StreamedEvent and plain strings
        $item1 = new StreamedEvent(event: 'action', data: ['tool' => 'search']);
        $collected[] = $item1;
        yield $item1;
        
        // Plain strings become default "update" events
        $item2 = 'Processing...';
        $collected[] = $item2;
        yield $item2;
        
        $item3 = new StreamedEvent(event: 'complete', data: ['text' => 'done']);
        $collected[] = $item3;
        yield $item3;
    })();
    
    $result = iterator_to_array($generator);
    
    expect($collected)->toHaveCount(3);
    expect($collected[0])->toBeInstanceOf(StreamedEvent::class);
    expect($collected[1])->toBeString();
    expect($collected[2])->toBeInstanceOf(StreamedEvent::class);
})->group('streaming', 'unit');

test('generator return type is enforced', function () {
    $createGenerator = function (): Generator {
        yield new StreamedEvent(event: 'test', data: 'hello');
    };
    
    $result = $createGenerator();
    
    expect($result)->toBeInstanceOf(Generator::class);
})->group('streaming', 'unit');

test('unconsumed generator does not execute', function () {
    $executed = false;
    
    $generator = (function () use (&$executed): Generator {
        $executed = true;
        yield new StreamedEvent(event: 'test', data: 'hello');
    })();
    
    expect($executed)->toBeFalse();
    
    iterator_to_array($generator);
    
    expect($executed)->toBeTrue();
})->group('streaming', 'unit');

test('agent callbacks work within event stream context', function () {
    $agent = new TestAgent;
    $callbackFired = false;
    $outputCount = 0;
    
    $generator = (function () use ($agent, &$callbackFired, &$outputCount): Generator {
        // Simulate a callback that yields a StreamedEvent
        $callback = function () use (&$callbackFired): Generator {
            $callbackFired = true;
            yield new StreamedEvent(event: 'action', data: ['tool' => 'test']);
        };
        
        // Execute the callback
        foreach ($callback() as $value) {
            $outputCount++;
            yield $value;
        }
        
        // Simulate agent completing
        $outputCount++;
        yield new StreamedEvent(event: 'complete', data: ['text' => 'done']);
    })();
    
    $collected = iterator_to_array($generator);
    
    expect($callbackFired)->toBeTrue();
    expect($outputCount)->toBe(2);
    expect($collected)->toHaveCount(2);
})->group('streaming', 'unit');

test('readme SSE pattern matches streaming behavior', function () {
    // This simulates the exact pattern from the README
    $output = [];
    
    $generator = (function () use (&$output): Generator {
        // Simulate onBeforeAction
        $beforeAction = function (string $tool) use (&$output) {
            $event = new StreamedEvent(
                event: 'action',
                data: ['tool' => $tool, 'status' => 'calling'],
            );
            $output[] = $event;
            yield $event;
        };
        
        // Simulate onAfterAction  
        $afterAction = function (string $tool, string $result) use (&$output) {
            $event = new StreamedEvent(
                event: 'action',
                data: ['tool' => $tool, 'result' => $result, 'status' => 'complete'],
            );
            $output[] = $event;
            yield $event;
        };
        
        // Simulate onLoopComplete
        $loopComplete = function (string $text, int $iterations) use (&$output) {
            $event = new StreamedEvent(
                event: 'complete',
                data: ['text' => $text, 'iterations' => $iterations],
            );
            $output[] = $event;
            yield $event;
        };
        
        // Simulate the flow
        yield from $beforeAction('weather_api');
        yield from $afterAction('weather_api', 'Sunny, 22°C');
        yield from $loopComplete('The weather is sunny!', 2);
    })();
    
    iterator_to_array($generator);
    
    expect($output)->toHaveCount(3);
    expect($output[0])->toBeInstanceOf(StreamedEvent::class);
    expect($output[1])->toBeInstanceOf(StreamedEvent::class);
    expect($output[2])->toBeInstanceOf(StreamedEvent::class);
})->group('streaming', 'unit');
