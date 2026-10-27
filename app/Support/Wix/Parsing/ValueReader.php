<?php

namespace App\Support\Wix\Parsing;

use App\Support\Wix\Data\Cell;
use App\Support\Wix\Data\Observation;
use App\Support\Wix\Data\RawField;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\RawType;
use App\Support\Wix\Enums\ValuePresence;
use App\Support\Wix\OperationalFailure;

final class ValueReader
{
    /** @param array{raw_type:string,lexeme:?string}|null $token @param list<string> $refs */
    public function field(string $name, ?array $token, array $refs): RawField
    {
        if ($token === null) {
            return new RawField($name, null, ValuePresence::Missing, RawType::Missing, $refs);
        }
        $type = RawType::from($token['raw_type']);
        if ($token['lexeme'] === null) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'value', null, null, $refs);
        }
        if (in_array($type, [RawType::Object, RawType::Array], true) && ! json_validate($token['lexeme'], 20)) {
            throw new OperationalFailure(DiagnosticCode::UnsupportedSection, 'value', null, null, $refs);
        }
        $raw = match ($type) {
            RawType::String => json_decode($token['lexeme'], true, 2, JSON_THROW_ON_ERROR),
            RawType::Null => null,
            default => $token['lexeme'],
        };

        return new RawField($name, $raw, ValuePresence::Present, $type, $refs);
    }

    public function observation(RawField $field, ?string $label, ?string $unit, ?string $admin, string $section, int $order, ?string $key = null): Observation
    {
        return new Observation($field->value_raw, $field->value_presence, $field->raw_type, $field->evidence_refs, $label, $unit, $admin, $section, $order, $key);
    }

    public function cell(?string $value, ?string $label, int $order, array $refs, ?string $unit = null): Cell
    {
        return new Cell($value, $value === null ? ValuePresence::Missing : ValuePresence::Present, $value === null ? RawType::Missing : RawType::String, $refs, $label, $unit, $order);
    }
}
