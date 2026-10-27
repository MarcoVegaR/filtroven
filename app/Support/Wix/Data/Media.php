<?php

namespace App\Support\Wix\Data;

final readonly class Media extends Resource
{
    /** @param list<RawField> $declared_metadata @param list<string> $evidence_refs @param list<NameValue> $roles */
    public function __construct(string $reference_raw, ?string $url_resolved, ?string $label_raw, array $declared_metadata, int $order, array $evidence_refs, public ?int $position_published, public ?string $type_declared, public ?string $format_declared, public ?string $mime_declared, public array $roles)
    {
        parent::__construct($reference_raw, $url_resolved, $label_raw, $declared_metadata, $order, $evidence_refs);
    }

    protected static function lists(): array
    {
        return parent::lists() + ['roles' => NameValue::class];
    }
}
