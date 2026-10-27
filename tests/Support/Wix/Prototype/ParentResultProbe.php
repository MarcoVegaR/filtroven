<?php

namespace Tests\Support\Wix\Prototype;

use Closure;
use LogicException;
use RuntimeException;

/** Finite, synthetic parent-owned ledger witness. No production parser, fetch, DNS or network. */
final class ParentResultProbe
{
    public const FINALIZATION_RESERVE_NS = 250000000;

    public function __construct(private readonly string $binary = PHP_BINARY) {}

    /** @return array<string, int> */
    private static function budget(int $requests = 0): array
    {
        return ['elapsed_ms' => 0, 'requests' => $requests, 'redirects' => 0, 'retries' => 0, 'bytes' => 0, 'nodes' => 0];
    }

    /** Six finite contract snapshots, not evidence of WIX support. @return array<string, mixed> */
    public static function snapshot(string $scenario = 'exact'): array
    {
        if (! in_array($scenario, ['exact', 'partial', 'negative', 'ambiguous', 'incomplete', 'not_attempted', 'conflict', 'unverified'], true)) {
            throw new LogicException('Unknown synthetic scenario.');
        }
        $utc = '2026-10-24T00:00:00Z';
        $url = 'https://www.wixfilters.com/es/catalog/results/product.html/SYNTHETIC-0051515R.html';
        $searchUrl = 'https://www.wixfilters.com/api/graphql/catalog-stage';
        $attempt = static fn (string $id, string $profile, string $stage, array $refs, string $outcome): array => [
            'attempt_id' => $id, 'profile' => $profile, 'stage' => $stage, 'pages' => $stage === 'search' ? [1] : [],
            'requests' => 1, 'outcome' => $outcome, 'evidence_refs' => $refs, 'started_at' => $utc, 'finished_at' => $utc, 'budget_used' => self::budget(1),
        ];
        $evidence = static fn (string $id, string $profile, string $surface, string $source, string $locator, string $excerpt): array => [
            'attempt_id' => $id, 'profile' => $profile, 'surface' => $surface, 'source_url' => $source, 'final_url' => null,
            'captured_at' => $utc, 'locator' => $locator, 'raw_excerpt' => $excerpt,
        ];
        $searchProof = 'SYNTHETIC: recognized page=1 totalPages=1 total=1; unique WIX sales_designation=0051515R sku=SYNTHETIC-A urlKey=SYNTHETIC-0051515R; full search traversal closed.';
        $htmlProof = 'SYNTHETIC: recognized template; JSON-LD brand=WIX, visible sales_designation=0051515R, form sku=SYNTHETIC-A linked to unique search candidate.';
        $surfaceProof = 'SYNTHETIC: fixed synthetic inventory only (NOT either real profile certification); every required surface and page inspected and closed. dimension Height=132 mm; specification Repeated label=0.758 (number); application manufacturer=SYNTHETIC, model=001, series/variant/period=null, engine="", cell End=9999-12-31 (string), source id=0001, navigation /SYNTHETIC?id=01&id=02, identical desktop #synthetic-app-1 and tablet #synthetic-app-1-tablet; OE manufacturer=null reference=001. Gallery+linked metadata and documents both closed: reference=/SYNTHETIC/not-downloaded, unresolved URL=null, label=null, mainImage="true" (string); media position/type=null, format=TIFF, MIME=image/tiff, role mainImage="true". Eight observations labelled Repeated label, ordered as published: missing property; present null; string ""; boolean true; string "true"; number 123456789012345678901234567890.003; object {"id":"001"}; array ["001","001"]. All observations section=synthetic-technical, no published unit/adminValue except explicit dimension mm; no other cells, identifiers, metadata, roles or required pages. No download.';
        $dto = [
            'contract_version' => '1.0', 'requested_code' => '0051515R', 'status' => 'success', 'resolution' => 'exact', 'product' => null,
            'scope' => ['policy' => 'synthetic-v1-primary-negative-fallback', 'required_profiles' => ['es-co', 'en-ap'], 'consulted_profiles' => ['es-co'], 'exhaustive_profiles' => ['es-co'], 'conclusion' => 'SYNTHETIC exact primary; no fallback/merge; finite synthetic inventory.'],
            'source' => ['profile' => 'es-co', 'url' => $url, 'captured_at' => $utc, 'evidence_refs' => ['h-identity']],
            'attempts' => [$attempt('a-search', 'es-co', 'search', ['g-identity'], 'exhaustive_unique_candidate'), $attempt('a-detail', 'es-co', 'detail', ['h-identity', 'h-sections'], 'identity_verified')],
            'evidence' => [
                'g-identity' => $evidence('a-search', 'es-co', 'graphql_search', $searchUrl, '/data/catalogSearch', $searchProof),
                'h-identity' => $evidence('a-detail', 'es-co', 'html', $url, 'recognized-main:brand/designation/form/link', $htmlProof),
                'h-sections' => $evidence('a-detail', 'es-co', 'html', $url, 'recognized-main:fixed-synthetic-inventory', $surfaceProof),
            ],
            'coverage' => ['sections' => [], 'groups' => [], 'complete' => true], 'diagnostics' => [],
            'meta' => ['profile_versions' => ['es-co' => 'synthetic-parent-witness-1', 'en-ap' => 'synthetic-parent-witness-1'], 'parser_version' => 'synthetic-parent-witness-1', 'requested_at' => $utc, 'returned_at' => $utc, 'budget_used' => self::budget(2)],
        ];
        $observation = static fn (string $type, ?string $raw, int $order): array => [
            'label_raw' => 'Repeated label', 'value_raw' => $raw, 'value_presence' => $type === 'missing' ? 'missing' : 'present', 'raw_type' => $type,
            'unit_raw' => null, 'raw_admin_value' => null, 'section' => 'synthetic-technical', 'order' => $order, 'evidence_refs' => ['h-sections'],
        ];
        $dimension = $observation('string', '132', 0);
        $dimension['label_raw'] = 'Height';
        $dimension['unit_raw'] = 'mm';
        $resource = ['reference_raw' => '/SYNTHETIC/not-downloaded', 'url_resolved' => null, 'label_raw' => null, 'declared_metadata' => [['name' => 'mainImage', 'value_raw' => 'true', 'value_presence' => 'present', 'raw_type' => 'string', 'evidence_refs' => ['h-sections']]], 'order' => 0, 'evidence_refs' => ['h-sections']];
        $dto['product'] = [
            'identity' => ['brand' => 'WIX', 'code' => '0051515R', 'identifiers' => [['name' => 'sku', 'value' => 'SYNTHETIC-A', 'evidence_refs' => ['g-identity', 'h-identity']]], 'evidence_refs' => ['g-identity', 'h-identity']],
            'dimensions' => [$dimension], 'specifications' => [$observation('number', '0.758', 0)],
            'applications' => [['manufacturer_raw' => 'SYNTHETIC', 'model_raw' => '001', 'series_raw' => null, 'variant_raw' => null, 'engine_raw' => '', 'period_raw' => null,
                'cells' => [['label_raw' => 'End', 'value_raw' => '9999-12-31', 'unit_raw' => null, 'value_presence' => 'present', 'raw_type' => 'string', 'order' => 0, 'evidence_refs' => ['h-sections']]],
                'source_identifiers' => [['name' => 'id', 'value' => '0001']], 'navigation' => ['path' => '/SYNTHETIC', 'params' => [['name' => 'id', 'value' => '01'], ['name' => 'id', 'value' => '02']]],
                'occurrences' => [['surface' => 'desktop', 'locator' => '#synthetic-app-1', 'evidence_refs' => ['h-sections']], ['surface' => 'tablet', 'locator' => '#synthetic-app-1-tablet', 'evidence_refs' => ['h-sections']]], 'order' => 0, 'evidence_refs' => ['h-sections']]],
            'oe_references' => [['manufacturer_raw' => null, 'reference_raw' => '001', 'order' => 0, 'evidence_refs' => ['h-sections']]],
            'media' => [$resource + ['position_published' => null, 'type_declared' => null, 'format_declared' => 'TIFF', 'mime_declared' => 'image/tiff', 'roles' => [['name' => 'mainImage', 'value' => 'true']]]],
            'documents' => [$resource],
            'observations' => [$observation('missing', null, 0), $observation('null', null, 1), $observation('string', '', 2), $observation('boolean', 'true', 3), $observation('string', 'true', 4), $observation('number', '123456789012345678901234567890.003', 5), $observation('object', '{"id":"001"}', 6), $observation('array', '["001","001"]', 7)],
        ];
        foreach (ContractWitness::SECTIONS as $section) {
            $dto['coverage']['sections'][$section] = ['state' => 'captured', 'complete' => true, 'reasons' => [], 'evidence_refs' => $section === 'identity' ? ['g-identity', 'h-identity'] : ['h-sections']];
        }
        if ($scenario === 'partial') {
            self::gap($dto, 'media', 'parse_error', 'failed', 'SYNTHETIC local gallery failure; other units retained.');
            $dto['product']['media'] = [];
        } elseif ($scenario !== 'exact') {
            $dto['product'] = null;
            $dto['source'] = null;
            $dto['status'] = 'failed';
            $dto['resolution'] = $scenario === 'negative' ? 'no_exact_match' : ($scenario === 'ambiguous' ? 'ambiguous' : ($scenario === 'not_attempted' ? 'not_attempted' : 'incomplete'));
            foreach (ContractWitness::SECTIONS as $section) {
                $dto['coverage']['sections'][$section] = ['state' => 'not_checked', 'complete' => false, 'reasons' => ['SYNTHETIC identity not confirmed; no dependent inspection.'], 'evidence_refs' => []];
            }
            if ($scenario === 'negative') {
                $dto['status'] = 'success';
                $dto['scope']['consulted_profiles'] = ['es-co', 'en-ap'];
                $dto['scope']['exhaustive_profiles'] = ['es-co', 'en-ap'];
                $dto['scope']['conclusion'] = 'SYNTHETIC exhaustive negative only in required es-co/en-ap.';
                $dto['attempts'] = [$attempt('a-search', 'es-co', 'search', ['g-identity'], 'exhaustive_negative'), $attempt('a-ap', 'en-ap', 'search', ['g-ap'], 'exhaustive_negative')];
                $dto['evidence'] = ['g-identity' => $evidence('a-search', 'es-co', 'graphql_search', $searchUrl, '/data/catalogSearch', 'SYNTHETIC recognized page=1 totalPages=0 total=0 items=[] errors absent; exhaustive negative.'), 'g-ap' => $evidence('a-ap', 'en-ap', 'graphql_search', 'https://www.wixfilters.com/api/graphql/catalog-prod', '/data/catalogSearch', 'SYNTHETIC recognized page=1 totalPages=0 total=0 items=[] errors absent; exhaustive negative.')];
            } elseif ($scenario === 'not_attempted') {
                $dto['attempts'] = [];
                $dto['evidence'] = [];
                $dto['scope']['consulted_profiles'] = $dto['scope']['exhaustive_profiles'] = [];
                $dto['meta']['budget_used'] = self::budget();
                self::diagnostic($dto, 'invalid_configuration', null, null, 'SYNTHETIC required primitive unavailable before child/network.');
            } else {
                $dto['attempts'] = [$dto['attempts'][0]];
                $dto['evidence'] = ['g-identity' => $dto['evidence']['g-identity']];
                $dto['meta']['budget_used'] = self::budget(1);
                $dto['scope']['exhaustive_profiles'] = [];
                $dto['scope']['conclusion'] = 'SYNTHETIC inconclusive primary; fallback forbidden.';
                $code = match ($scenario) {
                    'ambiguous' => 'ambiguous_identity', 'conflict' => 'identity_conflict', 'unverified' => 'identity_unverified', default => 'budget_exhausted',
                };
                if ($scenario === 'ambiguous') {
                    $dto['attempts'][0]['outcome'] = 'ambiguous_candidates';
                    $dto['evidence']['g-identity']['raw_excerpt'] = 'SYNTHETIC two distinct valid exact identities: WIX sales_designation=0051515R sku=SYNTHETIC-A urlKey=A; WIX sales_designation=0051515R sku=SYNTHETIC-B urlKey=B. No claimed exhaustion.';
                } elseif ($scenario === 'conflict') {
                    $dto['attempts'][0]['outcome'] = 'identity_conflict';
                    $dto['evidence']['g-identity']['raw_excerpt'] = 'SYNTHETIC WIX sales_designation=0051515R, same candidate reference urlKey=A with contradictory comparable identifiers: sku=SYNTHETIC-A versus sku=SYNTHETIC-B. No valid unique identity and no two distinct verified identities; traversal not closed.';
                } elseif ($scenario === 'unverified') {
                    $dto['attempts'][0]['outcome'] = 'identity_unverified';
                    $dto['evidence']['g-identity']['raw_excerpt'] = 'SYNTHETIC candidate WIX sales_designation=0051515R, required sku and urlKey missing; identity cannot be verified; traversal not closed.';
                }
                self::diagnostic($dto, $code, 'identity', 'a-search', 'SYNTHETIC primary identity cannot be concluded.', ['g-identity']);
            }
        }
        self::derive($dto);

        return $dto;
    }

    /**
     * Parent already owns synthetic captures; child acknowledgements commit only independent unit results.
     * Work D is min(global minus finalization reserve, soft unit cap); no refreshed regional/global budget.
     *
     * @param  array<string, string>  $plan  @return array<string, mixed>
     */
    public function run(array $plan, int $deadline, int $unitTimeoutMs = 1000, bool $identityConfirmed = true, ?Closure $cancelled = null, int $remainingUnits = 16, ?Closure $unitExecutor = null): array
    {
        $started = hrtime(true);
        if ($unitTimeoutMs < 150 || $unitTimeoutMs > 4000 || count($plan) > 8 || $remainingUnits < 0 || $remainingUnits > 16) {
            throw new LogicException('Invalid finite test profile.');
        }
        foreach ($plan as $section => $operation) {
            if (! in_array($section, array_diff(ContractWitness::SECTIONS, ['identity']), true) || ! in_array($operation, SupervisedPhpTask::OPERATIONS, true)) {
                throw new LogicException('Unknown finite synthetic unit.');
            }
        }
        $supervisor = new SupervisedPhpTask($this->binary);
        $dto = self::snapshot($supervisor->preflight()['problems'] === [] ? ($identityConfirmed ? 'exact' : 'incomplete') : 'not_attempted');
        $sourceFacts = $dto;
        $capturedProduct = $dto['product'];
        $capturedSections = $dto['coverage']['sections'];
        if ($identityConfirmed && $capturedProduct !== null) {
            foreach ($plan as $section => $operation) {
                $dto['product'][$section] = [];
                $dto['coverage']['sections'][$section] = ['state' => 'not_checked', 'complete' => false, 'reasons' => ['SYNTHETIC independent extraction pending.'], 'evidence_refs' => []];
            }
        }
        $reports = [];
        $lastFailure = null;
        $finalizeAt = $deadline - self::FINALIZATION_RESERVE_NS;
        if ($dto['resolution'] === 'not_attempted') {
            $plan = [];
        }
        foreach ($plan as $section => $operation) {
            $unitDeadline = min($finalizeAt, hrtime(true) + $unitTimeoutMs * 1000000);
            if (hrtime(true) + 150000000 >= $unitDeadline || $remainingUnits === 0) {
                $reason = 'budget_exhausted';
                $report = ['reason' => 'not_started', 'pid' => null, 'reaped' => false, 'pipes_closed' => true];
            } elseif ($lastFailure !== null && $section === 'documents') {
                $reason = $lastFailure;
                $report = ['reason' => 'dependency_not_started', 'pid' => null, 'reaped' => false, 'pipes_closed' => true];
            } else {
                $remainingUnits--;
                $report = $unitExecutor === null
                    ? $supervisor->run(['operation' => $operation, 'payload' => 'SYNTHETIC unit '.$section, 'units' => 1], $unitDeadline, $cancelled)
                    : $unitExecutor($section, $operation, $unitDeadline, $cancelled);
                $reason = match ($report['reason']) {
                    'completed' => null,
                    'deadline', 'cancelled', 'sampled_rss_limit' => 'budget_exhausted',
                    'transport_error' => 'transport_error',
                    default => 'parse_error',
                };
            }
            $reports[$section] = $report + ['absolute_deadline_ns' => $unitDeadline, 'global_deadline_ns' => $deadline];
            if ($reason !== null) {
                $lastFailure ??= $reason;
            }
            if ($reason !== null && $identityConfirmed) {
                self::gap($dto, $section, $reason, $report['pid'] === null ? 'not_checked' : 'failed', 'SYNTHETIC '.$report['reason'].'; original budget retained.');
                $dto['product'][$section] = [];
            } elseif ($reason === null && $identityConfirmed) {
                $dto['product'][$section] = $capturedProduct[$section];
                $dto['coverage']['sections'][$section] = $capturedSections[$section];
            }
        }
        if (! $identityConfirmed && $dto['resolution'] !== 'not_attempted') {
            $dto['diagnostics'] = [];
            $cause = $lastFailure ?? 'identity_unverified';
            self::diagnostic($dto, $cause, 'identity', 'a-search', 'SYNTHETIC identity remains unconfirmed; cause='.$cause.'.', ['g-identity']);
        }
        self::derive($dto);
        $dto['meta']['budget_used']['elapsed_ms'] = (int) ceil((hrtime(true) - $started) / 1000000);
        $buildStarted = hrtime(true);
        ContractWitness::assertRetained($dto, $sourceFacts);
        $units = $bytes = 0;
        ContractWitness::admit($dto, $units, $bytes);
        $json = json_encode(array_replace($dto, ['evidence' => (object) $dto['evidence']]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $roundTrip = json_decode($json, true, 20, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        ContractWitness::assertRetained($roundTrip, $sourceFacts);
        $evidenceIsObject = is_object(json_decode($json, false, 20, JSON_THROW_ON_ERROR)->evidence);
        $returned = hrtime(true);
        if ($returned >= $deadline) {
            throw new RuntimeException('Parent DTO missed original deadline; task remains open.');
        }

        return ['dto' => $roundTrip, 'reports' => $reports, 'remaining_units' => $remainingUnits, 'elapsed_ms' => ($returned - $started) / 1000000,
            'build_validate_serialize_ms' => ($returned - $buildStarted) / 1000000, 'reserved_ms' => self::FINALIZATION_RESERVE_NS / 1000000,
            'remaining_ms' => ($deadline - $returned) / 1000000, 'overshoot_ms' => max(0.0, ($returned - $deadline) / 1000000), 'json_bytes' => strlen($json), 'admitted_units' => $units,
            'json_evidence_is_object' => $evidenceIsObject];
    }

    /** Test-only comparable late contradiction, not a production identity verifier. @param array<string, mixed> $dto @return array<string, mixed> */
    public static function invalidate(array $dto): array
    {
        $dto['product'] = $dto['source'] = null;
        $dto['status'] = 'failed';
        $dto['resolution'] = 'incomplete';
        $dto['coverage']['sections']['identity'] = ['state' => 'failed', 'complete' => false, 'reasons' => ['SYNTHETIC comparable sku contradicted by later inspected surface.'], 'evidence_refs' => ['h-conflict']];
        $dto['evidence']['h-conflict'] = $dto['evidence']['h-identity'];
        $dto['evidence']['h-conflict']['locator'] = 'recognized-main:later-comparable-form-sku';
        $dto['evidence']['h-conflict']['raw_excerpt'] = 'SYNTHETIC later comparable form sku=SYNTHETIC-B contradicts verified candidate sku=SYNTHETIC-A.';
        $dto['attempts'][1]['evidence_refs'][] = 'h-conflict';
        self::diagnostic($dto, 'identity_mismatch', 'identity', 'a-detail', 'SYNTHETIC late contradiction invalidates all product projections.', ['h-conflict']);
        self::derive($dto);

        return $dto;
    }

    /** @param array<string, mixed> $dto */
    private static function gap(array &$dto, string $section, string $code, string $state, string $message): void
    {
        $dto['status'] = 'partial';
        $dto['coverage']['sections'][$section] = ['state' => $state, 'complete' => false, 'reasons' => [$message], 'evidence_refs' => $state === 'not_checked' ? [] : ['h-sections']];
        self::diagnostic($dto, $code, $section, 'a-detail', $message, $state === 'not_checked' ? [] : ['h-sections']);
    }

    /** @param array<string, mixed> $dto @param list<string> $refs */
    private static function diagnostic(array &$dto, string $code, ?string $section, ?string $attempt, string $message, array $refs = []): void
    {
        $dto['diagnostics'][] = ['code' => $code, 'stage' => 'synthetic-parent', 'attempt_id' => $attempt, 'severity' => 'error', 'recovered' => false, 'message' => $message, 'section' => $section, 'evidence_refs' => $refs];
    }

    /** @param array<string, mixed> $dto */
    private static function derive(array &$dto): void
    {
        $sections = $dto['coverage']['sections'];
        $groups = ['identity' => ['complete' => $sections['identity']['complete']], 'technical' => ['complete' => true], 'resources' => ['complete' => $sections['media']['complete'] && $sections['documents']['complete']]];
        foreach (['dimensions', 'specifications', 'applications', 'oe_references', 'observations'] as $name) {
            $groups['technical']['complete'] = $groups['technical']['complete'] && $sections[$name]['complete'];
        }
        $dto['coverage']['groups'] = $groups;
        $dto['coverage']['complete'] = $dto['product'] !== null && $groups['identity']['complete'] && $groups['technical']['complete'] && $groups['resources']['complete'];
    }
}
