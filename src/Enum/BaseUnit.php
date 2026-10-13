<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The fixed, approved unit list (REQ-002). Closed for extension in Phase 1's scope.
 */
enum BaseUnit: string
{
    case GRAM = 'g';
    case KILOGRAM = 'kg';
    case MILLILITRE = 'ml';
    case LITRE = 'l';
    case PIECE = 'piece';
}
