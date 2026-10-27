<?php

use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Runtime\Admission;
use Tests\Support\Wix\ProductionHarness;

require __DIR__.'/../../../vendor/autoload.php';

$admission = new Admission($argv[1], (int) $argv[2], (int) $argv[3], 'linux-cli-one-host-shared-inode');
$budget = ProductionHarness::context('51515', ['total_timeout_ms' => 500, 'request_timeout_ms' => 300, 'connect_timeout_ms' => 200])->budget;
try {
    $admission->acquire($budget);
    $admission->release();
    exit(2);
} catch (OperationalFailure $failure) {
    exit($failure->diagnosticCode->value === 'budget_exhausted' && $budget->snapshot()->requests === 0 ? 0 : 3);
}
