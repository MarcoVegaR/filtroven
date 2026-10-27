<?php

use App\Providers\AppServiceProvider;
use App\Services\Wix\WixLookupService;
use App\Support\Wix\Contracts\WixLookup;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Http\BoundedResolver;
use App\Support\Wix\Http\DestinationGuard;
use App\Support\Wix\Http\WixHttpTransport;
use App\Support\Wix\LookupContext;
use App\Support\Wix\LookupTelemetry;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Runtime\Admission;
use App\Support\Wix\Runtime\SupervisedPhpTask;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\Support\Wix\ProductionHarness;
use Tests\Support\Wix\Prototype\ContractWitness;
use Tests\Support\Wix\Prototype\NativeLab;

it('provides a standalone enabled interface that fails closed for missing configuration and exact rejected literals', function (string $literal, string $code) {
    $dto = (new WixLookupService)->fetch($literal)->toArray();
    ContractWitness::assert($dto);
    expect($dto['requested_code'])->toBe($literal)->and($dto['resolution'])->toBe('not_attempted')->and($dto['diagnostics'][0]['code'])->toBe($code)->and($dto['meta']['budget_used']['requests'])->toBe(0);
})->with([['51515', 'invalid_configuration'], ['', 'invalid_input'], [' 51515', 'invalid_input'], ["51515\n", 'invalid_input']]);

it('does not read configuration or perform transport work on enabled construction and keeps both gates zero I/O', function (bool $enabled) {
    $config = require __DIR__.'/../../../config/wix.php';
    expect($config['enabled'])->toBeTrue();
    $config['enabled'] = $enabled;
    $reads = 0;
    $transport = new ProductionHarness([]);
    $childrenPath = '/proc/self/task/'.getmypid().'/children';
    $childrenBefore = file_get_contents($childrenPath);
    $service = new WixLookupService(function () use (&$reads, $config): array {
        $reads++;

        return $config;
    }, $transport);
    expect($reads)->toBe(0)->and($transport->requests)->toBe([]);
    $dto = $service->fetch('51515')->toArray();
    ContractWitness::assert($dto);
    expect($reads)->toBe(1)->and($dto['status'])->toBe('failed')->and($dto['resolution'])->toBe('not_attempted')
        ->and($dto['diagnostics'][0]['code'])->toBe($enabled ? 'invalid_configuration' : 'disabled')
        ->and($dto['product'])->toBeNull()->and($dto['source'])->toBeNull()
        ->and($dto['attempts'])->toBe([])->and($dto['evidence'])->toBe([])
        ->and($dto['meta']['budget_used']['requests'])->toBe(0)->and($transport->requests)->toBe([])
        ->and(file_get_contents($childrenPath))->toBe($childrenBefore);
})->with(['configured enabled' => true, 'explicitly disabled' => false]);

it('fetches genuine CO and AP products through the production supervised capture pipeline', function (string $code, array $fixtures, int $pageSize, int $mediaCount) {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness(array_map($corpus->body(...), $fixtures));
    $config = ProductionHarness::configuration(['page_size' => $pageSize]);
    $result = (new WixLookupService(fn (): array => $config, $transport))->fetch($code)->toArray();
    ContractWitness::assert($result, captured: true);
    expect($result['resolution'])->toBe('exact')->and($result['product']['identity']['code'])->toBe($code)->and($result['product']['media'])->toHaveCount($mediaCount);
    expect($result['coverage']['sections']['media']['complete'])->toBeTrue()->and($result['coverage']['sections']['documents']['state'])->toBe('published_empty');
    expect($result['coverage']['complete'])->toBeTrue()->and($result['status'])->toBe('success');
    expect(count($transport->requests))->toBe(count($fixtures));
    if ($code === '51515R') {
        $specs = $result['product']['specifications'];
        $pressures = array_values(array_filter($specs, fn ($o): bool => in_array($o['source_key'] ?? null, ['bypass_valve_opening_pressurem', 'bypass_valve_opening_pressure'], true)));
        expect(array_column($pressures, 'value_raw'))->toBe(['0.758', '0.551']);
    } else {
        expect(array_any($result['product']['dimensions'], fn ($o): bool => $o['value_raw'] === '132.003'))->toBeTrue();
        expect(array_any($result['product']['observations'], fn ($o): bool => ($o['source_key'] ?? null) === 'main_application_aa'))->toBeTrue();
    }
})->with([
    ['51515', ['es-co-51515-search-p1-s1', 'es-co-51515-search-p2-s1', 'es-co-51515-search-p3-s1', 'es-co-51515-detail', 'es-co-51515_WIX-assets-l25-o0'], 1, 4],
    ['51515R', ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail', 'en-ap-51515R_WIX-assets-l25-o0'], 15, 12],
]);

it('does not reuse lookup payloads evidence or attempts across two calls', function () {
    $corpus = ProductionHarness::corpus();
    $fixtures = ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail', 'en-ap-51515R_WIX-assets-l25-o0'];
    $transport = new ProductionHarness(array_map($corpus->body(...), [...$fixtures, ...$fixtures]));
    $config = ProductionHarness::configuration();
    $service = new WixLookupService(fn (): array => $config, $transport);
    $first = $service->fetch('51515R');
    $second = $service->fetch('51515R');
    expect($first)->not->toBe($second)->and($first->attempts[0])->not->toBe($second->attempts[0])->and(count($transport->requests))->toBe(8)
        ->and($first->meta->budget_used->requests)->toBe(4)->and($second->meta->budget_used->requests)->toBe(4);
});

it('resolves the real transient Laravel bindings without database HTTP or constructor I/O', function () {
    $app = new Container;
    $app->instance('config', new Repository(['wix' => require __DIR__.'/../../../config/wix.php']));
    $app->instance(LoggerInterface::class, new NullLogger);
    $app->instance(Illuminate\Log\Context\Repository::class, new Illuminate\Log\Context\Repository(new Dispatcher($app)));
    (new AppServiceProvider($app))->register();
    $first = $app->make(WixLookup::class);
    $second = $app->make(WixLookupService::class);
    expect($first)->toBeInstanceOf(WixLookupService::class)->not->toBe($second)
        ->and($first->fetch('51515')->resolution->value)->toBe('not_attempted');
});

it('inherits or generates scoped correlation and restores context even on a visible programming bug', function (bool $bug, ?string $parent) {
    $repository = new Illuminate\Log\Context\Repository(new Dispatcher);
    if ($parent !== null) {
        $repository->add('correlation_id', $parent);
    }
    $repository->add('caller', 'retained');
    $before = $repository->all();
    $logger = new class extends AbstractLogger
    {
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [(string) $message, $context];
        }
    };
    $config = ProductionHarness::configuration();
    $transport = new ProductionHarness([static function (): never {
        throw new LogicException('PRIVATE_SENTINEL');
    }]);
    $service = new WixLookupService(function () use (&$config): array {
        return $config;
    }, $transport, new LookupTelemetry($logger, $repository));
    if ($bug) {
        expect(fn () => $service->fetch('51515'))->toThrow(LogicException::class, 'PRIVATE_SENTINEL');
    } else {
        $config['enabled'] = false;
        $service->fetch(str_repeat('sensitive', 1000));
    }
    expect($repository->all())->toBe($before)->and($logger->records)->not->toBeEmpty();
    $record = $logger->records[0][1];
    expect($record['lookup_id'])->toMatch('/^[0-9a-f-]{36}$/D')->and($record['correlation_id'])->toMatch('/^[0-9a-f-]{36}$/D');
    if ($parent !== null && $parent !== 'invalid') {
        expect($record['correlation_id'])->toBe($parent);
    }
    expect(json_encode($logger->records))->not->toContain('PRIVATE_SENTINEL', 'sensitivesensitive');
})->with([[false, null], [true, '01234567-89ab-4cde-8fab-0123456789ab'], [false, 'invalid']]);

it('rejects enabled incomplete runtime configuration before starting real DNS or admission', function () {
    $config = ProductionHarness::configuration();
    $result = (new WixLookupService(fn (): array => $config))->fetch('51515')->toArray();
    ContractWitness::assert($result);
    expect($result['resolution'])->toBe('not_attempted')->and($result['diagnostics'][0]['code'])->toBe('invalid_configuration')->and($result['attempts'])->toBe([]);
});

it('uses the real default native factory and rejects unsafe DNS or invalid admission before TLS', function (bool $invalidAdmission) {
    $lab = new NativeLab('mixed');
    $lock = $lab->directory.'/admission';
    file_put_contents($lock, '');
    $stat = stat($lock);
    $config = ProductionHarness::configuration();
    $config['runtime'] = ['dns_server' => '127.0.0.1', 'dns_port' => $lab->ready['dns_port'], 'admission_path' => $lock,
        'device' => $stat['dev'], 'inode' => $stat['ino'], 'topology' => 'linux-cli-one-host-shared-inode', 'ca_file' => $lab->directory.'/cert.pem'];
    if ($invalidAdmission) {
        $config['runtime']['inode']++;
    }
    try {
        $result = (new WixLookupService(fn (): array => $config))->fetch('51515')->toArray();
        ContractWitness::assert($result);
        expect($result['resolution'])->toBe($invalidAdmission ? 'not_attempted' : 'incomplete')->and($result['diagnostics'][0]['code'])->toBe($invalidAdmission ? 'invalid_configuration' : 'transport_error')
            ->and($result['meta']['budget_used']['requests'])->toBe($invalidAdmission ? 0 : 1)->and($result['product'])->toBeNull();
        expect(array_filter($lab->events(), fn ($e): bool => $e['kind'] === 'http'))->toBe([])
            ->and(array_filter($lab->events(), fn ($e): bool => $e['kind'] === 'connect'))->toBe([]);
        $other = fopen($lock, 'r+');
        try {
            expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();
        } finally {
            fclose($other);
        }
    } finally {
        $lab->close();
    }
})->with([false, true]);

it('executes direct fetch over real TLS gzip receive and supervised parsing in a hermetic native lab', function () {
    $lab = new NativeLab('public');
    $lock = $lab->directory.'/admission';
    file_put_contents($lock, '');
    $stat = stat($lock);
    $guard = new class(new BoundedResolver('127.0.0.1', $lab->ready['dns_port']), $lab) extends DestinationGuard
    {
        public function __construct(BoundedResolver $resolver, private readonly NativeLab $lab)
        {
            parent::__construct($resolver);
        }

        public function prepare(RequestPlan $request, LookupContext $context): array
        {
            parent::prepare($request, $context);
            $id = match ($request->surface->value) {
                'graphql_search' => $request->profile.'-51515R-search-p1-s15',
                'html' => 'en-ap-51515R-detail',
                'asset_metadata' => 'en-ap-51515R_WIX-assets-l25-o0',
            };
            $path = '/fixture/'.$id;

            return ['host' => 'runtime.test', 'port' => $this->lab->ready['tls_port'], 'path' => $path,
                'url' => 'https://runtime.test:'.$this->lab->ready['tls_port'].$path, 'ip' => '127.0.0.1', 'expires' => $context->budget->connectDeadline];
        }
    };
    $supervisor = new SupervisedPhpTask;
    $transport = new WixHttpTransport($guard, $supervisor, new Admission($lock, $stat['dev'], $stat['ino'], 'linux-cli-one-host-shared-inode'), $lab->directory.'/cert.pem');
    $config = ProductionHarness::configuration();
    try {
        $result = (new WixLookupService(fn (): array => $config, $transport))->fetch('51515R')->toArray();
        ContractWitness::assert($result, captured: true);
        expect($result['status'])->toBe('success')->and($result['product']['media'])->toHaveCount(12)->and($result['meta']['budget_used']['requests'])->toBe(4)
            ->and($supervisor->reaped)->toBeTrue()->and(is_dir('/proc/'.$supervisor->lastPid))->toBeFalse();
        expect(array_filter($lab->events(), fn ($e): bool => $e['kind'] === 'http'))->toHaveCount(4)
            ->and(array_filter($lab->events(), fn ($e): bool => $e['kind'] === 'dns'))->toHaveCount(8);
        $other = fopen($lock, 'r+');
        try {
            expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();
        } finally {
            fclose($other);
        }
    } finally {
        $lab->close();
    }
});

it('captures authentic populated technical applications and OE while unavailable metadata remains a scoped partial', function (string $profile, string $code, int $applications, int $oe) {
    $corpus = ProductionHarness::corpus();
    $responses = [];
    if ($profile === 'en-ap') {
        $responses[] = $corpus->body('es-co-51515R-search-p1-s15');
    }
    $responses[] = $corpus->body($profile.'-'.$code.'-search-p1-s15');
    $responses[] = $corpus->body($profile.'-'.$code.'-detail');
    $responses[] = static function (): never {
        throw new OperationalFailure(DiagnosticCode::TransportError, 'assets');
    };
    $transport = new ProductionHarness($responses);
    $config = ProductionHarness::configuration();
    $result = (new WixLookupService(fn (): array => $config, $transport))->fetch($code)->toArray();
    ContractWitness::assert($result, captured: true);
    expect($result['status'])->toBe('partial')->and($result['coverage']['groups']['technical']['complete'])->toBeTrue()
        ->and($result['product']['applications'])->toHaveCount($applications)->and($result['product']['oe_references'])->toHaveCount($oe);
})->with([['es-co', '42831', 11, 6], ['en-ap', '51515', 94, 28]]);
