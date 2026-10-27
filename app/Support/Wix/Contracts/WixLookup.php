<?php

namespace App\Support\Wix\Contracts;

use App\Support\Wix\Data\WixLookupResult;

interface WixLookup
{
    public function fetch(string $wixCode): WixLookupResult;
}
