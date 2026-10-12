<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Role set per OD-2 recommended default, adopted as decided-by-default for Phase 2
 * (see docs/plans/phase-2-plan.md). Authorization enforcement itself is Phase 9.
 */
enum UserRole: string
{
    case OPERATOR = 'OPERATOR';
    case MANAGER = 'MANAGER';
    case ADMIN = 'ADMIN';
}
