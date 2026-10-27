<?php

use App\Support\Wix\LookupBudget;
use App\Support\Wix\LookupLimits;
use App\Support\Wix\OperationalFailure;

function wixExplicitTestLimits(array $overrides = []): LookupLimits
{
    $limits = require __DIR__.'/../../../config/wix.php';

    return LookupLimits::fromArray(array_replace($limits['limits'], ['input_max_bytes' => 128, 'max_search_pages' => 4, 'max_section_pages' => 4, 'page_size' => 15, 'max_nodes_total' => 250000], $overrides));
}

it('carries the product evaluation baseline without inheriting old eight second experiments', function () {
    $limits = wixExplicitTestLimits();
    expect([$limits->totalTimeoutMs, $limits->connectTimeoutMs, $limits->requestTimeoutMs, $limits->maxRequests, $limits->maxDecodedBytesPerResponse, $limits->maxDecodedBytesTotal])->toBe([20000, 3000, 8000, 13, 2097152, 8388608]);
});

it('uses one monotonic deadline across thirteen predebits and rejects the fourteenth', function () {
    $now = 1000000000;
    $budget = new LookupBudget(wixExplicitTestLimits(), null, fn (): int => $now);
    for ($i = 0; $i < 13; $i++) {
        $budget->begin();
        $budget->finish();
    }
    expect($budget->snapshot()->requests)->toBe(13)->and($budget->deadline)->toBe(21000000000);
    expect(fn () => $budget->begin())->toThrow(OperationalFailure::class, 'budget_exhausted');
    expect($budget->snapshot()->requests)->toBe(13);
});

it('restricts request and DNS windows by caller deadline without resetting it', function () {
    $now = 1000000000;
    $budget = new LookupBudget(wixExplicitTestLimits(), 2000000000, function () use (&$now): int {
        return $now;
    });
    $budget->begin();
    expect($budget->requestDeadline)->toBe(1750000000)->and($budget->connectDeadline)->toBe(1750000000);
    $budget->finish();
    $now = 1750000000;
    expect(fn () => $budget->begin())->toThrow(OperationalFailure::class);
    expect($budget->snapshot()->requests)->toBe(1)->and($budget->deadline)->toBe(2000000000);
});

it('counts offered decoded bytes even when retention must reject the current chunk', function () {
    $budget = new LookupBudget(wixExplicitTestLimits(['max_decoded_bytes_per_response' => 8, 'max_decoded_bytes_total' => 10]));
    $budget->begin();
    $budget->receive(7);
    expect(fn () => $budget->receive(4))->toThrow(OperationalFailure::class);
    $budget->finish();
    expect($budget->snapshot()->bytes)->toBe(11);
    expect(fn () => $budget->begin())->toThrow(OperationalFailure::class);
});

it('shares node accounting and rejects a debit before exceeding the cap', function () {
    $budget = new LookupBudget(wixExplicitTestLimits(['max_nodes_total' => 5]));
    $budget->visit(3);
    expect(fn () => $budget->visit(3))->toThrow(OperationalFailure::class);
    expect($budget->snapshot()->nodes)->toBe(3);
});

it('does not enable manual redirects or retries merely because sends remain', function (string $kind) {
    $budget = new LookupBudget(wixExplicitTestLimits());
    expect(fn () => $budget->begin($kind))->toThrow(OperationalFailure::class);
    expect($budget->snapshot()->requests)->toBe(0);
})->with(['retry', 'redirect']);

it('predebits one pin renewal without extending any operation deadline or enabling HTTP retries', function () {
    $now = 1000000000;
    $budget = new LookupBudget(wixExplicitTestLimits(), clock: function () use (&$now): int {
        return $now;
    });
    $budget->begin();
    $deadlines = [$budget->deadline, $budget->requestDeadline, $budget->connectDeadline];
    $now += 1000000000;
    $budget->refreshPin();
    expect([$budget->deadline, $budget->requestDeadline, $budget->connectDeadline])->toBe($deadlines)
        ->and($budget->snapshot()->requests)->toBe(2)->and($budget->snapshot()->retries)->toBe(0);
    expect(fn () => $budget->refreshPin())->toThrow(OperationalFailure::class);
    $budget->finish();
    expect(fn () => $budget->begin('retry'))->toThrow(OperationalFailure::class);
    expect($budget->snapshot()->requests)->toBe(2);
});

it('refuses a pin renewal when its original connection window has expired despite remaining global time', function () {
    $now = 1000000000;
    $budget = new LookupBudget(wixExplicitTestLimits(), clock: function () use (&$now): int {
        return $now;
    });
    $budget->begin();
    $now = $budget->connectDeadline;
    expect($budget->deadline - $now)->toBe(17000000000);
    expect(fn () => $budget->refreshPin())->toThrow(OperationalFailure::class);
    expect($budget->snapshot()->requests)->toBe(1);
});
