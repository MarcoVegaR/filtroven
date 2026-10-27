<?php

namespace App\Support\Wix\Enums;

enum LookupResolution: string
{
    case Exact = 'exact';
    case NoExactMatch = 'no_exact_match';
    case Ambiguous = 'ambiguous';
    case Incomplete = 'incomplete';
    case NotAttempted = 'not_attempted';
}
