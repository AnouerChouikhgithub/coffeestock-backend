<?php

declare(strict_types=1);

namespace App\Enum;

enum MovementType: string
{
    case RECEIPT = 'RECEIPT';
    case CONSUMPTION = 'CONSUMPTION';
    case WASTE = 'WASTE';
    case ADJUSTMENT = 'ADJUSTMENT';
    case STOCKTAKE_DELTA = 'STOCKTAKE_DELTA';
}
