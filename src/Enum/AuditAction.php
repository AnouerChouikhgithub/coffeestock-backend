<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * One value per stock-affecting operation type (domain model, Audit Log section).
 * Extend as new operations ship; never repurpose existing values.
 */
enum AuditAction: string
{
    case RECEIPT_CREATE = 'RECEIPT_CREATE';
    case CONSUMPTION_CREATE = 'CONSUMPTION_CREATE';
    case WASTE_CREATE = 'WASTE_CREATE';
    case ADJUSTMENT_CREATE = 'ADJUSTMENT_CREATE';
    case STOCKTAKE_CREATE = 'STOCKTAKE_CREATE';
    case STOCKTAKE_COMMIT = 'STOCKTAKE_COMMIT';
    case MATERIAL_CREATE = 'MATERIAL_CREATE';
    case MATERIAL_UPDATE = 'MATERIAL_UPDATE';
    case SUPPLIER_CREATE = 'SUPPLIER_CREATE';
    case SUPPLIER_UPDATE = 'SUPPLIER_UPDATE';
    case SUPPLIER_DEACTIVATE = 'SUPPLIER_DEACTIVATE';
}
