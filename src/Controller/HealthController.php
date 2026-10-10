<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class HealthController extends AbstractController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $database = [
            'connected' => false,
            'driver' => 'pgsql',
            'message' => 'Database is not connected yet.',
        ];

        try {
            $this->connection->prepare('SELECT 1')->executeQuery();

            $database = [
                'connected' => true,
                'driver' => 'pgsql',
                'message' => 'Database connection is active.',
            ];
        } catch (\Throwable $exception) {
            $database['message'] = $exception->getMessage();
        }

        return $this->json([
            'status' => 'ok',
            'app' => 'coffeestock-backend',
            'environment' => $this->getParameter('kernel.environment'),
            'database' => $database,
        ]);
    }
}
