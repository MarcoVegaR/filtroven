<?php

namespace App\Support\Wix\Enums;

enum LookupStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
}
