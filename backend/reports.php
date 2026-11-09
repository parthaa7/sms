<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/inventory.php';

final class ReportService
{
    private static function range(array $q): array
    {
        $from = (string) ($q['from'] ?? date('Y-m-d', strtotime('-29 days')));
        $to   = (string) ($q['to'] ?? date('Y-m-d'));
        if (!valid_date($from) || !valid_date($to)) throw new ApiException('Dates must be in YYYY-MM-DD format.', 422);
        if ($from > $to) throw new ApiException("'from' must be on or before 'to'.", 422);
        return [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00', $from, $to];
    }

    private static function fetch(string $sql, array $params): array
    {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function sales(string $period, array $q): array
    {
        [$start, $end, $from, $to] = self::range($q);
        $bucket = match ($period) {
            'daily'   => 'DATE(transaction_date)',
            'weekly'  => 'YEARWEEK(transaction_date, 3)',
            'monthly' => "DATE_FORMAT(transaction_date, '%Y-%m')",
            default   => throw new ApiException('period must be daily, weekly or monthly.', 422),
        };

        $rows = self::fetch(
            "SELECT {$bucket} AS bucket, MIN(DATE(transaction_date)) AS period_start, MAX(DATE(transaction_date)) AS period_end,
                    COUNT(*) AS transactions, SUM(subtotal) AS subtotal, SUM(tax_amount) AS tax,
                    SUM(total_amount) AS revenue, ROUND(AVG(total_amount), 2) AS avg_bill
             FROM sales WHERE transaction_date >= ? AND transaction_date < ?
             GROUP BY bucket ORDER BY bucket",
            [$start, $end]
        );
        $totals = self::fetch(
            'SELECT COUNT(*) AS transactions, COALESCE(SUM(subtotal),0) AS subtotal, COALESCE(SUM(tax_amount),0) AS tax,
                    COALESCE(SUM(total_amount),0) AS revenue
             FROM sales WHERE transaction_date >= ? AND transaction_date < ?',
            [$start, $end]
        )[0];

        return ['period' => $period, 'from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals];
    }

    public static function topProducts(array $q): array
    {
        [$start, $end] = self::range($q);
        $limit = min(100, max(1, (int) ($q['limit'] ?? 10)));
        return self::fetch(
            "SELECT p.product_id, p.name, c.name AS category,
                    SUM(si.quantity) AS units_sold, SUM(si.line_total) AS revenue,
                    SUM(si.quantity * si.unit_cost) AS cost,
                    SUM(si.line_total - si.quantity * si.unit_cost) AS gross_profit
             FROM sale_items si
             JOIN sales s ON s.sale_id = si.sale_id
             JOIN products p ON p.product_id = si.product_id
             JOIN categories c ON c.category_id = p.category_id
             WHERE s.transaction_date >= ? AND s.transaction_date < ?
             GROUP BY p.product_id, p.name, c.name
             ORDER BY revenue DESC LIMIT {$limit}",
            [$start, $end]
        );
    }

    public static function payments(array $q): array
    {
        [$start, $end] = self::range($q);
        return self::fetch(
            'SELECT payment_type, COUNT(*) AS transactions, SUM(amount_paid) AS amount
             FROM payments WHERE payment_date >= ? AND payment_date < ?
             GROUP BY payment_type ORDER BY amount DESC',
            [$start, $end]
        );
    }

    public static function cashiers(array $q): array
    {
        [$start, $end] = self::range($q);
        return self::fetch(
            'SELECT u.user_id, u.username, u.full_name, COUNT(s.sale_id) AS transactions,
                    COALESCE(SUM(s.total_amount), 0) AS revenue, ROUND(COALESCE(AVG(s.total_amount), 0), 2) AS avg_bill
             FROM users u
             LEFT JOIN sales s ON s.user_id = u.user_id AND s.transaction_date >= ? AND s.transaction_date < ?
             GROUP BY u.user_id, u.username, u.full_name
             ORDER BY revenue DESC',
            [$start, $end]
        );
    }

    public static function inventory(array $q): array
    {
        [$start, $end, $from, $to] = self::range($q);
        $byCategory = db()->query(
            "SELECT c.category_id, c.name AS category, COUNT(p.product_id) AS products,
                    COALESCE(SUM(p.quantity), 0) AS units_in_stock,
                    COALESCE(SUM(p.quantity * p.purchase_price), 0) AS stock_value_cost,
                    COALESCE(SUM(p.quantity * p.selling_price), 0) AS stock_value_retail,
                    COALESCE(SUM(p.quantity <= p.reorder_level), 0) AS low_stock_products
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.category_id AND p.status_enum = 'active'
             GROUP BY c.category_id, c.name ORDER BY c.name"
        )->fetchAll();

        $movement = self::fetch(
            'SELECT change_type, COUNT(*) AS entries, SUM(quantity_change) AS net_units
             FROM inventory_log WHERE log_date >= ? AND log_date < ?
             GROUP BY change_type',
            [$start, $end]
        );

        return [
            'from' => $from, 'to' => $to,
            'by_category' => $byCategory,
            'totals' => [
                'units_in_stock'     => array_sum(array_column($byCategory, 'units_in_stock')),
                'stock_value_cost'   => round(array_sum(array_column($byCategory, 'stock_value_cost')), 2),
                'stock_value_retail' => round(array_sum(array_column($byCategory, 'stock_value_retail')), 2),
            ],
            'stock_movement' => $movement,
            'low_stock'      => InventoryManager::lowStock(),
        ];
    }
}

if (is_direct_request(__FILE__)) {
    run_api(static function () {
        require_method('GET');
        Auth::requireRole(Auth::ROLE_ADMIN);
        $action = $_GET['action'] ?? '';
        $csv = ($_GET['format'] ?? '') === 'csv';

        $data = match ($action) {
            'sales'        => ReportService::sales((string) ($_GET['period'] ?? 'daily'), $_GET),
            'top_products' => ReportService::topProducts($_GET),
            'payments'     => ReportService::payments($_GET),
            'cashiers'     => ReportService::cashiers($_GET),
            'inventory'    => ReportService::inventory($_GET),
            default        => throw new ApiException('Unknown report.', 404),
        };

        if ($csv) {
            $rows = match ($action) { 'sales' => $data['rows'], 'inventory' => $data['by_category'], default => $data };
            output_csv("sms_{$action}_" . date('Ymd') . '.csv', $rows);
        }
        return $data;
    });
}
