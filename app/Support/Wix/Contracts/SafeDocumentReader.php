<?php

namespace App\Support\Wix\Contracts;

use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\ParsedDocument;
use App\Support\Wix\LookupContext;

interface SafeDocumentReader
{
    public function read(CapturedResponse $response, LookupContext $context): ParsedDocument;
}
