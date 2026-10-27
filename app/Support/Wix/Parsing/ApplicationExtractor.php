<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Data\Application;
use App\Support\Wix\Data\NameValue;
use App\Support\Wix\Data\Navigation;
use App\Support\Wix\Data\Occurrence;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;

final class ApplicationExtractor
{
    public function __construct(private readonly ValueReader $values) {}

    /** Ordered and repeated query members; deliberately not parse_str. @return list<NameValue> */
    public function parameters(string $encoded): array
    {
        $query = rawurldecode($encoded);
        if (! str_starts_with($query, '?')) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'applications');
        }
        $out = [];
        foreach (explode('&', substr($query, 1)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[] = new NameValue(urldecode($key), urldecode($value));
        }

        return $out;
    }

    /** @return list<Application> */
    public function extract(HtmlSurface $html, string $marker, ?\Closure $commit = null): array
    {
        $panel = $html->panel('applications');
        $html->assertStatic($panel);
        if ($html->empty($panel, $marker)) {
            return [];
        }
        $out = [];
        $failure = null;
        try {
            foreach ($html->descendants($panel, 'table') as $table) {
                try {
                    if ($html->attribute($table, 'aria-label') !== 'Application Table') {
                        throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'applications');
                    }
                    $desktop = [];
                    $headers = [];
                    foreach ($html->children($table, 'tbody') as $body) {
                        $isDesktop = $html->hasClass($body, 'cmp-table__desktop');
                        $isTablet = $html->hasClass($body, 'cmp-table__tablet');
                        if (! $isDesktop && ! $isTablet) {
                            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'applications');
                        }
                        $records = [];
                        if ($isDesktop) {
                            foreach ($html->children($body, 'tr') as $row) {
                                $head = $html->children($row, 'th');
                                if ($head !== []) {
                                    $headers = array_map($html->text(...), $head);
                                } else {
                                    $records[] = [$row, $html->children($row, 'td'), $headers];
                                }
                            }
                        } else {
                            $cells = $labels = [];
                            foreach ($html->children($body, 'tr') as $row) {
                                $pair = $html->children($row, 'td');
                                if (count($pair) !== 2) {
                                    throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'applications');
                                }
                                $labels[] = $html->text($pair[0]);
                                $cells[] = $pair[1];
                            }
                            $records[] = [$body, $cells, $labels];
                        }
                        foreach ($records as [$record, $nodes, $labels]) {
                            try {
                                $links = array_values(array_filter($html->descendants($record, 'a'), fn ($n): bool => $html->attribute($n, 'data-g-binding-params') !== null));
                                if (count($links) !== 1 || $nodes === []) {
                                    throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'applications');
                                }
                                $link = $links[0];
                                $path = $html->attribute($link, 'data-g-binding-path');
                                if ($path !== '/'.$html->document->response->request->profile.'/catalog/results.html') {
                                    throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'applications');
                                }
                                $params = $this->parameters($html->attribute($link, 'data-g-binding-params'));
                                $get = static function (string $name) use ($params): ?string {
                                    $matches = array_values(array_filter($params, fn ($p): bool => $p->name === $name));

                                    return count($matches) === 1 ? $matches[0]->value : null;
                                };
                                $refs = [$html->reference($link)];
                                $cells = [];
                                foreach ($nodes as $index => $cell) {
                                    $cellRefs = [$html->reference($cell)];
                                    $refs = [...$refs, ...$cellRefs];
                                    $cells[] = $this->values->cell($html->text($cell), $labels[$index] ?? null, count($cells), $cellRefs);
                                }
                                foreach ($params as $param) {
                                    if (in_array($param->name, ['vehicleManufacturedFrom', 'vehicleManufacturedTo'], true)) {
                                        $cells[] = $this->values->cell($param->value, $param->name, count($cells), [$refs[0]]);
                                    }
                                }
                                $signature = json_encode([$path, array_map(fn ($p): array => [$p->name, $p->value], $params), array_map(fn ($c): array => [$c->label_raw, trim($c->value_raw ?? '')], $cells)], JSON_THROW_ON_ERROR);
                                $occurrence = new Occurrence($isDesktop ? 'desktop' : 'tablet', $record->locator, $refs);
                                $matched = null;
                                if ($isTablet) {
                                    foreach ($desktop as $index => $entry) {
                                        if ($entry === $signature) {
                                            $matched = $index;
                                            unset($desktop[$index]);
                                            break;
                                        }
                                    }
                                }
                                if ($matched !== null) {
                                    $prior = $out[$matched];
                                    $out[$matched] = new Application($prior->manufacturer_raw, $prior->model_raw, $prior->series_raw, $prior->variant_raw, $prior->engine_raw, $prior->period_raw, $prior->cells, $prior->source_identifiers, $prior->navigation, [...$prior->occurrences, $occurrence], $prior->order, [...$prior->evidence_refs, ...$refs]);
                                } else {
                                    $index = count($out);
                                    $out[] = new Application($get('vehicleMake'), $get('vehicleModel'), $get('modelSeriesName'), $get('vehicleName'), $get('engineCode'), null, $cells,
                                        array_values(array_filter($params, fn ($p): bool => str_ends_with($p->name, 'Id'))), new Navigation($path, $params), [$occurrence], $index, $refs);
                                    if ($isDesktop) {
                                        $desktop[$index] = $signature;
                                    }
                                }
                            } catch (OperationalFailure $invalid) {
                                if ($invalid->diagnosticCode !== DiagnosticCode::UnsupportedSection) {
                                    throw $invalid;
                                }
                                $failure = $invalid;
                            }
                        }
                    }
                } catch (OperationalFailure $invalid) {
                    if ($invalid->diagnosticCode !== DiagnosticCode::UnsupportedSection) {
                        throw $invalid;
                    }
                    $failure = $invalid;
                }
            }
            if ($out === []) {
                throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'applications');
            }
            $publishedLinks = array_filter($html->descendants($panel, 'a'), fn ($n): bool => $html->attribute($n, 'data-g-binding-params') !== null);
            if (count($publishedLinks) !== array_sum(array_map(fn (Application $application): int => count($application->occurrences), $out))) {
                throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'applications');
            }
            if ($failure !== null) {
                throw $failure;
            }

            return $commit === null ? $out : [];
        } finally {
            if ($commit !== null && $out !== []) {
                $commit($out);
            }
        }
    }
}
