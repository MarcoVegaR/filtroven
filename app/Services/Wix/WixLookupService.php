<?php

namespace App\Services\Wix;

use App\Support\Wix\Contracts\WixLookup;
use App\Support\Wix\Contracts\WixTransport;
use App\Support\Wix\Data\BudgetSnapshot;
use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Data\LookupMeta;
use App\Support\Wix\Data\LookupScope;
use App\Support\Wix\Data\SectionCoverage;
use App\Support\Wix\Data\WixLookupResult;
use App\Support\Wix\Data\WixProduct;
use App\Support\Wix\DetailVerifier;
use App\Support\Wix\Enums\CoverageState;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\LookupResolution;
use App\Support\Wix\EvidenceBuilder;
use App\Support\Wix\Http\AssetMetadataPaginator;
use App\Support\Wix\Http\BoundedResolver;
use App\Support\Wix\Http\DestinationGuard;
use App\Support\Wix\Http\WixHttpTransport;
use App\Support\Wix\LookupBudget;
use App\Support\Wix\LookupContext;
use App\Support\Wix\LookupTelemetry;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\ApplicationExtractor;
use App\Support\Wix\Parsing\DocumentExtractor;
use App\Support\Wix\Parsing\MediaExtractor;
use App\Support\Wix\Parsing\OeReferenceExtractor;
use App\Support\Wix\Parsing\SectionExtractor;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Parsing\SurfacePlan;
use App\Support\Wix\Parsing\TechnicalSectionExtractor;
use App\Support\Wix\Parsing\ValueReader;
use App\Support\Wix\Preflight;
use App\Support\Wix\Profiles\ProfileRegistry;
use App\Support\Wix\RegionalLookupPolicy;
use App\Support\Wix\ResultFactory;
use App\Support\Wix\Runtime\Admission;
use App\Support\Wix\Runtime\SupervisedPhpTask;
use App\Support\Wix\SearchPaginator;

final readonly class WixLookupService implements WixLookup
{
    public const ParserVersion = 'capture-2026-10-25.1';

    /** Configuration is re-read at fetch, admission, each send and supervised checkpoint. No constructor I/O. */
    public function __construct(private ?\Closure $configuration = null, private ?WixTransport $transport = null, private ?LookupTelemetry $telemetry = null, private ?\Closure $callerDeadline = null) {}

    public function fetch(string $wixCode): WixLookupResult
    {
        $started = hrtime(true);
        $requestedAt = gmdate('Y-m-d\TH:i:s\Z');

        return ($this->telemetry ?? new LookupTelemetry)->scope(function (\Closure $log) use ($wixCode, $started, $requestedAt): WixLookupResult {
            $log('begin', ['input_bytes' => strlen($wixCode), 'parser_version' => self::ParserVersion]);
            try {
                $config = $this->config();
                if (! is_bool($config['enabled'] ?? null)) {
                    throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
                }
                if ($config['enabled'] && ! is_array($config['limits'] ?? null)) {
                    throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
                }
                $limits = (new Preflight)->validate($wixCode, ($config['enabled'] ?? null) === true, $config['limits'] ?? []);
                if (! is_bool($config['fallback'] ?? null) || ($config['policy_version'] ?? null) !== '1.0') {
                    throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
                }
                $transport = $this->transport ?? $this->nativeTransport($config);
            } catch (OperationalFailure $failure) {
                $log('failure', ['code' => $failure->diagnosticCode->value, 'stage' => $failure->stage]);

                $result = $this->notAttempted($wixCode, $requestedAt, $failure, $started);
                $log('end', ['status' => $result->status->value, 'resolution' => $result->resolution->value, 'budget_used' => $result->meta->budget_used->toArray()]);

                return $result;
            }
            $deadline = $this->callerDeadline === null ? PHP_INT_MAX : ($this->callerDeadline)();
            if (! is_int($deadline)) {
                throw new \LogicException('Caller deadline must be a monotonic integer');
            }
            $budget = new LookupBudget($limits, min($deadline, $started + $limits->totalTimeoutMs * 1000000), enabled: fn (): bool => ($this->config()['enabled'] ?? null) === true);
            $log('policy', ['policy_version' => $config['policy_version'], 'fallback' => $config['fallback'], 'limits' => get_object_vars($limits)]);
            $context = new LookupContext($wixCode, $budget, $requestedAt, new EvidenceBuilder(16384), static function ($attempt) use ($log): void {
                $log('stage', ['profile' => $attempt->profile, 'stage' => $attempt->stage, 'outcome' => $attempt->outcome, 'pages' => count($attempt->pages), 'budget_used' => $attempt->budget_used->toArray()]);
            });
            $lookup = function () use ($transport, $config, $context, $budget, $log, $wixCode, $requestedAt): WixLookupResult {
                $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
                $profiles = new ProfileRegistry;
                $resolution = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), $profiles, $config['fallback']))->resolve($context);
                $product = null;
                if ($resolution->identity?->identity() !== null) {
                    $values = new ValueReader;
                    $media = new MediaExtractor($values, $reader);
                    try {
                        [$product, $coverage] = (new SurfacePlan(new SectionExtractor($values, $reader), new TechnicalSectionExtractor($values), new ApplicationExtractor($values), new OeReferenceExtractor, new DocumentExtractor, $media, new AssetMetadataPaginator($transport, $reader, $media)))->capture($resolution, $context);
                    } catch (OperationalFailure $failure) {
                        $sections = [new SectionCoverage(CoverageState::Captured, true, [], $resolution->identity->identity()->evidence_refs)];
                        foreach (array_slice(CoverageSections::NAMES, 1) as $section) {
                            $sections[] = new SectionCoverage(CoverageState::NotChecked, false, [$failure->diagnosticCode->value], []);
                            $context->diagnose((new OperationalFailure($failure->diagnosticCode, $failure->stage, null, $section))->diagnostic());
                        }
                        $coverage = new CoverageSections(...$sections);
                        $product = new WixProduct($resolution->identity->identity(), [], [], [], [], [], [], []);
                    }
                } else {
                    $coverage = $this->unchecked('No verified product');
                    if (array_any($context->diagnostics(), fn ($d): bool => $d->code === DiagnosticCode::IdentityMismatch)) {
                        $sections = $coverage->toArray();
                        $sections['identity'] = ['state' => 'failed', 'complete' => false, 'reasons' => ['identity_mismatch'], 'evidence_refs' => array_keys($context->evidence->all())];
                        $coverage = CoverageSections::fromArray($sections);
                    }
                }
                $versions = [];
                foreach ($resolution->scope->consulted_profiles as $profile) {
                    $versions[$profile] = $profiles->get($profile)->version();
                }
                $context->closeEvidence();
                $result = (new ResultFactory)->build($wixCode, $resolution->effectiveResolution(), $product, $resolution->effectiveScope(), $resolution->identity?->source(), $context->attempts(), $context->evidence->all(), $coverage, $context->diagnostics(), new LookupMeta($versions, self::ParserVersion, $requestedAt, gmdate('Y-m-d\TH:i:s\Z'), $budget->snapshot()));
                if ($result->diagnostics !== []) {
                    $log('failure', ['diagnostic_codes' => array_map(fn ($d): string => $d->code->value, $result->diagnostics), 'recovered' => count(array_filter($result->diagnostics, fn ($d): bool => $d->recovered))]);
                }
                $log('end', ['status' => $result->status->value, 'resolution' => $result->resolution->value, 'profile_versions' => $versions, 'groups' => $result->coverage->groups->toArray(), 'budget_used' => $budget->snapshot()->toArray(), 'diagnostic_codes' => array_map(fn ($d): string => $d->code->value, $result->diagnostics)]);

                return $result;
            };
            try {
                return $transport instanceof WixHttpTransport ? $transport->withinLookup($context, $lookup) : $lookup();
            } catch (OperationalFailure $failure) {
                $log('failure', ['code' => $failure->diagnosticCode->value, 'stage' => $failure->stage]);
                if ($context->attempts() !== []) {
                    throw $failure;
                }
                if ($failure->diagnosticCode === DiagnosticCode::InvalidConfiguration) {
                    return $this->notAttempted($wixCode, $requestedAt, $failure, $started);
                }

                return (new ResultFactory)->build($wixCode, LookupResolution::Incomplete, null, new LookupScope($config['fallback'] ? 'es-co-then-en-ap-v1' : 'es-co-only-v1', $config['fallback'] ? ['es-co', 'en-ap'] : ['es-co'], [], [], 'incomplete'), null, [], [], $this->unchecked($failure->diagnosticCode->value), [$failure->diagnostic()], new LookupMeta([], self::ParserVersion, $requestedAt, gmdate('Y-m-d\TH:i:s\Z'), $budget->snapshot()));
            }
        });
    }

    private function config(): array
    {
        $config = $this->configuration === null ? require __DIR__.'/../../../config/wix.php' : ($this->configuration)();
        if (! is_array($config)) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'preflight');
        }

        return $config;
    }

    private function nativeTransport(array $config): WixTransport
    {
        $runtime = $config['runtime'] ?? [];
        foreach (['dns_server', 'admission_path', 'topology', 'ca_file'] as $field) {
            if (! is_string($runtime[$field] ?? null) || $runtime[$field] === '') {
                throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'runtime');
            }
        }
        if (filter_var($runtime['dns_server'], FILTER_VALIDATE_IP) === false || ! is_int($runtime['device'] ?? null) || ! is_int($runtime['inode'] ?? null)
            || ! is_int($runtime['dns_port'] ?? null) || $runtime['dns_port'] < 1 || $runtime['dns_port'] > 65535 || $runtime['topology'] !== 'linux-cli-one-host-shared-inode' || ! is_file($runtime['ca_file'])) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'runtime');
        }

        return new WixHttpTransport(new DestinationGuard(new BoundedResolver($runtime['dns_server'], $runtime['dns_port'])), new SupervisedPhpTask,
            new Admission($runtime['admission_path'], $runtime['device'], $runtime['inode'], $runtime['topology']), $runtime['ca_file']);
    }

    private function unchecked(string $reason): CoverageSections
    {
        return new CoverageSections(...array_map(fn (): SectionCoverage => new SectionCoverage(CoverageState::NotChecked, false, [$reason], []), CoverageSections::NAMES));
    }

    private function notAttempted(string $literal, string $requestedAt, OperationalFailure $failure, int $started): WixLookupResult
    {
        return (new ResultFactory)->build($literal, LookupResolution::NotAttempted, null, new LookupScope('es-co-then-en-ap-v1', ['es-co', 'en-ap'], [], [], 'not_attempted'), null, [], [], $this->unchecked($failure->diagnosticCode->value), [$failure->diagnostic()], new LookupMeta([], self::ParserVersion, $requestedAt, gmdate('Y-m-d\TH:i:s\Z'), new BudgetSnapshot((int) ceil((hrtime(true) - $started) / 1000000), 0, 0, 0, 0, 0)));
    }
}
