<?php

namespace Tests\Support\Wix\Prototype;

use DateTimeImmutable;
use LogicException;

/** Test-only oracle for the complete PRD §5 shape, not a production DTO or upstream parser. */
final class ContractWitness
{
    public const SECTIONS = ['identity', 'dimensions', 'specifications', 'applications', 'oe_references', 'media', 'documents', 'observations'];

    public const CODES = ['invalid_input', 'disabled', 'invalid_configuration', 'transport_error', 'http_error', 'graphql_error', 'schema_error', 'pagination_error', 'budget_exhausted', 'identity_mismatch', 'identity_unverified', 'identity_conflict', 'ambiguous_identity', 'parse_error', 'unsupported_section'];

    /** @return array<string, array<string, mixed>> */
    private static function shapes(): array
    {
        $refs = 'list:string';
        $raw = ['value_raw' => '?string', 'value_presence' => 'string', 'raw_type' => 'string', 'evidence_refs' => $refs];
        $observation = $raw + ['label_raw' => '?string', 'unit_raw' => '?string', 'raw_admin_value' => '?string', 'section' => 'string', 'order' => 'int', 'source_key?' => '?string'];
        $resource = ['reference_raw' => 'string', 'url_resolved' => '?url', 'label_raw' => '?string', 'declared_metadata' => 'list:@raw', 'order' => 'int', 'evidence_refs' => $refs];

        return [
            'result' => ['contract_version' => 'string', 'requested_code' => 'string', 'status' => 'string', 'resolution' => 'string', 'product' => '?@product', 'scope' => '@scope', 'source' => '?@source', 'attempts' => 'list:@attempt', 'evidence' => 'map:@evidence', 'coverage' => '@coverage', 'diagnostics' => 'list:@diagnostic', 'meta' => '@meta'],
            'product' => ['identity' => '@identity', 'dimensions' => 'list:@observation', 'specifications' => 'list:@observation', 'applications' => 'list:@application', 'oe_references' => 'list:@oe', 'media' => 'list:@media', 'documents' => 'list:@resource', 'observations' => 'list:@observation'],
            'identity' => ['brand' => 'string', 'code' => 'string', 'identifiers' => 'list:@identifier', 'evidence_refs' => $refs],
            'identifier' => ['name' => 'string', 'value' => 'string', 'evidence_refs' => $refs],
            'observation' => $observation,
            'raw' => ['name' => 'string'] + $raw,
            'cell' => $raw + ['label_raw' => '?string', 'unit_raw' => '?string', 'order' => 'int'],
            'namevalue' => ['name' => 'string', 'value' => 'string'],
            'navigation' => ['path' => '?string', 'params' => 'list:@namevalue'],
            'occurrence' => ['surface' => 'string', 'locator' => 'string', 'evidence_refs' => $refs],
            'application' => ['manufacturer_raw' => '?string', 'model_raw' => '?string', 'series_raw' => '?string', 'variant_raw' => '?string', 'engine_raw' => '?string', 'period_raw' => '?string', 'cells' => 'list:@cell', 'source_identifiers' => 'list:@namevalue', 'navigation' => '@navigation', 'occurrences' => 'list:@occurrence', 'order' => 'int', 'evidence_refs' => $refs],
            'oe' => ['manufacturer_raw' => '?string', 'reference_raw' => 'string', 'order' => 'int', 'evidence_refs' => $refs],
            'resource' => $resource,
            'media' => $resource + ['position_published' => '?signed_int', 'type_declared' => '?string', 'format_declared' => '?string', 'mime_declared' => '?string', 'roles' => 'list:@namevalue'],
            'budget' => array_fill_keys(['elapsed_ms', 'requests', 'redirects', 'retries', 'bytes', 'nodes'], 'int'),
            'scope' => ['policy' => 'string', 'required_profiles' => 'list:string', 'consulted_profiles' => 'list:string', 'exhaustive_profiles' => 'list:string', 'conclusion' => 'string'],
            'source' => ['profile' => 'string', 'url' => 'url', 'captured_at' => 'utc', 'evidence_refs' => $refs],
            'attempt' => ['attempt_id' => 'string', 'profile' => 'string', 'stage' => 'string', 'pages' => 'list:int', 'requests' => 'int', 'outcome' => 'string', 'evidence_refs' => $refs, 'started_at' => 'utc', 'finished_at' => 'utc', 'budget_used' => '@budget'],
            'evidence' => ['attempt_id' => 'string', 'profile' => 'string', 'surface' => 'string', 'source_url' => 'url', 'final_url' => '?url', 'captured_at' => 'utc', 'locator' => 'string', 'raw_excerpt' => 'scalar'],
            'coverage' => ['sections' => array_fill_keys(self::SECTIONS, '@section'), 'groups' => array_fill_keys(['identity', 'technical', 'resources'], ['complete' => 'bool']), 'complete' => 'bool'],
            'section' => ['state' => 'string', 'complete' => 'bool', 'reasons' => 'list:string', 'evidence_refs' => $refs],
            'diagnostic' => ['code' => 'string', 'stage' => 'string', 'attempt_id' => '?string', 'severity' => 'string', 'recovered' => 'bool', 'message' => 'string', 'section' => '?string', 'evidence_refs' => $refs],
            'meta' => ['profile_versions' => 'map:string', 'parser_version' => 'string', 'requested_at' => 'utc', 'returned_at' => 'utc', 'budget_used' => '@budget'],
        ];
    }

    /** @param array<string, mixed> $dto @param array<string, mixed>|null $retainedEvidence */
    public static function assert(array $dto, ?array $retainedEvidence = null, bool $captured = false): void
    {
        $units = 0;
        $bytes = 0;
        self::admit($dto, $units, $bytes, maxUnits: $captured ? 262144 : 8192, maxBytes: $captured ? 16777216 : 524288);
        self::shape($dto, '@result', '$');
        self::require($dto['contract_version'] === '1.0', 'contract version');
        self::require(in_array($dto['status'].'/'.$dto['resolution'], ['success/exact', 'partial/exact', 'success/no_exact_match', 'failed/ambiguous', 'failed/incomplete', 'failed/not_attempted'], true), 'six pairs');
        $exact = $dto['resolution'] === 'exact';
        self::require(($dto['product'] !== null) === $exact && ($dto['source'] !== null) === $exact, 'product/source iff exact');
        $attempts = [];
        $requests = 0;
        foreach ($dto['attempts'] as $attempt) {
            self::require($attempt['attempt_id'] !== '' && ! isset($attempts[$attempt['attempt_id']]), 'unique attempt');
            self::require(in_array($attempt['profile'], $dto['scope']['consulted_profiles'], true), 'consulted attempt');
            self::require(self::timeLe($attempt['started_at'], $attempt['finished_at']), 'attempt chronology');
            self::require($attempt['requests'] === $attempt['budget_used']['requests'], 'attempt accounting');
            self::require(count(array_filter($attempt['pages'], fn (int $page): bool => $page < 1)) === 0, 'positive pages');
            $attempts[$attempt['attempt_id']] = $attempt;
            $requests += $attempt['requests'];
        }
        self::require($requests === $dto['meta']['budget_used']['requests'], 'global accounting');
        self::require(self::timeLe($dto['meta']['requested_at'], $dto['meta']['returned_at']), 'result chronology');
        foreach ($dto['scope']['consulted_profiles'] as $profile) {
            self::require(isset($dto['meta']['profile_versions'][$profile]), 'profile version');
            self::require(in_array($profile, array_column($dto['attempts'], 'profile'), true), 'consulted history');
        }
        foreach ($dto['scope']['exhaustive_profiles'] as $profile) {
            self::require(in_array($profile, $dto['scope']['consulted_profiles'], true), 'exhaustive consulted');
        }
        foreach ($dto['evidence'] as $id => $evidence) {
            self::require($id !== '' && isset($attempts[$evidence['attempt_id']]), 'evidence attempt');
            $attempt = $attempts[$evidence['attempt_id']];
            self::require($evidence['profile'] === $attempt['profile'] && in_array($id, $attempt['evidence_refs'], true), 'evidence attribution');
            self::require(in_array($evidence['surface'], ['graphql_search', 'html', 'asset_metadata'], true), 'surface');
            self::require($evidence['locator'] !== '' && $evidence['raw_excerpt'] !== '', 'contextual evidence');
            self::require($evidence['final_url'] !== $evidence['source_url'], 'unchanged URL must use null');
            self::require(self::timeLe($attempt['started_at'], $evidence['captured_at']) && self::timeLe($evidence['captured_at'], $attempt['finished_at']), 'capture chronology');
        }
        self::references($dto, $dto['evidence']);
        foreach ($dto['attempts'] as $attempt) {
            foreach ($attempt['evidence_refs'] as $id) {
                self::require($dto['evidence'][$id]['attempt_id'] === $attempt['attempt_id'], 'attempt refs attribution');
            }
        }
        $terminals = [];
        foreach ($dto['diagnostics'] as $diagnostic) {
            self::require(in_array($diagnostic['code'], self::CODES, true), 'diagnostic code');
            self::require(in_array($diagnostic['severity'], ['info', 'warning', 'error'], true), 'severity');
            self::require(! $diagnostic['recovered'] || $diagnostic['severity'] === 'warning', 'recovered warning');
            self::require($diagnostic['attempt_id'] === null || isset($attempts[$diagnostic['attempt_id']]), 'diagnostic attempt');
            self::require($diagnostic['section'] === null || in_array($diagnostic['section'], self::SECTIONS, true), 'diagnostic section');
            self::require($diagnostic['message'] !== '' && $diagnostic['stage'] !== '', 'causal diagnostic');
            if ($diagnostic['attempt_id'] !== null) {
                foreach ($diagnostic['evidence_refs'] as $id) {
                    self::require($dto['evidence'][$id]['attempt_id'] === $diagnostic['attempt_id'], 'diagnostic attribution');
                }
            }
            if ($diagnostic['severity'] === 'error') {
                self::require(! $diagnostic['recovered'], 'terminal not recovered');
                $terminals[] = $diagnostic;
            }
        }
        self::require($dto['status'] !== 'failed' || $terminals !== [], 'failed terminal');
        self::require($dto['status'] !== 'success' || $terminals === [], 'success no terminal');
        $sections = $dto['coverage']['sections'];
        foreach ($sections as $name => $section) {
            self::require(in_array($section['state'], ['captured', 'published_empty', 'not_published', 'failed', 'not_checked', 'unsupported'], true), 'coverage enum');
            self::require(! in_array($section['state'], ['failed', 'not_checked', 'unsupported'], true) || ! $section['complete'], 'incomplete state');
            self::require(! in_array($section['state'], ['captured', 'published_empty', 'not_published'], true) || $section['evidence_refs'] !== [], 'coverage proof');
            self::require($section['complete'] || $section['reasons'] !== [], 'gap cause');
            if ($dto['status'] === 'partial' && ! $section['complete']) {
                self::require(in_array($name, array_column($terminals, 'section'), true), 'terminal for every gap');
            }
        }
        $identity = $sections['identity']['complete'];
        $technical = ! in_array(false, array_map(fn (string $name): bool => $sections[$name]['complete'], ['dimensions', 'specifications', 'applications', 'oe_references', 'observations']), true);
        $resources = $sections['media']['complete'] && $sections['documents']['complete'];
        self::require($dto['coverage']['groups'] === ['identity' => ['complete' => $identity], 'technical' => ['complete' => $technical], 'resources' => ['complete' => $resources]], 'derived groups');
        self::require($dto['coverage']['complete'] === ($exact && $identity && $technical && $resources), 'global coverage');
        if ($exact) {
            self::require($identity && $sections['identity']['state'] === 'captured', 'verified identity');
            self::require($dto['product']['identity']['brand'] === 'WIX' && $dto['product']['identity']['code'] === $dto['requested_code'], 'literal identity');
            $surfaces = array_map(fn (string $id): string => $dto['evidence'][$id]['surface'], $dto['product']['identity']['evidence_refs']);
            self::require(in_array('graphql_search', $surfaces, true) && in_array('html', $surfaces, true), 'two identity stages');
            self::require(in_array($dto['source']['profile'], $dto['scope']['exhaustive_profiles'], true), 'unique exhausted search');
            foreach ($dto['product']['identity']['evidence_refs'] as $id) {
                self::require($dto['evidence'][$id]['profile'] === $dto['source']['profile'], 'no regional merge');
            }
            foreach ($dto['source']['evidence_refs'] as $id) {
                $evidence = $dto['evidence'][$id];
                self::require($evidence['surface'] === 'html' && $evidence['profile'] === $dto['source']['profile'] && ($evidence['final_url'] ?? $evidence['source_url']) === $dto['source']['url'] && $evidence['captured_at'] === $dto['source']['captured_at'], 'selected source proof');
            }
            self::require(($dto['status'] === 'success') === $dto['coverage']['complete'], 'exact status coverage');
        } else {
            self::require(! $identity && ! $dto['coverage']['complete'], 'no product coverage');
        }
        if ($dto['resolution'] === 'no_exact_match') {
            self::require($dto['scope']['required_profiles'] !== [], 'bounded negative scope');
            foreach ($dto['scope']['required_profiles'] as $profile) {
                $negative = array_filter($dto['attempts'], fn (array $attempt): bool => $attempt['profile'] === $profile && $attempt['stage'] === 'search' && $attempt['outcome'] === 'exhaustive_negative' && $attempt['evidence_refs'] !== [] && ! in_array(false, array_map(fn (string $id): bool => $dto['evidence'][$id]['surface'] === 'graphql_search', $attempt['evidence_refs']), true));
                self::require(in_array($profile, $dto['scope']['exhaustive_profiles'], true) && $negative !== [], 'negative proof');
            }
        }
        if ($dto['resolution'] === 'not_attempted') {
            self::require($dto['attempts'] === [] && $dto['evidence'] === [] && $requests === 0 && array_intersect(array_column($terminals, 'code'), ['invalid_input', 'disabled', 'invalid_configuration']) !== [], 'preflight no work');
        }
        if ($dto['resolution'] === 'ambiguous') {
            self::require(in_array('ambiguous_identity', array_column($terminals, 'code'), true), 'ambiguity cause');
        }
        if (in_array('identity_mismatch', array_column($terminals, 'code'), true)) {
            self::require($dto['resolution'] === 'incomplete' && $sections['identity']['state'] === 'failed' && $sections['identity']['evidence_refs'] !== [], 'late invalidation');
        }
        if ($retainedEvidence !== null) {
            foreach ($retainedEvidence as $id => $evidence) {
                self::require(($dto['evidence'][$id] ?? null) === $evidence, 'retained source proof '.$id);
            }
        }
    }

    /**
     * Source facts are an independent parent-owned test ledger, not inferred by reparsing prose excerpts.
     * This additional oracle checks proof/material fidelity as well as the entire structural contract above.
     *
     * @param  array<string, mixed>  $dto  @param array<string, mixed> $sourceFacts
     */
    public static function assertRetained(array $dto, array $sourceFacts): void
    {
        self::assert($dto, $sourceFacts['evidence']);
        self::require($dto['requested_code'] === $sourceFacts['requested_code'], 'retained requested literal');
        self::require($dto['attempts'] === $sourceFacts['attempts'], 'retained attempts');
        self::require($dto['scope'] === $sourceFacts['scope'], 'retained regional scope');
        if ($dto['product'] !== null) {
            self::require($sourceFacts['product'] !== null && $dto['product']['identity'] === $sourceFacts['product']['identity'], 'retained identity proof');
            self::require($dto['source'] === $sourceFacts['source'], 'retained selected source');
            foreach (array_diff(self::SECTIONS, ['identity']) as $section) {
                $items = $dto['product'][$section];
                if ($dto['coverage']['sections'][$section]['complete'] || $items !== []) {
                    self::require($items === $sourceFacts['product'][$section], 'retained raw material '.$section);
                }
            }
        } else {
            self::require($dto['resolution'] === $sourceFacts['resolution'], 'unconfirmed scenario proof');
        }
    }

    /** Admission is before native JSON; bounds the test oracle and parent result work, not a production quota. */
    public static function admit(mixed $value, int &$units, int &$bytes, int $depth = 0, int $maxUnits = 8192, int $maxBytes = 524288): void
    {
        self::require(++$units <= $maxUnits && $depth <= 20, 'witness units/depth');
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $bytes += strlen((string) $key) * 6 + 8;
                self::admit($item, $units, $bytes, $depth + 1, $maxUnits, $maxBytes);
            }
        } elseif (is_string($value)) {
            $bytes += strlen($value) * 6 + 2;
        } else {
            self::require(is_null($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value)), 'inert values');
            $bytes += 32;
        }
        self::require($bytes <= $maxBytes, 'witness escaped bytes');
    }

    private static function shape(mixed $value, array|string $schema, string $path): void
    {
        if (is_array($schema)) {
            self::require(is_array($value), $path.' object');
            $allowed = [];
            foreach ($schema as $key => $type) {
                $optional = str_ends_with($key, '?');
                $key = rtrim($key, '?');
                $allowed[] = $key;
                self::require($optional || array_key_exists($key, $value), $path.'.'.$key.' required');
                if (array_key_exists($key, $value)) {
                    self::shape($value[$key], $type, $path.'.'.$key);
                }
            }
            self::require(array_diff(array_keys($value), $allowed) === [], $path.' unknown fields');
            if (isset($value['raw_type'])) {
                self::raw($value);
            }
            if (isset($value['occurrences'])) {
                self::require($value['occurrences'] !== [], 'logical application occurrences');
            }
            if (isset($value['evidence_refs']) && ! str_starts_with($path, '$.attempts') && ! str_starts_with($path, '$.coverage') && ! str_starts_with($path, '$.diagnostics')) {
                self::require($value['evidence_refs'] !== [], $path.' nonempty proof');
            }

            return;
        }
        if (str_starts_with($schema, '?')) {
            if ($value !== null) {
                self::shape($value, substr($schema, 1), $path);
            }

            return;
        }
        if (str_starts_with($schema, '@')) {
            self::shape($value, self::shapes()[substr($schema, 1)], $path);

            return;
        }
        if (str_contains($schema, ':')) {
            [$collection, $type] = explode(':', $schema, 2);
            self::require(is_array($value) && ($collection !== 'list' || array_is_list($value)), $path.' collection');
            foreach ($value as $key => $item) {
                self::require($collection !== 'map' || is_string($key), $path.' map key');
                self::shape($item, $type, $path.'.'.$key);
            }

            return;
        }
        $valid = match ($schema) {
            'string' => is_string($value),
            'int' => is_int($value) && $value >= 0,
            'signed_int' => is_int($value),
            'bool' => is_bool($value),
            'scalar' => is_null($value) || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value)),
            'utc' => self::utc($value),
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && str_starts_with($value, 'https://') && ! preg_match('/[\x00-\x20]/', $value) && ! isset(parse_url($value)['user']),
            default => false,
        };
        self::require($valid, $path.' '.$schema);
    }

    private static function utc(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.[0-9]+)?Z$/D', $value) !== 1) {
            return false;
        }
        $seconds = substr($value, 0, 19).'Z';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $seconds);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d\TH:i:s\Z') === $seconds;
    }

    /** Lexical fractional UTC comparison avoids floating-point timestamp loss. */
    private static function timeLe(string $left, string $right): bool
    {
        $comparison = strcmp(substr($left, 0, 19), substr($right, 0, 19));
        if ($comparison !== 0) {
            return $comparison < 0;
        }
        $a = str_contains($left, '.') ? substr($left, 20, -1) : '';
        $b = str_contains($right, '.') ? substr($right, 20, -1) : '';
        $length = max(strlen($a), strlen($b));

        return strcmp(str_pad($a, $length, '0'), str_pad($b, $length, '0')) <= 0;
    }

    /** @param array<string, mixed> $value */
    private static function raw(array $value): void
    {
        $type = $value['raw_type'];
        self::require(in_array($type, ['string', 'number', 'boolean', 'null', 'object', 'array', 'missing'], true), 'raw enum');
        self::require(in_array($value['value_presence'], ['present', 'missing'], true), 'presence enum');
        self::require(($value['value_presence'] === 'missing') === ($type === 'missing'), 'missing type');
        if (in_array($type, ['missing', 'null'], true)) {
            self::require($value['value_raw'] === null, 'null raw value');
        } else {
            self::require(is_string($value['value_raw']), 'literal raw value');
            if ($type === 'number') {
                self::require(preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value['value_raw']) === 1, 'JSON number lexeme');
            } elseif ($type === 'boolean') {
                self::require(in_array($value['value_raw'], ['true', 'false'], true), 'boolean literal');
            } elseif ($type === 'object' || $type === 'array') {
                self::require(json_validate($value['value_raw'], 20) && str_starts_with(ltrim($value['value_raw']), $type === 'object' ? '{' : '['), 'structured raw JSON');
            }
        }
    }

    /** @param array<string, mixed> $value @param array<string, mixed> $evidence */
    private static function references(array $value, array $evidence): void
    {
        foreach ($value as $key => $item) {
            if ($key === 'evidence_refs') {
                foreach ($item as $id) {
                    self::require(isset($evidence[$id]), 'reference closure '.$id);
                }
            } elseif (is_array($item)) {
                self::references($item, $evidence);
            }
        }
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new LogicException('Contract witness: '.$message);
        }
    }
}
