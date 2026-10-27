<?php

namespace App\Support\Wix;

use App\Support\Wix\Contracts\WixTransport;
use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\Identifier;
use App\Support\Wix\Data\ProductIdentity;
use App\Support\Wix\Data\ProductSource;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Profiles\ProfileDefinition;

final class DetailVerifier
{
    public function __construct(private readonly WixTransport $transport, private readonly SupervisedDocumentReader $reader) {}

    /** @param array<string,mixed> $candidate */
    public function verify(ProfileDefinition $profile, array $candidate, LookupContext $context, string $attempt): IdentityLedger
    {
        $request = $profile->detail($candidate['urlKey'], $attempt);
        $response = $this->transport->send($request, $context);
        $context->evidence->capture($response, '/http/status', $response->status);
        if ($response->status !== 200) {
            throw new OperationalFailure(DiagnosticCode::HttpError, 'detail', $attempt);
        }
        $document = $this->reader->read($response, $context);
        $context->document = $document;
        $main = null;
        $mainCount = 0;
        $formLocator = $titleLocator = null;
        $designations = $skus = $products = $htmlRefs = [];
        foreach ($document->nodes as $node) {
            $context->budget->check('detail');
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }
            if ($node->name === 'main' && in_array('cmp-page__main', explode(' ', $attributes['class'] ?? ''), true)) {
                $main = $node->locator;
                $mainCount++;
            }
            $inMain = $main !== null && str_starts_with($node->locator, $main.'/');
            if ($inMain && $node->name === 'form' && ($attributes['data-cmp-is'] ?? null) === 'product') {
                $formLocator = $node->locator;
                $skus[] = $attributes['data-product-sku'] ?? null;
                $htmlRefs[] = $context->evidence->capture($response, $node->locator, json_encode(['data-cmp-is' => 'product', 'data-product-sku' => $attributes['data-product-sku'] ?? null], JSON_THROW_ON_ERROR));
            }
            if ($inMain && str_contains($node->locator, '/h1/') && ($attributes['role'] ?? null) === 'name'
                && in_array('productFullDetail__productName', explode(' ', $attributes['class'] ?? ''), true)) {
                $designations[] = $node->text;
                $titleLocator = $node->locator;
                $htmlRefs[] = $context->evidence->capture($response, $node->locator, $node->text);
            }
            if ($node->name === 'script' && ($attributes['type'] ?? null) === 'application/ld+json') {
                $jsonResponse = new CapturedResponse($request, 200, [], $node->text, $response->finalUrl, $response->capturedAt);
                $product = $this->reader->json($jsonResponse, $context);
                if (($product['@type'] ?? null) === 'Product') {
                    $products[] = $product;
                    $htmlRefs[] = $context->evidence->capture($response, $node->locator, $node->text);
                }
            }
        }
        if ($mainCount !== 1 || count($designations) !== 1 || count($skus) !== 1 || count($products) !== 1 || ! is_string($skus[0]) || $skus[0] === ''
            || $formLocator === null || $titleLocator === null || ! str_starts_with($titleLocator, $formLocator.'/')) {
            throw new OperationalFailure(DiagnosticCode::IdentityUnverified, 'detail', $attempt, 'identity', $htmlRefs);
        }
        $product = $products[0];
        if (! is_string($product['brand']['name'] ?? null) || ! is_string($product['sku'] ?? null) || ! is_string($product['name'] ?? null)) {
            throw new OperationalFailure(DiagnosticCode::IdentityUnverified, 'detail', $attempt, 'identity', $htmlRefs);
        }
        if ($designations[0] !== $context->requestedCode || $product['name'] !== 'WIX '.$context->requestedCode || $product['brand']['name'] !== 'WIX'
            || $product['sku'] !== $candidate['sku'] || $skus[0] !== $candidate['sku']) {
            throw new OperationalFailure(DiagnosticCode::IdentityMismatch, 'detail', $attempt, 'identity', $htmlRefs);
        }
        $refs = [...$candidate['evidence_refs'], ...$htmlRefs];
        $identity = new ProductIdentity('WIX', $context->requestedCode, [new Identifier('sku', $candidate['sku'], $refs), new Identifier('urlKey', $candidate['urlKey'], $refs)], $refs);
        $ledger = new IdentityLedger;
        $ledger->confirm($identity, new ProductSource($profile->id, $response->finalUrl ?? $request->url, $response->capturedAt, $htmlRefs));

        return $ledger;
    }
}
