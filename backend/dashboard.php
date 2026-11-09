<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

final class DashboardService
{
    public static function summary(array $user): array
    {
        $pdo = db();
        $isAdmin = $user['role'] === Auth::ROLE_ADMIN;
        $scopeUser = $isAdmin ? null : $user['user_id'];

        $todayStart = date('Y-m-d 00:00:00');
        $tomorrow   = date('Y-m-d 00:00:00', strtotime('tomorrow'));
        $monthStart = date('Y-m-01 00:00:00');
        $nextMonth  = date('Y-m-01 00:00:00', strtotime('first day of next month'));

        $counts = $pdo->query(
            "SELECT
               (SELECT COUNT(*) FROM products WHERE status_enum = 'active') AS total_products,
               (SELECT COUNT(*) FROM categories) AS total_categories,
               (SELECT COUNT(*) FROM suppliers)  AS total_suppliers,
               (SELECT COUNT(*) FROM products WHERE status_enum = 'active' AND quantity <= reorder_level) AS low_stock_items,
               (SELECT COUNT(*) FROM products WHERE status_enum = 'active' AND quantity = 0) AS out_of_stock_items"
        )->fetch();

        return [
            'scope'               => $isAdmin ? 'store' : 'my_sales',
            'counts'              => array_map('intval', $counts),
            'today_sales'         => self::salesBetween($todayStart, $tomorrow, $scopeUser),
            'monthly_sales'       => self::salesBetween($monthStart, $nextMonth, $scopeUser),
            'last_7_days'         => self::dailyTrend(7, $scopeUser),
            'recent_transactions' => self::recent(10, $scopeUser),
        ];
    }

    private static function salesBetween(string $start, string $end, ?int $userId): array
    {
        $sql = 'SELECT COUNT(*) AS transactions, COALESCE(SUM(total_amount), 0) AS revenue, COALESCE(SUM(tax_amount), 0) AS tax
                FROM sales WHERE transaction_date >= ? AND transaction_date < ?';
        $params = [$start, $end];
        if ($userId) { $sql .= ' AND user_id = ?'; $params[] = $userId; }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch();
        return ['transactions' => (int) $r['transactions'], 'revenue' => (float) $r['revenue'], 'tax' => (float) $r['tax']];
    }

    private static function dailyTrend(int $days, ?int $userId): array
    {
        $sql = 'SELECT DATE(transaction_date) AS day, COUNT(*) AS transactions, SUM(total_amount) AS revenue
                FROM sales WHERE transaction_date >= ?' . ($userId ? ' AND user_id = ?' : '') . '
                GROUP BY DATE(transaction_date) ORDER BY day';
        $params = [date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'))];
        if ($userId) $params[] = $userId;
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_column($stmt->fetchAll(), null, 'day');

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = ['day' => $d, 'transactions' => (int) ($rows[$d]['transactions'] ?? 0), 'revenue' => (float) ($rows[$d]['revenue'] ?? 0)];
        }
        return $trend;
    }

    private static function recent(int $limit, ?int $userId): array
    {
        $sql = "SELECT s.sale_id, s.invoice_no, s.transaction_date, s.total_amount, s.payment_method,
                       u.username AS cashier, COALESCE(c.name, 'Walk-in') AS customer
                FROM sales s
                JOIN users u ON u.user_id = s.user_id
                LEFT JOIN customers c ON c.customer_id = s.customer_id"
             . ($userId ? ' WHERE s.user_id = ?' : '')
             . " ORDER BY s.sale_id DESC LIMIT {$limit}";
        $stmt = db()->prepare($sql);
        $stmt->execute($userId ? [$userId] : []);
        return $stmt->fetchAll();
    }
}

if (is_direct_request(__FILE__)) {
    run_api(static function () {
        require_method('GET');
        return DashboardService::summary(Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_CASHIER));
    });
}
