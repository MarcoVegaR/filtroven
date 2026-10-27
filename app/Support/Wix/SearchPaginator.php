<?php

namespace App\Support\Wix;

use App\Support\Wix\Contracts\WixTransport;
use App\Support\Wix\Data\SearchOutcome;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Profiles\ProfileDefinition;

final class SearchPaginator
{
    public function __construct(private readonly WixTransport $transport, private readonly SupervisedDocumentReader $reader) {}

    public function search(ProfileDefinition $profile, LookupContext $context, string $attempt): SearchOutcome
    {
        $resolver = new CandidateResolver;
        $pages = $refs = $fingerprints = [];
        $total = $totalPages = null;
        $seen = 0;
        try {
            for ($page = 1; $page <= $context->budget->limits->maxSearchPages; $page++) {
                $context->budget->check('search');
                $size = $context->budget->limits->pageSize;
                $response = $this->transport->send($profile->search($context->requestedCode, $page, $size, $attempt), $context);
                $refs[] = $context->evidence->capture($response, '/http/status', $response->status);
                if ($response->status !== 200) {
                    throw new OperationalFailure(DiagnosticCode::HttpError, 'search');
                }
                $json = $this->reader->json($response, $context);
                if (isset($json['errors']) && $json['errors'] !== []) {
                    throw new OperationalFailure(DiagnosticCode::GraphqlError, 'search');
                }
                $search = $json['data']['catalogSearch'] ?? null;
                if (! is_array($search) || ! is_array($search['items'] ?? null) || ! array_is_list($search['items']) || ! is_array($search['pageInfo'] ?? null)) {
                    throw new OperationalFailure(DiagnosticCode::SchemaError, 'search');
                }
                $info = $search['pageInfo'];
                foreach (['currentPage', 'pageSize', 'totalPages'] as $key) {
                    if (! is_int($info[$key] ?? null)) {
                        throw new OperationalFailure(DiagnosticCode::PaginationError, 'search');
                    }
                }
                $count = $search['totalCount'] ?? null;
                if (! is_int($count) || $count < 0 || $info['currentPage'] !== $page || $info['pageSize'] !== $size
                    || $info['totalPages'] !== ($count === 0 ? 0 : intdiv($count - 1, $size) + 1)
                    || ($total !== null && ($total !== $count || $totalPages !== $info['totalPages']))) {
                    throw new OperationalFailure(DiagnosticCode::PaginationError, 'search');
                }
                $total = $count;
                $totalPages = $info['totalPages'];
                $expected = $count === 0 ? 0 : min($size, max(0, $count - ($page - 1) * $size));
                if (count($search['items']) !== $expected) {
                    throw new OperationalFailure(DiagnosticCode::PaginationError, 'search');
                }
                $fingerprint = hash('sha256', json_encode($search['items'], JSON_THROW_ON_ERROR));
                if (in_array($fingerprint, $fingerprints, true)) {
                    throw new OperationalFailure(DiagnosticCode::PaginationError, 'search');
                }
                $fingerprints[] = $fingerprint;
                $pageRef = $context->evidence->capture($response, '/data/catalogSearch/pageInfo', json_encode(['pageInfo' => $info, 'totalCount' => $count, 'itemCount' => count($search['items'])], JSON_THROW_ON_ERROR));
                $refs[] = $pageRef;
                $pages[] = $page;
                foreach ($search['items'] as $index => $item) {
                    if (! is_array($item['product'] ?? null)) {
                        throw new OperationalFailure(DiagnosticCode::SchemaError, 'search');
                    }
                    $product = $item['product'];
                    $identity = array_intersect_key($product, array_flip(['sku', 'urlKey']));
                    if (! is_array($product['attributes'] ?? null) || ! array_is_list($product['attributes'])) {
                        throw new OperationalFailure(DiagnosticCode::SchemaError, 'search');
                    }
                    $identity['attributes'] = [];
                    foreach ($product['attributes'] as $attribute) {
                        if (! is_array($attribute) || ! is_string($attribute['key'] ?? null)) {
                            throw new OperationalFailure(DiagnosticCode::SchemaError, 'search');
                        }
                        if (in_array($attribute['key'], ['product_brand', 'sales_designation'], true)) {
                            $identity['attributes'][] = $attribute;
                        }
                    }
                    $ref = $context->evidence->capture($response, '/data/catalogSearch/items/'.$index.'/product', json_encode($identity, JSON_THROW_ON_ERROR));
                    $refs[] = $ref;
                    $resolver->admit($product, $context->requestedCode, $ref);
                    foreach ($resolver->all() as $candidate) {
                        if ($candidate['sku'] === $product['sku'] && $candidate['urlKey'] === $product['urlKey']) {
                            $context->responses[$ref] = $response;
                        }
                    }
                    if (count($resolver->all()) > 1) {
                        return new SearchOutcome($profile->id, false, $resolver->all(), $refs, $pages, DiagnosticCode::AmbiguousIdentity);
                    }
                }
                $seen += count($search['items']);
                if ($page >= $totalPages && $seen === $total) {
                    return new SearchOutcome($profile->id, true, $resolver->all(), $refs, $pages);
                }
            }
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'search');
        } catch (OperationalFailure $failure) {
            return new SearchOutcome($profile->id, false, $resolver->all(), $refs, $pages, $failure->diagnosticCode, $failure->stage);
        }
    }
}
