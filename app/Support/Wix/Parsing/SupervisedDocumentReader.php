<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Contracts\SafeDocumentReader;
use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\DocumentNode;
use App\Support\Wix\Data\NameValue;
use App\Support\Wix\Data\ParsedDocument;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Runtime\SupervisedPhpTask;

final class SupervisedDocumentReader implements SafeDocumentReader
{
    public function __construct(private readonly SupervisedPhpTask $supervisor) {}

    /** @return array<string,mixed> */
    public function json(CapturedResponse $response, LookupContext $context): array
    {
        $root = null;
        $this->execute('json', $response, $context, static function (array $record) use (&$root): void {
            $target = &$root;
            foreach ($record['path'] as $key) {
                $target = &$target[$key];
            }
            $target = $record['type'] === 'scalar' ? $record['value'] : ($record['type'] === 'object' && $record['empty'] ? new \stdClass : []);
        });
        if (! is_array($root)) {
            throw new OperationalFailure(DiagnosticCode::SchemaError, 'json');
        }

        return $root;
    }

    public function read(CapturedResponse $response, LookupContext $context): ParsedDocument
    {
        $nodes = [];
        $this->execute('html', $response, $context, static function (array $record) use (&$nodes): void {
            $nodes[] = new DocumentNode($record['locator'], $record['name'], $record['text'], array_map(static fn (array $a): NameValue => new NameValue(...$a), $record['attributes']), $record['content'] ?? null);
        });

        return new ParsedDocument($response, $nodes, true);
    }

    /** @return array<string,array{raw_type:string,lexeme:?string}> JSON pointers retain source tokens, including exponent/precision. */
    public function lexemes(CapturedResponse $response, LookupContext $context): array
    {
        $values = [];
        $this->execute('lexemes', $response, $context, static function (array $record) use (&$values): void {
            $pointer = implode('/', array_map(static fn (string|int $key): string => str_replace(['~', '/'], ['~0', '~1'], (string) $key), $record['path']));
            $values['/'.$pointer] = ['raw_type' => $record['raw_type'], 'lexeme' => $record['lexeme']];
        });

        return $values;
    }

    private function execute(string $operation, CapturedResponse $response, LookupContext $context, \Closure $accept): void
    {
        $context->budget->check('parse');
        if (! class_exists(\DOMDocument::class) || ! defined('LIBXML_NONET') || ! function_exists('json_decode')) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'parse');
        }
        if (strlen($response->body) > min(2097152, $context->budget->limits->maxDecodedBytesPerResponse)) {
            throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'parse');
        }
        $pending = '';
        $this->supervisor->run($operation, ['max_nodes' => $context->budget->limits->maxNodesTotal - $context->budget->snapshot()->nodes], $response->body,
            $context->budget->deadline - $context->budget->limits->finalizationReserveMs * 1000000, 16777216,
            static function (string $frame) use ($context, $accept, &$pending): void {
                $context->budget->check('parse');
                if (! in_array($frame[0], ['J', 'j'], true) || strlen($frame) < 2) {
                    throw new OperationalFailure(DiagnosticCode::ParseError, 'parse');
                }
                if ($pending === '') {
                    $context->budget->visit(1);
                }
                if (strlen($frame) - 1 > 16777216 - strlen($pending)) {
                    throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'parse');
                }
                $pending .= substr($frame, 1);
                if ($frame[0] === 'J') {
                    $accept(json_decode($pending, true, 64, JSON_THROW_ON_ERROR));
                    $pending = '';
                }
            }, static function () use ($context): bool {
                $context->budget->check('parse');

                return false;
            });
        if ($pending !== '') {
            throw new OperationalFailure(DiagnosticCode::ParseError, 'parse');
        }
    }
}
