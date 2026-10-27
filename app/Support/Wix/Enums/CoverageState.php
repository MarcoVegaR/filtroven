<?php

namespace App\Support\Wix\Enums;

enum CoverageState: string
{
    case Captured = 'captured';
    case PublishedEmpty = 'published_empty';
    case NotPublished = 'not_published';
    case Failed = 'failed';
    case NotChecked = 'not_checked';
    case Unsupported = 'unsupported';
}
