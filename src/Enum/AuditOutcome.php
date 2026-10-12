<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditOutcome: string
{
    case SUCCESS = 'SUCCESS';
    case FAILURE = 'FAILURE';
}
