<?php

namespace App\Support\Wix\Contracts;

use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\LookupContext;

interface WixTransport
{
    public function send(RequestPlan $request, LookupContext $context): CapturedResponse;
}
