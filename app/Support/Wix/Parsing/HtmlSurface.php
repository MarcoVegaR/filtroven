<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Data\DocumentNode;
use App\Support\Wix\Data\ParsedDocument;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;

/** Indexed inert nodes, never a second native DOM/XPath in the parent. */
final class HtmlSurface
{
    private array $children = [];

    public function __construct(public readonly ParsedDocument $document, public readonly LookupContext $context)
    {
        foreach ($document->nodes as $node) {
            $context->budget->check('section-index');
            $this->children[substr($node->locator, 0, strrpos($node->locator, '/'))][] = $node;
        }
    }

    public function attribute(DocumentNode $node, string $name): ?string
    {
        foreach ($node->attributes as $attribute) {
            if ($attribute->name === $name) {
                return $attribute->value;
            }
        }

        return null;
    }

    public function hasClass(DocumentNode $node, string $class): bool
    {
        return in_array($class, explode(' ', $this->attribute($node, 'class') ?? ''), true);
    }

    public function panel(string $id): DocumentNode
    {
        $matches = array_values(array_filter($this->document->nodes, fn (DocumentNode $n): bool => $this->attribute($n, 'id') === $id));
        if (count($matches) !== 1 || ! $this->hasClass($matches[0], 'cmp-accordion__item')) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, $id);
        }
        $panels = array_values(array_filter($this->children($matches[0]), fn (DocumentNode $n): bool => $this->attribute($n, 'data-cmp-hook-accordion') === 'panel'));
        if (count($panels) !== 1) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, $id);
        }

        return $panels[0];
    }

    /** @return list<DocumentNode> */
    public function children(DocumentNode $node, ?string $name = null): array
    {
        return array_values(array_filter($this->children[$node->locator] ?? [], static fn (DocumentNode $n): bool => $name === null || $n->name === $name));
    }

    /** @return list<DocumentNode> */
    public function descendants(DocumentNode $node, ?string $name = null): array
    {
        $out = [];
        $walk = function (DocumentNode $parent) use (&$walk, &$out, $name): void {
            foreach ($this->children($parent) as $child) {
                $this->context->budget->check('section');
                if ($name === null || $child->name === $name) {
                    $out[] = $child;
                }
                $walk($child);
            }
        };
        $walk($node);

        return $out;
    }

    public function text(DocumentNode $node): string
    {
        $text = $node->content ?? $node->text;

        return str_contains($text, "\n") || str_contains($text, "\r") ? trim($text) : $text;
    }

    public function reference(DocumentNode $node): string
    {
        return $this->context->evidence->capture($this->document->response, $node->locator, json_encode(['name' => $node->name, 'attributes' => array_map(fn ($a): array => [$a->name, $a->value], $node->attributes), 'text' => $node->content ?? $node->text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function empty(DocumentNode $panel, string $marker): bool
    {
        $nodes = $this->descendants($panel);
        $empty = count($nodes) === 1 && $nodes[0]->name === 'div' && trim($nodes[0]->text) === $marker;
        if ($empty) {
            $this->reference($panel);
            $this->reference($nodes[0]);
        }

        return $empty;
    }

    public function assertStatic(DocumentNode $panel): void
    {
        foreach ([$panel, ...$this->descendants($panel)] as $node) {
            foreach ($node->attributes as $attribute) {
                if (preg_match('/(?:load-more|lazy|pagination|cursor|has-more|next-page|endpoint|data-fetch|data-ajax|data-src|data-loading)/i', $attribute->name.' '.$attribute->value)) {
                    throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'deferred', null, null, [$this->reference($node)]);
                }
            }
            if (in_array($node->name, ['script', 'iframe'], true)) {
                throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'deferred', null, null, [$this->reference($node)]);
            }
            if (preg_match('/^(?:Loading|Cargando|Show more|Load more|Cargar m[aá]s)(?:[.\s…]|$)/iu', trim($node->text))) {
                throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'deferred', null, null, [$this->reference($node)]);
            }
        }
    }
}
