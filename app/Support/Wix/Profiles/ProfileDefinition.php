<?php

namespace App\Support\Wix\Profiles;

use App\Support\Wix\Data\NameValue;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\OperationalFailure;

abstract readonly class ProfileDefinition
{
    public const Query = 'query($search:String!$currentPage:Int!$pageSize:Int!$filterBy:TYPE_OF_FILTER){catalogSearch:product_search_name(search:$search currentPage:$currentPage pageSize:$pageSize filterBy:$filterBy){availableFilters:available_filters{label totalProducts:total_products code __typename}notFoundSearchTerms:not_found_search_terms items{product{sku urlKey:url_key name attributes:attributes_value{key value adminValue:admin_value __typename}references{referenceTypeId:reference_type_id referenceTypeName:reference_type_name referenceTypeDescription:reference_type_description referenceProducts:reference_products{salesDesignation:sales_designation urlKey:url_key __typename}__typename}__typename}__typename}pageInfo:page_info{pageSize:page_size currentPage:current_page totalPages:total_pages __typename}totalCount:total_count __typename}}';

    public function __construct(public string $id, public string $endpoint, public string $store, public string $locale)
    {
        if (! in_array($id, ['es-co', 'en-ap'], true)) {
            throw new \LogicException('Unknown closed profile');
        }
    }

    public function version(): string
    {
        return SurfaceInventory::PROFILE_VERSION;
    }

    public function inventory(): SurfaceInventory
    {
        return new SurfaceInventory($this->id);
    }

    /** @return list<NameValue> */
    public function headers(bool $graphql = false): array
    {
        $headers = [new NameValue('User-Agent', 'FiltrovenPublicResearch/1.0 PHP-native low-volume'), new NameValue('Accept', '*/*'), new NameValue('Accept-Encoding', 'identity')];

        return $graphql ? [...$headers, new NameValue('Store', $this->store), new NameValue('Content-Type', 'application/json')] : $headers;
    }

    public function search(string $code, int $page, int $size, string $attempt): RequestPlan
    {
        if ($page < 1 || $size < 1) {
            throw new \LogicException('Positive pagination arguments required');
        }
        $variables = ['search' => $code, 'currentPage' => $page, 'pageSize' => $size, 'filterBy' => 'ALL_FILTER'];
        $url = $this->endpoint.'?'.http_build_query(['query' => self::Query, 'variables' => json_encode($variables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], '', '&', PHP_QUERY_RFC3986);

        return new RequestPlan($attempt, $this->id, EvidenceSurface::GraphqlSearch, $url, $this->headers(true));
    }

    /** URL key is a published source identifier; never derived from a designation/SKU. */
    public function detail(string $urlKey, string $attempt): RequestPlan
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $urlKey) !== 1) {
            throw new OperationalFailure(DiagnosticCode::IdentityUnverified, 'detail');
        }

        return new RequestPlan($attempt, $this->id, EvidenceSurface::Html, 'https://www.wixfilters.com/'.$this->id.'/catalog/results/product.html/'.$urlKey.'.html', $this->headers());
    }

    /** @return array<string,list<string>> Corpus bindings are provenance, not positive-support certification. */
    public function fixtureBindings(): array
    {
        $detail = $this->id === 'es-co' ? 'es-co-51515-detail' : 'en-ap-51515R-detail';
        $search = $this->id === 'es-co' ? 'es-co-51515-search-p1-s1' : 'en-ap-51515R-search-p1-s15';
        $populated = $this->id === 'es-co' ? 'es-co-42831-detail' : 'en-ap-51515-detail';
        $asset = $this->id === 'es-co' ? 'es-co-51515_WIX-assets-l25-o0' : 'en-ap-51515R_WIX-assets-l25-o0';

        return ['identity' => [$search, $detail], 'dimensions' => [$search, $detail], 'specifications' => [$search, $detail],
            'applications' => [$search, $detail, $populated], 'oe_references' => [$detail, $populated], 'media' => [$detail, $asset],
            'documents' => [$detail], 'observations' => [$search, $detail]];
    }
}
