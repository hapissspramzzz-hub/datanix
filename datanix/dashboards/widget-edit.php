<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$workspace = require_permission('build_dashboard');
$dashboardId = filter_var($_REQUEST['dashboard_id'] ?? null, FILTER_VALIDATE_INT);
$widgetId = filter_var($_REQUEST['widget_id'] ?? null, FILTER_VALIDATE_INT);
$stmt = $GLOBALS['pdo']->prepare('SELECT w.* FROM dashboard_widgets w JOIN dashboards d ON d.id=w.dashboard_id WHERE w.id=? AND w.dashboard_id=? AND d.workspace_id=? LIMIT 1');
$stmt->execute([$widgetId, $dashboardId, $workspace['id']]);
$widget = $stmt->fetch();
if (!$widget) {
    http_response_code(404);
    exit('Widget tidak ditemukan pada workspace aktif.');
}
$stmt = $GLOBALS['pdo']->prepare('SELECT id,name FROM datasets WHERE workspace_id=? ORDER BY name');
$stmt->execute([$workspace['id']]);
$datasets = $stmt->fetchAll();
$config = json_decode((string) $widget['config_json'], true) ?: [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title = trim((string) ($_POST['title'] ?? ''));
    $question = trim((string) ($_POST['question'] ?? ''));
    $type = (string) ($_POST['widget_type'] ?? 'metric');
    $datasetId = filter_var($_POST['dataset_id'] ?? null, FILTER_VALIDATE_INT);
    $allowed = plan_limits($workspace)['builder'] ? ['metric','line','bar','donut','table'] : ['metric','line','bar','table'];
    if (mb_strlen($title, 'UTF-8') < 2 || mb_strlen($title, 'UTF-8') > 180 || $question === '' || mb_strlen($question, 'UTF-8') > 1500) {
        $error = 'Judul dan pertanyaan harus valid.';
    } elseif (!in_array($type, $allowed, true)) {
        $error = 'Jenis grafik tidak tersedia pada paket ini.';
    } else {
        $stmt = $GLOBALS['pdo']->prepare('SELECT id FROM datasets WHERE id=? AND workspace_id=?');
        $stmt->execute([$datasetId, $workspace['id']]);
        if (!$stmt->fetchColumn()) {
            $error = 'Pilih dataset dari workspace aktif.';
        } else {
            $GLOBALS['pdo']->prepare('UPDATE dashboard_widgets SET dataset_id=?,title=?,widget_type=?,config_json=? WHERE id=? AND dashboard_id=?')->execute([$datasetId, $title, $type, json_encode(['question' => $question], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $widgetId, $dashboardId]);
            audit_log('dashboard.widget_updated', 'dashboard', (int) $dashboardId, ['widget_id' => (int) $widgetId, 'type' => $type]);
            flash('success', 'Widget diperbarui.');
            redirect('dashboards/view.php?id=' . (int) $dashboardId);
        }
    }
}

$title = (string) ($_POST['title'] ?? $widget['title']);
$question = (string) ($_POST['question'] ?? ($config['question'] ?? ''));
$type = (string) ($_POST['widget_type'] ?? $widget['widget_type']);
$datasetId = (int) ($_POST['dataset_id'] ?? ($widget['dataset_id'] ?? 0));
require_once __DIR__ . '/../includes/header.php';
$layout = 'app';
datanix_header('Edit widget', 'dashboards', $layout);
?>
<div class="page-head"><div><div class="section-kicker">DASHBOARD BUILDER</div><h1>Edit widget</h1><p>Query dihitung ulang dari dataset yang dipilih.</p></div><a class="btn btn-light-outline" href="<?= e(url('dashboards/view.php?id=' . (int) $dashboardId)) ?>">Kembali</a></div>
<?php if ($error): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
<section class="panel" style="max-width:760px"><div class="panel-body"><form method="post"><?= csrf_field() ?><input type="hidden" name="dashboard_id" value="<?= e($dashboardId) ?>"><input type="hidden" name="widget_id" value="<?= e($widgetId) ?>">
    <div class="mb-3"><label class="form-label">Dataset</label><select class="form-select" name="dataset_id" required><?php foreach ($datasets as $dataset): ?><option value="<?= e($dataset['id']) ?>" <?= ((int) $dataset['id'] === $datasetId) ? 'selected' : '' ?>><?= e($dataset['name']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label">Judul</label><input class="form-control" name="title" maxlength="180" value="<?= e($title) ?>" required></div>
    <div class="mb-3"><label class="form-label">Jenis visual</label><select class="form-select" name="widget_type"><?php foreach (['metric'=>'Key metric','bar'=>'Bar chart','line'=>'Line / trend','table'=>'Data table','donut'=>'Donut (Pro+)'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $type === $value ? 'selected' : '' ?> <?= ($value === 'donut' && !plan_limits($workspace)['builder']) ? 'disabled' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label">Pertanyaan/metrik</label><input class="form-control" name="question" maxlength="1500" value="<?= e($question) ?>" required></div>
    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap"><p class="field-help mb-0">Angka selalu dihitung oleh QueryEngine; tanpa AI eksternal.</p><button class="btn btn-primary">Simpan perubahan</button></div>
</form></div></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
