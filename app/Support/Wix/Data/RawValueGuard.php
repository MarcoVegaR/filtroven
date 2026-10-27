<?php

namespace App\Support\Wix\Data;

use App\Support\Wix\Enums\RawType;
use App\Support\Wix\Enums\ValuePresence;

final class RawValueGuard
{
    public static function validate(?string $value, ValuePresence $presence, RawType $type): void
    {
        ContractGuard::require(($presence === ValuePresence::Missing) === ($type === RawType::Missing), 'missing raw type');
        if ($type === RawType::Missing || $type === RawType::Null) {
            ContractGuard::require($value === null, 'null raw value');

            return;
        }
        ContractGuard::require($value !== null, 'present literal raw value');
        if ($type === RawType::Number) {
            ContractGuard::require(preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value) === 1, 'JSON number lexeme');
        } elseif ($type === RawType::Boolean) {
            ContractGuard::require(in_array($value, ['true', 'false'], true), 'JSON boolean');
        } elseif ($type === RawType::Object || $type === RawType::Array) {
            ContractGuard::require(json_validate($value, 20) && str_starts_with(ltrim($value), $type === RawType::Object ? '{' : '['), 'structured JSON literal');
        }
    }
}
