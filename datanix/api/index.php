<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/QueryEngine.php';

function api_method(string $expected): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($expected)) {
        header('Allow: ' . strtoupper($expected));
        json_response(['ok' => false, 'error' => 'Metode tidak diizinkan.'], 405);
    }
}

function api_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $base = rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/');
    if ($base !== '' && str_starts_with($path, $base . '/')) {
        $path = substr($path, strlen($base));
    }
    return trim((string) preg_replace('~^/api/?~', '', $path), '/');
}

function api_number(array $metric): string
{
    $value = $metric['value'] ?? null;
    if ($value === null || !is_numeric($value)) {
        return e($value ?? '—');
    }
    if (($metric['kind'] ?? '') === 'percent') {
        return e(format_number((float) $value, 2)) . '%';
    }
    if (!empty($metric['currency'])) {
        return e(format_money((float) $value, (string) $metric['currency']));
    }
    return e(format_number((float) $value, 2));
}

function api_render_result(array $result, int $queryId, bool $canSave, bool $canReport): string
{
    if (($result['status'] ?? '') === 'insufficient') {
        return '<div class="callout warning"><strong>' . e($result['answer'] ?? 'Saya belum bisa memastikan karena data yang tersedia tidak mencukupi.') . '</strong><div class="small-muted mt-2">' . e($result['reason'] ?? '') . '</div></div><div class="result-basis">Status: insufficient · Tidak ada nilai yang ditambahkan.</div>';
    }
    $html = '<div class="result-summary">' . e($result['answer'] ?? '') . '</div><div class="result-metrics">';
    foreach (($result['metrics'] ?? []) as $metric) {
        $html .= '<div class="result-metric"><small>' . e($metric['label'] ?? 'Measure') . '</small><strong>' . api_number($metric) . '</strong></div>';
    }
    $html .= '</div>';
    if (!empty($result['insights'])) {
        $html .= '<div class="result-card"><strong class="small-caps">INSIGHT NARATIF</strong><ul class="small-muted mt-2 mb-0">';
        foreach ($result['insights'] as $insight) {
            $html .= '<li>' . e($insight) . '</li>';
        }
        $html .= '</ul></div>';
    }
    $basis = $result['basis'] ?? [];
    $metricType = (string) ($basis['metric']['type'] ?? '');
    $isPercent = ($result['kind'] ?? '') === 'growth' || $metricType === 'ratio';
    $currency = $basis['currency'] ?? null;
    if (!empty($result['chart']['labels'])) {
        $chart = json_encode($result['chart'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        $html .= '<div class="result-card"><strong class="small-caps">VISUALISASI · ' . e(ucfirst((string) ($result['kind'] ?? 'aggregate'))) . '</strong><div class="chart-canvas" data-chart="' . e($chart) . '"></div></div>';
    }
    if (!empty($result['table'])) {
        $html .= '<div class="result-card"><div class="d-flex align-items-center justify-content-between"><strong class="small-caps">SOURCE ROWS / AGGREGATION</strong><span class="badge-soft">' . e(format_number((int) ($result['basis']['rows'] ?? 0))) . ' rows matched</span></div><div class="table-wrap mt-2"><table class="data-table"><thead><tr><th>Group</th><th>Value</th><th>Rows</th></tr></thead><tbody>';
        foreach (array_slice($result['table'], 0, 20) as $row) {
            $value = $row['value'] ?? null;
            $formatted = is_numeric($value)
                ? ($isPercent ? format_number((float) $value, 2) . '%' : (!empty($currency) ? format_money((float) $value, (string) $currency) : format_number((float) $value, 2)))
                : (string) ($value ?? '—');
            $html .= '<tr><td>' . e($row['label'] ?? '') . '</td><td class="mono">' . e($formatted) . '</td><td>' . e(isset($row['rows']) ? format_number((int) $row['rows']) : '—') . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';
    }
    if (!empty($result['recommendations'])) {
        $html .= '<div class="result-card"><strong class="small-caps">NEXT STEPS</strong><ul class="small-muted mt-2 mb-0">';
        foreach ($result['recommendations'] as $recommendation) {
            $html .= '<li>' . e($recommendation) . '</li>';
        }
        $html .= '</ul></div>';
    }
    $html .= '<div class="result-basis">Dataset: ' . e($basis['dataset'] ?? '') . ' · ' . e(format_number((int) ($basis['rows'] ?? 0))) . ' dari ' . e(format_number((int) ($basis['dataset_rows'] ?? 0))) . ' baris';
    if (!empty($basis['period'])) {
        $html .= ' · Periode ' . e($basis['period']['start'] ?? '') . '–' . e($basis['period']['end'] ?? '');
    }
    $html .= ' · Metrik: ' . e($basis['derived_metric'] ?? ($result['metrics'][0]['label'] ?? 'aggregate'));
    if (!empty($basis['currency'])) {
        $html .= ' · Currency: ' . e($basis['currency']);
    }
    $html .= ' · Narrative: ' . e($result['ai_provider'] ?? 'deterministic') . '</div>';
    $saveAction = $canSave
        ? '<button class="btn btn-light-outline btn-sm" data-save-insight data-query-id="' . e($queryId) . '" data-title="' . e($result['metrics'][0]['label'] ?? 'Insight') . '">Save Insight</button>'
        : '<a class="btn btn-light-outline btn-sm" href="' . e(url('plans.php')) . '">Save Insight · Pro</a>';
    $reportAction = $canReport
        ? '<a class="btn btn-light-outline btn-sm" href="' . e(url('reports/create.php?query_id=' . $queryId)) . '">Buat report</a>'
        : '<a class="btn btn-light-outline btn-sm" href="' . e(url('plans.php')) . '">Report · Pro</a>';
    $html .= '<div class="chat-result-actions">' . $saveAction . $reportAction . '</div>';
    return $html;
}

$path = api_path();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$db = $GLOBALS['pdo'];

if ($path === 'health' && $method === 'GET') {
    try {
        $db->query('SELECT 1');
        json_response(['ok' => true, 'service' => 'datanix-api', 'database' => 'ready']);
    } catch (Throwable $exception) {
        json_response(['ok' => false, 'service' => 'datanix-api', 'database' => 'unavailable'], 503);
    }
}

if ($path === 'auth/register') {
    api_method('POST');
    verify_csrf();
    $input = request_data();
    $name = trim((string) ($input['name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 190
        || strlen($password) < 12 || strlen($password) > 200) {
        json_response(['ok' => false, 'error' => 'Nama, email, atau password tidak memenuhi persyaratan (password minimal 12 karakter).'], 422);
    }
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $db->lastInsertId();
        $workspaceName = mb_substr($name . ' Workspace', 0, 140, 'UTF-8');
        $slug = mb_substr(slugify($workspaceName) . '-' . $userId, 0, 160, 'UTF-8');
        $db->prepare('INSERT INTO workspaces(owner_id,name,slug,plan) VALUES(?,?,?,"free")')->execute([$userId, $workspaceName, $slug]);
        $workspaceId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO workspace_members(workspace_id,user_id,member_role) VALUES(?,?,"owner")')->execute([$workspaceId, $userId]);
        $db->commit();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['workspace_id'] = $workspaceId;
        audit_log('user.registered', 'user', $userId);
        json_response(['ok' => true, 'user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => 'member'], 'workspace' => ['id' => $workspaceId, 'name' => $workspaceName, 'plan' => 'free']], 201);
    } catch (PDOException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception->getCode() === '23000') {
            json_response(['ok' => false, 'error' => 'Email tidak dapat digunakan.'], 409);
        }
        error_log('Datanix REST registration failed: ' . $exception->getMessage());
        json_response(['ok' => false, 'error' => 'Akun belum dapat dibuat.'], 500);
    }
}

if ($path === 'auth/login') {
    api_method('POST');
    verify_csrf();
    $input = request_data();
    $attempts = (int) ($_SESSION['_login_attempts'] ?? 0);
    $lockedUntil = (int) ($_SESSION['_login_locked_until'] ?? 0);
    $now = time();
    if ($lockedUntil > $now) {
        json_response(['ok' => false, 'error' => 'Coba lagi beberapa menit kemudian.'], 429);
    }
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    $stmt = $db->prepare('SELECT id,name,email,password_hash,role,is_active FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string) $user['password_hash']) || (int) $user['is_active'] !== 1) {
        $_SESSION['_login_attempts'] = ++$attempts;
        if ($attempts >= 6) {
            $_SESSION['_login_locked_until'] = $now + 300;
            $_SESSION['_login_attempts'] = 0;
        }
        json_response(['ok' => false, 'error' => 'Email atau password tidak cocok.'], 401);
    }
    session_regenerate_id(true);
    unset($_SESSION['_login_attempts'], $_SESSION['_login_locked_until']);
    $_SESSION['user_id'] = (int) $user['id'];
    $stmt = $db->prepare('SELECT workspace_id FROM workspace_members WHERE user_id=? ORDER BY workspace_id LIMIT 1');
    $stmt->execute([$user['id']]);
    $workspaceId = (int) $stmt->fetchColumn();
    if ($workspaceId > 0) {
        $_SESSION['workspace_id'] = $workspaceId;
    }
    $db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
    audit_log('user.login', 'user', (int) $user['id']);
    json_response(['ok' => true, 'user' => ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']], 'workspace_id' => $workspaceId ?: null]);
}

if ($path === 'auth/me') {
    api_method('GET');
    $user = require_login();
    $workspace = current_workspace();
    json_response(['ok' => true, 'user' => ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']], 'workspace' => $workspace ? ['id' => (int) $workspace['id'], 'name' => $workspace['name'], 'plan' => $workspace['plan'], 'member_role' => $workspace['member_role']] : null]);
}

if ($path === 'auth/logout') {
    api_method('POST');
    verify_csrf();
    $user = current_user();
    if ($user) {
        audit_log('user.logout', 'user', (int) $user['id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    json_response(['ok' => true]);
}

if ($path === 'workspaces') {
    $user = require_login();
    if ($method === 'GET') {
        $stmt = $db->prepare('SELECT w.id,w.name,w.slug,w.plan,w.status,w.created_at,m.member_role FROM workspaces w JOIN workspace_members m ON m.workspace_id=w.id WHERE m.user_id=? AND w.status="active" ORDER BY w.updated_at DESC');
        $stmt->execute([$user['id']]);
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    api_method('POST');
    verify_csrf();
    $input = request_data();
    $name = trim((string) ($input['name'] ?? ''));
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 140) {
        json_response(['ok' => false, 'error' => 'Nama workspace harus 2–140 karakter.'], 422);
    }
    $stmt = $db->prepare('SELECT COUNT(*),MAX(plan="pro" OR plan="business") FROM workspaces WHERE owner_id=?');
    $stmt->execute([$user['id']]);
    [$ownedCount, $hasPaidWorkspace] = $stmt->fetch(PDO::FETCH_NUM);
    if ((int) $hasPaidWorkspace !== 1 && (int) $ownedCount >= 1) {
        json_response(['ok' => false, 'error' => 'Paket Free mendukung satu workspace pribadi.'], 403);
    }
    $db->beginTransaction();
    try {
        $slug = mb_substr(slugify($name) . '-' . $user['id'] . '-' . bin2hex(random_bytes(3)), 0, 160, 'UTF-8');
        $db->prepare('INSERT INTO workspaces(owner_id,name,slug,plan) VALUES(?,?,?,"free")')->execute([$user['id'], $name, $slug]);
        $workspaceId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO workspace_members(workspace_id,user_id,member_role) VALUES(?,?,"owner")')->execute([$workspaceId, $user['id']]);
        $db->commit();
        $_SESSION['workspace_id'] = $workspaceId;
        audit_log('workspace.created', 'workspace', $workspaceId);
        json_response(['ok' => true, 'id' => $workspaceId, 'name' => $name, 'plan' => 'free'], 201);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Workspace belum dapat dibuat.'], 500);
    }
}

if (preg_match('~^workspaces/(\d+)/switch$~', $path, $matches) && $method === 'POST') {
    verify_csrf();
    $user = require_login();
    $workspaceId = (int) $matches[1];
    $stmt = $db->prepare('SELECT id FROM workspace_members WHERE workspace_id=? AND user_id=?');
    $stmt->execute([$workspaceId, $user['id']]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Anda bukan anggota workspace ini.'], 403);
    }
    $_SESSION['workspace_id'] = $workspaceId;
    json_response(['ok' => true, 'workspace_id' => $workspaceId]);
}

if (preg_match('~^workspaces/(\d+)/members$~', $path, $matches)) {
    $user = require_login();
    $workspaceId = (int) $matches[1];
    $stmt = $db->prepare('SELECT w.*,m.member_role FROM workspaces w JOIN workspace_members m ON m.workspace_id=w.id WHERE w.id=? AND m.user_id=? AND w.status="active" LIMIT 1');
    $stmt->execute([$workspaceId, $user['id']]);
    $workspace = $stmt->fetch();
    if (!$workspace) {
        json_response(['ok' => false, 'error' => 'Workspace tidak ditemukan.'], 404);
    }
    if ($method === 'GET') {
        if (!can('view', $workspace)) {
            json_response(['ok' => false, 'error' => 'Akses ditolak.'], 403);
        }
        $stmt = $db->prepare('SELECT u.id,u.name,u.email,m.member_role,m.created_at FROM workspace_members m JOIN users u ON u.id=m.user_id WHERE m.workspace_id=? ORDER BY FIELD(m.member_role,"owner","admin","editor","analyst","viewer"),u.name');
        $stmt->execute([$workspaceId]);
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    api_method('POST');
    verify_csrf();
    if (!can('manage_members', $workspace)) {
        json_response(['ok' => false, 'error' => 'Hanya owner/admin workspace yang dapat mengelola anggota.'], 403);
    }
    if ($workspace['plan'] !== 'business') {
        json_response(['ok' => false, 'error' => 'Manajemen anggota tersedia pada paket Business.'], 403);
    }
    $input = request_data();
    $action = (string) ($input['action'] ?? 'add');
    if ($action === 'add') {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $role = (string) ($input['member_role'] ?? 'viewer');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin','editor','analyst','viewer'], true)) {
            json_response(['ok' => false, 'error' => 'Email atau peran anggota tidak valid.'], 422);
        }
        $stmt = $db->prepare('SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1');
        $stmt->execute([$email]);
        $targetId = (int) $stmt->fetchColumn();
        if (!$targetId) {
            json_response(['ok' => false, 'error' => 'Email harus milik akun Datanix aktif yang telah mendaftar.'], 404);
        }
        try {
            $db->prepare('INSERT INTO workspace_members(workspace_id,user_id,member_role,invited_by) VALUES(?,?,?,?)')->execute([$workspaceId, $targetId, $role, $user['id']]);
        } catch (PDOException $exception) {
            json_response(['ok' => false, 'error' => 'Anggota sudah terdaftar atau belum dapat ditambahkan.'], 409);
        }
        $db->prepare('INSERT INTO audit_logs(workspace_id,actor_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,"workspace.member_added","user",?,?)')->execute([$workspaceId, $user['id'], $targetId, json_encode(['role' => $role])]);
        json_response(['ok' => true, 'user_id' => $targetId], 201);
    }
    $targetId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    $stmt = $db->prepare('SELECT member_role FROM workspace_members WHERE workspace_id=? AND user_id=?');
    $stmt->execute([$workspaceId, $targetId]);
    $targetRole = $stmt->fetchColumn();
    if (!$targetRole || (int) $targetId === (int) $user['id'] || $targetRole === 'owner') {
        json_response(['ok' => false, 'error' => 'Anggota tidak ditemukan atau tindakan diblokir untuk owner/akun sendiri.'], 403);
    }
    if ($action === 'remove') {
        $db->prepare('DELETE FROM workspace_members WHERE workspace_id=? AND user_id=?')->execute([$workspaceId, $targetId]);
        $auditAction = 'workspace.member_removed';
    } elseif ($action === 'role') {
        $role = (string) ($input['member_role'] ?? 'viewer');
        if (!in_array($role, ['admin','editor','analyst','viewer'], true)) {
            json_response(['ok' => false, 'error' => 'Peran tidak valid.'], 422);
        }
        $db->prepare('UPDATE workspace_members SET member_role=? WHERE workspace_id=? AND user_id=?')->execute([$role, $workspaceId, $targetId]);
        $auditAction = 'workspace.member_role_changed';
    } else {
        json_response(['ok' => false, 'error' => 'Tindakan tidak dikenal.'], 422);
    }
    $db->prepare('INSERT INTO audit_logs(workspace_id,actor_id,action,entity_type,entity_id) VALUES(?,?,?,?,?)')->execute([$workspaceId, $user['id'], $auditAction, 'user', $targetId]);
    json_response(['ok' => true]);
}

if ($path === 'datasets') {
    api_method('GET');
    $workspace = require_permission('view');
    $stmt = $db->prepare('SELECT id,name,row_count,column_count,source_license,is_sample,created_at FROM datasets WHERE workspace_id=? ORDER BY created_at DESC');
    $stmt->execute([$workspace['id']]);
    json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
}

if (preg_match('~^datasets/(\d+)/schema$~', $path, $matches)) {
    api_method('GET');
    $workspace = require_permission('view');
    $stmt = $db->prepare('SELECT id,name,row_count,column_count,schema_json,source_license,source_citation,is_sample FROM datasets WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([(int) $matches[1], $workspace['id']]);
    $dataset = $stmt->fetch();
    if (!$dataset) {
        json_response(['ok' => false, 'error' => 'Dataset tidak ditemukan.'], 404);
    }
    $dataset['schema'] = json_decode((string) $dataset['schema_json'], true) ?: [];
    unset($dataset['schema_json']);
    json_response(['ok' => true, 'data' => $dataset]);
}

if (preg_match('~^datasets/(\d+)/rows$~', $path, $matches)) {
    api_method('GET');
    $workspace = require_permission('view');
    $datasetId = (int) $matches[1];
    $stmt = $db->prepare('SELECT id,schema_json,row_count FROM datasets WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$datasetId, $workspace['id']]);
    $dataset = $stmt->fetch();
    if (!$dataset) {
        json_response(['ok' => false, 'error' => 'Dataset tidak ditemukan.'], 404);
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200, 'UTF-8');
    $where = 'dataset_id=?';
    $params = [$datasetId];
    if ($search !== '') {
        $escaped = str_replace(['!','%','_'], ['!!','!%','!_'], $search);
        $where .= " AND row_json LIKE ? ESCAPE '!'";
        $params[] = '%' . $escaped . '%';
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM dataset_rows WHERE ' . $where);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $limit));
    $page = min($page, $pages);
    $stmt = $db->prepare('SELECT `row_number`,row_json FROM dataset_rows WHERE ' . $where . ' ORDER BY `row_number` LIMIT ? OFFSET ?');
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, ($page - 1) * $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    while ($record = $stmt->fetch()) {
        $rows[] = ['row_number' => (int) $record['row_number'], 'values' => json_decode((string) $record['row_json'], true) ?: []];
    }
    json_response(['ok' => true, 'data' => ['dataset_id' => $datasetId, 'schema' => json_decode((string) $dataset['schema_json'], true) ?: [], 'rows' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => $pages]]);
}

if ($path === 'query') {
    api_method('POST');
    verify_csrf();
    $user = require_login();
    $workspace = require_permission('ask');
    $input = request_data();
    $datasetId = filter_var($input['dataset_id'] ?? null, FILTER_VALIDATE_INT);
    $question = trim((string) ($input['question'] ?? ''));
    if (!$datasetId || $question === '' || mb_strlen($question, 'UTF-8') > 1500) {
        json_response(['ok' => false, 'error' => 'Pilih dataset dan masukkan pertanyaan maksimal 1.500 karakter.'], 422);
    }
    $config = require __DIR__ . '/../config/ai.php';
    $aiEnabled = ($config['provider'] ?? '') === 'openai-compatible' && !empty($config['api_base']) && !empty($config['api_key']);
    if ($aiEnabled && empty($user['ai_disclosure_at'])) {
        if (empty($input['ai_consent'])) {
            json_response(['ok' => false, 'consent_required' => true, 'error' => 'Persetujuan untuk pemrosesan AI eksternal diperlukan.'], 428);
        }
        $db->prepare('UPDATE users SET ai_disclosure_at=NOW() WHERE id=?')->execute([$user['id']]);
        $user['ai_disclosure_at'] = date('Y-m-d H:i:s');
        audit_log('ai.disclosure.accepted', 'user', (int) $user['id']);
    }
    $limit = plan_limits($workspace)['queries'];
    $used = usage_count('ai_query', (int) $workspace['id']);
    if ($limit !== -1 && $used >= $limit) {
        json_response(['ok' => false, 'error' => 'Kuota pertanyaan AI paket ini telah habis.'], 429);
    }
    $conversationId = filter_var($input['conversation_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    if ($conversationId) {
        $stmt = $db->prepare('SELECT id FROM conversations WHERE id=? AND workspace_id=? AND user_id=? LIMIT 1');
        $stmt->execute([$conversationId, $workspace['id'], $user['id']]);
        if (!$stmt->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'Percakapan tidak ditemukan.'], 404);
        }
    } else {
        $title = mb_substr($question, 0, 215, 'UTF-8');
        $stmt = $db->prepare('INSERT INTO conversations(workspace_id,dataset_id,user_id,title) VALUES(?,?,?,?)');
        $stmt->execute([$workspace['id'], $datasetId, $user['id'], $title]);
        $conversationId = (int) $db->lastInsertId();
    }
    $db->prepare('INSERT INTO messages(conversation_id,role,content) VALUES(?,"user",?)')->execute([$conversationId, $question]);
    $result = DatanixQueryEngine::analyze((int) $workspace['id'], (int) $datasetId, $question, $conversationId);
    $queryStatus = ($result['status'] ?? '') === 'insufficient' ? 'insufficient' : 'success';
    $model = $result['model'] ?? null;
    $stmt = $db->prepare('INSERT INTO ai_queries(workspace_id,dataset_id,conversation_id,user_id,question,query_kind,result_json,model,status) VALUES(?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$workspace['id'], $datasetId, $conversationId, $user['id'], $question, $result['kind'] ?? null, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $model, $queryStatus]);
    $queryId = (int) $db->lastInsertId();
    $answerText = (string) ($result['answer'] ?? '');
    $db->prepare('INSERT INTO messages(conversation_id,role,content,result_json) VALUES(?,"assistant",?,?)')->execute([$conversationId, $answerText, json_encode(['query_id' => $queryId, 'status' => $queryStatus])]);
    if ($queryStatus === 'success') {
        $db->prepare('INSERT INTO usage_logs(workspace_id,user_id,usage_type,units,month_key,metadata_json) VALUES(?,?,"ai_query",1,?,?)')->execute([$workspace['id'], $user['id'], date('Y-m'), json_encode(['query_id' => $queryId, 'status' => $queryStatus])]);
    }
    $db->prepare('UPDATE conversations SET dataset_id=?,updated_at=NOW() WHERE id=?')->execute([$datasetId, $conversationId]);
    audit_log('analysis.query','ai_query',$queryId,['status' => $queryStatus, 'kind' => $result['kind'] ?? null]);
    json_response(['ok' => true, 'query_id' => $queryId, 'conversation_id' => $conversationId, 'html' => api_render_result($result, $queryId, (bool) plan_limits($workspace)['saved_insights'], (bool) plan_limits($workspace)['exports']), 'result' => $result, 'usage' => ['used' => $used + ($queryStatus === 'success' ? 1 : 0), 'limit' => $limit]]);
}

if ($path === 'conversations') {
    api_method('GET');
    $user = require_login();
    $workspace = require_permission('view');
    $stmt = $db->prepare('SELECT c.id,c.title,c.dataset_id,c.updated_at,d.name dataset_name FROM conversations c LEFT JOIN datasets d ON d.id=c.dataset_id WHERE c.workspace_id=? AND c.user_id=? ORDER BY c.updated_at DESC LIMIT 100');
    $stmt->execute([$workspace['id'], $user['id']]);
    json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
}

if ($path === 'insights') {
    if ($method === 'GET') {
        $workspace = require_permission('view');
        $stmt = $db->prepare('SELECT i.id,i.title,i.question,i.created_at,d.name dataset_name FROM saved_insights i LEFT JOIN datasets d ON d.id=i.dataset_id WHERE i.workspace_id=? ORDER BY i.updated_at DESC LIMIT 100');
        $stmt->execute([$workspace['id']]);
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    api_method('POST');
    verify_csrf();
    $user = require_login();
    $workspace = require_permission('build_dashboard');
    if (!plan_limits($workspace)['saved_insights']) {
        json_response(['ok' => false, 'error' => 'Saved Insights tersedia pada Pro atau Business.'], 403);
    }
    $input = request_data();
    $queryId = filter_var($input['query_id'] ?? null, FILTER_VALIDATE_INT);
    $title = trim((string) ($input['title'] ?? 'Insight'));
    $stmt = $db->prepare('SELECT dataset_id,question,result_json FROM ai_queries WHERE id=? AND workspace_id=? AND user_id=? AND status="success" LIMIT 1');
    $stmt->execute([$queryId, $workspace['id'], $user['id']]);
    $query = $stmt->fetch();
    if (!$query || $title === '' || mb_strlen($title, 'UTF-8') > 220) {
        json_response(['ok' => false, 'error' => 'Query atau judul insight tidak valid.'], 422);
    }
    $stmt = $db->prepare('INSERT INTO saved_insights(workspace_id,dataset_id,created_by,title,question,result_json) VALUES(?,?,?,?,?,?)');
    $stmt->execute([$workspace['id'], $query['dataset_id'], $user['id'], $title, $query['question'], $query['result_json']]);
    $id = (int) $db->lastInsertId();
    audit_log('insight.saved','saved_insight',$id);
    json_response(['ok' => true, 'id' => $id], 201);
}

if ($path === 'dashboards') {
    if ($method === 'GET') {
        $workspace = require_permission('view');
        $stmt = $db->prepare('SELECT d.id,d.name,d.description,d.dataset_id,d.updated_at,COUNT(w.id) widget_count FROM dashboards d LEFT JOIN dashboard_widgets w ON w.dashboard_id=d.id WHERE d.workspace_id=? GROUP BY d.id ORDER BY d.updated_at DESC');
        $stmt->execute([$workspace['id']]);
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    api_method('POST');
    verify_csrf();
    $user = require_login();
    $workspace = require_permission('build_dashboard');
    if (!plan_limits($workspace)['builder']) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM dashboards WHERE workspace_id=?');
        $stmt->execute([$workspace['id']]);
        if ((int) $stmt->fetchColumn() >= 1) {
            json_response(['ok' => false, 'error' => 'Paket Free mendukung satu dashboard dasar. Pro atau Business membuka dashboard builder penuh.'], 403);
        }
    }
    $input = request_data();
    $title = trim((string) ($input['name'] ?? ''));
    $datasetId = filter_var($input['dataset_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    if (mb_strlen($title, 'UTF-8') < 2 || mb_strlen($title, 'UTF-8') > 180) {
        json_response(['ok' => false, 'error' => 'Nama dashboard harus 2–180 karakter.'], 422);
    }
    if ($datasetId) {
        $stmt = $db->prepare('SELECT id FROM datasets WHERE id=? AND workspace_id=?');
        $stmt->execute([$datasetId, $workspace['id']]);
        if (!$stmt->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'Dataset tidak ditemukan di workspace ini.'], 404);
        }
    }
    $stmt = $db->prepare('INSERT INTO dashboards(workspace_id,dataset_id,created_by,name,description) VALUES(?,?,?,?,?)');
    $stmt->execute([$workspace['id'], $datasetId, $user['id'], $title, trim((string) ($input['description'] ?? ''))]);
    $id = (int) $db->lastInsertId();
    audit_log('dashboard.created','dashboard',$id);
    json_response(['ok' => true, 'id' => $id], 201);
}

if (preg_match('~^dashboards/(\d+)$~', $path, $matches) && $method === 'GET') {
    $workspace = require_permission('view');
    $stmt = $db->prepare('SELECT * FROM dashboards WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([(int) $matches[1], $workspace['id']]);
    $dashboard = $stmt->fetch();
    if (!$dashboard) {
        json_response(['ok' => false, 'error' => 'Dashboard tidak ditemukan.'], 404);
    }
    $stmt = $db->prepare('SELECT id,dataset_id,widget_type,title,metric_key,config_json,position_x,position_y,width_units,height_units,sort_order FROM dashboard_widgets WHERE dashboard_id=? ORDER BY sort_order');
    $stmt->execute([$dashboard['id']]);
    $dashboard['widgets'] = $stmt->fetchAll();
    json_response(['ok' => true, 'data' => $dashboard]);
}

if (preg_match('~^dashboards/(\d+)/layout$~', $path, $matches) && $method === 'POST') {
    verify_csrf();
    $workspace = require_permission('build_dashboard');
    if (!plan_limits($workspace)['builder']) {
        json_response(['ok' => false, 'error' => 'Layout bebas tersedia pada Pro atau Business.'], 403);
    }
    $dashboardId = (int) $matches[1];
    $stmt = $db->prepare('SELECT id FROM dashboards WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$dashboardId, $workspace['id']]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Dashboard tidak ditemukan.'], 404);
    }
    $input = request_data();
    $widgets = $input['widgets'] ?? null;
    if (!is_array($widgets) || !array_is_list($widgets) || count($widgets) > 100) {
        json_response(['ok' => false, 'error' => 'Susunan widget tidak valid.'], 422);
    }
    $db->beginTransaction();
    try {
        $update = $db->prepare('UPDATE dashboard_widgets SET sort_order=?,position_x=?,position_y=?,width_units=? WHERE id=? AND dashboard_id=?');
        foreach ($widgets as $order => $widget) {
            if (!is_array($widget) || !isset($widget['id'])) {
                throw new RuntimeException('Widget layout tidak valid.');
            }
            $width = (int) ($widget['width_units'] ?? 6);
            if (!in_array($width, [6, 12], true)) {
                throw new RuntimeException('Lebar widget tidak valid.');
            }
            $update->execute([$order, ($order % 2) * 6, intdiv($order, 2), $width, (int) $widget['id'], $dashboardId]);
            if ($update->rowCount() > 1) {
                throw new RuntimeException('Widget layout tidak valid.');
            }
        }
        $db->prepare('UPDATE dashboards SET layout_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?')->execute([json_encode($widgets), $dashboardId, $workspace['id']]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Layout belum dapat disimpan.'], 422);
    }
    json_response(['ok' => true]);
}

if (preg_match('~^dashboards/(\d+)/widgets$~', $path, $matches) && $method === 'POST') {
    verify_csrf();
    $user = require_login();
    $workspace = require_permission('build_dashboard');
    $builderEnabled = plan_limits($workspace)['builder'];
    if (!plan_limits($workspace)['builder']) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM dashboard_widgets w JOIN dashboards d ON d.id=w.dashboard_id WHERE d.workspace_id=?');
        $stmt->execute([$workspace['id']]);
        if ((int) $stmt->fetchColumn() >= 3) {
            json_response(['ok' => false, 'error' => 'Paket Free mendukung maksimal tiga widget dasar.'], 403);
        }
    }
    $dashboardId = (int) $matches[1];
    $stmt = $db->prepare('SELECT id,dataset_id FROM dashboards WHERE id=? AND workspace_id=?');
    $stmt->execute([$dashboardId, $workspace['id']]);
    $dashboard = $stmt->fetch();
    if (!$dashboard) {
        json_response(['ok' => false, 'error' => 'Dashboard tidak ditemukan.'], 404);
    }
    $input = request_data();
    $type = (string) ($input['widget_type'] ?? 'metric');
    $allowedTypes = $builderEnabled ? ['metric','line','bar','donut','table'] : ['metric','line','bar','table'];
    $title = trim((string) ($input['title'] ?? 'Widget baru'));
    $datasetId = filter_var($input['dataset_id'] ?? $dashboard['dataset_id'], FILTER_VALIDATE_INT) ?: null;
    $config = is_array($input['config'] ?? null) ? $input['config'] : [];
    if (!in_array($type, $allowedTypes, true) || $title === '' || mb_strlen($title, 'UTF-8') > 180) {
        json_response(['ok' => false, 'error' => 'Jenis atau judul widget tidak valid.'], 422);
    }
    if ($datasetId) {
        $stmt = $db->prepare('SELECT id FROM datasets WHERE id=? AND workspace_id=?');
        $stmt->execute([$datasetId, $workspace['id']]);
        if (!$stmt->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'Dataset widget tidak ditemukan.'], 404);
        }
    }
    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM dashboard_widgets WHERE dashboard_id=?');
    $stmt->execute([$dashboardId]);
    $sort = (int) $stmt->fetchColumn();
    $stmt = $db->prepare('INSERT INTO dashboard_widgets(dashboard_id,dataset_id,widget_type,title,metric_key,config_json,sort_order) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$dashboardId, $datasetId, $type, $title, $config['metric_key'] ?? null, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $sort]);
    json_response(['ok' => true, 'id' => (int) $db->lastInsertId()], 201);
}

if ($path === 'sources') {
    $user = require_login();
    $workspace = require_workspace();
    if ($method === 'GET') {
        require_permission('view');
        $stmt = $db->prepare('SELECT id,name,source_type,status,row_count,last_updated_at,error_message,created_at FROM data_sources WHERE workspace_id=? ORDER BY created_at DESC');
        $stmt->execute([$workspace['id']]);
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    api_method('POST');
    verify_csrf();
    require_permission('connect');
    require_once __DIR__ . '/../includes/Connectors.php';
    try {
        $datasetId = DatanixConnectors::connectAndImport((int) $workspace['id'], (int) $user['id'], request_data());
        audit_log('source.connected', 'data_source', $datasetId);
        json_response(['ok' => true, 'dataset_id' => $datasetId], 201);
    } catch (Throwable $exception) {
        error_log('Datanix connector create failed: ' . $exception->getMessage());
        json_response(['ok' => false, 'error' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Koneksi belum dapat dibuat.'], 422);
    }
}

if (preg_match('~^sources/(\d+)/sync$~', $path, $matches) && $method === 'POST') {
    verify_csrf();
    $user = require_login();
    $workspace = require_permission('connect');
    require_once __DIR__ . '/../includes/Connectors.php';
    try {
        $datasetId = DatanixConnectors::sync((int) $matches[1], (int) $workspace['id'], (int) $user['id']);
        json_response(['ok' => true, 'dataset_id' => $datasetId]);
    } catch (Throwable $exception) {
        $db->prepare('UPDATE data_sources SET status="error",error_message=? WHERE id=? AND workspace_id=?')->execute([mb_substr($exception->getMessage(), 0, 480, 'UTF-8'), (int) $matches[1], $workspace['id']]);
        error_log('Datanix connector sync failed: ' . $exception->getMessage());
        json_response(['ok' => false, 'error' => 'Sinkronisasi gagal. Periksa allowlist, TLS, kredensial, tabel dan konektivitas.'], 422);
    }
}

if (preg_match('~^sources/(\d+)$~', $path, $matches) && $method === 'DELETE') {
    verify_csrf();
    $user = require_login();
    $workspace = require_permission('edit_data');
    $sourceId = (int) $matches[1];
    $stmt = $db->prepare('SELECT id,storage_path FROM data_sources WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$sourceId, $workspace['id']]);
    $source = $stmt->fetch();
    if (!$source) {
        json_response(['ok' => false, 'error' => 'Data source tidak ditemukan.'], 404);
    }
    $db->prepare('DELETE FROM data_sources WHERE id=? AND workspace_id=?')->execute([$sourceId, $workspace['id']]);
    if ($source['storage_path'] && is_file($source['storage_path']) && str_starts_with((string) realpath($source['storage_path']), APP_ROOT . '/storage/private/')) {
        @unlink($source['storage_path']);
    }
    audit_log('source.deleted', 'data_source', $sourceId);
    json_response(['ok' => true]);
}

if ($path === 'subscriptions') {
    $user = require_login();
    $workspace = require_workspace();
    if ($method === 'GET') {
        $stmt = $db->prepare('SELECT id,plan,status,price_idr,starts_at,ends_at,created_at FROM subscriptions WHERE workspace_id=? AND user_id=? ORDER BY created_at DESC LIMIT 30');
        $stmt->execute([$workspace['id'], $user['id']]);
        json_response(['ok' => true, 'data' => $stmt->fetchAll(), 'automatic_billing' => false]);
    }
    api_method('POST');
    verify_csrf();
    $input = request_data();
    $plan = (string) ($input['plan'] ?? '');
    if (!in_array($plan, ['pro','business'], true)) {
        json_response(['ok' => false, 'error' => 'Paket tidak valid.'], 422);
    }
    if ($workspace['plan'] === $plan) {
        json_response(['ok' => false, 'error' => 'Workspace ini sudah memakai paket tersebut.'], 409);
    }
    $stmt = $db->prepare('SELECT id FROM subscriptions WHERE workspace_id=? AND plan=? AND status IN ("pending","active") LIMIT 1');
    $stmt->execute([$workspace['id'], $plan]);
    if ($stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Permintaan/paket yang sama sudah tercatat.'], 409);
    }
    $price = $plan === 'pro' ? 79000 : 0;
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO subscriptions(user_id,workspace_id,plan,status,price_idr,note) VALUES(?,?,?,"pending",?,"Permintaan prototype; verifikasi manual, tanpa penagihan otomatis.")')->execute([$user['id'], $workspace['id'], $plan, $price]);
        $subscriptionId = (int) $db->lastInsertId();
        if ($price > 0) {
            $db->prepare('INSERT INTO payments(subscription_id,amount_idr,status,reference) VALUES(?,? ,"pending","Manual review only — no gateway")')->execute([$subscriptionId, $price]);
        }
        $db->commit();
        audit_log('subscription.requested', 'subscription', $subscriptionId, ['plan' => $plan]);
        json_response(['ok' => true, 'id' => $subscriptionId, 'status' => 'pending', 'automatic_billing' => false], 201);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Permintaan upgrade belum dapat disimpan.'], 500);
    }
}

if ($path === 'reports') {
    $user = require_login();
    $workspace = require_workspace();
    if ($method === 'GET') {
        require_permission('view');
        $stmt = $db->prepare('SELECT r.id,r.title,r.dataset_id,r.period_start,r.period_end,r.created_at,d.name dataset_name FROM reports r LEFT JOIN datasets d ON d.id=r.dataset_id WHERE r.workspace_id=? ORDER BY r.created_at DESC LIMIT 100');
        $stmt->execute([$workspace['id']]);
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    api_method('POST');
    verify_csrf();
    require_permission('build_dashboard');
    if (!plan_limits($workspace)['exports']) {
        json_response(['ok' => false, 'error' => 'Report generator tersedia pada Pro atau Business.'], 403);
    }
    $input = request_data();
    $datasetId = filter_var($input['dataset_id'] ?? null, FILTER_VALIDATE_INT);
    $title = trim((string) ($input['title'] ?? ''));
    $question = trim((string) ($input['question'] ?? ''));
    if (!$datasetId || $title === '' || mb_strlen($title, 'UTF-8') > 220 || $question === '' || mb_strlen($question, 'UTF-8') > 1500) {
        json_response(['ok' => false, 'error' => 'Judul, dataset dan pertanyaan wajib valid.'], 422);
    }
    $stmt = $db->prepare('SELECT id FROM datasets WHERE id=? AND workspace_id=?');
    $stmt->execute([$datasetId, $workspace['id']]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Dataset tidak ditemukan pada workspace aktif.'], 404);
    }
    DatanixAI::setRemoteEnabled(false);
    $result = DatanixQueryEngine::analyze((int) $workspace['id'], (int) $datasetId, $question);
    if (($result['status'] ?? '') !== 'success') {
        json_response(['ok' => false, 'error' => $result['answer'] ?? 'Saya belum bisa memastikan karena data yang tersedia tidak mencukupi.', 'status' => 'insufficient', 'result' => $result], 422);
    }
    $stmt = $db->prepare('INSERT INTO reports(workspace_id,dataset_id,created_by,title,summary,result_json,format) VALUES(?,?,?,?,?,? ,"html")');
    $stmt->execute([$workspace['id'], $datasetId, $user['id'], $title, $result['answer'] ?? '', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    $reportId = (int) $db->lastInsertId();
    audit_log('report.created', 'report', $reportId, ['dataset_id' => (int) $datasetId, 'api' => true]);
    json_response(['ok' => true, 'id' => $reportId, 'result' => $result], 201);
}

if (preg_match('~^reports/(\d+)$~', $path, $matches)) {
    $user = require_login();
    $workspace = require_workspace();
    $reportId = (int) $matches[1];
    if ($method === 'GET') {
        require_permission('view');
        $stmt = $db->prepare('SELECT r.id,r.title,r.dataset_id,r.period_start,r.period_end,r.summary,r.result_json,r.created_at,d.name dataset_name FROM reports r LEFT JOIN datasets d ON d.id=r.dataset_id WHERE r.id=? AND r.workspace_id=? LIMIT 1');
        $stmt->execute([$reportId, $workspace['id']]);
        $report = $stmt->fetch();
        if (!$report) {
            json_response(['ok' => false, 'error' => 'Report tidak ditemukan.'], 404);
        }
        $report['result'] = json_decode((string) $report['result_json'], true) ?: [];
        unset($report['result_json']);
        json_response(['ok' => true, 'data' => $report]);
    }
    api_method('DELETE');
    verify_csrf();
    require_permission('build_dashboard');
    if (!plan_limits($workspace)['exports']) {
        json_response(['ok' => false, 'error' => 'Pengelolaan report tersedia pada Pro atau Business.'], 403);
    }
    $stmt = $db->prepare('DELETE FROM reports WHERE id=? AND workspace_id=?');
    $stmt->execute([$reportId, $workspace['id']]);
    if (!$stmt->rowCount()) {
        json_response(['ok' => false, 'error' => 'Report tidak ditemukan.'], 404);
    }
    audit_log('report.deleted', 'report', $reportId);
    json_response(['ok' => true]);
}

if (preg_match('~^insights/(\d+)$~', $path, $matches)) {
    $user = require_login();
    $workspace = require_workspace();
    $insightId = (int) $matches[1];
    if ($method === 'GET') {
        require_permission('view');
        $stmt = $db->prepare('SELECT i.*,d.name dataset_name FROM saved_insights i LEFT JOIN datasets d ON d.id=i.dataset_id WHERE i.id=? AND i.workspace_id=? LIMIT 1');
        $stmt->execute([$insightId, $workspace['id']]);
        $insight = $stmt->fetch();
        if (!$insight) {
            json_response(['ok' => false, 'error' => 'Insight tidak ditemukan.'], 404);
        }
        $insight['result'] = json_decode((string) $insight['result_json'], true) ?: [];
        unset($insight['result_json']);
        json_response(['ok' => true, 'data' => $insight]);
    }
    api_method('DELETE');
    verify_csrf();
    require_permission('build_dashboard');
    if (!plan_limits($workspace)['saved_insights']) {
        json_response(['ok' => false, 'error' => 'Saved Insights tersedia pada Pro atau Business.'], 403);
    }
    $stmt = $db->prepare('DELETE FROM saved_insights WHERE id=? AND workspace_id=?');
    $stmt->execute([$insightId, $workspace['id']]);
    if (!$stmt->rowCount()) {
        json_response(['ok' => false, 'error' => 'Insight tidak ditemukan.'], 404);
    }
    audit_log('insight.deleted', 'saved_insight', $insightId);
    json_response(['ok' => true]);
}

if ($path === 'admin/analytics') {
    api_method('GET');
    require_admin();
    $counts = [];
    foreach (['users','workspaces','datasets','dashboards','ai_queries','subscriptions','payments'] as $table) {
        $counts[$table] = (int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    $counts['confirmed_revenue_idr'] = (int) $db->query('SELECT COALESCE(SUM(amount_idr),0) FROM payments WHERE status="confirmed"')->fetchColumn();
    json_response(['ok' => true, 'data' => $counts]);
}

json_response(['ok' => false, 'error' => 'Endpoint tidak ditemukan.'], 404);
