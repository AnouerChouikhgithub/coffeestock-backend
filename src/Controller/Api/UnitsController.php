<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\BaseUnit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/units — the fixed approved unit set (REQ-002).
 */
final class UnitsController extends AbstractController
{
    #[Route('/api/units', name: 'api_units_list', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json([
            'items' => array_map(static fn (BaseUnit $u): string => $u->value, BaseUnit::cases()),
        ]);
    }
}
