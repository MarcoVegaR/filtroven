<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Data\Observation;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;

final class TechnicalSectionExtractor
{
    public const Dimensions = ['height', 'length', 'width', 'outside_diameter_top', 'outside_diameter_bottom', 'inside_diameter_top', 'inside_diameter_bottom', 'thread_size', 'aa_dimension_string'];

    public function __construct(private readonly ValueReader $values) {}

    /** @return list<Observation> */
    public function table(HtmlSurface $html, string $id, string $marker, ?\Closure $commit = null): array
    {
        $panel = $html->panel($id);
        $html->assertStatic($panel);
        if ($html->empty($panel, $marker)) {
            return [];
        }
        $rows = $html->descendants($panel, 'tr');
        if ($rows === []) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, $id);
        }
        $out = [];
        $failure = null;
        foreach ($rows as $row) {
            $cells = $html->children($row, 'td');
            if (count($cells) < 1) {
                $failure = new OperationalFailure(DiagnosticCode::UnsupportedSection, $id);

                continue;
            }
            $refs = [$html->reference($row), ...array_map($html->reference(...), $cells)];
            $label = $html->text($cells[0]);
            $unit = preg_match('/\(([^()]*)\)\s*$/u', $label, $match) ? $match[1] : null;
            foreach (array_slice($cells, 1) ?: [null] as $cell) {
                $raw = $cell === null ? null : ['raw_type' => 'string', 'lexeme' => json_encode($html->text($cell), JSON_THROW_ON_ERROR)];
                $publishedUnit = $unit;
                if ($publishedUnit === null && $cell !== null && preg_match('/\s(mm|cm|m|bar|psi|kPa|MPa|°C|l\/min|L\/min)$/uD', $html->text($cell), $match)) {
                    $publishedUnit = $match[1];
                }
                $item = $this->values->observation($this->values->field('value', $raw, $refs), $label, $publishedUnit, null, $id, count($out));
                $out[] = $item;
                if ($commit !== null) {
                    $commit([$item]);
                }
            }
        }
        foreach ($html->descendants($panel, 'p') as $paragraph) {
            $ref = $html->reference($paragraph);
            $item = $this->values->observation($this->values->field('value', ['raw_type' => 'string', 'lexeme' => json_encode($html->text($paragraph), JSON_THROW_ON_ERROR)], [$ref]), null, null, null, $id, count($out));
            $out[] = $item;
            if ($commit !== null) {
                $commit([$item]);
            }
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $commit === null ? $out : [];
    }

    /** @return list<Observation> */
    public function reach(HtmlSurface $html): array
    {
        $panel = $html->panel('reachInformation');
        $html->assertStatic($panel);
        $out = [];
        foreach ($html->descendants($panel) as $node) {
            if ($node->name === 'p' || ($node->name === 'span' && $html->text($node) !== '' && ! $html->hasClass($node, 'cmp-button__text'))) {
                $ref = $html->reference($node);
                $out[] = $this->values->observation($this->values->field('value', ['raw_type' => 'string', 'lexeme' => json_encode($html->text($node), JSON_THROW_ON_ERROR)], [$ref]), null, null, null, 'reachInformation', count($out));
            }
        }
        if ($out === []) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'reachInformation');
        }

        return $out;
    }
}
