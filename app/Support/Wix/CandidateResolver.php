<?php

namespace App\Support\Wix;

use App\Support\Wix\Enums\DiagnosticCode;

final class CandidateResolver
{
    /** @var list<array<string,mixed>> */
    private array $candidates = [];

    /** @var array<string,array{sku:string,urlKey:string,brand:string,admin:string,designation:string}> */
    private array $references = [];

    /** @param array<string,mixed> $product */
    public function admit(array $product, string $requested, string $reference): void
    {
        $attributes = $product['attributes'] ?? null;
        if (! is_array($attributes) || ! array_is_list($attributes)) {
            throw new OperationalFailure(DiagnosticCode::SchemaError, 'candidate');
        }
        $facts = [];
        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || ! is_string($attribute['key'] ?? null)) {
                throw new OperationalFailure(DiagnosticCode::SchemaError, 'candidate');
            }
            if (in_array($attribute['key'], ['product_brand', 'sales_designation'], true)) {
                if (isset($facts[$attribute['key']]) && $facts[$attribute['key']] !== $attribute) {
                    throw new OperationalFailure(DiagnosticCode::IdentityConflict, 'candidate');
                }
                $facts[$attribute['key']] = $attribute;
            }
        }
        if (! isset($facts['product_brand'], $facts['sales_designation'])) {
            throw new OperationalFailure(DiagnosticCode::IdentityUnverified, 'candidate');
        }
        if (! is_string($facts['product_brand']['value'] ?? null) || ! is_string($facts['product_brand']['adminValue'] ?? null)
            || ! is_string($facts['sales_designation']['value'] ?? null)) {
            throw new OperationalFailure(DiagnosticCode::IdentityUnverified, 'candidate');
        }
        if (is_string($product['sku'] ?? null) && $product['sku'] !== '' && is_string($product['urlKey'] ?? null) && $product['urlKey'] !== '') {
            $identity = ['sku' => $product['sku'], 'urlKey' => $product['urlKey'], 'brand' => $facts['product_brand']['value'],
                'admin' => $facts['product_brand']['adminValue'], 'designation' => $facts['sales_designation']['value']];
            foreach (['sku', 'urlKey'] as $field) {
                $key = $field.':'.$product[$field];
                if (isset($this->references[$key]) && $this->references[$key] !== $identity) {
                    throw new OperationalFailure(DiagnosticCode::IdentityConflict, 'candidate');
                }
                $this->references[$key] = $identity;
            }
        }
        if (($facts['product_brand']['value'] ?? null) !== 'WIX' || ($facts['product_brand']['adminValue'] ?? null) !== 'wix'
            || ($facts['sales_designation']['value'] ?? null) !== $requested) {
            return;
        }
        foreach (['sku', 'urlKey'] as $field) {
            if (! is_string($product[$field] ?? null) || $product[$field] === '') {
                throw new OperationalFailure(DiagnosticCode::IdentityUnverified, 'candidate');
            }
        }
        foreach ($this->candidates as &$candidate) {
            $sameSku = $candidate['sku'] === $product['sku'];
            $sameUrl = $candidate['urlKey'] === $product['urlKey'];
            if ($sameSku !== $sameUrl) {
                throw new OperationalFailure(DiagnosticCode::IdentityConflict, 'candidate');
            }
            if ($sameSku && $sameUrl) {
                $candidate['evidence_refs'][] = $reference;

                return;
            }
        }
        unset($candidate);
        $this->candidates[] = ['sku' => $product['sku'], 'urlKey' => $product['urlKey'], 'brand' => 'WIX', 'code' => $requested, 'evidence_refs' => [$reference]];
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->candidates;
    }
}
