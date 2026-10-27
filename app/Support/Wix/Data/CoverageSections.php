<?php

namespace App\Support\Wix\Data;

final readonly class CoverageSections extends ContractData
{
    public const NAMES = ['identity', 'dimensions', 'specifications', 'applications', 'oe_references', 'media', 'documents', 'observations'];

    public function __construct(public SectionCoverage $identity, public SectionCoverage $dimensions, public SectionCoverage $specifications, public SectionCoverage $applications, public SectionCoverage $oe_references, public SectionCoverage $media, public SectionCoverage $documents, public SectionCoverage $observations) {}
}
