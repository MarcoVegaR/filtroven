<?php

namespace App\Support\Wix\Data;

/** Inert supervisor output; never a live DOM or executable upstream expression. */
final readonly class DocumentNode
{
    /** @param list<NameValue> $attributes */
    public function __construct(public string $locator, public string $name, public string $text, public array $attributes, public ?string $content = null)
    {
        ContractGuard::require($locator !== '' && array_is_list($attributes), 'located document node');
        foreach ($attributes as $attribute) {
            ContractGuard::require($attribute instanceof NameValue, 'typed node attribute');
        }
    }
}
