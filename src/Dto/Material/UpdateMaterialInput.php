<?php

declare(strict_types=1);

namespace App\Dto\Material;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for PATCH /api/materials/{id}. Only a subset of fields is mutable per the
 * domain model; base_unit is NOT accepted (immutable once a movement exists — and in
 * general treated as immutable per the contract's PATCH section).
 */
final class UpdateMaterialInput
{
    /**
     * @param array<string, mixed> $raw raw JSON body (to detect base_unit presence)
     */
    public function __construct(
        #[Assert\Length(min: 1, max: 120)]
        public readonly ?string $name = null,
        #[Assert\Choice(choices: ['RAW', 'CONSUMABLE'])]
        public readonly ?string $type = null,
        #[Assert\Range(min: 0, max: 10000000)]
        public readonly ?string $reorder_threshold = null,
        #[Assert\Range(min: 0, max: 10000000)]
        public readonly ?string $stocktake_threshold = null,
        #[Assert\Type(type: 'bool')]
        public readonly ?bool $is_active = null,
        public readonly array $raw = [],
    ) {
    }
}
