<?php

namespace Tests\Support\Wix;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Offline test data only. Never fetches source URLs or executes captured content. */
final class FixtureCorpus
{
    private const int MaxBodyBytes = 2_097_152;

    private array $manifest;

    private array $fixtures = [];

    private array $expected;

    private readonly string $root;

    public function __construct(string $directory, ?array $manifest = null)
    {
        $root = realpath($directory);
        $this->check($root !== false && is_dir($root), 'Missing corpus directory');
        $this->root = $root;
        $this->manifest = $manifest ?? $this->json(file_get_contents($root.'/manifest.json'));
        $this->keys($this->manifest, ['schema_version', 'revision', 'queries', 'sanitization_rules', 'expected', 'gaps', 'fixtures']);
        $this->check($this->manifest['schema_version'] === 1, 'Unsupported corpus schema');
        $this->text($this->manifest['revision'], 'revision');
        $this->check(is_array($this->manifest['queries']) && is_array($this->manifest['sanitization_rules']), 'Invalid rule registry');
        $this->check(is_array($this->manifest['gaps']) && $this->manifest['gaps'] !== [], 'Missing declared gaps');
        $this->check(is_array($this->manifest['fixtures']) && array_is_list($this->manifest['fixtures']) && $this->manifest['fixtures'] !== [], 'Invalid fixture list');
        $this->expected = $this->json($this->readVerified($this->manifest['expected'], 'expected'));

        foreach ($this->manifest['fixtures'] as $fixture) {
            $this->keys($fixture, ['fixture_id', 'nature', 'parent_id', 'representation', 'profile', 'profile_version', 'surface', 'request', 'response', 'provenance', 'body', 'expectations']);
            $id = $fixture['fixture_id'];
            $this->check(is_string($id) && preg_match('/\A[A-Za-z0-9_-]+\z/', $id) === 1, 'Invalid fixture ID');
            $this->check(! isset($this->fixtures[$id]), 'Duplicate fixture ID');
            $this->fixtures[$id] = $fixture;
        }

        $this->check(count($this->expected) === count($this->fixtures), 'Unlinked expectations');

        foreach ($this->fixtures as $id => $fixture) {
            $this->validateMetadata($fixture);
            $this->body($id);
        }
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->fixtures);
    }

    public function fixture(string $id): array
    {
        $this->check(isset($this->fixtures[$id]), 'Unknown fixture ID');

        return $this->fixtures[$id];
    }

    public function expected(string $id): array
    {
        $this->fixture($id);

        return $this->expected[$id];
    }

    public function requestUrl(string $id): string
    {
        $request = $this->fixture($id)['request'];
        $url = $request['url'];

        if ($request['graphql'] !== null) {
            $graphql = $request['graphql'];
            $this->keys($graphql, ['query_id', 'variables']);
            $this->text($graphql['query_id'], 'query ID');
            $query = $this->manifest['queries'][$graphql['query_id']] ?? null;
            $this->text($query, 'query');
            $this->keys($graphql['variables'], ['search', 'currentPage', 'pageSize', 'filterBy']);
            $this->check(is_string($graphql['variables']['search']) && is_int($graphql['variables']['currentPage']) && is_int($graphql['variables']['pageSize']), 'Invalid GraphQL variables');
            $url .= '?'.http_build_query([
                'query' => $query,
                'variables' => json_encode($graphql['variables'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    public function body(string $id): string
    {
        $fixture = $this->fixture($id);
        $this->check(($fixture['body']['encoding'] ?? null) === 'gzip-base64-json', 'Invalid body encoding');
        $container = $this->json($this->readVerified($fixture['body'], 'bodies'));
        $this->keys($container, ['encoding', 'chunks']);
        $this->check($container['encoding'] === 'gzip-base64' && is_array($container['chunks']) && array_is_list($container['chunks']) && $container['chunks'] !== [], 'Invalid encoded body');

        foreach ($container['chunks'] as $chunk) {
            $this->check(is_string($chunk) && strlen($chunk) <= 120 && preg_match('/\A[A-Za-z0-9+\/=]+\z/', $chunk) === 1, 'Invalid body chunk');
        }

        $compressed = base64_decode(implode('', $container['chunks']), true);
        $this->check($compressed !== false, 'Invalid base64 body');
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException('Invalid gzip body');
        }, E_WARNING);

        try {
            $body = gzdecode($compressed, self::MaxBodyBytes);
        } finally {
            restore_error_handler();
        }
        $this->check(is_string($body), 'Invalid gzip body');
        $this->digest($body, $fixture['provenance']['sanitized']);

        return $body;
    }

    /**
     * Finite fixture recipes only: no business interpretation, transport or captured timestamps.
     *
     * @return array<string, array{nature: string, purpose: string, steps: list<array>, expected: array}>
     */
    public function variants(?array $catalog = null): array
    {
        $catalog ??= $this->json($this->readVerified([
            'path' => 'bodies/variants.json', 'bytes' => 9908, 'sha256' => 'c6965a31c467e6c521b5ba35158aa036655a446bb5db1c40fa7d2664875cc7df',
        ], 'bodies'));
        $expected = $this->json($this->readVerified([
            'path' => 'expected/variants.json', 'bytes' => 9302, 'sha256' => 'e1f956803815b1ab54782110880a35be361d334f38816cf6285c6fb71f95fa03',
        ], 'expected'));
        $this->keys($catalog, ['schema_version', 'revision', 'scope', 'parents', 'cases']);
        $this->check($catalog['schema_version'] === 1 && $catalog['revision'] === $expected['revision'], 'Invalid variant revision');
        $this->check(is_array($catalog['parents']) && count($catalog['parents']) <= 16, 'Invalid variant parents');
        $this->check(is_array($catalog['cases']) && count($catalog['cases']) > 0 && count($catalog['cases']) <= 64, 'Invalid variant count');
        $this->check(array_keys($catalog['cases']) === array_keys($expected['cases']), 'Unlinked variant expectations');

        foreach ($catalog['parents'] as $parent) {
            $this->keys($parent, ['fixture_id', 'sha256']);
            $fixture = $this->fixture($parent['fixture_id']);
            $this->check($fixture['nature'] === 'genuine' && $parent['sha256'] === $fixture['provenance']['sanitized']['sha256'], 'Variant parent checksum mismatch');
        }

        $variants = [];
        foreach ($catalog['cases'] as $id => $case) {
            $this->keys($case, ['nature', 'purpose', 'steps']);
            $this->check(is_string($id) && preg_match('/\A[a-z0-9-]+\z/', $id) === 1, 'Invalid variant ID');
            $this->check(in_array($case['nature'], ['derived', 'synthetic'], true), 'Variant cannot claim genuine capture');
            $this->check(array_diff(array_keys($case), ['nature', 'purpose', 'steps']) === [], 'Unexpected variant capture metadata');
            $this->text($case['purpose'], 'variant purpose');
            $this->check(is_array($case['steps']) && array_is_list($case['steps']) && count($case['steps']) > 0 && count($case['steps']) <= 16, 'Invalid variant steps');
            $steps = [];
            foreach ($case['steps'] as $step) {
                $steps[] = $this->variantStep($step, $case['nature'], $catalog['parents']);
            }
            $variants[$id] = ['nature' => $case['nature'], 'purpose' => $case['purpose'], 'steps' => $steps, 'expected' => $expected['cases'][$id]];
        }

        return $variants;
    }

    /** Reproducible offline inventory metadata only, not an adapter or a parser certification oracle. */
    public function inventory(?array $inventory = null): array
    {
        if ($inventory === null) {
            $path = $this->root.'/expected/inventory.json';
            $this->check(is_file($path) && filesize($path) <= self::MaxBodyBytes, 'Missing or oversized inventory');
            $inventory = $this->json(file_get_contents($path));
        }
        $this->keys($inventory, ['schema_version', 'inventory_version', 'corpus_revision', 'variant_revision', 'profile_version', 'contract_version', 'status', 'certified_cells', 'technical_evidence', 'common_closure', 'media_scope', 'document_scope_decision', 'rows']);
        $this->check($inventory['schema_version'] === 1 && $inventory['inventory_version'] === 'technical-2026-10-20.2', 'Invalid inventory revision');
        $this->check($inventory['corpus_revision'] === $this->manifest['revision'] && $inventory['variant_revision'] === '2026-10-19.2' && $inventory['contract_version'] === '1.0', 'Invalid inventory versions');
        $this->check($inventory['status'] === 'technical_inventory_not_parser_certification' && $inventory['certified_cells'] === 0, 'Inventory readiness is not parser certification');
        $evidence = $inventory['technical_evidence'];
        $this->keys($evidence, ['manifest', 'variant_inputs', 'variant_expected']);
        $manifest = $evidence['manifest'];
        $this->keys($manifest, ['path', 'bytes', 'sha256']);
        $this->check($manifest['path'] === 'manifest.json', 'Invalid inventory manifest path');
        $this->digest(file_get_contents($this->root.'/manifest.json'), $manifest);
        foreach (['variant_inputs' => ['bodies', 'bodies/variants.json'], 'variant_expected' => ['expected', 'expected/variants.json']] as $key => [$directory, $path]) {
            $this->check(($evidence[$key]['path'] ?? null) === $path, 'Invalid inventory variant path');
            $this->readVerified($evidence[$key], $directory);
        }
        $this->text($inventory['common_closure'], 'common closure');
        $media = $inventory['media_scope'];
        $this->keys($media, ['required', 'endpoint', 'filter', 'link', 'pagination', 'no_extrapolation', 'decision']);
        $this->check($media['required'] === ['html:ProductGallery', 'asset_metadata:linked-filtered-collection'] && $media['endpoint'] === '/bin/assets.json', 'Invalid joint media inventory');
        $this->check($media['filter'] === ['metaData' => 'true', 'brand' => 'WIX Filters', 'productView' => 'Front', 'productRelatedAssetType' => 'Product Image', 'mainImage' => 'true'], 'Invalid fixed media filter');
        foreach (['link', 'pagination', 'no_extrapolation', 'decision'] as $field) {
            $this->text($media[$field], 'media '.$field);
        }
        $documents = $inventory['document_scope_decision'];
        $this->keys($documents, ['status', 'detected_populated', 'authentic_positive_support_certified', 'current']);
        $this->check($documents['status'] === 'empty_recognition_populated_unsupported' && $documents['authentic_positive_support_certified'] === false, 'Invalid document support claim');
        $this->check($documents['detected_populated'] === ['state' => 'unsupported', 'complete' => false, 'after_verified_identity' => 'partial/exact', 'diagnostic' => 'unsupported_section', 'severity' => 'error', 'recovered' => false], 'Invalid populated document gap semantics');
        $this->text($documents['current'], 'document limitation');
        $this->check(is_array($inventory['rows']) && array_is_list($inventory['rows']) && count($inventory['rows']) === 16, 'Invalid inventory cell count');
        $cells = [];
        foreach ($inventory['rows'] as $row) {
            $this->keys($row, ['profile', 'section', 'surfaces', 'fixtures', 'html_locator', 'recognition', 'empty', 'populated', 'closure', 'gap', 'certified']);
            $this->check(in_array($row['profile'], ['es-co', 'en-ap'], true) && in_array($row['section'], ['identity', 'dimensions', 'specifications', 'applications', 'oe_references', 'media', 'documents', 'observations'], true), 'Invalid inventory cell');
            $cell = $row['profile'].':'.$row['section'];
            $this->check(! isset($cells[$cell]), 'Duplicate inventory cell');
            $cells[$cell] = true;
            $this->check($row['certified'] === false, 'Inventory readiness is not parser certification');
            foreach (['html_locator', 'recognition', 'empty', 'populated', 'closure', 'gap'] as $field) {
                $this->text($row[$field], 'inventory '.$field);
            }
            $this->check(is_array($row['fixtures']) && array_is_list($row['fixtures']) && $row['fixtures'] !== [] && is_array($row['surfaces']) && array_is_list($row['surfaces']), 'Invalid inventory evidence');
            $surfaces = [];
            foreach ($row['fixtures'] as $id) {
                $fixture = $this->fixture($id);
                $this->check($fixture['profile'] === $row['profile'] && $fixture['profile_version'] === $inventory['profile_version'], 'Invalid inventory fixture context');
                $surfaces[] = $fixture['surface'];
            }
            $surfaces = array_values(array_unique($surfaces));
            $required = $row['surfaces'];
            sort($surfaces);
            sort($required);
            $this->check($surfaces === $required, 'Invalid inventory surface evidence');
            if ($row['section'] === 'documents') {
                $this->check(str_contains($row['gap'], 'AUTHENTIC_POSITIVE_DOCUMENT_MISSING'), 'Missing authentic document gap');
            }
        }

        return $inventory;
    }

    private function variantStep(array $step, string $nature, array $parents): array
    {
        $allowed = $nature === 'derived' ? ['parent', 'replace', 'set', 'remove', 'status'] : ['profile', 'surface', 'body', 'status'];
        $this->check(array_diff(array_keys($step), $allowed) === [], 'Unexpected variant step metadata');
        $parent = null;
        if ($nature === 'derived') {
            $this->check(is_string($step['parent'] ?? null) && isset($parents[$step['parent']]), 'Missing variant parent');
            $parent = $parents[$step['parent']];
            $fixture = $this->fixture($parent['fixture_id']);
            $body = $this->body($parent['fixture_id']);
            $profile = $fixture['profile'];
            $surface = $fixture['surface'];
            foreach (['replace', 'set', 'remove'] as $operation) {
                $this->check(is_array($step[$operation] ?? []) && array_is_list($step[$operation] ?? []) && count($step[$operation] ?? []) <= 16, 'Invalid variant operation count');
            }
            foreach ($step['replace'] ?? [] as $replacement) {
                $this->keys($replacement, ['from', 'to']);
                $this->text($replacement['from'], 'replacement source');
                $this->check(is_string($replacement['to']), 'Invalid replacement text');
                $matches = substr_count($body, $replacement['from']);
                $count = $replacement['count'] ?? $matches;
                $this->check(is_int($count) && $count > 0 && $count <= $matches, 'Variant replacement did not match');
                $this->check(strlen($body) + $count * max(0, strlen($replacement['to']) - strlen($replacement['from'])) <= self::MaxBodyBytes, 'Oversized variant body');
                $offset = 0;
                for ($index = 0; $index < $count; $index++) {
                    $position = strpos($body, $replacement['from'], $offset);
                    $body = substr_replace($body, $replacement['to'], $position, strlen($replacement['from']));
                    $offset = $position + strlen($replacement['to']);
                }
            }
            if (($step['set'] ?? []) !== [] || ($step['remove'] ?? []) !== []) {
                $data = $this->json($body);
                foreach ($step['remove'] ?? [] as $path) {
                    $this->changeVariantValue($data, $path, null, true);
                }
                foreach ($step['set'] ?? [] as $change) {
                    $this->keys($change, ['path', 'value']);
                    $this->changeVariantValue($data, $change['path'], $change['value'], false);
                }
                $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        } else {
            $this->keys($step, ['profile', 'surface', 'body']);
            $body = $step['body'];
            $profile = $step['profile'];
            $surface = $step['surface'];
        }
        $this->check(is_string($body) && strlen($body) > 0 && strlen($body) <= self::MaxBodyBytes, 'Oversized or invalid variant body');
        $this->check(in_array($profile, ['es-co', 'en-ap'], true) && in_array($surface, ['graphql_search', 'html', 'asset_metadata'], true), 'Invalid variant context');
        $status = $step['status'] ?? 200;
        $this->check(is_int($status) && $status >= 100 && $status <= 599, 'Invalid variant HTTP status');

        return ['profile' => $profile, 'surface' => $surface, 'status' => $status, 'body' => $body, 'bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'parent' => $parent, 'changes' => $step];
    }

    private function changeVariantValue(array &$data, mixed $path, mixed $value, bool $remove): void
    {
        $this->check(is_array($path) && array_is_list($path) && count($path) > 0 && count($path) <= 16, 'Invalid variant field path');
        foreach ($path as $key) {
            $this->check(is_int($key) || (is_string($key) && $key !== ''), 'Invalid variant field key');
        }
        $last = array_pop($path);
        $cursor = &$data;
        foreach ($path as $key) {
            $this->check(array_key_exists($key, $cursor) && is_array($cursor[$key]), 'Missing variant field container');
            $cursor = &$cursor[$key];
        }
        if ($remove) {
            $this->check(array_key_exists($last, $cursor), 'Missing variant removal target');
            unset($cursor[$last]);
        } else {
            $cursor[$last] = $value;
        }
    }

    private function validateMetadata(array $fixture): void
    {
        $id = $fixture['fixture_id'];
        $this->check(in_array($fixture['nature'], ['genuine', 'derived', 'synthetic'], true), 'Invalid fixture nature');
        $this->check(in_array($fixture['profile'], ['es-co', 'en-ap'], true), 'Invalid profile');
        $this->text($fixture['profile_version'], 'profile version');
        $this->check($fixture['representation'] === 'sanitized_capture', 'Invalid representation');
        $this->check(in_array($fixture['surface'], ['graphql_search', 'html', 'asset_metadata'], true), 'Invalid surface');
        $provenance = $fixture['provenance'];
        $this->keys($provenance, ['investigation', 'source_manifest_sha256', 'source_body', 'source_request_sha256', 'source_metadata_sha256', 'received', 'stored', 'sanitized', 'capture_transformations', 'transformations']);
        $this->check(is_array($provenance['transformations']) && is_array($provenance['capture_transformations']), 'Invalid transformations');

        foreach ($provenance['transformations'] as $rule) {
            $this->check(is_string($rule) && isset($this->manifest['sanitization_rules'][$rule]), 'Unknown sanitization rule');
        }

        foreach (['received', 'stored', 'sanitized'] as $representation) {
            $this->digestMetadata($provenance[$representation]);
        }

        foreach (['source_manifest_sha256', 'source_request_sha256', 'source_metadata_sha256'] as $hash) {
            $this->hash($provenance[$hash]);
        }

        $this->check($provenance['investigation'] === 'engram:2494', 'Missing capture provenance');
        $this->check(is_string($provenance['source_body']) && preg_match('~\A/tmp/opencode/prd15-live-20261019/[A-Za-z0-9_-]+\.body\.(?:json|html)\z~', $provenance['source_body']) === 1, 'Unrecognized historical source');
        $extension = $fixture['surface'] === 'html' ? 'html' : 'json';
        $this->check($provenance['source_body'] === '/tmp/opencode/prd15-live-20261019/'.$id.'.body.'.$extension, 'Historical source belongs to another fixture');

        if ($fixture['nature'] === 'genuine') {
            $this->check($fixture['parent_id'] === null, 'Genuine capture cannot have a parent');
        } elseif ($fixture['nature'] === 'derived') {
            $parent = $this->fixtures[$fixture['parent_id'] ?? ''] ?? null;
            $this->check(is_array($parent) && $parent['nature'] === 'genuine', 'Missing genuine parent');
            $this->check(($provenance['parent_sha256'] ?? null) === $parent['provenance']['sanitized']['sha256'] && $provenance['transformations'] !== [], 'Missing derivation provenance');
        } else {
            throw new RuntimeException('Synthetic fixtures require a separate schema, not LIVE provenance');
        }

        $changedAtCapture = $provenance['received']['sha256'] !== $provenance['stored']['sha256'];
        $this->check($changedAtCapture || $provenance['received']['bytes'] === $provenance['stored']['bytes'], 'Inconsistent received byte count');
        $this->check(($provenance['received']['verification'] ?? null) === ($changedAtCapture ? 'historical_attestation_raw_not_retained' : 'recomputed_from_source'), 'Invalid received-body attestation');
        $this->check(! $changedAtCapture || $provenance['capture_transformations'] !== [], 'Missing capture transformation');
        $this->check($provenance['stored']['sha256'] === $provenance['sanitized']['sha256'] || $provenance['transformations'] !== [], 'Missing sanitization transformation');

        $request = $fixture['request'];
        $this->keys($request, ['method', 'url', 'headers', 'graphql', 'url_sha256']);
        $this->check($request['method'] === 'GET' && is_array($request['headers']), 'Invalid captured request');
        $this->text($request['url'], 'request URL');

        foreach ($request['headers'] as $name => $value) {
            $this->check(in_array($name, ['User-Agent', 'Accept', 'Accept-Encoding', 'Store', 'Content-Type'], true) && is_string($value) && ! preg_match('/[\r\n]/', $value), 'Unsafe captured header');
        }

        $url = $this->requestUrl($id);
        $this->sourceUrl($url, $fixture);
        $this->hash($request['url_sha256']);
        $this->check(hash_equals($request['url_sha256'], hash('sha256', $url)), 'Captured URL checksum mismatch');

        if ($fixture['surface'] === 'graphql_search') {
            $store = $fixture['profile'] === 'es-co' ? 'pcat_wix_co_store_es' : 'pcat_wix_my_store_en';
            $this->check(($request['headers']['Store'] ?? null) === $store && $request['graphql'] !== null, 'Missing regional request context');
        } else {
            $this->check($request['graphql'] === null, 'Unexpected GraphQL context');
        }

        $response = $fixture['response'];
        $this->keys($response, ['initial_url_ref', 'final_url', 'status', 'redirects', 'started_at', 'captured_at']);
        $this->check($response['initial_url_ref'] === 'request.url' && is_int($response['status']) && $response['status'] >= 100 && $response['status'] <= 599, 'Invalid response metadata');
        $this->check($response['redirects'] === [] && $response['final_url'] === null, 'This capture revision has no redirects');
        $this->utc($response['started_at']);
        $this->utc($response['captured_at']);
        $this->check($response['captured_at'] >= $response['started_at'], 'Capture predates request');
        $this->keys($fixture['expectations'], ['case_id', 'criteria']);
        $this->check($fixture['expectations']['case_id'] === $id && isset($this->expected[$id]) && is_array($this->expected[$id]), 'Missing fixture expectations');
        $this->check(is_array($fixture['expectations']['criteria']) && $fixture['expectations']['criteria'] !== [], 'Missing acceptance trace');
    }

    private function sourceUrl(string $url, array $fixture): void
    {
        $parts = parse_url($url);
        $this->check(is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'www.wixfilters.com' && ($parts['port'] ?? 443) === 443 && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment']) && ! preg_match('/[\x00-\x20\x7f]/', $url), 'Source outside corpus allowlist');
        $path = $parts['path'] ?? '';
        $allowed = match ($fixture['surface']) {
            'graphql_search' => $path === '/api/graphql/'.($fixture['profile'] === 'es-co' ? 'catalog-stage' : 'catalog-prod'),
            'asset_metadata' => $path === '/bin/assets.json',
            'html' => preg_match('~\A/'.preg_quote($fixture['profile'], '~').'/catalog/results/product\.html/[A-Za-z0-9_-]+\.html\z~', $path) === 1 && ! isset($parts['query']),
        };
        $this->check($allowed, 'Source path outside corpus allowlist');
    }

    private function readVerified(mixed $descriptor, string $subdirectory): string
    {
        $this->keys($descriptor, ['path', 'bytes', 'sha256']);
        $path = $descriptor['path'];
        $this->check(is_string($path) && preg_match('~\A'.$subdirectory.'/[A-Za-z0-9_.-]+\.json\z~', $path) === 1, 'Unsafe fixture path');
        $resolved = realpath($this->root.'/'.$path);
        $this->check($resolved !== false && str_starts_with($resolved, $this->root.'/'.$subdirectory.'/') && is_file($resolved), 'Missing or escaping fixture file');
        $this->check(filesize($resolved) <= self::MaxBodyBytes, 'Oversized fixture file');
        $bytes = file_get_contents($resolved);
        $this->digest($bytes, $descriptor);

        return $bytes;
    }

    private function digest(string $bytes, mixed $descriptor): void
    {
        $this->digestMetadata($descriptor);
        $this->check(strlen($bytes) === $descriptor['bytes'], 'Fixture byte count mismatch');
        $this->check(hash_equals($descriptor['sha256'], hash('sha256', $bytes)), 'Fixture checksum mismatch');
    }

    private function digestMetadata(mixed $descriptor): void
    {
        $this->keys($descriptor, ['bytes', 'sha256']);
        $this->check(is_int($descriptor['bytes']) && $descriptor['bytes'] > 0 && $descriptor['bytes'] <= self::MaxBodyBytes, 'Invalid byte count');
        $this->hash($descriptor['sha256']);
    }

    private function hash(mixed $value): void
    {
        $this->check(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1, 'Invalid SHA-256');
    }

    private function utc(mixed $value): void
    {
        $this->text($value, 'UTC timestamp');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $this->check($date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value, 'Invalid UTC timestamp');
    }

    private function keys(mixed $value, array $keys): void
    {
        $this->check(is_array($value), 'Invalid corpus metadata object');

        foreach ($keys as $key) {
            $this->check(array_key_exists($key, $value), 'Missing corpus metadata: '.$key);
        }
    }

    private function text(mixed $value, string $label): void
    {
        $this->check(is_string($value) && $value !== '', 'Missing '.$label);
    }

    private function json(string $contents): array
    {
        $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        $this->check(is_array($decoded), 'Invalid corpus JSON object');

        return $decoded;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
