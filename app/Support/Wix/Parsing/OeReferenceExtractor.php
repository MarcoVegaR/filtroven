<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Data\OeReference;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;

final class OeReferenceExtractor
{
    /** @return list<OeReference> */
    public function extract(HtmlSurface $html, string $marker, ?\Closure $commit = null): array
    {
        $panel = $html->panel('oeNumbers');
        $html->assertStatic($panel);
        if ($html->empty($panel, $marker)) {
            return [];
        }
        $byPath = [];
        $failure = null;
        foreach ($html->descendants($panel) as $node) {
            $byPath[$node->locator] = $node;
            if ($html->hasClass($node, 'cmp-accordion__item')) {
                $panels = array_values(array_filter($html->children($node), fn ($n): bool => $html->attribute($n, 'data-cmp-hook-accordion') === 'panel'));
                if (count($panels) !== 1 || ($html->descendants($panels[0], 'li') === [] && ! $html->empty($panels[0], $marker))) {
                    $failure = new OperationalFailure(DiagnosticCode::UnsupportedSection, 'oeNumbers');
                }
            }
        }
        $out = [];
        foreach ($html->descendants($panel) as $node) {
            if ($node->name === 'li') {
                $path = $node->locator;
                $group = null;
                while (str_contains($path, '/') && $path !== $panel->locator) {
                    $path = substr($path, 0, strrpos($path, '/'));
                    if (isset($byPath[$path]) && $html->hasClass($byPath[$path], 'cmp-accordion__item')) {
                        $group = $byPath[$path];
                        break;
                    }
                }
                $headers = $group === null ? [] : $html->children($group, 'h3');
                $titles = count($headers) === 1 ? array_values(array_filter($html->descendants($headers[0]), fn ($n): bool => $html->hasClass($n, 'cmp-accordion__title'))) : [];
                if (count($titles) !== 1) {
                    $failure = new OperationalFailure(DiagnosticCode::UnsupportedSection, 'oeNumbers');
                }
                $refs = count($titles) === 1 ? [$html->reference($titles[0]), $html->reference($node)] : [$html->reference($node)];
                $item = new OeReference(count($titles) === 1 ? $html->text($titles[0]) : null, $html->text($node), count($out), $refs);
                $out[] = $item;
                if ($commit !== null) {
                    $commit([$item]);
                }
            }
        }
        if ($out === []) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'oeNumbers');
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $commit === null ? $out : [];
    }
}
