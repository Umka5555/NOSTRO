<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function getSortParams(array $allowedColumns, string $defaultColumn): array {
    $sortBy = $_GET['sort_by'] ?? $defaultColumn;
    if (!in_array($sortBy, $allowedColumns, true)) {
        $sortBy = $defaultColumn;
    }
    $sortDir = strtoupper((string)($_GET['sort_dir'] ?? 'DESC'));
    if ($sortDir !== 'ASC' && $sortDir !== 'DESC') {
        $sortDir = 'DESC';
    }
    return [$sortBy, $sortDir];
}

function buildFilterWhere(array $accountFields, string $companyField, array &$params): string {
    $conditions = [];
    $account = trim((string)($_GET['account'] ?? ''));
    $company = trim((string)($_GET['company'] ?? ''));

    if ($account !== '' && count($accountFields) > 0) {
        $accParts = [];
        foreach ($accountFields as $idx => $col) {
            $key = ':acc_' . $idx;
            $accParts[] = "COALESCE({$col}, '') ILIKE {$key}";
            $params[$key] = '%' . $account . '%';
        }
        $conditions[] = '(' . implode(' OR ', $accParts) . ')';
    }

    if ($company !== '' && $companyField !== '') {
        $conditions[] = "COALESCE({$companyField}, '') ILIKE :company";
        $params[':company'] = '%' . $company . '%';
    }

    return count($conditions) > 0 ? (' WHERE ' . implode(' AND ', $conditions)) : '';
}

$action = $_GET['action'] ?? '';

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'Ошибка подключения к PostgreSQL: ' . $e->getMessage()
    ], 500);
}

try {
    switch ($action) {
        case 'ping':
            $version = $pdo->query('SELECT version()')->fetchColumn();
            jsonResponse(['ok' => true, 'version' => $version]);

        case 'login':
            $body = getJsonBody();
            $fio = trim((string)($body['fio'] ?? ''));
            $password = (string)($body['password'] ?? '');

            if ($fio === '' || $password === '') {
                jsonResponse(['ok' => false, 'error' => 'Заполните логин и пароль!'], 400);
            }

            $stmt = $pdo->prepare('SELECT id, role, fio, password FROM users WHERE LOWER(fio) = LOWER(:fio) LIMIT 1');
            $stmt->execute([':fio' => $fio]);
            $user = $stmt->fetch();

            if (!$user || $user['password'] !== $password) {
                jsonResponse(['ok' => false, 'error' => 'Неверный логин или пароль!'], 401);
            }

            jsonResponse([
                'ok' => true,
                'user' => [
                    'id' => (int)$user['id'],
                    'fio' => $user['fio'],
                    'role' => $user['role'],
                ]
            ]);

        case 'users_list':
            $rows = $pdo->query('SELECT id, role, fio, password FROM users ORDER BY id ASC')->fetchAll();
            jsonResponse(['ok' => true, 'users' => $rows]);

        case 'users_save':
            $body = getJsonBody();
            $id = isset($body['id']) && $body['id'] !== '' ? (int)$body['id'] : null;
            $role = trim((string)($body['role'] ?? ''));
            $fio = trim((string)($body['fio'] ?? ''));
            $password = trim((string)($body['password'] ?? ''));

            if ($role === '' || $fio === '' || $password === '') {
                jsonResponse(['ok' => false, 'error' => 'Заполните роль, ФИО и пароль!'], 400);
            }

            if ($id !== null) {
                $dup = $pdo->prepare('SELECT id FROM users WHERE LOWER(fio) = LOWER(:fio) AND id <> :id');
                $dup->execute([':fio' => $fio, ':id' => $id]);
                if ($dup->fetch()) {
                    jsonResponse(['ok' => false, 'error' => 'Пользователь с таким ФИО уже существует!'], 409);
                }
                $stmt = $pdo->prepare('UPDATE users SET role = :role, fio = :fio, password = :password WHERE id = :id');
                $stmt->execute([':role' => $role, ':fio' => $fio, ':password' => $password, ':id' => $id]);
                jsonResponse(['ok' => true, 'message' => 'Пользователь обновлен!']);
            } else {
                $dup = $pdo->prepare('SELECT id FROM users WHERE LOWER(fio) = LOWER(:fio)');
                $dup->execute([':fio' => $fio]);
                if ($dup->fetch()) {
                    jsonResponse(['ok' => false, 'error' => 'Пользователь с таким ФИО уже существует!'], 409);
                }
                $stmt = $pdo->prepare('INSERT INTO users (role, fio, password) VALUES (:role, :fio, :password)');
                $stmt->execute([':role' => $role, ':fio' => $fio, ':password' => $password]);
                jsonResponse(['ok' => true, 'message' => 'Пользователь добавлен!']);
            }

        case 'users_delete':
            $body = getJsonBody();
            $id = isset($body['id']) ? (int)$body['id'] : 0;
            if ($id <= 0) {
                jsonResponse(['ok' => false, 'error' => 'Некорректный ID пользователя'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => $id]);
            jsonResponse(['ok' => true, 'message' => 'Пользователь удален!']);

        case 'accounts_list':
            $params = [];
            $where = buildFilterWhere(['account_number'], 'owner_name', $params);
            [$sortBy, $sortDir] = getSortParams(['account_number', 'owner_name', 'balance', 'currency', 'acc_date'], 'account_number');
            $stmt = $pdo->prepare("SELECT account_number, acc_date, currency, balance, prev_balance, owner_name FROM nostro_accounts{$where} ORDER BY {$sortBy} {$sortDir}");
            $stmt->execute($params);
            jsonResponse(['ok' => true, 'accounts' => $stmt->fetchAll()]);

        case 'account_get':
            $accountNumber = trim((string)($_GET['account_number'] ?? ''));
            if ($accountNumber === '') {
                jsonResponse(['ok' => true, 'account' => null]);
            }
            $stmt = $pdo->prepare('SELECT account_number, balance, prev_balance, owner_name, currency FROM nostro_accounts WHERE account_number = :acc LIMIT 1');
            $stmt->execute([':acc' => $accountNumber]);
            $acc = $stmt->fetch();
            jsonResponse(['ok' => true, 'account' => $acc ?: null]);

        case 'account_update':
            $body = getJsonBody();
            $accountNumber = trim((string)($body['account_number'] ?? ''));
            $newBalance = $body['balance'] ?? null;
            $ownerName = trim((string)($body['owner_name'] ?? ''));

            if ($accountNumber === '' || $newBalance === null || !is_numeric($newBalance)) {
                jsonResponse(['ok' => false, 'error' => 'Укажите номер счёта и корректную сумму!'], 400);
            }

            $stmt = $pdo->prepare('SELECT id, balance FROM nostro_accounts WHERE account_number = :acc LIMIT 1');
            $stmt->execute([':acc' => $accountNumber]);
            $existing = $stmt->fetch();

            if ($existing) {
                $upd = $pdo->prepare('UPDATE nostro_accounts SET prev_balance = balance, balance = :bal, owner_name = :owner, currency = COALESCE(NULLIF(:curr, \'\'), currency), acc_date = CURRENT_DATE WHERE id = :id');
                $upd->execute([
                    ':bal' => (float)$newBalance,
                    ':owner' => $ownerName,
                    ':curr' => trim((string)($body['currency'] ?? '')),
                    ':id' => $existing['id']
                ]);
            } else {
                $ins = $pdo->prepare("INSERT INTO nostro_accounts (account_number, acc_date, currency, balance, prev_balance, owner_name) VALUES (:acc, CURRENT_DATE, 'RUB', :bal, 0.00, :owner)");
                $ins->execute([
                    ':acc' => $accountNumber,
                    ':bal' => (float)$newBalance,
                    ':owner' => $ownerName
                ]);
            }

            $stmt = $pdo->prepare('SELECT account_number, balance, prev_balance, owner_name FROM nostro_accounts WHERE account_number = :acc LIMIT 1');
            $stmt->execute([':acc' => $accountNumber]);
            jsonResponse(['ok' => true, 'account' => $stmt->fetch()]);

        case 'account_revert':
            $body = getJsonBody();
            $accountNumber = trim((string)($body['account_number'] ?? ''));
            if ($accountNumber === '') {
                jsonResponse(['ok' => false, 'error' => 'Укажите номер счёта!'], 400);
            }
            $stmt = $pdo->prepare('SELECT id, balance, prev_balance FROM nostro_accounts WHERE account_number = :acc LIMIT 1');
            $stmt->execute([':acc' => $accountNumber]);
            $existing = $stmt->fetch();
            if (!$existing || (float)$existing['balance'] === (float)$existing['prev_balance']) {
                jsonResponse(['ok' => false, 'error' => 'Нет изменений для отмены'], 400);
            }
            $upd = $pdo->prepare('UPDATE nostro_accounts SET balance = prev_balance WHERE id = :id');
            $upd->execute([':id' => $existing['id']]);
            $stmt->execute([':acc' => $accountNumber]);
            jsonResponse(['ok' => true, 'account' => $stmt->fetch()]);

        case 'deals_list':
            $params = [];
            $where = buildFilterWhere(['nostro_account', 'sender_account', 'receiver_account'], 'company', $params);
            [$sortBy, $sortDir] = getSortParams(['deal_code', 'deal_date', 'amount', 'nostro_account', 'company', 'deal_type', 'status', 'currency'], 'deal_date');
            $stmt = $pdo->prepare("SELECT id, deal_code, TO_CHAR(deal_date, 'YYYY-MM-DD HH24:MI') AS deal_date, amount, nostro_account, direction, currency, sender_account, receiver_account, status, deal_type, company FROM deals{$where} ORDER BY {$sortBy} {$sortDir}");
            $stmt->execute($params);
            jsonResponse(['ok' => true, 'deals' => $stmt->fetchAll()]);

        case 'deal_save':
            $body = getJsonBody();
            $id = !empty($body['id']) ? (int)$body['id'] : null;
            $code = trim((string)($body['deal_code'] ?? ''));
            $amount = $body['amount'] ?? null;
            if ($code === '' || $amount === null || !is_numeric($amount)) {
                jsonResponse(['ok' => false, 'error' => 'Заполните номер сделки и сумму!'], 400);
            }
            if ($id !== null) {
                $stmt = $pdo->prepare('
                    UPDATE deals SET
                        deal_code = :code,
                        amount = :amount,
                        nostro_account = :nostro,
                        direction = :direction,
                        currency = :currency,
                        sender_account = :sender,
                        receiver_account = :receiver,
                        status = :status,
                        deal_type = :deal_type,
                        company = :company
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':id' => $id,
                    ':code' => $code,
                    ':amount' => (float)$amount,
                    ':nostro' => trim((string)($body['nostro_account'] ?? '')),
                    ':direction' => trim((string)($body['direction'] ?? 'Входящий')),
                    ':currency' => trim((string)($body['currency'] ?? 'RUB')),
                    ':sender' => trim((string)($body['sender_account'] ?? '')),
                    ':receiver' => trim((string)($body['receiver_account'] ?? '')),
                    ':status' => trim((string)($body['status'] ?? 'Активна')),
                    ':deal_type' => trim((string)($body['deal_type'] ?? 'FX Deals')),
                    ':company' => trim((string)($body['company'] ?? '')),
                ]);
                jsonResponse(['ok' => true, 'message' => 'Сделка обновлена в БД!']);
            }
            $stmt = $pdo->prepare('
                INSERT INTO deals (deal_code, deal_date, amount, nostro_account, direction, currency, sender_account, receiver_account, status, deal_type, company)
                VALUES (:code, CURRENT_TIMESTAMP, :amount, :nostro, :direction, :currency, :sender, :receiver, :status, :deal_type, :company)
                ON CONFLICT (deal_code) DO UPDATE SET
                    amount = EXCLUDED.amount,
                    nostro_account = EXCLUDED.nostro_account,
                    direction = EXCLUDED.direction,
                    currency = EXCLUDED.currency,
                    status = EXCLUDED.status,
                    deal_type = EXCLUDED.deal_type,
                    company = EXCLUDED.company
            ');
            $stmt->execute([
                ':code' => $code,
                ':amount' => (float)$amount,
                ':nostro' => trim((string)($body['nostro_account'] ?? '')),
                ':direction' => trim((string)($body['direction'] ?? 'Входящий')),
                ':currency' => trim((string)($body['currency'] ?? 'RUB')),
                ':sender' => trim((string)($body['sender_account'] ?? '')),
                ':receiver' => trim((string)($body['receiver_account'] ?? '')),
                ':status' => trim((string)($body['status'] ?? 'Активна')),
                ':deal_type' => trim((string)($body['deal_type'] ?? 'FX Deals')),
                ':company' => trim((string)($body['company'] ?? '')),
            ]);
            jsonResponse(['ok' => true, 'message' => 'Сделка сохранена в БД!']);

        case 'payments_list':
            $params = [];
            $where = buildFilterWhere(['nostro_account', 'sender_account', 'receiver_account'], 'company', $params);
            [$sortBy, $sortDir] = getSortParams(['payment_code', 'pay_date', 'amount', 'nostro_account', 'company', 'status', 'currency', 'direction'], 'pay_date');
            $stmt = $pdo->prepare("SELECT id, payment_code, pay_date, amount, nostro_account, direction, currency, sender_account, receiver_account, status, deal_type, company FROM payments{$where} ORDER BY {$sortBy} {$sortDir}");
            $stmt->execute($params);
            jsonResponse(['ok' => true, 'payments' => $stmt->fetchAll()]);

        case 'payment_save':
            $body = getJsonBody();
            $id = !empty($body['id']) ? (int)$body['id'] : null;
            $code = trim((string)($body['payment_code'] ?? ''));
            $amount = $body['amount'] ?? null;
            if ($code === '' || $amount === null || !is_numeric($amount)) {
                jsonResponse(['ok' => false, 'error' => 'Заполните ID и Сумму!'], 400);
            }
            $payDate = !empty($body['pay_date']) ? $body['pay_date'] : date('Y-m-d');
            $status = trim((string)($body['status'] ?? 'Отправлен'));

            if ($id !== null) {
                $stmt = $pdo->prepare('
                    UPDATE payments SET
                        payment_code = :code,
                        pay_date = :pay_date,
                        amount = :amount,
                        nostro_account = :nostro,
                        direction = :direction,
                        currency = :currency,
                        sender_account = :sender,
                        receiver_account = :receiver,
                        status = :status,
                        deal_type = :deal_type,
                        company = :company
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':id' => $id,
                    ':code' => $code,
                    ':pay_date' => $payDate,
                    ':amount' => (float)$amount,
                    ':nostro' => trim((string)($body['nostro_account'] ?? '')),
                    ':direction' => trim((string)($body['direction'] ?? 'Входящий')),
                    ':currency' => trim((string)($body['currency'] ?? 'RUB')),
                    ':sender' => trim((string)($body['sender_account'] ?? '')),
                    ':receiver' => trim((string)($body['receiver_account'] ?? '')),
                    ':status' => $status,
                    ':deal_type' => trim((string)($body['deal_type'] ?? '')),
                    ':company' => trim((string)($body['company'] ?? '')),
                ]);
                jsonResponse(['ok' => true, 'message' => 'Платёж обновлён в БД!']);
            }

            $stmt = $pdo->prepare('
                INSERT INTO payments (payment_code, pay_date, amount, nostro_account, direction, currency, sender_account, receiver_account, status, deal_type, company)
                VALUES (:code, :pay_date, :amount, :nostro, :direction, :currency, :sender, :receiver, :status, :deal_type, :company)
                ON CONFLICT (payment_code) DO UPDATE SET
                    pay_date = EXCLUDED.pay_date,
                    amount = EXCLUDED.amount,
                    nostro_account = EXCLUDED.nostro_account,
                    direction = EXCLUDED.direction,
                    currency = EXCLUDED.currency,
                    sender_account = EXCLUDED.sender_account,
                    receiver_account = EXCLUDED.receiver_account,
                    status = EXCLUDED.status,
                    deal_type = EXCLUDED.deal_type,
                    company = EXCLUDED.company
            ');
            $stmt->execute([
                ':code' => $code,
                ':pay_date' => $payDate,
                ':amount' => (float)$amount,
                ':nostro' => trim((string)($body['nostro_account'] ?? '')),
                ':direction' => trim((string)($body['direction'] ?? 'Входящий')),
                ':currency' => trim((string)($body['currency'] ?? 'RUB')),
                ':sender' => trim((string)($body['sender_account'] ?? '')),
                ':receiver' => trim((string)($body['receiver_account'] ?? '')),
                ':status' => $status,
                ':deal_type' => trim((string)($body['deal_type'] ?? '')),
                ':company' => trim((string)($body['company'] ?? '')),
            ]);
            jsonResponse(['ok' => true, 'message' => 'Платёж сохранён в БД!']);

        case 'payment_status':
            $body = getJsonBody();
            $code = trim((string)($body['payment_code'] ?? ''));
            $status = trim((string)($body['status'] ?? ''));
            if ($code === '' || $status === '') {
                jsonResponse(['ok' => false, 'error' => 'Укажите ID платежа и статус!'], 400);
            }
            $stmt = $pdo->prepare('UPDATE payments SET status = :status WHERE payment_code = :code');
            $stmt->execute([':status' => $status, ':code' => $code]);
            jsonResponse(['ok' => true, 'message' => 'Статус обновлён!']);

        case 'positions_list':
            $params = [];
            $where = buildFilterWhere(['account_number', 'sender_account', 'receiver_account'], 'company', $params);
            [$sortBy, $sortDir] = getSortParams(['position_code', 'pos_date', 'amount', 'balance', 'account_number', 'company', 'status', 'currency'], 'pos_date');
            $stmt = $pdo->prepare("SELECT id, COALESCE(position_code, 'POS-' || id) AS position_code, pos_date, amount, balance, currency, account_number, sender_account, receiver_account, status, company FROM positions{$where} ORDER BY {$sortBy} {$sortDir}");
            $stmt->execute($params);
            jsonResponse(['ok' => true, 'positions' => $stmt->fetchAll()]);

        case 'position_save':
            $body = getJsonBody();
            $id = !empty($body['id']) ? (int)$body['id'] : null;
            $acc = trim((string)($body['account_number'] ?? ''));
            if ($acc === '') {
                jsonResponse(['ok' => false, 'error' => 'Укажите счёт НОСТРО!'], 400);
            }
            $posDate = !empty($body['pos_date']) ? $body['pos_date'] : date('Y-m-d');
            $posCode = trim((string)($body['position_code'] ?? ('POS-' . substr((string)time(), -6))));
            if ($id !== null) {
                $stmt = $pdo->prepare('
                    UPDATE positions SET
                        account_number = :acc,
                        pos_date = :pos_date,
                        amount = :amount,
                        balance = :balance,
                        currency = :currency,
                        sender_account = :sender,
                        receiver_account = :receiver,
                        status = :status,
                        company = :company
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':id' => $id,
                    ':acc' => $acc,
                    ':pos_date' => $posDate,
                    ':amount' => is_numeric($body['amount'] ?? null) ? (float)$body['amount'] : 0.0,
                    ':balance' => is_numeric($body['balance'] ?? null) ? (float)$body['balance'] : 0.0,
                    ':currency' => trim((string)($body['currency'] ?? 'RUB')),
                    ':sender' => trim((string)($body['sender_account'] ?? '')),
                    ':receiver' => trim((string)($body['receiver_account'] ?? '')),
                    ':status' => trim((string)($body['status'] ?? 'Активна')),
                    ':company' => trim((string)($body['company'] ?? '')),
                ]);
                jsonResponse(['ok' => true, 'message' => 'Позиция обновлена в БД!']);
            }
            $stmt = $pdo->prepare('
                INSERT INTO positions (position_code, account_number, pos_date, amount, balance, currency, sender_account, receiver_account, status, company)
                VALUES (:code, :acc, :pos_date, :amount, :balance, :currency, :sender, :receiver, :status, :company)
            ');
            $stmt->execute([
                ':code' => $posCode,
                ':acc' => $acc,
                ':pos_date' => $posDate,
                ':amount' => is_numeric($body['amount'] ?? null) ? (float)$body['amount'] : 0.0,
                ':balance' => is_numeric($body['balance'] ?? null) ? (float)$body['balance'] : 0.0,
                ':currency' => trim((string)($body['currency'] ?? 'RUB')),
                ':sender' => trim((string)($body['sender_account'] ?? '')),
                ':receiver' => trim((string)($body['receiver_account'] ?? '')),
                ':status' => trim((string)($body['status'] ?? 'Активна')),
                ':company' => trim((string)($body['company'] ?? '')),
            ]);
            jsonResponse(['ok' => true, 'message' => 'Позиция сохранена в БД!']);

        case 'reports_list':
            $reportType = trim((string)($_GET['report_type'] ?? 'Список сделок'));
            $params = [];

            if ($reportType === 'Реестр платежей') {
                $where = buildFilterWhere(['nostro_account', 'sender_account', 'receiver_account'], 'company', $params);
                [$sortBy, $sortDir] = getSortParams(['code', 'rep_date', 'amount', 'account', 'company', 'status'], 'rep_date');
                $colMap = ['code' => 'payment_code', 'rep_date' => 'pay_date', 'amount' => 'amount', 'account' => 'nostro_account', 'company' => 'company', 'status' => 'status'];
                $orderCol = $colMap[$sortBy];
                $stmt = $pdo->prepare("SELECT payment_code AS code, pay_date::text AS rep_date, deal_type AS category, nostro_account AS account, company, amount, currency, status FROM payments{$where} ORDER BY {$orderCol} {$sortDir}");
            } elseif ($reportType === 'Позиции по счетам') {
                $where = buildFilterWhere(['account_number', 'sender_account', 'receiver_account'], 'company', $params);
                [$sortBy, $sortDir] = getSortParams(['code', 'rep_date', 'amount', 'account', 'company', 'status'], 'rep_date');
                $colMap = ['code' => 'id', 'rep_date' => 'pos_date', 'amount' => 'balance', 'account' => 'account_number', 'company' => 'company', 'status' => 'status'];
                $orderCol = $colMap[$sortBy];
                $stmt = $pdo->prepare("SELECT COALESCE(position_code, 'POS-' || id) AS code, pos_date::text AS rep_date, 'Позиция' AS category, account_number AS account, company, balance AS amount, currency, status FROM positions{$where} ORDER BY {$orderCol} {$sortDir}");
            } else {
                // Тикет сделки / Список сделок
                $where = buildFilterWhere(['nostro_account', 'sender_account', 'receiver_account'], 'company', $params);
                [$sortBy, $sortDir] = getSortParams(['code', 'rep_date', 'amount', 'account', 'company', 'status'], 'rep_date');
                $colMap = ['code' => 'deal_code', 'rep_date' => 'deal_date', 'amount' => 'amount', 'account' => 'nostro_account', 'company' => 'company', 'status' => 'status'];
                $orderCol = $colMap[$sortBy];
                $stmt = $pdo->prepare("SELECT deal_code AS code, TO_CHAR(deal_date, 'YYYY-MM-DD') AS rep_date, deal_type AS category, nostro_account AS account, company, amount, currency, status FROM deals{$where} ORDER BY {$orderCol} {$sortDir}");
            }

            $stmt->execute($params);
            jsonResponse(['ok' => true, 'rows' => $stmt->fetchAll()]);

        case 'dashboard_stats':
            $totalBalance = (float)$pdo->query('
                SELECT COALESCE(SUM(a.balance * COALESCE(c.rate_to_rub, 1.0)), 0)
                FROM nostro_accounts a
                LEFT JOIN currencies c ON UPPER(c.code) = UPPER(a.currency)
            ')->fetchColumn();
            $activePositions = (int)$pdo->query("SELECT COUNT(*) FROM positions WHERE status = 'Активна'")->fetchColumn();
            $pendingPayments = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE LOWER(status) IN ('сформирован', 'изменён')")->fetchColumn();
            jsonResponse([
                'ok' => true,
                'total_balance' => $totalBalance,
                'active_positions' => $activePositions,
                'pending_payments' => $pendingPayments,
            ]);

        case 'companies_list':
            $rows = $pdo->query("
                SELECT DISTINCT name FROM (
                    SELECT TRIM(owner_name) AS name FROM nostro_accounts WHERE TRIM(COALESCE(owner_name, '')) <> ''
                    UNION
                    SELECT TRIM(company) AS name FROM deals WHERE TRIM(COALESCE(company, '')) <> ''
                    UNION
                    SELECT TRIM(company) AS name FROM payments WHERE TRIM(COALESCE(company, '')) <> ''
                    UNION
                    SELECT TRIM(company) AS name FROM positions WHERE TRIM(COALESCE(company, '')) <> ''
                ) t
                ORDER BY name ASC
            ")->fetchAll(PDO::FETCH_COLUMN);
            jsonResponse(['ok' => true, 'companies' => $rows]);

        case 'currencies_list':
            $pdo->exec('ALTER TABLE currencies ADD COLUMN IF NOT EXISTS rate_to_rub NUMERIC(12, 4) NOT NULL DEFAULT 1.0000');
            $pdo->exec("
                INSERT INTO currencies (code, rate_date, name, rate_to_rub) VALUES
                    ('RUB', CURRENT_DATE, 'Российский рубль', 1.0000),
                    ('USD', CURRENT_DATE, 'Доллар США', 98.5000),
                    ('EUR', CURRENT_DATE, 'Евро', 105.2000),
                    ('CNY', CURRENT_DATE, 'Китайский юань', 13.6000)
                ON CONFLICT (code) DO NOTHING
            ");
            $rows = $pdo->query('SELECT code, name, rate_to_rub, rate_date FROM currencies ORDER BY code ASC')->fetchAll();
            jsonResponse(['ok' => true, 'currencies' => $rows]);

        case 'currency_save':
            $body = getJsonBody();
            $code = strtoupper(trim((string)($body['code'] ?? '')));
            $rate = $body['rate_to_rub'] ?? null;
            $rateDate = !empty($body['rate_date']) ? $body['rate_date'] : date('Y-m-d');
            if ($code === '' || $rate === null || !is_numeric($rate) || (float)$rate <= 0) {
                jsonResponse(['ok' => false, 'error' => 'Укажите валюту и корректный курс больше 0!'], 400);
            }
            $pdo->exec('ALTER TABLE currencies ADD COLUMN IF NOT EXISTS rate_to_rub NUMERIC(12, 4) NOT NULL DEFAULT 1.0000');
            $stmt = $pdo->prepare('
                INSERT INTO currencies (code, rate_date, name, rate_to_rub)
                VALUES (:code, :rate_date, :name, :rate)
                ON CONFLICT (code) DO UPDATE SET
                    rate_to_rub = EXCLUDED.rate_to_rub,
                    rate_date = EXCLUDED.rate_date
            ');
            $stmt->execute([
                ':code' => $code,
                ':rate_date' => $rateDate,
                ':name' => $code,
                ':rate' => (float)$rate,
            ]);
            jsonResponse(['ok' => true, 'message' => "Котировка {$code} сохранена!"]);

        default:
            jsonResponse(['ok' => false, 'error' => 'Неизвестное действие'], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()], 500);
}
