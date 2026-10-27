<?php

namespace App\Support\Wix\Enums;

enum EvidenceSurface: string
{
    case GraphqlSearch = 'graphql_search';
    case Html = 'html';
    case AssetMetadata = 'asset_metadata';
}
