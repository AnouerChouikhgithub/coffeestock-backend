<?php

declare(strict_types=1);

namespace App\Enum;

enum MaterialType: string
{
    case RAW = 'RAW';
    case CONSUMABLE = 'CONSUMABLE';
}
