<?php

namespace App\Support\Wix\Http;

use App\Support\Wix\Contracts\WixTransport;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\IdentityLedger;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\MediaExtractor;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Profiles\ProfileDefinition;

final class AssetMetadataPaginator
{
    public function __construct(private readonly WixTransport $transport, private readonly SupervisedDocumentReader $reader, private readonly MediaExtractor $media) {}

    /** Commits completed resources once per validated page, including when a later hit interrupts it. */
    public function capture(ProfileDefinition $profile, IdentityLedger $identity, LookupContext $context, string $attempt, \Closure $commit, int $limit = 25): array
    {
        $sku = null;
        foreach ($identity->identity()?->identifiers ?? [] as $identifier) {
            if ($identifier->name === 'sku') {
                $sku = $identifier->value;
            }
        }
        if ($sku === null || $limit < 1 || $limit > 25) {
            throw new OperationalFailure(DiagnosticCode::IdentityUnverified, 'assets');
        }
        $offset = 0;
        $total = null;
        $failure = null;
        $fingerprints = $refs = [];
        for ($page = 0; $page < $context->budget->limits->maxSectionPages; $page++) {
            $context->budget->check('assets');
            $query = $profile->inventory()->mediaFilter() + ['productReferences' => $sku, 'limit' => (string) $limit];
            if ($offset > 0) {
                $query['offset'] = (string) $offset;
            }
            $request = new RequestPlan($attempt, $profile->id, EvidenceSurface::AssetMetadata, 'https://www.wixfilters.com/bin/assets.json?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $profile->headers());
            $response = $this->transport->send($request, $context);
            $refs[] = $context->evidence->capture($response, '/http/status', $response->status);
            if ($response->status !== 200) {
                throw new OperationalFailure(DiagnosticCode::HttpError, 'assets', $attempt, 'media', $refs);
            }
            $json = $this->reader->json($response, $context);
            if (($json['successful'] ?? null) !== true || ! is_int($json['offset'] ?? null) || ! is_int($json['limit'] ?? null)
                || ! is_int($json['total'] ?? null) || ! is_bool($json['hasMore'] ?? null) || ! is_array($json['hits'] ?? null) || ! array_is_list($json['hits'])) {
                throw new OperationalFailure(DiagnosticCode::SchemaError, 'assets', $attempt, 'media', $refs);
            }
            if ($json['offset'] !== $offset || $json['limit'] !== $limit || $json['total'] < 0 || ($total !== null && $total !== $json['total'])
                || count($json['hits']) !== min($limit, max(0, $json['total'] - $offset)) || $json['hasMore'] !== ($offset + count($json['hits']) < $json['total'])) {
                throw new OperationalFailure(DiagnosticCode::PaginationError, 'assets', $attempt, 'media', $refs);
            }
            $total = $json['total'];
            $fingerprint = hash('sha256', json_encode($json['hits'], JSON_THROW_ON_ERROR));
            if (isset($fingerprints[$fingerprint])) {
                throw new OperationalFailure(DiagnosticCode::PaginationError, 'assets', $attempt, 'media', $refs);
            }
            $fingerprints[$fingerprint] = true;
            $pageRef = $context->evidence->capture($response, '/pagination', json_encode(array_intersect_key($json, array_flip(['successful', 'offset', 'limit', 'total', 'hasMore'])), JSON_THROW_ON_ERROR));
            $refs[] = $pageRef;
            $tokens = $this->reader->lexemes($response, $context);
            $items = [];
            try {
                foreach ($json['hits'] as $index => $hit) {
                    $context->budget->check('assets');
                    try {
                        $meta = $hit['metaData'] ?? null;
                        if (! is_array($meta) || ! is_array($meta['productReferences'] ?? null) || ! array_is_list($meta['productReferences'])
                            || ! array_all($meta['productReferences'], fn ($reference): bool => is_string($reference)) || ! in_array($sku, $meta['productReferences'], true)) {
                            throw new OperationalFailure(DiagnosticCode::SchemaError, 'assets', $attempt, 'media', $refs);
                        }
                        foreach (['brand' => 'WIX Filters', 'productView' => 'Front', 'productRelatedAssetType' => 'Product Image', 'mainImage' => 'true'] as $key => $expected) {
                            if (($meta[$key] ?? null) !== $expected) {
                                throw new OperationalFailure(DiagnosticCode::SchemaError, 'assets', $attempt, 'media', $refs);
                            }
                        }
                        $items[] = $this->media->resource($hit, '/hits/'.$index, $tokens, $response, $context, $offset + $index, '/hits/'.$index, true);
                    } catch (OperationalFailure $invalid) {
                        if (! in_array($invalid->diagnosticCode, [DiagnosticCode::SchemaError, DiagnosticCode::UnsupportedSection], true)) {
                            throw $invalid;
                        }
                        $failure = $invalid;
                    }
                }
            } finally {
                $commit($items, $offset, $pageRef);
            }
            $offset += count($json['hits']);
            if (! $json['hasMore']) {
                if ($failure !== null) {
                    throw $failure;
                }

                return $refs;
            }
        }

        throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'assets', $attempt, 'media', $refs);
    }
}
