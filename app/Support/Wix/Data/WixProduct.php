<?php

namespace App\Support\Wix\Data;

final readonly class WixProduct extends ContractData
{
    /** @param list<Observation> $dimensions @param list<Observation> $specifications @param list<Application> $applications @param list<OeReference> $oe_references @param list<Media> $media @param list<Resource> $documents @param list<Observation> $observations */
    public function __construct(public ProductIdentity $identity, public array $dimensions, public array $specifications, public array $applications, public array $oe_references, public array $media, public array $documents, public array $observations)
    {
        $this->validateLists();
    }

    protected static function lists(): array
    {
        return ['dimensions' => Observation::class, 'specifications' => Observation::class, 'applications' => Application::class, 'oe_references' => OeReference::class, 'media' => Media::class, 'documents' => Resource::class, 'observations' => Observation::class];
    }
}
