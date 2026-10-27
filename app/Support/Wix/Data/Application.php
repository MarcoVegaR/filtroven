<?php

namespace App\Support\Wix\Data;

final readonly class Application extends ContractData
{
    /** @param list<Cell> $cells @param list<NameValue> $source_identifiers @param list<Occurrence> $occurrences @param list<string> $evidence_refs */
    public function __construct(public ?string $manufacturer_raw, public ?string $model_raw, public ?string $series_raw, public ?string $variant_raw, public ?string $engine_raw, public ?string $period_raw, public array $cells, public array $source_identifiers, public Navigation $navigation, public array $occurrences, public int $order, public array $evidence_refs)
    {
        $this->validateLists();
        ContractGuard::refs($evidence_refs);
        ContractGuard::require($order >= 0 && $occurrences !== [], 'logical application occurrences/order');
    }

    protected static function lists(): array
    {
        return ['cells' => Cell::class, 'source_identifiers' => NameValue::class, 'occurrences' => Occurrence::class, 'evidence_refs' => 'string'];
    }
}
