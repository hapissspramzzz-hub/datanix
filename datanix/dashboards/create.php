<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DashboardBuilder.php';

$user = require_login();
$workspace = require_permission('build_dashboard');
$error = '';
$stmt = $GLOBALS['pdo']->prepare('SELECT id,name,row_count,column_count,schema_json FROM datasets WHERE workspace_id=? ORDER BY name');
$stmt->execute([$workspace['id']]);
$datasets = $stmt->fetchAll();
$canBuild = plan_limits($workspace)['builder'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $datasetId = filter_var($_POST['dataset_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $mode = (string) ($_POST['mode'] ?? 'manual');
    $prompt = trim((string) ($_POST['prompt'] ?? 'Create a sales dashboard'));

    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 180) {
        $error = 'Nama dashboard harus 2–180 karakter.';
    } elseif ($datasetId && !in_array($datasetId, array_map(static fn ($item) => (int) $item['id'], $datasets), true)) {
        $error = 'Pilih dataset dari workspace aktif.';
    } elseif ($mode === 'auto' && !$datasetId) {
        $error = 'Pilih dataset sebelum membuat dashboard otomatis.';
    } else {
        $stmt = $GLOBALS['pdo']->prepare('SELECT COUNT(*) FROM dashboards WHERE workspace_id=?');
        $stmt->execute([$workspace['id']]);
        $count = (int) $stmt->fetchColumn();
        $max = $canBuild ? -1 : 1;
        if ($max !== -1 && $count >= $max) {
            $error = 'Paket Free mendukung satu dashboard dasar. Pro/Business membuka pembuatan dashboard tambahan.';
        } else {
            try {
                $widgets = [];
                if ($mode === 'auto' && $datasetId) {
                    $stmt = $GLOBALS['pdo']->prepare('SELECT schema_json FROM datasets WHERE id=? AND workspace_id=?');
                    $stmt->execute([$datasetId, $workspace['id']]);
                    $schema = json_decode((string) $stmt->fetchColumn(), true) ?: [];
                    $widgets = DatanixDashboardBuilder::suggestions($schema, $prompt, $canBuild ? 6 : 3);
                    if (!$canBuild) {
                        $widgets = array_map(static function (array $widget): array {
                            if ($widget['type'] === 'donut') {
                                $widget['type'] = 'bar';
                            }
                            return $widget;
                        }, $widgets);
                    }
                }
                $db = $GLOBALS['pdo'];
                $db->beginTransaction();
                $db->prepare('INSERT INTO dashboards(workspace_id,dataset_id,created_by,name,description) VALUES(?,?,?,?,?)')->execute([$workspace['id'], $datasetId, $user['id'], $name, $description]);
                $dashboardId = (int) $db->lastInsertId();
                $insertWidget = $db->prepare('INSERT INTO dashboard_widgets(dashboard_id,dataset_id,widget_type,title,metric_key,config_json,sort_order) VALUES(?,?,?,?,?,?,?)');
                foreach ($widgets as $order => $widget) {
                    $insertWidget->execute([$dashboardId, $datasetId, $widget['type'], $widget['title'], null, json_encode(['question' => $widget['question']], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $order]);
                }
                $db->commit();
                audit_log('dashboard.created', 'dashboard', $dashboardId, ['mode' => $mode, 'widgets' => count($widgets)]);
                flash('success', $widgets ? 'Dashboard dan widget berbasis schema dibuat.' : 'Dashboard dibuat.');
                redirect('dashboards/view.php?id=' . $dashboardId);
            } catch (Throwable $exception) {
                if ($GLOBALS['pdo']->inTransaction()) {
                    $GLOBALS['pdo']->rollBack();
                }
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Dashboard belum dapat dibuat.';
                if (!$exception instanceof RuntimeException) {
                    error_log('Datanix dashboard create failed: ' . $exception->getMessage());
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
$layout = 'app';
datanix_header('Buat dashboard', 'dashboards', $layout);
?>
<div class="page-head">
    <div><div class="section-kicker">DASHBOARD BUILDER</div><h1>Buat dashboard</h1><p>Mulai kosong, atau minta Datanix merangkai widget hanya dari kolom yang terdeteksi.</p></div>
    <a class="btn btn-light-outline" href="<?= e(url('dashboards/index.php')) ?>">Kembali</a>
</div>
<?php if ($error): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="panel h-100"><div class="panel-body">
            <form method="post"><?= csrf_field() ?>
                <div class="mb-3"><label class="form-label" for="dash-name">Nama dashboard</label><input id="dash-name" class="form-control" name="name" value="<?= e($_POST['name'] ?? '') ?>" maxlength="180" required placeholder="Contoh: Monthly sales review"></div>
                <div class="mb-3"><label class="form-label" for="dash-description">Deskripsi</label><textarea id="dash-description" class="form-control" name="description" rows="2" maxlength="2000" placeholder="Tujuan analisis singkat."><?= e($_POST['description'] ?? '') ?></textarea></div>
                <div class="mb-3"><label class="form-label" for="dash-dataset">Dataset utama</label><select id="dash-dataset" class="form-select" name="dataset_id"><option value="">Pilih nanti</option><?php foreach ($datasets as $dataset): ?><option value="<?= e($dataset['id']) ?>" <?= ((int) $dataset['id'] === (int) ($_POST['dataset_id'] ?? 0)) ? 'selected' : '' ?>><?= e($dataset['name']) ?> · <?= e(format_number((int) $dataset['row_count'])) ?> rows</option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label" for="dash-prompt">Prompt dashboard (opsional)</label><input id="dash-prompt" class="form-control" name="prompt" maxlength="400" value="<?= e($_POST['prompt'] ?? 'Create a sales dashboard') ?>" placeholder="Create a sales dashboard"></div>
                <div class="d-flex gap-2 flex-wrap"><button class="btn btn-primary" name="mode" value="auto">Generate from data</button><button class="btn btn-light-outline" name="mode" value="manual">Buat kosong</button></div>
                <p class="field-help mt-3 mb-0">Generator menggunakan schema lokal. Metrik tanpa kolom pendukung tidak dibuat; generasi dashboard tidak mengirim data ke AI eksternal.</p>
            </form>
        </div></section>
    </div>
    <div class="col-lg-5">
        <section class="panel h-100"><div class="panel-body"><h2 class="panel-title">Yang akan dibuat</h2><div class="small-muted mt-2">Jika tersedia di dataset, starter dashboard dapat berisi:</div><div class="tag-row mt-3"><span class="tag brand">Revenue</span><span class="tag">Orders</span><span class="tag">Customers</span><span class="tag">Trend</span><span class="tag">Top products</span><span class="tag">Geography</span></div><hr><div class="source-attribution">Free: 1 dashboard dan hingga 3 widget dasar. Pro/Business: builder penuh, hingga 100 widget/dashboard, layout tersimpan, drag-and-drop, dan resize. Semua angka dihitung dari data workspace.</div></div></section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
