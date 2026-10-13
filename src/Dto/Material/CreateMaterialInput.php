<?php

declare(strict_types=1);

namespace App\Dto\Material;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /api/materials. Decimals arrive as strings per the contract's
 * decimal-as-string transport rule.
 */
final class CreateMaterialInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'name is required')]
        #[Assert\Length(max: 120)]
        public readonly string $name = '',
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['RAW', 'CONSUMABLE'], message: 'type must be RAW or CONSUMABLE')]
        public readonly string $type = '',
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['g', 'kg', 'ml', 'l', 'piece'], message: 'base_unit must be one of g, kg, ml, l, piece')]
        public readonly string $base_unit = '',
        #[Assert\Range(min: 0, max: 10000000)]
        public readonly ?string $reorder_threshold = null,
        #[Assert\Range(min: 0, max: 10000000)]
        public readonly ?string $stocktake_threshold = null,
    ) {
    }
}
