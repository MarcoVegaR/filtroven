<?php

namespace App\Support\Wix\Data;

/** Parent-owned inert units admitted by the bounded document reader. */
final readonly class ParsedDocument
{
    /** @param list<DocumentNode> $nodes */
    public function __construct(public CapturedResponse $response, public array $nodes, public bool $complete)
    {
        ContractGuard::require(array_is_list($nodes), 'ordered document nodes');
        foreach ($nodes as $node) {
            ContractGuard::require($node instanceof DocumentNode, 'typed document node');
        }
    }
}
