<?php

namespace App\Support\Wix\Enums;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
