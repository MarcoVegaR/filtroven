<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Data\BudgetSnapshot;
use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Data\LookupAttempt;
use App\Support\Wix\Data\Media;
use App\Support\Wix\Data\Observation;
use App\Support\Wix\Data\RegionalResolution;
use App\Support\Wix\Data\SectionCoverage;
use App\Support\Wix\Data\SurfaceProgress;
use App\Support\Wix\Data\WixProduct;
use App\Support\Wix\Enums\CoverageState;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\Http\AssetMetadataPaginator;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Profiles\ProfileRegistry;

final class SurfacePlan
{
    public function __construct(private readonly SectionExtractor $sections, private readonly TechnicalSectionExtractor $technical, private readonly ApplicationExtractor $applications,
        private readonly OeReferenceExtractor $oe, private readonly DocumentExtractor $documents, private readonly MediaExtractor $media, private readonly AssetMetadataPaginator $assets) {}

    /** @return array{?WixProduct,CoverageSections} */
    public function capture(RegionalResolution $resolution, LookupContext $context): array
    {
        $identity = $resolution->identity;
        if ($identity?->identity() === null || $context->document === null) {
            throw new \LogicException('Section plan requires verified identity and retained document');
        }
        $profile = (new ProfileRegistry)->get($identity->source()->profile);
        $html = new HtmlSurface($context->document, $context);
        $lists = $progress = [];
        foreach (array_slice(CoverageSections::NAMES, 1) as $section) {
            $lists[$section] = [];
            $progress[$section] = [];
        }
        $run = function (string $section, EvidenceSurface $surface, \Closure $capture) use (&$lists, &$progress, $context): void {
            $prior = array_keys($context->evidence->all());
            $countBefore = count($lists[$section]);
            $began = false;
            try {
                $context->budget->check('section');
                $began = true;
                $items = $capture(static function (array $items) use (&$lists, $section): void {
                    $lists[$section] = [...$lists[$section], ...$items];
                });
                $refs = array_values(array_diff(array_keys($context->evidence->all()), $prior));
                if ($refs === []) {
                    $refs = array_keys(array_filter($context->evidence->all(), fn ($e): bool => $e->surface === $surface && $e->profile === $context->document->response->request->profile));
                }
                $captured = $items !== [] || count($lists[$section]) > $countBefore;
                $lists[$section] = [...$lists[$section], ...$items];
                $progress[$section][] = new SurfaceProgress($surface, $captured ? CoverageState::Captured : CoverageState::PublishedEmpty, true, true, true, false, $refs, []);
            } catch (OperationalFailure $failure) {
                $refs = array_values(array_unique([...$failure->evidenceRefs, ...array_diff(array_keys($context->evidence->all()), $prior)]));
                $state = count($lists[$section]) > $countBefore ? CoverageState::Captured : ($failure->diagnosticCode === DiagnosticCode::UnsupportedSection ? CoverageState::Unsupported : CoverageState::Failed);
                $progress[$section][] = new SurfaceProgress($surface, $began ? $state : CoverageState::NotChecked, $began, $began, false, true, $refs, [$failure->diagnosticCode->value]);
                $attempt = match ($surface) {
                    EvidenceSurface::AssetMetadata => 'assets-'.$context->document->response->request->profile,
                    EvidenceSurface::GraphqlSearch => 'search-'.$context->document->response->request->profile,
                    EvidenceSurface::Html => $context->document->response->request->attemptId,
                };
                $context->diagnose((new OperationalFailure($failure->diagnosticCode, $failure->stage, $attempt, $section, $refs))->diagnostic());
            }
        };
        foreach (['dimensions', 'specifications', 'applications', 'observations'] as $section) {
            $run($section, EvidenceSurface::GraphqlSearch, fn (\Closure $commit): array => $this->sections->graph($section, $identity, $context, $commit));
        }
        $run('media', EvidenceSurface::Html, fn (\Closure $commit): array => $this->media->gallery($html, $commit));
        $marker = $profile->inventory()->emptyMarker();
        foreach (['dimensions' => 'dimensions', 'specifications' => 'technicalData'] as $section => $id) {
            $run($section, EvidenceSurface::Html, fn (\Closure $commit): array => $this->technical->table($html, $id, $marker, $commit));
        }
        $run('applications', EvidenceSurface::Html, fn (\Closure $commit): array => $this->applications->extract($html, $marker, $commit));
        $run('oe_references', EvidenceSurface::Html, fn (\Closure $commit): array => $this->oe->extract($html, $marker, $commit));
        $run('documents', EvidenceSurface::Html, function () use ($html, $marker): array {
            $items = $this->documents->extract($html, $marker);
            $html->reference($html->descendants($html->panel('downloads'))[0]);

            return $items;
        });
        $run('observations', EvidenceSurface::Html, function () use ($html, $identity, $context): array {
            $items = [...$this->sections->descriptions($html), ...$this->technical->reach($html)];
            foreach ($html->descendants($html->panel('reachInformation')) as $parent) {
                if ($html->hasClass($parent, 'cmp-product__reach-information-product')) {
                    $spans = $html->children($parent, 'span');
                    if (count($spans) === 2) {
                        $identity->compare('sales_designation', $html->text($spans[1]), $context, $html->reference($spans[1]));
                    }
                }
            }

            return $items;
        });
        $attempt = 'assets-'.$profile->id;
        $before = $context->budget->snapshot();
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $prior = array_keys($context->evidence->all());
        $assetPages = [];
        $run('media', EvidenceSurface::AssetMetadata, function () use ($profile, $identity, $context, $attempt, &$lists, &$assetPages): array {
            if ($identity->identity() === null) {
                throw new OperationalFailure(DiagnosticCode::IdentityMismatch, 'assets');
            }
            $this->assets->capture($profile, $identity, $context, $attempt, static function (array $items, int $offset, string $ref) use (&$lists, &$assetPages): void {
                $lists['media'] = [...$lists['media'], ...$items];
                $assetPages[] = count($assetPages) + 1;
            });

            return [];
        });
        $after = $context->budget->snapshot();
        $used = new BudgetSnapshot($after->elapsed_ms - $before->elapsed_ms, $after->requests - $before->requests, 0, 0, $after->bytes - $before->bytes, $after->nodes - $before->nodes);
        $context->recordAttempt(new LookupAttempt($attempt, $profile->id, 'asset_metadata', $assetPages, $used->requests, $progress['media'][1]->complete() ? 'captured' : 'incomplete', array_values(array_diff(array_keys($context->evidence->all()), $prior)), $started, gmdate('Y-m-d\TH:i:s\Z'), $used));
        $sections = [];
        foreach (CoverageSections::NAMES as $section) {
            if ($section === 'identity') {
                $valid = $identity->identity() !== null;
                $sections[] = new SectionCoverage($valid ? CoverageState::Captured : CoverageState::Failed, $valid, $valid ? [] : ['identity_mismatch'], $valid ? $identity->identity()->evidence_refs : array_keys($context->evidence->all()));
            } else {
                $sections[] = (new CoverageEvaluator)->section($profile->inventory(), $section, $progress[$section]);
            }
        }
        foreach ($lists as &$items) {
            foreach ($items as $index => $item) {
                if ($item instanceof Observation) {
                    $items[$index] = new Observation($item->value_raw, $item->value_presence, $item->raw_type, $item->evidence_refs, $item->label_raw, $item->unit_raw, $item->raw_admin_value, $item->section, $index, $item->source_key);
                } elseif ($item instanceof Media) {
                    $items[$index] = new Media($item->reference_raw, $item->url_resolved, $item->label_raw, $item->declared_metadata, $index, $item->evidence_refs, $item->position_published, $item->type_declared, $item->format_declared, $item->mime_declared, $item->roles);
                }
            }
        }
        unset($items);
        $product = $identity->identity() === null ? null : new WixProduct($identity->identity(), ...array_values($lists));

        return [$product, new CoverageSections(...$sections)];
    }
}
