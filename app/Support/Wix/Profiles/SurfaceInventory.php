<?php

namespace App\Support\Wix\Profiles;

use App\Support\Wix\Data\ContractGuard;
use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Enums\EvidenceSurface;

/** Fixed joint inventories. A version identifies scope, not successful parser certification. */
final readonly class SurfaceInventory
{
    public const VERSION = 'technical-2026-10-20.2';

    public const PROFILE_VERSION = 'observed-2026-10-19';

    public function __construct(public string $profile)
    {
        ContractGuard::require(in_array($profile, ['es-co', 'en-ap'], true), 'known inventory profile');
    }

    /** @return list<EvidenceSurface> */
    public function surfaces(string $section): array
    {
        ContractGuard::require(in_array($section, CoverageSections::NAMES, true), 'known fixed inventory section');

        return match ($section) {
            'oe_references','documents' => [EvidenceSurface::Html],
            'media' => [EvidenceSurface::Html, EvidenceSurface::AssetMetadata],
            default => [EvidenceSurface::GraphqlSearch, EvidenceSurface::Html],
        };
    }

    /** @return array<string,string> */
    public function mediaFilter(): array
    {
        return ['metaData' => 'true', 'brand' => 'WIX Filters', 'productView' => 'Front', 'productRelatedAssetType' => 'Product Image', 'mainImage' => 'true'];
    }

    public function supportsPopulatedDocuments(): bool
    {
        return false;
    }

    public function documentGap(): string
    {
        return 'AUTHENTIC_POSITIVE_DOCUMENT_MISSING';
    }

    public function emptyMarker(): string
    {
        return $this->profile === 'es-co' ? 'No hay información disponible' : 'No Data Available';
    }
}
