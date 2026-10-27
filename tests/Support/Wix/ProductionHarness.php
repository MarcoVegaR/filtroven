<?php

namespace Tests\Support\Wix;

use App\Support\Wix\Contracts\WixTransport;
use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Data\LookupMeta;
use App\Support\Wix\Data\RegionalResolution;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Data\SectionCoverage;
use App\Support\Wix\Data\WixProduct;
use App\Support\Wix\Enums\CoverageState;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\Enums\LookupResolution;
use App\Support\Wix\EvidenceBuilder;
use App\Support\Wix\Http\BoundedResolver;
use App\Support\Wix\Http\DestinationGuard;
use App\Support\Wix\Http\WixHttpTransport;
use App\Support\Wix\LookupBudget;
use App\Support\Wix\LookupContext;
use App\Support\Wix\LookupLimits;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\HtmlSurface;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Profiles\SurfaceInventory;
use App\Support\Wix\ResultFactory;
use App\Support\Wix\Runtime\Admission;
use App\Support\Wix\Runtime\SupervisedPhpTask;
use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\NativeLab;

final class ProductionHarness implements WixTransport
{
    /** @var list<RequestPlan> */
    public array $requests = [];

    /** @param list<string|CapturedResponse|\Closure> $responses */
    public function __construct(private array $responses) {}

    public static function context(string $code = '51515', array $overrides = []): LookupContext
    {
        $config = require __DIR__.'/../../../config/wix.php';
        $limits = LookupLimits::fromArray(array_replace($config['limits'], ['input_max_bytes' => 256, 'max_search_pages' => 4, 'max_section_pages' => 4, 'page_size' => 15, 'max_nodes_total' => 250000], $overrides));

        return new LookupContext($code, new LookupBudget($limits), gmdate('Y-m-d\TH:i:s\Z'), new EvidenceBuilder(16384));
    }

    public static function corpus(): FixtureCorpus
    {
        return new FixtureCorpus(__DIR__.'/../../fixtures/wix');
    }

    public static function configuration(array $overrides = []): array
    {
        $config = require __DIR__.'/../../../config/wix.php';
        $config['enabled'] = true;
        $config['limits'] = array_replace($config['limits'], ['input_max_bytes' => 256, 'max_search_pages' => 4, 'max_section_pages' => 4, 'page_size' => 15, 'max_nodes_total' => 250000], $overrides);

        return $config;
    }

    /** Test-only mapping AFTER the real origin/all-address DNS guard, never a production loopback exception.
     * @param  list<string>  $paths
     * @return array{WixHttpTransport,SupervisedPhpTask,BoundedResolver,string}
     */
    public static function native(NativeLab $lab, array $paths): array
    {
        $lock = $lab->directory.'/admission';
        file_put_contents($lock, '');
        $stat = stat($lock);
        $resolver = new BoundedResolver('127.0.0.1', $lab->ready['dns_port']);
        $guard = new class($resolver, $lab, $paths) extends DestinationGuard
        {
            public function __construct(BoundedResolver $resolver, private readonly NativeLab $lab, private array $paths)
            {
                parent::__construct($resolver);
            }

            public function prepare(RequestPlan $request, LookupContext $context): array
            {
                parent::prepare($request, $context);
                if ($this->paths === []) {
                    throw new \LogicException('Unexpected native offline request');
                }
                $path = array_shift($this->paths);

                return ['host' => 'runtime.test', 'port' => $this->lab->ready['tls_port'], 'path' => $path,
                    'url' => 'https://runtime.test:'.$this->lab->ready['tls_port'].$path, 'ip' => '127.0.0.1', 'expires' => $context->budget->connectDeadline];
            }
        };
        $supervisor = new SupervisedPhpTask;

        return [new WixHttpTransport($guard, $supervisor, new Admission($lock, $stat['dev'], $stat['ino'], 'linux-cli-one-host-shared-inode'), $lab->directory.'/cert.pem'), $supervisor, $resolver, $lock];
    }

    public static function deadlineMeasurement(string $scenario, int $started, int $deadline, array $dto): void
    {
        $returned = hrtime(true);
        if (getenv('PRD15_MEASURE_SUPERVISOR') === '1') {
            fwrite(STDERR, 'WIX_DEADLINE_ACCEPTANCE '.json_encode(['scenario' => $scenario, 'elapsed_ms' => ($returned - $started) / 1000000,
                'window_ms' => ($deadline - $started) / 1000000, 'remaining_ms' => ($deadline - $returned) / 1000000,
                'overshoot_ms' => max(0, ($returned - $deadline) / 1000000), 'status' => $dto['status'], 'resolution' => $dto['resolution'], 'budget' => $dto['meta']['budget_used']], JSON_THROW_ON_ERROR)."\n");
        }
    }

    public static function html(string $fixture, ?string $body = null): HtmlSurface
    {
        $profile = str_starts_with($fixture, 'es-co') ? 'es-co' : 'en-ap';
        $corpus = self::corpus();
        $request = new RequestPlan('detail-'.$profile, $profile, EvidenceSurface::Html, $corpus->requestUrl($fixture), []);
        $response = new CapturedResponse($request, 200, [], $body ?? $corpus->body($fixture), null, gmdate('Y-m-d\TH:i:s\Z'));
        $context = self::context();

        return new HtmlSurface((new SupervisedDocumentReader(new SupervisedPhpTask))->read($response, $context), $context);
    }

    public function send(RequestPlan $request, LookupContext $context): CapturedResponse
    {
        $this->requests[] = $request;
        if ($this->responses === []) {
            throw new \LogicException('Unexpected offline request');
        }
        $context->budget->begin();
        try {
            $value = array_shift($this->responses);
            if ($value instanceof \Closure) {
                $value = $value($request, $context);
            }
            $response = $value instanceof CapturedResponse ? $value : new CapturedResponse($request, 200, [], $value, null, gmdate('Y-m-d\TH:i:s\Z'));
            $context->budget->receive(strlen($response->body));

            return $response;
        } finally {
            $context->budget->finish();
        }
    }

    /** Test consumer only: verifies phase4 handoff with the real factory and independent prototype oracle. */
    public static function assertContract(RegionalResolution $result, LookupContext $context): array
    {
        $identity = $result->identity?->identity();
        $source = $result->identity?->source();
        $resolution = $result->effectiveResolution();
        $diagnostics = $context->diagnostics();
        if ($resolution === LookupResolution::Exact && $identity === null) {
            $resolution = LookupResolution::Incomplete;
        }
        $mismatch = array_any($diagnostics, fn ($d): bool => $d->code === DiagnosticCode::IdentityMismatch);
        $sections = [];
        foreach (CoverageSections::NAMES as $name) {
            $complete = $name === 'identity' && $identity !== null;
            $state = $complete ? CoverageState::Captured : ($name === 'identity' && $mismatch ? CoverageState::Failed : CoverageState::NotChecked);
            $refs = $complete ? $identity->evidence_refs : ($name === 'identity' && $mismatch ? array_keys($context->evidence->all()) : []);
            $sections[] = new SectionCoverage($state, $complete, $complete ? [] : ['Section capture is outside the phase4 handoff'], $refs);
            if ($identity !== null && ! $complete) {
                $diagnostics[] = (new OperationalFailure(DiagnosticCode::UnsupportedSection, 'test-consumer', null, $name))->diagnostic();
            }
        }
        $product = $identity === null ? null : new WixProduct($identity, [], [], [], [], [], [], []);
        $versions = [];
        foreach ($result->scope->consulted_profiles as $profile) {
            $versions[$profile] = SurfaceInventory::PROFILE_VERSION;
        }
        $meta = new LookupMeta($versions, 'phase4-test-consumer', $context->requestedAt, gmdate('Y-m-d\TH:i:s\Z'), $context->budget->snapshot());
        $dto = (new ResultFactory)->build($context->requestedCode, $resolution, $product, $result->effectiveScope(), $source, $context->attempts(), $context->evidence->all(), new CoverageSections(...$sections), $diagnostics, $meta)->toArray();
        ContractWitness::assert($dto);

        return $dto;
    }
}
