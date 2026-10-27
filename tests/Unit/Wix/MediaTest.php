<?php

use App\Support\Wix\DetailVerifier;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\EvidenceBuilder;
use App\Support\Wix\Http\AssetMetadataPaginator;
use App\Support\Wix\LookupBudget;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Parsing\MediaExtractor;
use App\Support\Wix\Parsing\SupervisedDocumentReader;
use App\Support\Wix\Parsing\ValueReader;
use App\Support\Wix\Profiles\ProfileRegistry;
use App\Support\Wix\RegionalLookupPolicy;
use App\Support\Wix\Runtime\SupervisedPhpTask;
use App\Support\Wix\SearchPaginator;
use Tests\Support\Wix\ProductionHarness;

it('captures authentic gallery references separately without binary downloads or guessed MIME', function (string $fixture, int $count) {
    $items = (new MediaExtractor(new ValueReader, new SupervisedDocumentReader(new SupervisedPhpTask)))->gallery(ProductionHarness::html($fixture));
    expect($items)->toHaveCount($count);
    foreach ($items as $item) {
        expect($item->reference_raw)->toStartWith('https://s7g10.scene7.com/')->and($item->url_resolved)->toBeNull()->and($item->mime_declared)->toBeNull();
        expect($item->declared_metadata)->not->toBeEmpty();
    }
})->with([['es-co-51515-detail', 2], ['en-ap-51515R-detail', 8]]);

it('retains unsafe template references without expanding approved origins', function () {
    $media = new MediaExtractor(new ValueReader, new SupervisedDocumentReader(new SupervisedPhpTask));
    foreach (['http://127.0.0.1/x', 'file:///etc/passwd', 'https://www.wixfilters.com@evil.invalid/x', '/content/dam/wix-filters/../private', 'https://s7g10.scene7.com/is/image/x?wid={width}'] as $raw) {
        expect($media->resolve($raw))->toBeNull();
    }
});

it('exhausts both authentic AP metadata pages with the same fixed filter and published SKU', function (bool $failSecond) {
    $corpus = ProductionHarness::corpus();
    $responses = [$corpus->body('es-co-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-search-p1-s15'), $corpus->body('en-ap-51515R-detail'), $corpus->body('en-ap-51515R_WIX-assets-l2-o0')];
    $responses[] = $failSecond ? static function (): never {
        throw new OperationalFailure(DiagnosticCode::TransportError, 'assets');
    } : $corpus->body('en-ap-51515R_WIX-assets-l2-o2');
    $transport = new ProductionHarness($responses);
    $context = ProductionHarness::context('51515R');
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $profiles = new ProfileRegistry;
    $result = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), $profiles))->resolve($context);
    $media = new MediaExtractor(new ValueReader, $reader);
    $paginator = new AssetMetadataPaginator($transport, $reader, $media);
    $items = [];
    $capture = function () use ($paginator, $profiles, $result, $context, &$items): array {
        return $paginator->capture($profiles->get('en-ap'), $result->identity, $context, 'assets-en-ap', static function (array $page) use (&$items): void {
            $items = [...$items, ...$page];
        }, 2);
    };
    if ($failSecond) {
        expect($capture)->toThrow(OperationalFailure::class, 'transport_error');
    } else {
        expect($capture())->not->toBeEmpty();
    }
    expect($items)->toHaveCount($failSecond ? 2 : 4);
    expect($transport->requests[3]->url)->toBe($corpus->requestUrl('en-ap-51515R_WIX-assets-l2-o0'))
        ->and($transport->requests[4]->url)->toBe($corpus->requestUrl('en-ap-51515R_WIX-assets-l2-o2'));
    foreach ($items as $item) {
        expect(array_any($item->roles, fn ($role): bool => $role->name === 'mainImage' && $role->value === 'true'))->toBeTrue();
    }
})->with([false, true]);

it('keeps raw noninteger gallery positions and null projections without choosing a main resource', function () {
    $corpus = ProductionHarness::corpus();
    $derived = str_replace('&#34;position&#34;:1', '&#34;position&#34;:&#34;001&#34;', $corpus->body('en-ap-51515R-detail'));
    $items = (new MediaExtractor(new ValueReader, new SupervisedDocumentReader(new SupervisedPhpTask)))->gallery(ProductionHarness::html('en-ap-51515R-detail', $derived));
    expect($items[0]->position_published)->toBeNull()->and($items[1]->position_published)->toBe(2)
        ->and($items[0]->declared_metadata[1]->value_raw)->toBe('001')->and($items[0]->declared_metadata[1]->raw_type->value)->toBe('string');
});

it('retains a fully parsed metadata sibling when evidence budget interrupts the next hit', function () {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness(array_map($corpus->body(...), ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail', 'en-ap-51515R_WIX-assets-l25-o0']));
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $profiles = new ProfileRegistry;
    $original = ProductionHarness::context('51515R');
    $resolution = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), $profiles))->resolve($original);
    $json = json_decode($corpus->body('en-ap-51515R_WIX-assets-l25-o0'), true, 32, JSON_THROW_ON_ERROR);
    $maximum = 2 + count($json['hits'][0]);
    $context = new LookupContext('51515R', $original->budget, gmdate('Y-m-d\TH:i:s\Z'), new EvidenceBuilder(16384, $maximum));
    $committed = $pages = [];
    $paginator = new AssetMetadataPaginator($transport, $reader, new MediaExtractor(new ValueReader, $reader));
    $capture = function () use ($paginator, $profiles, $resolution, $context, &$committed, &$pages): array {
        return $paginator->capture($profiles->get('en-ap'), $resolution->identity, $context, 'assets-en-ap', function (array $items, int $offset, string $ref) use (&$committed, &$pages): void {
            $committed = [...$committed, ...$items];
            $pages[] = [$offset, $ref];
        });
    };
    expect($capture)->toThrow(OperationalFailure::class, 'budget_exhausted');
    expect($context->evidence->all())->toHaveCount($maximum);
    expect($committed)->toHaveCount(1);
    expect($pages)->toHaveCount(1)->and($pages[0][0])->toBe(0)
        ->and($committed[0]->reference_raw)->toBe($json['hits'][0]['link'])->and($committed[0]->order)->toBe(0)
        ->and($transport->requests)->toHaveCount(4);
    foreach ([$pages[0][1], ...$committed[0]->evidence_refs, ...array_merge(...array_map(fn ($field): array => $field->evidence_refs, $committed[0]->declared_metadata))] as $ref) {
        expect($context->evidence->all())->toHaveKey($ref);
    }
});

it('retains a completed metadata hit without starting another operation after midpage global interruption', function (string $interruption) {
    $corpus = ProductionHarness::corpus();
    $transport = new ProductionHarness(array_map($corpus->body(...), ['es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'en-ap-51515R-detail', 'en-ap-51515R_WIX-assets-l2-o0']));
    $reader = new SupervisedDocumentReader(new SupervisedPhpTask);
    $profiles = new ProfileRegistry;
    $original = ProductionHarness::context('51515R');
    $resolution = (new RegionalLookupPolicy(new SearchPaginator($transport, $reader), new DetailVerifier($transport, $reader), $profiles))->resolve($original);
    $assets = json_decode($corpus->body('en-ap-51515R_WIX-assets-l2-o0'), true, 32, JSON_THROW_ON_ERROR);
    $evidence = new EvidenceBuilder(16384);
    $completedCount = 2 + count($assets['hits'][0]);
    $limits = $original->budget->limits;
    $started = hrtime(true);
    $clock = fn (): int => $started + ($interruption === 'deadline' && count($evidence->all()) >= $completedCount ? $limits->totalTimeoutMs * 1000000 : 0);
    $enabled = fn (): bool => $interruption !== 'cancel' || count($evidence->all()) < $completedCount;
    $context = new LookupContext('51515R', new LookupBudget($limits, clock: $clock, enabled: $enabled), gmdate('Y-m-d\TH:i:s\Z'), $evidence);
    $paginator = new AssetMetadataPaginator($transport, $reader, new MediaExtractor(new ValueReader, $reader));
    $committed = $pages = [];
    $capture = function () use ($paginator, $profiles, $resolution, $context, &$committed, &$pages): array {
        return $paginator->capture($profiles->get('en-ap'), $resolution->identity, $context, 'assets-en-ap', function (array $items, int $offset, string $ref) use (&$committed, &$pages): void {
            $committed = [...$committed, ...$items];
            $pages[] = [$offset, $ref];
        }, 2);
    };
    expect($capture)->toThrow(OperationalFailure::class, $interruption === 'cancel' ? 'disabled' : 'budget_exhausted');
    expect($committed)->toHaveCount(1)->and($committed[0]->reference_raw)->toBe($assets['hits'][0]['link'])
        ->and($committed[0]->order)->toBe(0)->and($pages)->toHaveCount(1)->and($pages[0][0])->toBe(0)
        ->and($evidence->all())->toHaveCount($completedCount)->and($transport->requests)->toHaveCount(4);
    foreach ([$pages[0][1], ...$committed[0]->evidence_refs] as $ref) {
        expect($evidence->all())->toHaveKey($ref);
    }
})->with(['deadline', 'cancel']);
