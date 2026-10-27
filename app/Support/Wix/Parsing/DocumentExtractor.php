<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\OperationalFailure;

final class DocumentExtractor
{
    public function extract(HtmlSurface $html, string $marker): array
    {
        $panel = $html->panel('downloads');
        $html->assertStatic($panel);
        if (! $html->empty($panel, $marker)) {
            $refs = [$html->reference($panel)];
            foreach ($html->descendants($panel) as $node) {
                if ($node->name === 'a' || trim($node->text) !== '') {
                    $refs[] = $html->reference($node);
                }
            }
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'downloads', null, 'documents', $refs);
        }

        return [];
    }
}
