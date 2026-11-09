<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

final class InventoryManager
{
    public const CHANGE_TYPES = ['initial', 'sale', 'restock', 'adjustment', 'return'];

    public static function changeStock(PDO $pdo, int $productId, int $delta, string $type,
                                       ?int $userId, ?string $remarks = null, ?int $saleId = null): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('changeStock() must run inside a transaction.');
        if (!in_array($type, self::CHANGE_TYPES, true)) throw new InvalidArgumentException("Invalid change type '{$type}'.");
        if ($delta === 0) throw new ApiException('Quantity change cannot be zero.', 422);

        $stmt = $pdo->prepare('SELECT product_id, name, quantity, reorder_level FROM products WHERE product_id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $p = $stmt->fetch();
        if (!$p) throw new ApiException("Product #{$productId} not found.", 404);

        $old = (int) $p['quantity'];
        $new = $old + $delta;
        if ($new < 0) {
            throw new ApiException("Insufficient stock for '{$p['name']}' (available: {$old}, requested: " . abs($delta) . ').', 409);
        }

        $pdo->prepare('UPDATE products SET quantity = ? WHERE product_id = ?')->execute([$new, $productId]);
        self::writeLog($pdo, $productId, $type, $delta, $new, $userId, $remarks, $saleId);

        return [
            'product_id'        => $productId,
            'name'              => $p['name'],
            'previous_quantity' => $old,
            'current_quantity'  => $new,
            'reorder_level'     => (int) $p['reorder_level'],
            'is_low_stock'      => $new <= (int) $p['reorder_level'],
        ];
    }

    public static function writeLog(PDO $pdo, int $productId, string $type, int $delta, int $currentQty,
                                    ?int $userId, ?string $remarks = null, ?int $saleId = null): void
    {
        $pdo->prepare('INSERT INTO inventory_log
                         (product_id, user_id, sale_id, change_type, quantity_change, current_quantity, remarks, log_date)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$productId, $userId, $saleId, $type, $delta, $currentQty, $remarks, date('Y-m-d H:i:s')]);
    }

    public static function restock(int $productId, int $qty, int $userId, ?string $remarks = null, ?float $newPurchasePrice = null): array
    {
        return transaction(static function (PDO $pdo) use ($productId, $qty, $userId, $remarks, $newPurchasePrice) {
            $result = self::changeStock($pdo, $productId, $qty, 'restock', $userId, $remarks ?? 'Stock received');
            if ($newPurchasePrice !== null) {
                $pdo->prepare('UPDATE products SET purchase_price = ? WHERE product_id = ?')->execute([$newPurchasePrice, $productId]);
            }
            return $result;
        });
    }

    public static function adjust(int $productId, int $countedQty, int $userId, string $reason): array
    {
        return transaction(static function (PDO $pdo) use ($productId, $countedQty, $userId, $reason) {
            $stmt = $pdo->prepare('SELECT quantity FROM products WHERE product_id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $current = $stmt->fetchColumn();
            if ($current === false) throw new ApiException("Product #{$productId} not found.", 404);

            $delta = $countedQty - (int) $current;
            if ($delta === 0) return ['product_id' => $productId, 'current_quantity' => $countedQty, 'message' => 'No change needed.'];
            return self::changeStock($pdo, $productId, $delta, 'adjustment', $userId, $reason);
        });
    }

    public static function lowStock(): array
    {
        return db()->query(
            "SELECT p.product_id, p.name, p.barcode, p.quantity, p.reorder_level,
                    (CAST(p.reorder_level AS SIGNED) * 2 - p.quantity) AS suggested_reorder_qty,
                    c.name AS category, s.name AS supplier, s.contact_info AS supplier_contact,
                    CASE WHEN p.quantity = 0 THEN 'OUT_OF_STOCK' ELSE 'LOW' END AS alert_level
             FROM products p
             JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
             WHERE p.status_enum = 'active' AND p.quantity <= p.reorder_level
             ORDER BY p.quantity ASC, p.name"
        )->fetchAll();
    }

    public static function log(array $q): array
    {
        $where = [];
        $params = [];
        if (!empty($q['product_id'])) { $where[] = 'l.product_id = ?'; $params[] = (int) $q['product_id']; }
        if (!empty($q['change_type']) && in_array($q['change_type'], self::CHANGE_TYPES, true)) {
            $where[] = 'l.change_type = ?'; $params[] = $q['change_type'];
        }
        if (!empty($q['from']) && valid_date($q['from'])) { $where[] = 'l.log_date >= ?'; $params[] = $q['from'] . ' 00:00:00'; }
        if (!empty($q['to']) && valid_date($q['to']))     { $where[] = 'l.log_date < ?';  $params[] = date('Y-m-d', strtotime($q['to'] . ' +1 day')) . ' 00:00:00'; }
        $limit = min(500, max(1, (int) ($q['limit'] ?? 200)));

        $sql = 'SELECT l.log_id, l.log_date, l.change_type, l.quantity_change, l.current_quantity, l.remarks,
                       l.sale_id, p.product_id, p.name AS product_name, u.username AS changed_by
                FROM inventory_log l
                JOIN products p ON p.product_id = l.product_id
                LEFT JOIN users u ON u.user_id = l.user_id '
             . ($where ? 'WHERE ' . implode(' AND ', $where) : '')
             . " ORDER BY l.log_id DESC LIMIT {$limit}";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

if (is_direct_request(__FILE__)) {
    run_api(static function () {
        switch ($_GET['action'] ?? '') {
            case 'low_stock':
                require_method('GET');
                Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_CASHIER);
                $items = InventoryManager::lowStock();
                return ['count' => count($items), 'items' => $items];

            case 'log':
                require_method('GET');
                Auth::requireRole(Auth::ROLE_ADMIN);
                return InventoryManager::log($_GET);

            case 'restock':
                require_method('POST');
                $user = Auth::requireRole(Auth::ROLE_ADMIN);
                $in = validate(get_input(), [
                    'product_id'     => 'required|int|min:1',
                    'quantity'       => 'required|int|min:1|max:100000',
                    'purchase_price' => 'nullable|decimal|min:0',
                    'remarks'        => 'nullable|string|max:255',
                ]);
                return InventoryManager::restock($in['product_id'], $in['quantity'], $user['user_id'],
                                                 $in['remarks'] ?? null, $in['purchase_price'] ?? null);

            case 'adjust':
                require_method('POST');
                $user = Auth::requireRole(Auth::ROLE_ADMIN);
                $in = validate(get_input(), [
                    'product_id'      => 'required|int|min:1',
                    'counted_quantity'=> 'required|int|min:0|max:1000000',
                    'reason'          => 'required|string|min:3|max:255',
                ]);
                return InventoryManager::adjust($in['product_id'], $in['counted_quantity'], $user['user_id'], $in['reason']);

            default:
                throw new ApiException('Unknown action.', 404);
        }
    });
}
