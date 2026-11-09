<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/inventory.php';

final class CrudService
{
    public const ENTITIES = [
        'categories' => [
            'table' => 'categories', 'pk' => 'category_id',
            'select' => 't.*', 'from' => 'categories t', 'search' => ['t.name'],
            'rules' => ['name' => 'required|string|max:100', 'description' => 'nullable|string|max:255'],
            'read' => [Auth::ROLE_ADMIN, Auth::ROLE_CASHIER], 'write' => [Auth::ROLE_ADMIN],
        ],
        'suppliers' => [
            'table' => 'suppliers', 'pk' => 'supplier_id',
            'select' => 't.*', 'from' => 'suppliers t', 'search' => ['t.name', 't.contact_info'],
            'rules' => ['name' => 'required|string|max:150', 'contact_info' => 'nullable|string|max:255'],
            'read' => [Auth::ROLE_ADMIN], 'write' => [Auth::ROLE_ADMIN],
        ],
        'customers' => [
            'table' => 'customers', 'pk' => 'customer_id',
            'select' => 't.*', 'from' => 'customers t', 'search' => ['t.name', 't.phone', 't.email'],
            'rules' => ['name' => 'required|string|max:100', 'phone' => 'nullable|phone', 'email' => 'nullable|email|max:150'],
            'read' => [Auth::ROLE_ADMIN, Auth::ROLE_CASHIER], 'write' => [Auth::ROLE_ADMIN, Auth::ROLE_CASHIER],
        ],
        'products' => [
            'table' => 'products', 'pk' => 'product_id',
            'select' => 't.*, c.name AS category_name, s.name AS supplier_name, (t.quantity <= t.reorder_level) AS is_low_stock',
            'from' => 'products t JOIN categories c ON c.category_id = t.category_id
                       LEFT JOIN suppliers s ON s.supplier_id = t.supplier_id',
            'search' => ['t.name', 't.barcode', 'c.name'],
            'rules' => [
                'name'           => 'required|string|max:150',
                'category_id'    => 'required|int|min:1',
                'supplier_id'    => 'nullable|int|min:1',
                'purchase_price' => 'required|decimal|min:0|max:9999999',
                'selling_price'  => 'required|decimal|min:0|max:9999999',
                'reorder_level'  => 'int|min:0|max:100000',
                'barcode'        => 'nullable|barcode',
                'status_enum'    => 'in:active,inactive',
            ],
            'read' => [Auth::ROLE_ADMIN, Auth::ROLE_CASHIER], 'write' => [Auth::ROLE_ADMIN],
        ],
        'users' => [
            'table' => 'users', 'pk' => 'user_id',
            'select' => 't.user_id, t.username, t.full_name, t.role_enum, t.is_active, t.last_login, t.created_at',
            'from' => 'users t', 'search' => ['t.username', 't.full_name'],
            'rules' => [
                'username'  => 'required|username',
                'full_name' => 'nullable|string|max:100',
                'role_enum' => 'required|in:Administrator,Cashier',
                'is_active' => 'bool',
                'password'  => 'required|string|min:8|max:72',
            ],
            'read' => [Auth::ROLE_ADMIN], 'write' => [Auth::ROLE_ADMIN],
        ],
    ];

    public static function config(string $entity): array
    {
        return self::ENTITIES[$entity] ?? throw new ApiException("Unknown entity '{$entity}'.", 404);
    }

    public static function list(string $entity, array $q): array
    {
        $cfg = self::config($entity);
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($q['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];
        $search = clean_string((string) ($q['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(' . implode(' OR ', array_map(static fn($c) => "{$c} LIKE ?", $cfg['search'])) . ')';
            array_push($params, ...array_fill(0, count($cfg['search']), $like));
        }
        if ($entity === 'products') {
            if (!empty($q['category_id'])) { $where[] = 't.category_id = ?'; $params[] = (int) $q['category_id']; }
            if (!empty($q['supplier_id'])) { $where[] = 't.supplier_id = ?'; $params[] = (int) $q['supplier_id']; }
            if (in_array($q['status'] ?? '', ['active', 'inactive'], true)) { $where[] = 't.status_enum = ?'; $params[] = $q['status']; }
            if (!empty($q['low_stock'])) $where[] = 't.quantity <= t.reorder_level';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = db()->prepare("SELECT COUNT(*) FROM {$cfg['from']} {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = db()->prepare("SELECT {$cfg['select']} FROM {$cfg['from']} {$whereSql}
                               ORDER BY t.{$cfg['pk']} DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                             'total_pages' => (int) ceil($total / $perPage)],
        ];
    }

    public static function get(string $entity, int $id): array
    {
        $cfg = self::config($entity);
        $stmt = db()->prepare("SELECT {$cfg['select']} FROM {$cfg['from']} WHERE t.{$cfg['pk']} = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new ApiException(ucfirst(rtrim($entity, 's')) . " #{$id} not found.", 404);
    }

    public static function create(string $entity, array $input, int $actorId): array
    {
        $cfg = self::config($entity);
        $data = validate($input, $cfg['rules']);
        $initialQty = 0;

        if ($entity === 'users') {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        if ($entity === 'products') {
            $initialQty = (int) (validate($input, ['quantity' => 'int|min:0|max:1000000'])['quantity'] ?? 0);
            $data['quantity'] = $initialQty;
        }

        $id = transaction(static function (PDO $pdo) use ($cfg, $data, $entity, $initialQty, $actorId) {
            $cols = array_keys($data);
            $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $cfg['table'],
                implode(', ', array_map(static fn($c) => "`{$c}`", $cols)),
                implode(', ', array_fill(0, count($cols), '?')));
            $pdo->prepare($sql)->execute(array_values($data));
            $newId = (int) $pdo->lastInsertId();

            if ($entity === 'products' && $initialQty > 0) {
                InventoryManager::writeLog($pdo, $newId, 'initial', $initialQty, $initialQty, $actorId, 'Opening stock');
            }
            return $newId;
        });

        return self::get($entity, $id);
    }

    public static function update(string $entity, int $id, array $input, int $actorId): array
    {
        $cfg = self::config($entity);
        self::get($entity, $id);

        if ($entity === 'products' && array_key_exists('quantity', $input)) {
            throw new ApiException('Stock cannot be edited here. Use Inventory > Restock or Adjust so the change is audited.', 422);
        }

        $data = validate($input, $cfg['rules'], partial: true);

        if ($entity === 'users') {
            if ($id === $actorId && isset($data['role_enum']) && $data['role_enum'] !== Auth::ROLE_ADMIN) {
                throw new ApiException('You cannot remove your own Administrator role.', 422);
            }
            if ($id === $actorId && isset($data['is_active']) && $data['is_active'] === 0) {
                throw new ApiException('You cannot deactivate your own account.', 422);
            }
            if (isset($data['password'])) {
                $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
                unset($data['password']);
            }
        }
        if (!$data) throw new ApiException('No valid fields supplied for update.', 422);

        $set = implode(', ', array_map(static fn($c) => "`{$c}` = ?", array_keys($data)));
        db()->prepare("UPDATE {$cfg['table']} SET {$set} WHERE {$cfg['pk']} = ?")
            ->execute([...array_values($data), $id]);

        return self::get($entity, $id);
    }

    public static function delete(string $entity, int $id, int $actorId): array
    {
        $cfg = self::config($entity);
        if ($entity === 'users' && $id === $actorId) {
            throw new ApiException('You cannot delete your own account.', 422);
        }
        $stmt = db()->prepare("DELETE FROM {$cfg['table']} WHERE {$cfg['pk']} = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) throw new ApiException('Record not found.', 404);
        return ['deleted' => true, 'id' => $id];
    }
}

if (is_direct_request(__FILE__)) {
    run_api(static function () {
        $entity = (string) ($_GET['entity'] ?? '');
        $action = (string) ($_GET['action'] ?? 'list');
        $id = (int) ($_GET['id'] ?? 0);
        $cfg = CrudService::config($entity);

        $isRead = in_array($action, ['list', 'get'], true);
        $user = Auth::requireRole(...($isRead ? $cfg['read'] : $cfg['write']));
        if (!$isRead && $action !== 'create' && $id <= 0) throw new ApiException('Missing or invalid id.', 422);

        switch ($action) {
            case 'list':   require_method('GET');  return CrudService::list($entity, $_GET);
            case 'get':    require_method('GET');  return CrudService::get($entity, $id);
            case 'create': require_method('POST'); return CrudService::create($entity, get_input(), $user['user_id']);
            case 'update': require_method('POST', 'PUT');    return CrudService::update($entity, $id, get_input(), $user['user_id']);
            case 'delete': require_method('POST', 'DELETE'); return CrudService::delete($entity, $id, $user['user_id']);
            default: throw new ApiException('Unknown action.', 404);
        }
    });
}
