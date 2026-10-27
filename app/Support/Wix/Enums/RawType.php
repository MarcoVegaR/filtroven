<?php

namespace App\Support\Wix\Enums;

enum RawType: string
{
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Null = 'null';
    case Object = 'object';
    case Array = 'array';
    case Missing = 'missing';
}
