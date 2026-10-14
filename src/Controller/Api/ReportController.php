<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\AuditAction;
use App\Entity\AuditLog;
use App\Entity\Material;
use App\Repository\AuditLogRepository;
use App\Repository\MaterialRepository;
use App\Repository\StockMovementRepository;
use App\Service\InventoryService;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AbstractController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly MaterialRepository $materials,
        private readonly StockMovementRepository $movements,
        private readonly AuditLogRepository $auditLogs,
    ) {
    }

    // -------------------------------------------------------------------------
    // 25. GET /api/reports/inventory
    // Same data as GET /api/inventory, full-fidelity (no belowThresholdOnly shorthand).
    // -------------------------------------------------------------------------
    #[Route('/api/reports/inventory', name: 'api_reports_inventory', methods: ['GET'])]
    public function inventoryReport(Request $request): JsonResponse
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }

        $type = $request->query->get('type');
        if (null !== $type && !in_array($type, ['RAW', 'CONSUMABLE'], true)) {
            return ErrorResponse::validationFailed([['field' => 'type', 'issue' => 'must be RAW or CONSUMABLE']]);
        }

        // Contract: date accepted only as "today" (date-snapshot reconstruction out of scope).
        $date = $request->query->get('date');
        if (null !== $date && 'today' !== $date) {
            return ErrorResponse::validationFailed([['field' => 'date', 'issue' => 'must be "today" or omitted']]);
        }

        $asOf = null;
        if ('today' === $date) {
            $asOf = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        }

        $page = $pagination['page'];
        $perPage = $pagination['perPage'];

        // Full-fidelity list: all materials, no belowThresholdOnly filtering.
        $qb = $this->materials->createQueryBuilder('m')
            ->andWhere('m.is_active = true')
            ->orderBy('m.name', 'ASC');

        if (null !== $type) {
            $qb->andWhere('m.type = :type')->setParameter('type', $type);
        }

        $qb->setFirstResult(0)->setMaxResults(null);
        $paginator = new Paginator($qb, true);
        $total = \count($paginator);

        $items = [];
        foreach ($paginator->getIterator() as $material) {
            /** @var Material $material */
            $items[] = $this->inventory->stockFor($material, $asOf);
        }

        $offset = ($page - 1) * $perPage;

        return $this->json([
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'items' => \array_slice($items, $offset, $perPage),
        ]);
    }

    // -------------------------------------------------------------------------
    // 26. GET /api/reports/movements
    // Movement aggregates (REQ-071): total_quantity and movement_count per material/type.
    // -------------------------------------------------------------------------
    #[Route('/api/reports/movements', name: 'api_reports_movements', methods: ['GET'])]
    public function movementsReport(Request $request): JsonResponse
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }

        $materialId = $request->query->get('material_id');
        if (null !== $materialId) {
            if (!\preg_match('/^\d+$/', $materialId)) {
                return ErrorResponse::validationFailed([['field' => 'material_id', 'issue' => 'must be an integer']]);
            }
            $materialId = (int) $materialId;
        }

        $movementType = $request->query->get('movement_type');
        if (null !== $movementType && !in_array($movementType, ['RECEIPT', 'CONSUMPTION', 'WASTE', 'ADJUSTMENT', 'STOCKTAKE_DELTA'], true)) {
            return ErrorResponse::validationFailed([['field' => 'movement_type', 'issue' => 'must be one of RECEIPT, CONSUMPTION, WASTE, ADJUSTMENT, STOCKTAKE_DELTA']]);
        }

        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        if (null !== $dateFrom && !\preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?$/', $dateFrom)) {
            return ErrorResponse::validationFailed([['field' => 'date_from', 'issue' => 'must be an ISO-8601 timestamp']]);
        }
        if (null !== $dateTo && !\preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?$/', $dateTo)) {
            return ErrorResponse::validationFailed([['field' => 'date_to', 'issue' => 'must be an ISO-8601 timestamp']]);
        }

        $qb = $this->movements->createQueryBuilder('m')
            ->join('m.material', 'mat')
            ->select('mat.id AS materialId', 'm.movement_type AS movementType', 'SUM(m.signed_quantity) AS totalQuantity', 'COUNT(m.id) AS movementCount')
            ->groupBy('mat.id', 'm.movement_type')
            ->orderBy('mat.id', 'ASC')
            ->addOrderBy('m.movement_type', 'ASC');

        if (null !== $materialId) {
            $qb->andWhere('m.material = :materialId')->setParameter('materialId', $materialId);
        }
        if (null !== $movementType) {
            $qb->andWhere('m.movement_type = :movementType')->setParameter('movementType', $movementType);
        }
        if (null !== $dateFrom) {
            $qb->andWhere('m.occurred_at >= :dateFrom')->setParameter('dateFrom', new \DateTimeImmutable($dateFrom, new \DateTimeZone('UTC')));
        }
        if (null !== $dateTo) {
            $qb->andWhere('m.occurred_at <= :dateTo')->setParameter('dateTo', new \DateTimeImmutable($dateTo, new \DateTimeZone('UTC')));
        }

        $page = $pagination['page'];
        $perPage = $pagination['perPage'];

        // Aggregates yield scalar rows only — Doctrine's Paginator refuses those, so
        // fetch all groups (bounded by materials × 5 types, like the inventory report)
        // and apply pagination in memory.
        $groups = $qb->getQuery()->getArrayResult();
        $total = \count($groups);
        $offset = ($page - 1) * $perPage;

        $items = [];
        foreach (\array_slice($groups, $offset, $perPage) as $row) {
            $items[] = [
                'material_id' => (int) $row['materialId'],
                'movement_type' => self::movementTypeString($row['movementType']),
                'total_quantity' => InventoryService::normalizeScale((string) $row['totalQuantity']),
                'movement_count' => (int) $row['movementCount'],
            ];
        }

        return $this->json([
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'items' => $items,
        ]);
    }

    // -------------------------------------------------------------------------
    // 27. GET /api/reports/inventory/export.csv
    // -------------------------------------------------------------------------
    #[Route('/api/reports/inventory/export.csv', name: 'api_reports_inventory_csv', methods: ['GET'])]
    public function inventoryCsv(Request $request): Response
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            $json = $pagination;
            $data = $json->getContent();
            $statusCode = $json->getStatusCode();

            return new Response($data, $statusCode, [
                'Content-Type' => 'application/json',
            ]);
        }

        $type = $request->query->get('type');
        if (null !== $type && !in_array($type, ['RAW', 'CONSUMABLE'], true)) {
            return ErrorResponse::validationFailed([['field' => 'type', 'issue' => 'must be RAW or CONSUMABLE']]);
        }

        $asOf = null;
        $date = $request->query->get('date');
        if (null !== $date && 'today' !== $date) {
            return ErrorResponse::validationFailed([['field' => 'date', 'issue' => 'must be "today" or omitted']]);
        }
        if ('today' === $date) {
            $asOf = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        }

        $qb = $this->materials->createQueryBuilder('m')
            ->andWhere('m.is_active = true')
            ->orderBy('m.name', 'ASC');

        if (null !== $type) {
            $qb->andWhere('m.type = :type')->setParameter('type', $type);
        }

        $paginator = new Paginator($qb, true);

        $rows = [['material_id', 'name', 'type', 'base_unit', 'stock_on_hand', 'below_threshold']];
        foreach ($paginator as $material) {
            /** @var Material $material */
            $stock = $this->inventory->stockFor($material, $asOf);
            $rows[] = [
                (string) $material->getId(),
                $material->getName(),
                $material->getType()->value,
                $material->getBaseUnit()->value,
                $stock['stock_on_hand'],
                $stock['below_threshold'] ? 'true' : 'false',
            ];
        }

        return CsvResponse::make(self::rowsToCsv($rows));
    }

    // -------------------------------------------------------------------------
    // 28. GET /api/reports/movements/export.csv
    // -------------------------------------------------------------------------
    #[Route('/api/reports/movements/export.csv', name: 'api_reports_movements_csv', methods: ['GET'])]
    public function movementsCsv(Request $request): Response
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            $json = $pagination;
            return new Response($json->getContent(), $json->getStatusCode(), [
                'Content-Type' => 'application/json',
            ]);
        }

        $materialId = $request->query->get('material_id');
        if (null !== $materialId) {
            if (!\preg_match('/^\d+$/', $materialId)) {
                return ErrorResponse::validationFailed([['field' => 'material_id', 'issue' => 'must be an integer']]);
            }
            $materialId = (int) $materialId;
        }

        $movementType = $request->query->get('movement_type');
        if (null !== $movementType && !in_array($movementType, ['RECEIPT', 'CONSUMPTION', 'WASTE', 'ADJUSTMENT', 'STOCKTAKE_DELTA'], true)) {
            return ErrorResponse::validationFailed([['field' => 'movement_type', 'issue' => 'must be one of RECEIPT, CONSUMPTION, WASTE, ADJUSTMENT, STOCKTAKE_DELTA']]);
        }

        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        if (null !== $dateFrom && !\preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?$/', $dateFrom)) {
            return ErrorResponse::validationFailed([['field' => 'date_from', 'issue' => 'must be an ISO-8601 timestamp']]);
        }
        if (null !== $dateTo && !\preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?$/', $dateTo)) {
            return ErrorResponse::validationFailed([['field' => 'date_to', 'issue' => 'must be an ISO-8601 timestamp']]);
        }

        $qb = $this->movements->createQueryBuilder('m')
            ->join('m.material', 'mat')
            ->select('mat.id AS materialId', 'm.movement_type AS movementType', 'SUM(m.signed_quantity) AS totalQuantity', 'COUNT(m.id) AS movementCount')
            ->groupBy('mat.id', 'm.movement_type')
            ->orderBy('mat.id', 'ASC')
            ->addOrderBy('m.movement_type', 'ASC');

        if (null !== $materialId) {
            $qb->andWhere('m.material = :materialId')->setParameter('materialId', $materialId);
        }
        if (null !== $movementType) {
            $qb->andWhere('m.movement_type = :movementType')->setParameter('movementType', $movementType);
        }
        if (null !== $dateFrom) {
            $qb->andWhere('m.occurred_at >= :dateFrom')->setParameter('dateFrom', new \DateTimeImmutable($dateFrom, new \DateTimeZone('UTC')));
        }
        if (null !== $dateTo) {
            $qb->andWhere('m.occurred_at <= :dateTo')->setParameter('dateTo', new \DateTimeImmutable($dateTo, new \DateTimeZone('UTC')));
        }

        $groups = $qb->getQuery()->getArrayResult();

        $rows = [['material_id', 'movement_type', 'total_quantity', 'movement_count']];
        foreach ($groups as $row) {
            $rows[] = [
                (string) $row['materialId'],
                self::movementTypeString($row['movementType']),
                InventoryService::normalizeScale((string) $row['totalQuantity']),
                (string) $row['movementCount'],
            ];
        }

        return CsvResponse::make(self::rowsToCsv($rows));
    }

    // -------------------------------------------------------------------------
    // 29. GET /api/audit-logs
    // Admin-only in the contract (Phase 9 enforcement deferred).
    // -------------------------------------------------------------------------
    #[Route('/api/audit-logs', name: 'api_audit_logs', methods: ['GET'])]
    public function auditLogs(Request $request): JsonResponse
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }

        $actorUserId = $request->query->get('actor_user_id');
        if (null !== $actorUserId && !\preg_match('/^\d+$/', $actorUserId)) {
            return ErrorResponse::validationFailed([['field' => 'actor_user_id', 'issue' => 'must be an integer']]);
        }
        if (null !== $actorUserId) {
            $actorUserId = (int) $actorUserId;
        }

        $action = $request->query->get('action');
        if (null !== $action && !self::isValidAuditAction($action)) {
            return ErrorResponse::validationFailed([['field' => 'action', 'issue' => 'must be a valid audit action']]);
        }

        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        if (null !== $dateFrom && !\preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?$/', $dateFrom)) {
            return ErrorResponse::validationFailed([['field' => 'date_from', 'issue' => 'must be an ISO-8601 timestamp']]);
        }
        if (null !== $dateTo && !\preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?$/', $dateTo)) {
            return ErrorResponse::validationFailed([['field' => 'date_to', 'issue' => 'must be an ISO-8601 timestamp']]);
        }

        $qb = $this->auditLogs->createQueryBuilder('a')
            ->orderBy('a.occurred_at', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if (null !== $actorUserId) {
            $qb->andWhere('a.actor_user_id = :actorUserId')->setParameter('actorUserId', $actorUserId);
        }
        if (null !== $action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if (null !== $dateFrom) {
            $qb->andWhere('a.occurred_at >= :dateFrom')->setParameter('dateFrom', new \DateTimeImmutable($dateFrom, new \DateTimeZone('UTC')));
        }
        if (null !== $dateTo) {
            $qb->andWhere('a.occurred_at <= :dateTo')->setParameter('dateTo', new \DateTimeImmutable($dateTo, new \DateTimeZone('UTC')));
        }

        $page = $pagination['page'];
        $perPage = $pagination['perPage'];
        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        $paginator = new Paginator($qb, true);
        $total = \count($paginator);

        $items = [];
        foreach ($paginator as $log) {
            /** @var AuditLog $log */
            $items[] = [
                'id' => $log->getId(),
                'actor_user_id' => $log->getActorUser()->getId(),
                'action' => $log->getAction()->value,
                'target_entity' => $log->getTargetEntity(),
                'target_entity_id' => $log->getTargetEntityId(),
                'occurred_at' => QueryParamsHelper::atom($log->getOccurredAt()),
                'outcome' => $log->getOutcome()->value,
                'detail' => $log->getDetail(),
            ];
        }

        return $this->json([
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'items' => $items,
        ]);
    }

    /**
     * @param list<array<int|string, int|string|bool|null>> $rows
     */
    private static function rowsToCsv(array $rows): string
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = \implode(',', \array_map([self::class, 'csvEscape'], $row));
        }

        return \implode("\n", $out) . "\n";
    }

    private static function movementTypeString(mixed $value): string
    {
        return $value instanceof \BackedEnum ? $value->value : (string) $value;
    }

    private static function csvEscape(string $value): string
    {
        if (false !== \strpos($value, ',') || false !== \strpos($value, '"') || false !== \strpos($value, "\n") || false !== \strpos($value, "\r")) {
            return '"' . \str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /** @return list<string> */
    private static function validAuditActions(): array
    {
        return [
            AuditAction::RECEIPT_CREATE->value,
            AuditAction::CONSUMPTION_CREATE->value,
            AuditAction::WASTE_CREATE->value,
            AuditAction::ADJUSTMENT_CREATE->value,
            AuditAction::STOCKTAKE_CREATE->value,
            AuditAction::STOCKTAKE_COMMIT->value,
            AuditAction::MATERIAL_CREATE->value,
            AuditAction::MATERIAL_UPDATE->value,
            AuditAction::SUPPLIER_CREATE->value,
            AuditAction::SUPPLIER_UPDATE->value,
            AuditAction::SUPPLIER_DEACTIVATE->value,
        ];
    }

    private static function isValidAuditAction(string $action): bool
    {
        return \in_array($action, self::validAuditActions(), true);
    }
}
