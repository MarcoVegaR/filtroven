<?php

namespace App\Support\Wix\Profiles;

final readonly class EsCoProfile extends ProfileDefinition
{
    public function __construct()
    {
        parent::__construct('es-co', 'https://www.wixfilters.com/api/graphql/catalog-stage', 'pcat_wix_co_store_es', 'es');
    }
}
