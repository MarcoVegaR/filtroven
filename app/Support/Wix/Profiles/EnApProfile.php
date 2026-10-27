<?php

namespace App\Support\Wix\Profiles;

final readonly class EnApProfile extends ProfileDefinition
{
    public function __construct()
    {
        parent::__construct('en-ap', 'https://www.wixfilters.com/api/graphql/catalog-prod', 'pcat_wix_my_store_en', 'en');
    }
}
