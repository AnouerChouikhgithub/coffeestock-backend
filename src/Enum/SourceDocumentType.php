<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Polymorphic provenance discriminator for StockMovement (domain model:
 * source_document_type + source_document_id pair, no FK).
 */
enum SourceDocumentType: string
{
    case RECEIPT_ITEM = 'RECEIPT_ITEM';
    case CONSUMPTION_ENTRY = 'CONSUMPTION_ENTRY';
    case WASTE_ENTRY = 'WASTE_ENTRY';
    case ADJUSTMENT = 'ADJUSTMENT';
    case STOCKTAKE_COUNT = 'STOCKTAKE_COUNT';
}
