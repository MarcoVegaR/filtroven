<?php

namespace App\Support\Wix\Data;

final readonly class Navigation extends ContractData
{
    /** @param list<NameValue> $params */
    public function __construct(public ?string $path, public array $params)
    {
        $this->validateLists();
    }

    protected static function lists(): array
    {
        return ['params' => NameValue::class];
    }
}
