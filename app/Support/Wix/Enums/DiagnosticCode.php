<?php

namespace App\Support\Wix\Enums;

enum DiagnosticCode: string
{
    case InvalidInput = 'invalid_input';
    case Disabled = 'disabled';
    case InvalidConfiguration = 'invalid_configuration';
    case TransportError = 'transport_error';
    case HttpError = 'http_error';
    case GraphqlError = 'graphql_error';
    case SchemaError = 'schema_error';
    case PaginationError = 'pagination_error';
    case BudgetExhausted = 'budget_exhausted';
    case IdentityMismatch = 'identity_mismatch';
    case IdentityUnverified = 'identity_unverified';
    case IdentityConflict = 'identity_conflict';
    case AmbiguousIdentity = 'ambiguous_identity';
    case ParseError = 'parse_error';
    case UnsupportedSection = 'unsupported_section';
}
