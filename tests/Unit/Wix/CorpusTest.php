<?php

use Tests\Support\Wix\FixtureCorpus;

function wixCorpusDirectory(): string
{
    return dirname(__DIR__, 2).'/fixtures/wix';
}

function wixCorpusXPath(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);

    try {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NONET);

        return new DOMXPath($document);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

it('loads the selected corpus entirely offline with verified files and honest provenance', function () {
    expect(hash_file('sha256', wixCorpusDirectory().'/manifest.json'))
        ->toBe('8cb51d7b477328b4dd709ad98388638423d46a120cc6f1bddc2145c6377b1c9f');

    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $fixtures = $corpus->manifest()['fixtures'];

    expect($corpus->ids())->toHaveCount(16)
        ->and(array_count_values(array_column($fixtures, 'surface')))
        ->toBe(['graphql_search' => 8, 'html' => 4, 'asset_metadata' => 4]);

    foreach ($fixtures as $fixture) {
        $body = $corpus->body($fixture['fixture_id']);
        expect(strlen($body))->toBe($fixture['provenance']['sanitized']['bytes'])
            ->and(hash('sha256', $body))->toBe($fixture['provenance']['sanitized']['sha256'])
            ->and($fixture['nature'])->toBe('genuine')
            ->and($fixture['parent_id'])->toBeNull()
            ->and($fixture['profile_version'])->toBe('observed-2026-10-19');
    }

    expect($corpus->manifest()['gaps'][0])
        ->toBe([
            'profiles' => ['es-co', 'en-ap'],
            'surface' => 'documents',
            'gap' => 'No authentic positive document capture. Empty downloads do not certify populated support.',
        ]);
});

it('preserves the observed search pages, variants and explicit negative page metadata', function (string $id) {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $data = json_decode($corpus->body($id), true, flags: JSON_THROW_ON_ERROR);
    $catalog = $data['data']['catalogSearch'];
    $expected = $corpus->expected($id);

    expect($data)->not->toHaveKey('errors')
        ->and($catalog['totalCount'])->toBe($expected['total_count'])
        ->and($catalog['pageInfo'])->toBe($expected['page_info'])
        ->and(array_column(array_column($catalog['items'], 'product'), 'sku'))->toBe($expected['skus']);

    parse_str(parse_url($corpus->requestUrl($id), PHP_URL_QUERY), $query);
    expect($query['query'])->toBe($corpus->manifest()['queries']['product_search_name'])
        ->and(json_decode($query['variables'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe($corpus->fixture($id)['request']['graphql']['variables']);
})->with([
    'es-co-51515-search-p1-s1', 'es-co-51515-search-p2-s1', 'es-co-51515-search-p3-s1',
    'es-co-51515R-search-p1-s15', 'en-ap-51515R-search-p1-s15', 'es-co-42831-search-p1-s15',
    'en-ap-NOEXISTE51515ZZ-search-p1-s15', 'en-ap-51515-search-p1-s15',
]);

it('keeps full product sections, responsive occurrences, gallery and explicit downloads emptiness', function (string $id) {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $html = $corpus->body($id);
    $xpath = wixCorpusXPath($html);
    $expected = $corpus->expected($id);

    foreach (['dimensions', 'technicalData', 'applications', 'oeNumbers', 'downloads', 'reachInformation'] as $section) {
        expect($xpath->query('//*[@id="'.$section.'"]'))->toHaveCount(1);
    }

    expect($xpath->query('//*[@id="dimensions"]//tr[td]'))->toHaveCount(3)
        ->and($xpath->query('//*[@data-g-binding-application-link]'))->toHaveCount($expected['application_occurrences'])
        ->and($xpath->query('//*[@id="downloads"]//a'))->toHaveCount(0)
        ->and($xpath->evaluate('string(//*[@id="downloads"])'))->toMatch('/No (?:hay información disponible|Data Available)/u')
        ->and($xpath->query('//script[not(@type="text/json") and not(@type="application/ld+json")]'))->toHaveCount(0)
        ->and($html)->not->toMatch('/googleApiKey|accountsMicroserviceClientId|microsoftClientId|AIza[\w-]{20,}/');

    $params = [];
    foreach ($xpath->query('//*[@data-g-binding-application-link]') as $link) {
        $params[] = $link->getAttribute('data-g-binding-params');
    }
    expect(array_unique($params))->toHaveCount($expected['logical_application_candidates']);

    $gallery = $xpath->query('//*[@data-g-name="ProductGallery"]')->item(0);
    expect(json_decode($gallery->getAttribute('data-g-binding-gallery-items'), true, flags: JSON_THROW_ON_ERROR))
        ->toHaveCount($expected['gallery_items']);

    $profile = null;
    foreach ($xpath->query('//script[@type="text/json"]') as $script) {
        $config = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
        if (isset($config['productDetailUrlPattern'])) {
            $profile = $config;
        }
    }
    expect($profile['productDetailUrlPattern'])
        ->toBe('/'.$corpus->fixture($id)['profile'].'/catalog/results/product.html/[URL_KEY].html')
        ->and($expected['not_certified'])->toBeTrue();
})->with(['es-co-51515-detail', 'es-co-42831-detail', 'en-ap-51515R-detail', 'en-ap-51515-detail']);

it('retains distinct AP pressure literals and raw form SKU versus GTIN', function () {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $data = json_decode($corpus->body('en-ap-51515R-search-p1-s15'), true, flags: JSON_THROW_ON_ERROR);
    $attributes = array_column($data['data']['catalogSearch']['items'][0]['product']['attributes'], 'value', 'key');

    expect($attributes['sales_designation'])->toBe('51515R')
        ->and($attributes['bypass_valve_opening_pressurem'])->toBe('0.758')
        ->and($attributes['bypass_valve_opening_pressure'])->toBe('0.551')
        ->and($attributes['operating_temperature_min'])->toBe('-17.777');

    $html = $corpus->body('en-ap-51515R-detail');
    expect($html)->toContain('data-product-sku="51515R_WIX"')->toContain('765809129054');

    $metadata = $corpus->fixture('en-ap-51515-detail')['provenance'];
    expect($metadata['received']['bytes'])->toBe(541232)
        ->and($metadata['stored']['bytes'])->toBe(541221)
        ->and($metadata['received']['verification'])->toBe('historical_attestation_raw_not_retained')
        ->and($metadata['received']['sha256'])->not->toBe($metadata['stored']['sha256'])
        ->and($metadata['stored']['sha256'])->not->toBe($metadata['sanitized']['sha256']);
});

it('preserves asset pagination, multiple string mainImage roles and CO linkage without binaries', function () {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $pages = [];
    foreach (['en-ap-51515R_WIX-assets-l2-o0', 'en-ap-51515R_WIX-assets-l2-o2', 'en-ap-51515R_WIX-assets-l25-o0', 'es-co-51515_WIX-assets-l25-o0'] as $id) {
        $page = json_decode($corpus->body($id), true, flags: JSON_THROW_ON_ERROR);
        $expected = $corpus->expected($id);
        expect($page['total'])->toBe($expected['total'])
            ->and($page['offset'])->toBe($expected['offset'])
            ->and($page['hasMore'])->toBe($expected['has_more'])
            ->and(array_column($page['hits'], 'link'))->toBe($expected['links'])
            ->and(array_column($page['hits'], 'mimeType'))->toBe($expected['mime_types'])
            ->and(array_column(array_column($page['hits'], 'metaData'), 'mainImage'))->toBe($expected['main_images']);
        $pages[] = $page;
    }

    expect([...$pages[0]['hits'], ...$pages[1]['hits']])->toBe($pages[2]['hits'])
        ->and(array_column(array_column($pages[3]['hits'], 'metaData'), 'productReferences'))
        ->toBe([['51515_WIX'], ['51515_WIX']]);
});

it('rejects invalid corpus metadata before it can be used as capture evidence', function (array $changes, string $message) {
    $manifest = (new FixtureCorpus(wixCorpusDirectory()))->manifest();

    foreach ($changes as $path => $value) {
        $cursor = &$manifest;
        $keys = explode('.', $path);
        $last = array_pop($keys);
        foreach ($keys as $key) {
            $cursor = &$cursor[$key];
        }
        if ($value === '__REMOVE__') {
            unset($cursor[$last]);
        } else {
            $cursor[$last] = $value;
        }
        unset($cursor);
    }

    expect(fn () => new FixtureCorpus(wixCorpusDirectory(), $manifest))->toThrow(RuntimeException::class, $message);
})->with([
    'schema' => [['schema_version' => 2], 'Unsupported corpus schema'],
    'duplicate ID' => [['fixtures.1.fixture_id' => 'es-co-51515-search-p1-s1'], 'Duplicate fixture ID'],
    'missing provenance' => [['fixtures.0.provenance' => '__REMOVE__'], 'Missing corpus metadata: provenance'],
    'missing raw hash' => [['fixtures.0.provenance.received.sha256' => '__REMOVE__'], 'Missing corpus metadata: sha256'],
    'missing UTC' => [['fixtures.0.response.captured_at' => '__REMOVE__'], 'Missing corpus metadata: captured_at'],
    'invalid UTC' => [['fixtures.0.response.captured_at' => '2026-02-30T14:41:21Z'], 'Invalid UTC'],
    'invalid classification' => [['fixtures.0.nature' => 'LIVE'], 'Invalid fixture nature'],
    'synthetic is not LIVE' => [['fixtures.0.nature' => 'synthetic'], 'Synthetic fixtures require a separate schema'],
    'genuine with parent' => [['fixtures.0.parent_id' => 'other'], 'Genuine capture cannot have a parent'],
    'derived missing parent' => [['fixtures.0.nature' => 'derived', 'fixtures.0.parent_id' => 'missing'], 'Missing genuine parent'],
    'missing capture transform' => [['fixtures.3.provenance.capture_transformations' => []], 'Missing capture transformation'],
    'missing sanitize transform' => [['fixtures.3.provenance.transformations' => []], 'Missing sanitization transformation'],
    'unknown sanitize rule' => [['fixtures.3.provenance.transformations' => ['not-a-rule']], 'Unknown sanitization rule'],
    'inconsistent received bytes' => [['fixtures.0.provenance.received.bytes' => 6692], 'Inconsistent received byte count'],
    'historical attestation missing' => [['fixtures.3.provenance.received.verification' => 'recomputed_from_source'], 'Invalid received-body attestation'],
    'wrong historical attribution' => [['fixtures.0.provenance.source_body' => '/tmp/opencode/prd15-live-20261019/other.body.json'], 'Historical source belongs to another fixture'],
    'wrong stored file bytes' => [['fixtures.0.body.bytes' => 2475], 'Fixture byte count mismatch'],
    'wrong stored file hash' => [['fixtures.0.body.sha256' => str_repeat('0', 64)], 'Fixture checksum mismatch'],
    'wrong decoded bytes' => [['fixtures.0.provenance.sanitized.bytes' => 6692], 'Fixture byte count mismatch'],
    'wrong decoded hash' => [['fixtures.0.provenance.sanitized.sha256' => str_repeat('0', 64), 'fixtures.0.provenance.transformations' => ['html-sanitize-v1']], 'Fixture checksum mismatch'],
    'missing body' => [['fixtures.0.body.path' => 'bodies/missing.json'], 'Missing or escaping fixture file'],
    'traversal' => [['fixtures.0.body.path' => '../manifest.json'], 'Unsafe fixture path'],
    'nested traversal' => [['fixtures.0.body.path' => 'bodies/../manifest.json'], 'Unsafe fixture path'],
    'absolute path' => [['fixtures.0.body.path' => '/tmp/opencode/wix-live-manifest.json'], 'Unsafe fixture path'],
    'stream wrapper' => [['fixtures.0.body.path' => 'php://filter/resource=manifest.json'], 'Unsafe fixture path'],
    'unrecognized source' => [['fixtures.0.provenance.source_body' => '/tmp/other.body.json'], 'Unrecognized historical source'],
    'foreign origin' => [['fixtures.0.request.url' => 'https://example.com/api/graphql/catalog-stage'], 'Source outside corpus allowlist'],
    'userinfo' => [['fixtures.0.request.url' => 'https://user@www.wixfilters.com/api/graphql/catalog-stage'], 'Source outside corpus allowlist'],
    'wrong regional path' => [['fixtures.0.request.url' => 'https://www.wixfilters.com/api/graphql/catalog-prod'], 'Source path outside corpus allowlist'],
    'cookie header' => [['fixtures.0.request.headers.Cookie' => 'not-retained'], 'Unsafe captured header'],
    'missing regional Store' => [['fixtures.0.request.headers.Store' => '__REMOVE__'], 'Missing regional request context'],
    'unknown query' => [['fixtures.0.request.graphql.query_id' => 'unknown'], 'Missing query'],
    'missing expected file' => [['expected.path' => 'expected/missing.json'], 'Missing or escaping fixture file'],
]);

it('detects physical body corruption and malformed encoded data in an owned temporary copy', function (string $kind, string $message) {
    $manifest = (new FixtureCorpus(wixCorpusDirectory()))->manifest();
    $directory = sys_get_temp_dir().'/filtroven-wix-corpus-'.bin2hex(random_bytes(8));
    mkdir($directory.'/bodies', 0700, true);
    mkdir($directory.'/expected', 0700);
    $files = [];

    try {
        foreach ([$manifest['expected'], ...array_column($manifest['fixtures'], 'body')] as $descriptor) {
            $destination = $directory.'/'.$descriptor['path'];
            copy(wixCorpusDirectory().'/'.$descriptor['path'], $destination);
            $files[] = $destination;
        }

        $descriptor = &$manifest['fixtures'][0]['body'];
        $path = $directory.'/'.$descriptor['path'];
        $contents = file_get_contents($path);
        if ($kind === 'bytes') {
            $contents .= "\n";
        } elseif ($kind === 'checksum') {
            $contents[0] = '[';
        } else {
            $contents = json_encode(['encoding' => 'gzip-base64', 'chunks' => [base64_encode('not a gzip payload')]], JSON_THROW_ON_ERROR);
            $descriptor['bytes'] = strlen($contents);
            $descriptor['sha256'] = hash('sha256', $contents);
        }
        file_put_contents($path, $contents);

        expect(fn () => new FixtureCorpus($directory, $manifest))->toThrow(RuntimeException::class, $message);
    } finally {
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($directory.'/bodies');
        rmdir($directory.'/expected');
        rmdir($directory);
    }
})->with([
    ['bytes', 'Fixture byte count mismatch'],
    ['checksum', 'Fixture checksum mismatch'],
    ['gzip', 'Invalid gzip body'],
]);

it('rejects unknown fixture IDs', function () {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    expect(fn () => $corpus->body('not-a-capture'))->toThrow(RuntimeException::class, 'Unknown fixture ID');
});

function wixVariantInputs(): array
{
    return json_decode(file_get_contents(wixCorpusDirectory().'/bodies/variants.json'), true, 64, JSON_THROW_ON_ERROR);
}

function wixVariantCatalogPage(array $variant, int $index = 0): array
{
    return json_decode($variant['steps'][$index]['body'], true, 64, JSON_THROW_ON_ERROR)['data']['catalogSearch'];
}

it('materializes finite offline variants with separate lineage and future expectations', function () {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $variants = $corpus->variants();
    expect($variants)->toHaveCount(28);
    foreach ($variants as $variant) {
        expect($variant['purpose'])->not->toBeEmpty()
            ->and($variant['expected']['expect'])->not->toBeEmpty()
            ->and($variant['expected']['criteria'])->not->toBeEmpty();
        foreach ($variant['steps'] as $step) {
            expect($step)->not->toHaveKey('captured_at')
                ->and($step)->not->toHaveKey('received')
                ->and($step['bytes'])->toBe(strlen($step['body']))
                ->and($step['sha256'])->toBe(hash('sha256', $step['body']));
            if ($variant['nature'] === 'derived') {
                $parent = $corpus->fixture($step['parent']['fixture_id']);
                expect($step['parent']['sha256'])->toBe($parent['provenance']['sanitized']['sha256'])
                    ->and($step['profile'])->toBe($parent['profile'])
                    ->and($step['surface'])->toBe($parent['surface']);
            } else {
                expect($step['parent'])->toBeNull();
            }
        }
    }
    expect(array_count_values(array_column($variants, 'nature')))->toBe(['derived' => 22, 'synthetic' => 6]);
});

it('keeps the late exact fixture on page three with coherent page metadata', function () {
    $variant = (new FixtureCorpus(wixCorpusDirectory()))->variants()['late-exact'];
    foreach ([0, 1, 2] as $index) {
        $page = wixVariantCatalogPage($variant, $index);
        $attributes = array_column($page['items'][0]['product']['attributes'], 'value', 'key');
        expect($page['pageInfo']['currentPage'])->toBe($index + 1)
            ->and($page['pageInfo']['totalPages'])->toBe(3)
            ->and($page['totalCount'])->toBe(3)
            ->and($attributes['sales_designation'] === '51515')->toBe($index === 2)
            ->and($attributes['product_brand'])->toBe('WIX');
    }
});

it('provides different repetition ambiguity conflict and missing-ID inputs without resolving them', function () {
    $variants = (new FixtureCorpus(wixCorpusDirectory()))->variants();
    $original = wixVariantCatalogPage($variants['repeated-reference'])['items'][0]['product'];
    $repeat = wixVariantCatalogPage($variants['repeated-reference'], 1)['items'][0]['product'];
    $distinct = wixVariantCatalogPage($variants['two-exact-identities'], 1)['items'][0]['product'];
    $conflict = wixVariantCatalogPage($variants['conflicting-reference'], 1)['items'][0]['product'];
    expect($repeat)->toBe($original)
        ->and($distinct['sku'])->not->toBe($original['sku'])
        ->and($distinct['urlKey'])->not->toBe($original['urlKey'])
        ->and(array_column($distinct['attributes'], 'value', 'key')['sales_designation'])->toBe('51515')
        ->and($conflict['sku'])->toBe($original['sku'])
        ->and($conflict['urlKey'])->not->toBe($original['urlKey'])
        ->and(wixVariantCatalogPage($variants['missing-identifier'])['items'][0]['product'])->not->toHaveKey('urlKey');
    $late = $variants['late-mismatch']['steps'];
    expect($late[1]['body'])->toContain('data-product-sku="51515_WIX"')
        ->and($late[2]['body'])->not->toContain('data-product-sku="51515_WIX"')
        ->and($late[2]['body'])->toContain('data-product-sku="SYNTHETIC-OTHER_WIX"');
});

it('contains genuine error conditions in the derived inputs rather than only outcome labels', function () {
    $variants = (new FixtureCorpus(wixCorpusDirectory()))->variants();
    $errors = json_decode($variants['graphql-errors-data']['steps'][0]['body'], true, flags: JSON_THROW_ON_ERROR);
    expect($errors['errors'])->not->toBeEmpty()
        ->and($errors['data']['catalogSearch']['items'])->not->toBeEmpty()
        ->and($variants['graphql-errors-data']['steps'][0]['status'])->toBe(200)
        ->and(fn () => json_decode($variants['invalid-json']['steps'][0]['body'], true, flags: JSON_THROW_ON_ERROR))->toThrow(JsonException::class)
        ->and(json_decode($variants['unknown-schema']['steps'][0]['body'], true, flags: JSON_THROW_ON_ERROR)['data'])->not->toHaveKey('catalogSearch');
    foreach (['http-forbidden' => 403, 'http-rate-limit' => 429, 'http-server-error' => 500] as $id => $status) {
        expect($variants[$id]['steps'][0]['status'])->toBe($status);
    }
    expect(wixVariantCatalogPage($variants['page-omitted'], 1)['pageInfo']['currentPage'])->toBe(3)
        ->and(wixVariantCatalogPage($variants['page-repeated'], 1)['pageInfo']['currentPage'])->toBe(1)
        ->and(wixVariantCatalogPage($variants['totals-drift'], 1)['totalCount'])->toBe(4)
        ->and(wixVariantCatalogPage($variants['page-type'])['pageInfo']['currentPage'])->toBe('1');
    $retry = $variants['graphql-recovered']['steps'];
    expect(json_decode($retry[0]['body'], true, flags: JSON_THROW_ON_ERROR))->toHaveKey('errors')
        ->and(json_decode($retry[1]['body'], true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('errors');
    $assets = json_decode($variants['asset-pending']['steps'][0]['body'], true, flags: JSON_THROW_ON_ERROR);
    expect($assets['hasMore'])->toBeTrue()->and($assets['hits'])->toHaveCount(2)
        ->and($variants['asset-loop']['steps'][0]['body'])->toBe($variants['asset-loop']['steps'][1]['body']);
});

it('preserves missing null empty typed values repeated keys and independent application queries in inputs', function () {
    $variants = (new FixtureCorpus(wixCorpusDirectory()))->variants();
    $rows = wixVariantCatalogPage($variants['missing-null-empty-types'])['items'][0]['product']['attributes'];
    expect($rows)->toHaveCount(8)
        ->and(array_column($rows, 'key'))->toBe(array_fill(0, 8, 'synthetic_extra'))
        ->and($rows[0])->not->toHaveKey('value')
        ->and($rows[1])->toHaveKey('value')->and($rows[1]['value'])->toBeNull()
        ->and($rows[2]['value'])->toBe('')
        ->and($rows[3]['value'])->toBeTrue()->and($rows[3]['adminValue'])->toBeTrue()
        ->and($rows[4]['value'])->toBe('true')->and($rows[4]['adminValue'])->toBe('true')
        ->and($rows[5]['value'])->toBe(132.003)
        ->and($rows[6]['value'])->toBe(['literal' => '001'])
        ->and($rows[7]['value'])->toBe(['', null, '001']);
    $xpath = wixCorpusXPath($variants['applications-distinct-queries']['steps'][0]['body']);
    $links = $xpath->query('//*[@data-g-binding-application-link]');
    expect($links)->toHaveCount(2)
        ->and($links[0]->getAttribute('data-g-binding-application-link'))->toBe($links[1]->getAttribute('data-g-binding-application-link'))
        ->and($links[0]->getAttribute('data-g-binding-params'))->toBe('id=A&id=B&engine=')
        ->and($links[1]->getAttribute('data-g-binding-params'))->toBe('id=A&id=C&engine=');
});

it('keeps populated documents explicitly invented and asset projections raw', function () {
    $variants = (new FixtureCorpus(wixCorpusDirectory()))->variants();
    foreach (['documents-co-populated', 'documents-ap-populated'] as $id) {
        $xpath = wixCorpusXPath($variants[$id]['steps'][0]['body']);
        expect($xpath->query('//*[@id="downloads"]//a'))->toHaveCount(1)
            ->and($xpath->evaluate('string(//*[@id="downloads"]//a/@href)'))->toBe('/SYNTHETIC-document.pdf')
            ->and($variants[$id]['expected']['expect'])->toContain('unsupported/false');
    }
    $resource = json_decode($variants['unresolved-resource']['steps'][0]['body'], true, flags: JSON_THROW_ON_ERROR)['hits'][0];
    expect($resource['link'])->toBe('[SYNTHETIC_ASSET]')
        ->and($resource['position'])->toBe('first')
        ->and($resource['metaData']['mainImage'])->toBeTrue();
});

it('holds adversarial entities scripts URLs and finite volume as inert bytes only', function () {
    $variants = (new FixtureCorpus(wixCorpusDirectory()))->variants();
    expect($variants['external-entities']['steps'][0]['body'])
        ->toContain("SYSTEM 'file:///SYNTHETIC-not-a-real-file'", "SYSTEM 'https://example.invalid/SYNTHETIC-entity'", '<script>');
    $urls = json_decode($variants['dangerous-urls']['steps'][0]['body'], true, flags: JSON_THROW_ON_ERROR)['references'];
    expect($urls)->toHaveCount(10)->toContain('https://[::1]/', 'https://169.254.169.254/', "https://www.wixfilters.com/\r\nX-SYNTHETIC:yes")
        ->and(substr_count($variants['finite-volume']['steps'][0]['body'], '<div>'))->toBe(9);
});

it('links all thirty criteria without pretending future gates passed', function () {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $variants = $corpus->variants();
    $expected = json_decode(file_get_contents(wixCorpusDirectory().'/expected/variants.json'), true, 64, JSON_THROW_ON_ERROR);
    expect(array_keys($expected['traceability']))->toBe(array_map(fn (int $n): string => sprintf('CA-%02d', $n), range(1, 30)))
        ->and($expected['execution'])->toBe('future_adapter_expectations_not_executed_by_corpus_tests');
    foreach ($expected['traceability'] as $entry) {
        expect($entry['refs'])->not->toBeEmpty();
        foreach ($entry['refs'] as $ref) {
            if ($entry['kind'] === 'variant') {
                expect($variants)->toHaveKey($ref);
            } elseif ($entry['kind'] === 'capture') {
                expect($corpus->ids())->toContain($ref);
            } else {
                expect($entry['kind'])->toBe('future_gate')->and($entry['gap'])->not->toBeEmpty();
            }
        }
    }
    foreach ($variants as $variant) {
        foreach ($variant['expected']['criteria'] as $criterion) {
            expect($expected['traceability'])->toHaveKey($criterion);
        }
    }
});

it('rejects dishonest unbounded or broken variant recipes', function (string $mutation, string $message) {
    $catalog = wixVariantInputs();
    match ($mutation) {
        'parent hash' => $catalog['parents']['co-p1']['sha256'] = str_repeat('0', 64),
        'unknown parent' => $catalog['cases']['late-exact']['steps'][0]['parent'] = 'missing',
        'genuine' => $catalog['cases']['late-exact']['nature'] = 'genuine',
        'synthetic parent' => $catalog['cases']['unknown-main']['steps'][0]['parent'] = 'co-p1',
        'capture UTC' => $catalog['cases']['late-exact']['captured_at'] = '2026-10-19T00:00:00Z',
        'bad replace' => $catalog['cases']['late-exact']['steps'][0]['replace'][0]['from'] = 'NOT-IN-PARENT',
        'empty replace' => $catalog['cases']['late-exact']['steps'][0]['replace'][0]['from'] = '',
        'bad path' => $catalog['cases']['missing-identifier']['steps'][0]['remove'] = [['missing', 'field']],
        'too many steps' => $catalog['cases']['late-exact']['steps'] = array_fill(0, 17, ['parent' => 'co-p1']),
        'bad status' => $catalog['cases']['http-forbidden']['steps'][0]['status'] = '403',
        'unlinked expected' => $catalog['cases']['invented-case'] = $catalog['cases']['late-exact'],
        'bad revision' => $catalog['revision'] = 'unreviewed',
        'empty steps' => $catalog['cases']['late-exact']['steps'] = [],
        'unknown operation' => $catalog['cases']['late-exact']['steps'][0]['evaluate'] = 'SYNTHETIC never execute',
        'synthetic UTC' => $catalog['cases']['unknown-main']['steps'][0]['captured_at'] = '2026-10-19T00:00:00Z',
        'missing removal' => $catalog['cases']['missing-identifier']['steps'][0]['remove'] = [['data', 'absent']],
        'oversized replacement' => $catalog['cases']['late-exact']['steps'][0]['replace'][0]['to'] = str_repeat('x', 2_097_153),
        'oversized synthetic' => $catalog['cases']['unknown-main']['steps'][0]['body'] = str_repeat('x', 2_097_153),
    };
    expect(fn () => (new FixtureCorpus(wixCorpusDirectory()))->variants($catalog))->toThrow(RuntimeException::class, $message);
})->with([
    ['parent hash', 'Variant parent checksum mismatch'],
    ['unknown parent', 'Missing variant parent'],
    ['genuine', 'Variant cannot claim genuine capture'],
    ['synthetic parent', 'Unexpected variant step metadata'],
    ['capture UTC', 'Unexpected variant capture metadata'],
    ['bad replace', 'Variant replacement did not match'],
    ['empty replace', 'Missing replacement source'],
    ['bad path', 'Missing variant field container'],
    ['too many steps', 'Invalid variant steps'],
    ['bad status', 'Invalid variant HTTP status'],
    ['unlinked expected', 'Unlinked variant expectations'],
    ['bad revision', 'Invalid variant revision'],
    ['empty steps', 'Invalid variant steps'],
    ['unknown operation', 'Unexpected variant step metadata'],
    ['synthetic UTC', 'Unexpected variant step metadata'],
    ['missing removal', 'Missing variant removal target'],
    ['oversized replacement', 'Oversized variant body'],
    ['oversized synthetic', 'Oversized or invalid variant body'],
]);

it('cross-checks sixteen fixed inventory rows against manifest surfaces and actual HTML blocks', function () {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $inventory = $corpus->inventory();
    $sections = ['identity', 'dimensions', 'specifications', 'applications', 'oe_references', 'media', 'documents', 'observations'];
    expect($inventory['corpus_revision'])->toBe($corpus->manifest()['revision'])
        ->and($inventory['variant_revision'])->toBe(wixVariantInputs()['revision'])
        ->and($inventory['contract_version'])->toBe('1.0')
        ->and($inventory['rows'])->toHaveCount(16)
        ->and($inventory['certified_cells'])->toBe(0)
        ->and($inventory)->not->toHaveKey('signoffs')
        ->and($inventory)->not->toHaveKey('direct_decision')
        ->and($inventory)->not->toHaveKey('technical_review');
    $cells = [];
    foreach ($inventory['rows'] as $row) {
        $cells[] = $row['profile'].':'.$row['section'];
        expect($sections)->toContain($row['section'])
            ->and($row['certified'])->toBeFalse()
            ->and($row['closure'])->not->toBeEmpty()
            ->and($row['gap'])->not->toBeEmpty()
            ->and($row['empty'])->not->toBeEmpty()
            ->and($row['populated'])->not->toBeEmpty();
        $surfaces = [];
        foreach ($row['fixtures'] as $id) {
            $fixture = $corpus->fixture($id);
            $surfaces[] = $fixture['surface'];
            expect($fixture['profile'])->toBe($row['profile'])
                ->and($fixture['profile_version'])->toBe($inventory['profile_version']);
            if ($fixture['surface'] === 'html') {
                expect(wixCorpusXPath($corpus->body($id))->query($row['html_locator']))->not->toHaveCount(0);
            }
        }
        sort($surfaces);
        $required = $row['surfaces'];
        sort($required);
        expect(array_values(array_unique($surfaces)))->toBe($required);
    }
    $expectedCells = [];
    foreach (['es-co', 'en-ap'] as $profile) {
        foreach ($sections as $section) {
            $expectedCells[] = $profile.':'.$section;
        }
    }
    sort($cells);
    sort($expectedCells);
    expect($cells)->toBe($expectedCells)
        ->and($inventory['status'])->toBe('technical_inventory_not_parser_certification')
        ->and($inventory['document_scope_decision']['status'])->toBe('empty_recognition_populated_unsupported');
});

it('matches the fixed media filter to each captured request without certifying universal assets', function () {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $inventory = $corpus->inventory();
    $scope = $inventory['media_scope'];
    expect($scope['required'])->toBe(['html:ProductGallery', 'asset_metadata:linked-filtered-collection']);
    foreach ($corpus->ids() as $id) {
        $fixture = $corpus->fixture($id);
        if ($fixture['surface'] !== 'asset_metadata') {
            continue;
        }
        $parts = parse_url($corpus->requestUrl($id));
        parse_str($parts['query'], $query);
        expect($parts['path'])->toBe($scope['endpoint']);
        foreach ($scope['filter'] as $key => $literal) {
            expect($query[$key])->toBe($literal);
        }
        $body = json_decode($corpus->body($id), true, flags: JSON_THROW_ON_ERROR);
        foreach ($body['hits'] as $hit) {
            expect($hit['metaData']['productReferences'])->toContain($query['productReferences']);
        }
    }
    $documents = array_values(array_filter($inventory['rows'], fn (array $row): bool => $row['section'] === 'documents'));
    expect($documents)->toHaveCount(2);
    foreach ($documents as $row) {
        expect($row['gap'])->toContain('AUTHENTIC_POSITIVE_DOCUMENT_MISSING')
            ->and($row['certified'])->toBeFalse();
    }
});

it('loads reproducible inventory without human approvals or access to Engram', function () {
    $inventory = (new FixtureCorpus(wixCorpusDirectory()))->inventory();
    expect($inventory['inventory_version'])->toBe('technical-2026-10-20.2')
        ->and($inventory)->not->toHaveKeys(['signoffs', 'direct_decision', 'technical_review', 'accepted_from'])
        ->and($inventory['technical_evidence']['manifest']['sha256'])->toBe(hash_file('sha256', wixCorpusDirectory().'/manifest.json'))
        ->and($inventory['document_scope_decision']['authentic_positive_support_certified'])->toBeFalse()
        ->and($inventory['document_scope_decision']['detected_populated'])->toBe([
            'state' => 'unsupported', 'complete' => false, 'after_verified_identity' => 'partial/exact',
            'diagnostic' => 'unsupported_section', 'severity' => 'error', 'recovered' => false,
        ])
        ->and($inventory['media_scope']['pagination'])->toContain('hasMore=false', 'coherent exhausted total', 'not a universal page cap');
});

it('rejects missing or invalid technical inventory metadata without requiring organizational roles', function (string $path, mixed $value, string $message) {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $inventory = $corpus->inventory();
    $cursor = &$inventory;
    $keys = explode('.', $path);
    $last = array_pop($keys);
    foreach ($keys as $key) {
        $cursor = &$cursor[$key];
    }
    if ($value === '__REMOVE__') {
        unset($cursor[$last]);
    } else {
        $cursor[$last] = $value;
    }
    unset($cursor);

    expect(fn () => $corpus->inventory($inventory))->toThrow(RuntimeException::class, $message);
})->with([
    'missing evidence' => ['technical_evidence', '__REMOVE__', 'Missing corpus metadata: technical_evidence'],
    'missing manifest evidence' => ['technical_evidence.manifest', '__REMOVE__', 'Missing corpus metadata: manifest'],
    'invalid manifest path' => ['technical_evidence.manifest.path', '../manifest.json', 'Invalid inventory manifest path'],
    'invalid manifest hash' => ['technical_evidence.manifest.sha256', str_repeat('0', 64), 'Fixture checksum mismatch'],
    'invalid manifest size' => ['technical_evidence.manifest.bytes', 1, 'Fixture byte count mismatch'],
    'missing variant evidence' => ['technical_evidence.variant_inputs', '__REMOVE__', 'Missing corpus metadata: variant_inputs'],
    'invalid variant hash' => ['technical_evidence.variant_inputs.sha256', str_repeat('0', 64), 'Fixture checksum mismatch'],
    'invalid expected hash' => ['technical_evidence.variant_expected.sha256', str_repeat('0', 64), 'Fixture checksum mismatch'],
    'false parser certification' => ['certified_cells', 16, 'Inventory readiness is not parser certification'],
    'false cell certification' => ['rows.0.certified', true, 'Inventory readiness is not parser certification'],
    'wrong corpus version' => ['corpus_revision', 'invented', 'Invalid inventory versions'],
    'wrong profile version' => ['profile_version', 'invented', 'Invalid inventory fixture context'],
    'missing row closure' => ['rows.0.closure', '__REMOVE__', 'Missing corpus metadata: closure'],
    'missing row evidence' => ['rows.0.fixtures', [], 'Invalid inventory evidence'],
    'foreign fixture context' => ['rows.0.fixtures', ['en-ap-51515R-detail'], 'Invalid inventory fixture context'],
    'omitted required surface' => ['rows.0.surfaces', ['html'], 'Invalid inventory surface evidence'],
    'invalid section' => ['rows.0.section', 'optional_identity', 'Invalid inventory cell'],
    'gallery only' => ['media_scope.required', ['html:ProductGallery'], 'Invalid joint media inventory'],
    'changed filter' => ['media_scope.filter.mainImage', 'false', 'Invalid fixed media filter'],
    'missing pagination' => ['media_scope.pagination', '__REMOVE__', 'Missing corpus metadata: pagination'],
    'missing linkage' => ['media_scope.link', '', 'Missing media link'],
    'false document certification' => ['document_scope_decision.authentic_positive_support_certified', true, 'Invalid document support claim'],
    'populated marked complete' => ['document_scope_decision.detected_populated.complete', true, 'Invalid populated document gap semantics'],
    'missing terminal diagnostic' => ['document_scope_decision.detected_populated.diagnostic', '__REMOVE__', 'Invalid populated document gap semantics'],
    'hidden authentic gap' => ['rows.12.gap', 'No gap', 'Missing authentic document gap'],
    'missing cell' => ['rows.15', '__REMOVE__', 'Invalid inventory cell count'],
    'duplicate cell' => ['rows.1.profile', 'es-co', 'Duplicate inventory cell'],
    'wrong inventory revision' => ['inventory_version', 'unversioned', 'Invalid inventory revision'],
]);

it('keeps approval history irrelevant to technical inventory eligibility', function () {
    $corpus = new FixtureCorpus(wixCorpusDirectory());
    $inventory = $corpus->inventory();
    $inventory['signoffs'] = ['maintainer' => null, 'qa' => null, 'project_owner' => null];
    $inventory['direct_decision'] = ['status' => 'not_recorded'];
    $inventory['technical_review'] = ['source' => 'unavailable historical record'];

    expect($corpus->inventory($inventory)['rows'])->toBe($corpus->inventory()['rows']);
    $inventory['certified_cells'] = 16;
    expect(fn () => $corpus->inventory($inventory))->toThrow(RuntimeException::class, 'Inventory readiness is not parser certification');
});

it('preserves the PRD technical identifiers and automatic consumer contract without administrative roles', function () {
    $prd = file_get_contents(dirname(wixCorpusDirectory(), 3).'/PRD-15.md');
    foreach (['CA' => 30, 'RF' => 16, 'RNF' => 7, 'INV' => 12] as $prefix => $count) {
        for ($index = 1; $index <= $count; $index++) {
            expect($prd)->toContain(sprintf('%s-%02d', $prefix, $index));
        }
    }
    expect($prd)->toContain(
        'fetch(string $wixCode): WixLookupResult',
        '### Validación y mantenimiento del adaptador',
        'No requiere intervención humana, aprobaciones individuales ni un operador dedicado.',
        'sin establecer roles o procedimientos administrativos adicionales.',
        'verificaciones técnicas y condiciones de uso del proveedor aplicables.',
        'monitoreo y deshabilitación/kill-switch',
        'contract_version = "1.0"',
    )->not->toContain('### Responsables por rol y registro de gates', 'Mantenedor de integración', 'Responsable del proyecto');
});
