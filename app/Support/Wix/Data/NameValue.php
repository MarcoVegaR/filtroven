<?php

namespace App\Support\Wix\Data;

final readonly class NameValue extends ContractData
{
    public function __construct(public string $name, public string $value) {}
}
