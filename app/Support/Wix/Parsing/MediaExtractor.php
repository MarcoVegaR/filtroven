<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\Media;
use App\Support\Wix\Data\NameValue;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;

final class MediaExtractor
{
    public function __construct(private readonly ValueReader $values, private readonly SupervisedDocumentReader $reader) {}

    /** No new outbound origin approval: scene7/templates remain raw, not actionable URLs. */
    public function resolve(string $raw): ?string
    {
        $url = str_starts_with($raw, '/content/dam/wix-filters/') ? 'https://www.wixfilters.com'.$raw : $raw;
        if (preg_match('~^https://www\.wixfilters\.com/content/dam/wix-filters/[A-Za-z0-9_./%-]+$~D', $url) !== 1
            || str_contains(rawurldecode($url), '..') || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url)) {
            return null;
        }

        return $url;
    }

    /** @return list<Media> */
    public function gallery(HtmlSurface $html, ?\Closure $commit = null): array
    {
        $matches = array_values(array_filter($html->document->nodes, fn ($n): bool => $html->attribute($n, 'data-g-name') === 'ProductGallery'));
        if (count($matches) !== 1) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'gallery');
        }
        $node = $matches[0];
        $raw = $html->attribute($node, 'data-gallery-items');
        if ($raw === null || $raw !== $html->attribute($node, 'data-g-binding-gallery-items')) {
            throw new OperationalFailure(DiagnosticCode::SchemaError, 'gallery');
        }
        $source = $html->document->response;
        $response = new CapturedResponse($source->request, $source->status, [], $raw, $source->finalUrl, $source->capturedAt);
        $json = $this->reader->json(new CapturedResponse($source->request, 200, [], '{"items":'.$raw.'}', $source->finalUrl, $source->capturedAt), $html->context);
        if (! is_array($json['items'] ?? null) || ! array_is_list($json['items'])) {
            throw new OperationalFailure(DiagnosticCode::SchemaError, 'gallery');
        }
        $tokens = $this->reader->lexemes($response, $html->context);
        $out = [];
        $failure = null;
        foreach ($json['items'] as $index => $item) {
            $html->context->budget->check('gallery');
            try {
                $resource = $this->resource($item, '/'.$index, $tokens, $source, $html->context, count($out), $node->locator.'/@data-gallery-items/'.$index, false);
                $out[] = $resource;
                if ($commit !== null) {
                    $commit([$resource]);
                }
            } catch (OperationalFailure $invalid) {
                if (! in_array($invalid->diagnosticCode, [DiagnosticCode::SchemaError, DiagnosticCode::UnsupportedSection], true)) {
                    throw $invalid;
                }
                $failure = $invalid;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $commit === null ? $out : [];
    }

    public function resource(mixed $item, string $pointer, array $tokens, CapturedResponse $response, LookupContext $context, int $order, string $locator, bool $asset): Media
    {
        if (! is_array($item)) {
            throw new OperationalFailure(DiagnosticCode::SchemaError, 'media');
        }
        $reference = $item[$asset ? 'link' : 'path'] ?? null;
        if (! is_string($reference)) {
            throw new OperationalFailure(DiagnosticCode::SchemaError, 'media');
        }
        $metadata = [];
        $refs = [];
        foreach ($item as $key => $unused) {
            $path = $pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], $key);
            $token = $tokens[$path] ?? null;
            if ($token === null || $token['lexeme'] === null) {
                throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'media');
            }
            $ref = $context->evidence->capture($response, $locator.'/'.$key, $token['lexeme']);
            $refs[] = $ref;
            $metadata[] = $this->values->field($key, $token, [$ref]);
        }
        $meta = $asset ? ($item['metaData'] ?? []) : $item;
        if (! is_array($meta)) {
            throw new OperationalFailure(DiagnosticCode::SchemaError, 'media');
        }
        $roles = [];
        foreach (['mainImage', 'productView', 'productRelatedAssetType'] as $key) {
            if (is_string($meta[$key] ?? null)) {
                $roles[] = new NameValue($key, $meta[$key]);
            }
        }

        return new Media($reference, $this->resolve($reference), is_string($item['label'] ?? $meta['jcr:title'] ?? null) ? ($item['label'] ?? $meta['jcr:title']) : null,
            $metadata, $order, $refs, is_int($item['position'] ?? null) ? $item['position'] : null,
            is_string($item['type'] ?? $meta['productRelatedAssetType'] ?? null) ? ($item['type'] ?? $meta['productRelatedAssetType']) : null,
            is_string($item['format'] ?? null) ? $item['format'] : null, is_string($item['mimeType'] ?? null) ? $item['mimeType'] : null, $roles);
    }
}
