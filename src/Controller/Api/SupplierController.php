<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Supplier;
use App\Repository\SupplierRepository;
use App\Service\SupplierService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SupplierController extends AbstractController
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly SupplierRepository $repository,
    ) {
    }

    #[Route('/api/suppliers', name: 'api_suppliers_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $result = $this->suppliers->create(
            (string) ($body['name'] ?? ''),
            isset($body['contact_name']) ? (string) $body['contact_name'] : null,
            isset($body['email']) ? (string) $body['email'] : null,
            isset($body['phone']) ? (string) $body['phone'] : null,
        );
        if (\is_array($result)) {
            $isDuplicate = 'name' === $result[0]['field'] && str_contains($result[0]['issue'], 'already exists');

            return $isDuplicate
                ? ErrorResponse::make('DUPLICATE_NAME', 'A supplier with this name already exists.', $result, 422)
                : ErrorResponse::validationFailed($result);
        }

        return $this->json($this->supplierArray($result), 201);
    }

    #[Route('/api/suppliers', name: 'api_suppliers_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }

        $includeInactive = 'true' === $request->query->get('includeInactive', 'false');
        $search = $request->query->get('search');

        $result = $this->suppliers->list($pagination['page'], $pagination['perPage'], $includeInactive, $search);

        return $this->json([
            'page' => $pagination['page'],
            'perPage' => $pagination['perPage'],
            'total' => $result['total'],
            'items' => array_map(fn (Supplier $s) => $this->supplierArray($s), $result['items']),
        ]);
    }

    #[Route('/api/suppliers/{id}', name: 'api_suppliers_read', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function read(int $id): JsonResponse
    {
        $supplier = $this->repository->find($id);
        if (!$supplier instanceof Supplier) {
            return ErrorResponse::notFound('Supplier not found.');
        }

        return $this->json($this->supplierArray($supplier));
    }

    #[Route('/api/suppliers/{id}', name: 'api_suppliers_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $supplier = $this->repository->find($id);
        if (!$supplier instanceof Supplier) {
            return ErrorResponse::notFound('Supplier not found.');
        }

        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $result = $this->suppliers->update(
            $supplier,
            isset($body['name']) ? (string) $body['name'] : null,
            array_key_exists('contact_name', $body) ? (null === $body['contact_name'] ? null : (string) $body['contact_name']) : null,
            array_key_exists('email', $body) ? (null === $body['email'] ? null : (string) $body['email']) : null,
            array_key_exists('phone', $body) ? (null === $body['phone'] ? null : (string) $body['phone']) : null,
        );
        if (\is_array($result)) {
            $isDuplicate = 'name' === $result[0]['field'] && str_contains($result[0]['issue'], 'already exists');

            return $isDuplicate
                ? ErrorResponse::make('DUPLICATE_NAME', 'A supplier with this name already exists.', $result, 422)
                : ErrorResponse::validationFailed($result);
        }

        return $this->json($this->supplierArray($result));
    }

    #[Route('/api/suppliers/{id}/deactivate', name: 'api_suppliers_deactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deactivate(int $id): Response
    {
        $supplier = $this->repository->find($id);
        if (!$supplier instanceof Supplier) {
            return ErrorResponse::notFound('Supplier not found.');
        }

        $this->suppliers->deactivate($supplier);

        return new Response('', 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierArray(Supplier $s): array
    {
        return [
            'id' => $s->getId(),
            'name' => $s->getName(),
            'contact_name' => $s->getContactName(),
            'email' => $s->getEmail(),
            'phone' => $s->getPhone(),
            'is_active' => $s->isActive(),
        ];
    }
}
