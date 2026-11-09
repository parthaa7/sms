<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/inventory.php';

final class BillingService
{
    private const PRODUCT_COLS = 'product_id, name, barcode, selling_price, purchase_price, quantity, reorder_level, status_enum';

    public static function findByBarcode(string $barcode): array
    {
        $stmt = db()->prepare('SELECT ' . self::PRODUCT_COLS . " FROM products WHERE barcode = ? AND status_enum = 'active'");
        $stmt->execute([$barcode]);
        return $stmt->fetch() ?: throw new ApiException('No active product with this barcode.', 404);
    }

    public static function searchProducts(string $q): array
    {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $stmt = db()->prepare('SELECT ' . self::PRODUCT_COLS . " FROM products
                               WHERE status_enum = 'active' AND (name LIKE ? OR barcode = ?)
                               ORDER BY name LIMIT 20");
        $stmt->execute([$like, $q]);
        return $stmt->fetchAll();
    }

    public static function calculateCart(array $items, bool $forUpdate = false): array
    {
        if (!$items) throw new ApiException('Cart is empty.', 422);
        if (count($items) > 200) throw new ApiException('Too many cart lines.', 422);

        $qtyById = [];
        foreach (array_values($items) as $i => $item) {
            $pid = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $qty = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
            if ($pid === false || $qty === false) throw new ApiException('Invalid cart line #' . ($i + 1) . '.', 422);
            $qtyById[$pid] = ($qtyById[$pid] ?? 0) + $qty;
        }
        ksort($qtyById);

        $ids = array_keys($qtyById);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare('SELECT ' . self::PRODUCT_COLS . " FROM products WHERE product_id IN ({$ph}) ORDER BY product_id"
                              . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute($ids);
        $found = array_column($stmt->fetchAll(), null, 'product_id');

        $lines = [];
        $errors = [];
        $subtotal = 0.0;
        foreach ($qtyById as $pid => $qty) {
            $p = $found[$pid] ?? null;
            if (!$p || $p['status_enum'] !== 'active') { $errors[] = "Product #{$pid} is not available for sale."; continue; }
            if ((int) $p['quantity'] < $qty) {
                $errors[] = "Insufficient stock for {$p['name']}: requested {$qty}, available {$p['quantity']}.";
                continue;
            }
            $unit = (float) $p['selling_price'];
            $lineTotal = round($unit * $qty, 2);
            $subtotal += $lineTotal;
            $lines[] = [
                'product_id' => $pid, 'name' => $p['name'], 'barcode' => $p['barcode'],
                'quantity' => $qty, 'unit_price' => $unit, 'unit_cost' => (float) $p['purchase_price'],
                'line_total' => $lineTotal, 'stock_available' => (int) $p['quantity'],
            ];
        }
        if ($errors) throw new ApiException('Some items cannot be billed.', 409, $errors);

        $subtotal = round($subtotal, 2);
        $tax = round($subtotal * GST_RATE, 2);
        return [
            'lines'      => $lines,
            'item_count' => array_sum(array_column($lines, 'quantity')),
            'subtotal'   => $subtotal,
            'gst_rate'   => GST_RATE,
            'tax_amount' => $tax,
            'total'      => round($subtotal + $tax, 2),
        ];
    }

    public static function checkout(array $input, int $cashierId): array
    {
        $data = validate($input, [
            'customer_id'     => 'nullable|int|min:1',
            'payment_method'  => 'required|in:Cash,Card,UPI',
            'amount_tendered' => 'nullable|decimal|min:0|max:99999999',
            'reference_no'    => 'nullable|string|max:100',
        ]);
        $items = $input['items'] ?? null;
        if (!is_array($items)) throw new ApiException('items must be an array of {product_id, quantity}.', 422);

        $result = transaction(static function (PDO $pdo) use ($data, $items, $cashierId) {
            $cart = self::calculateCart($items, forUpdate: true);
            $method = $data['payment_method'];

            $tendered = $method === 'Cash' ? ($data['amount_tendered'] ?? null) : $cart['total'];
            if ($tendered === null || round($tendered, 2) < $cart['total']) {
                throw new ApiException('Amount tendered is less than the bill total (' . number_format($cart['total'], 2) . ').', 422);
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO sales (user_id, customer_id, transaction_date, subtotal, tax_amount, total_amount, payment_method)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cashierId, $data['customer_id'] ?? null, $now, $cart['subtotal'], $cart['tax_amount'], $cart['total'], $method]);
            $saleId = (int) $pdo->lastInsertId();

            $invoiceNo = sprintf('INV-%s-%06d', date('Ymd'), $saleId);
            $pdo->prepare('UPDATE sales SET invoice_no = ? WHERE sale_id = ?')->execute([$invoiceNo, $saleId]);

            $itemStmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, unit_cost, line_total)
                                       VALUES (?, ?, ?, ?, ?, ?)');
            $alerts = [];
            foreach ($cart['lines'] as $l) {
                $itemStmt->execute([$saleId, $l['product_id'], $l['quantity'], $l['unit_price'], $l['unit_cost'], $l['line_total']]);
                $stock = InventoryManager::changeStock($pdo, $l['product_id'], -$l['quantity'], 'sale', $cashierId, "Invoice {$invoiceNo}", $saleId);
                if ($stock['is_low_stock']) $alerts[] = $stock;
            }

            $pdo->prepare('INSERT INTO payments (sale_id, amount_paid, payment_type, reference_no, payment_date) VALUES (?, ?, ?, ?, ?)')
                ->execute([$saleId, $cart['total'], $method, $data['reference_no'] ?? null, $now]);

            return [
                'sale_id' => $saleId, 'invoice_no' => $invoiceNo,
                'amount_tendered' => round($tendered, 2),
                'change_due' => round($tendered - $cart['total'], 2),
                'low_stock_alerts' => $alerts,
            ];
        });

        $result['invoice'] = self::getInvoice($result['sale_id']);
        return $result;
    }

    public static function getInvoice(int $saleId): array
    {
        $stmt = db()->prepare("SELECT s.*, u.username AS cashier, u.full_name AS cashier_name,
                                      COALESCE(c.name, 'Walk-in Customer') AS customer_name, c.phone AS customer_phone
                               FROM sales s
                               JOIN users u ON u.user_id = s.user_id
                               LEFT JOIN customers c ON c.customer_id = s.customer_id
                               WHERE s.sale_id = ?");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch() ?: throw new ApiException("Sale #{$saleId} not found.", 404);

        $items = db()->prepare('SELECT si.product_id, p.name, p.barcode, si.quantity, si.unit_price, si.line_total
                                FROM sale_items si JOIN products p ON p.product_id = si.product_id
                                WHERE si.sale_id = ? ORDER BY si.sale_item_id');
        $items->execute([$saleId]);

        $payments = db()->prepare('SELECT payment_id, amount_paid, payment_type, reference_no, payment_date FROM payments WHERE sale_id = ?');
        $payments->execute([$saleId]);

        return [
            'store'    => ['name' => STORE_NAME, 'address' => STORE_ADDRESS, 'gstin' => STORE_GSTIN, 'gst_rate' => GST_RATE],
            'sale'     => $sale,
            'items'    => $items->fetchAll(),
            'payments' => $payments->fetchAll(),
        ];
    }
}

if (is_direct_request(__FILE__)) {
    run_api(static function () {
        $user = Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_CASHIER);

        switch ($_GET['action'] ?? '') {
            case 'scan':
                require_method('GET');
                $in = validate($_GET, ['barcode' => 'required|barcode']);
                return BillingService::findByBarcode($in['barcode']);

            case 'search':
                require_method('GET');
                $in = validate($_GET, ['q' => 'required|string|min:1|max:100']);
                return BillingService::searchProducts($in['q']);

            case 'calculate':
                require_method('POST');
                return BillingService::calculateCart((array) (get_input()['items'] ?? []));

            case 'checkout':
                require_method('POST');
                return BillingService::checkout(get_input(), $user['user_id']);

            case 'invoice':
                require_method('GET');
                return BillingService::getInvoice((int) ($_GET['id'] ?? 0));

            default:
                throw new ApiException('Unknown action.', 404);
        }
    });
}
