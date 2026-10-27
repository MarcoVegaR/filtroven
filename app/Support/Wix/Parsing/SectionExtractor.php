<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\IdentityLedger;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;

final class SectionExtractor
{
    public function __construct(private readonly ValueReader $values, private readonly SupervisedDocumentReader $reader) {}

    /** Every selected source occurrence remains separate, even if a repeated page reference was deduplicated for identity. */
    public function graph(string $section, IdentityLedger $identity, LookupContext $context, ?\Closure $commit = null): array
    {
        $out = [];
        $found = false;
        $failure = null;
        foreach ($context->responses as $reference => $response) {
            if ($response->request->profile !== $identity->source()?->profile) {
                continue;
            }
            $found = true;
            $tokens = $this->reader->lexemes($response, $context);
            $base = $context->evidence->all()[$reference]->locator;
            $attributeBase = $base.'/attributes/';
            foreach ($tokens as $path => $token) {
                $context->budget->check('attributes');
                if (! str_starts_with($path, $attributeBase) || ! str_ends_with($path, '/key')) {
                    continue;
                }
                $key = $this->values->field('key', $token, [$reference]);
                if ($key->raw_type->value !== 'string') {
                    throw new OperationalFailure(DiagnosticCode::SchemaError, 'attributes');
                }
                $prefix = substr($path, 0, -4);
                $sourceKey = $key->value_raw;
                $dimension = in_array($sourceKey, TechnicalSectionExtractor::Dimensions, true);
                $excluded = in_array($sourceKey, ['channel_active', 'qrcode_scantrust', 'publish_product', 'product_status_aa', 'product_shape_group_searchable_dimensions', 'product_shape_group_product_shape_image'], true)
                    || preg_match('/(?:price|analytics|tracking)/i', $sourceKey);
                $specification = ! $dimension && ! $excluded && preg_match('/(?:valve|pressure|temperature|flow_rate|filter_media|beta_ratio|micron)/', $sourceKey);
                $target = $dimension ? 'dimensions' : ($specification ? 'specifications' : 'observations');
                if ($section === 'applications' || $excluded || $section !== $target) {
                    continue;
                }
                $before = count($out);
                try {
                    $proof = $tokens[$prefix] ?? null;
                    if ($proof === null || $proof['lexeme'] === null) {
                        throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'attributes');
                    }
                    $ref = $context->evidence->capture($response, $prefix, $proof['lexeme']);
                    $raw = $this->values->field('value', $tokens[$prefix.'/value'] ?? null, [$ref]);
                    $label = $this->string($tokens[$prefix.'/label'] ?? null);
                    $unit = $this->string($tokens[$prefix.'/unit'] ?? null);
                    $admin = $this->string($tokens[$prefix.'/adminValue'] ?? null);
                    $out[] = $this->values->observation($raw, $label, $unit, $admin, 'graphql.attributes', count($out), $sourceKey);
                    foreach (['adminValue', 'label', 'unit'] as $property) {
                        $extra = $tokens[$prefix.'/'.$property] ?? null;
                        if ($extra !== null && ! in_array($extra['raw_type'], ['string', 'null'], true)) {
                            $out[] = $this->values->observation($this->values->field($property, $extra, [$ref]), null, null, null, 'graphql.attributes', count($out), $sourceKey.'.'.$property);
                        }
                    }
                    foreach ($tokens as $extraPath => $extra) {
                        $context->budget->check('attribute-qualifier');
                        if (! str_starts_with($extraPath, $prefix.'/')) {
                            continue;
                        }
                        $property = substr($extraPath, strlen($prefix) + 1);
                        if (str_contains($property, '/') || in_array($property, ['key', 'value', 'adminValue', 'label', 'unit', '__typename'], true)) {
                            continue;
                        }
                        $out[] = $this->values->observation($this->values->field($property, $extra, [$ref]), null, null, null, 'graphql.attributes', count($out), $sourceKey.'.'.$property);
                    }
                    if (in_array($sourceKey, ['product_brand', 'sales_designation'], true) && $raw->raw_type->value === 'string') {
                        $identity->compare($sourceKey === 'product_brand' ? 'brand' : $sourceKey, $raw->value_raw, $context, $ref);
                    }
                } catch (OperationalFailure $invalid) {
                    if (! in_array($invalid->diagnosticCode, [DiagnosticCode::SchemaError, DiagnosticCode::UnsupportedSection], true)) {
                        throw $invalid;
                    }
                    $failure = $invalid;
                } finally {
                    if ($commit !== null && count($out) > $before) {
                        $commit(array_slice($out, $before));
                    }
                }
            }
        }
        if (! $found) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'attributes');
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $commit === null ? $out : [];
    }

    private function string(?array $token): ?string
    {
        return $token !== null && $token['raw_type'] === 'string' && $token['lexeme'] !== null ? json_decode($token['lexeme'], true, 2, JSON_THROW_ON_ERROR) : null;
    }

    /** HTML summary/description are observations, never invented fitments or normalized dimensions. */
    public function descriptions(HtmlSurface $html): array
    {
        $out = [];
        foreach ($html->document->nodes as $parent) {
            if (! $html->hasClass($parent, 'cmp-product__description-text') && ! $html->hasClass($parent, 'cmp-product__summary')) {
                continue;
            }
            $html->assertStatic($parent);
            foreach ($html->descendants($parent) as $node) {
                if (in_array($node->name, ['li', 'p'], true)) {
                    $ref = $html->reference($node);
                    $out[] = $this->values->observation($this->values->field('value', ['raw_type' => 'string', 'lexeme' => json_encode($html->text($node), JSON_THROW_ON_ERROR)], [$ref]), null, null, null, 'html.description-summary', count($out));
                }
            }
        }

        return $out;
    }
}
