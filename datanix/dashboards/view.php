<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/QueryEngine.php';
require_once __DIR__ . '/../includes/AIService.php';

$workspace = require_permission('view');
$dashboardId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$stmt = $GLOBALS['pdo']->prepare('SELECT * FROM dashboards WHERE id=? AND workspace_id=? LIMIT 1');
$stmt->execute([$dashboardId, $workspace['id']]);
$dashboard = $stmt->fetch();
if (!$dashboard) {
    http_response_code(404);
    exit('Dashboard tidak ditemukan.');
}
$stmt = $GLOBALS['pdo']->prepare('SELECT w.*,d.name dataset_name,d.row_count dataset_rows FROM dashboard_widgets w LEFT JOIN datasets d ON d.id=w.dataset_id WHERE w.dashboard_id=? AND EXISTS(SELECT 1 FROM dashboards b WHERE b.id=w.dashboard_id AND b.workspace_id=?) ORDER BY w.sort_order,w.position_y,w.position_x');
$stmt->execute([$dashboard['id'], $workspace['id']]);
$widgets = $stmt->fetchAll();
$stmt = $GLOBALS['pdo']->prepare('SELECT id,name FROM datasets WHERE workspace_id=? ORDER BY name');
$stmt->execute([$workspace['id']]);
$datasets = $stmt->fetchAll();

$yearOptions = [];
$baseDateColumn = null;
if ($dashboard['dataset_id']) {
    $stmt = $GLOBALS['pdo']->prepare('SELECT schema_json FROM datasets WHERE id=? AND workspace_id=?');
    $stmt->execute([$dashboard['dataset_id'], $workspace['id']]);
    $schema = json_decode((string) $stmt->fetchColumn(), true) ?: [];
    foreach ($schema as $column) {
        if (preg_match('/date|time|tanggal|waktu|period|month|year/i', (string) ($column['name'] ?? ''))) {
            $baseDateColumn = (string) $column['name'];
            break;
        }
    }
    if ($baseDateColumn) {
        $stmt = $GLOBALS['pdo']->prepare('SELECT row_json FROM dataset_rows WHERE dataset_id=? ORDER BY `row_number` LIMIT 10000');
        $stmt->execute([$dashboard['dataset_id']]);
        while ($raw = $stmt->fetchColumn()) {
            $row = json_decode((string) $raw, true) ?: [];
            $value = $row[$baseDateColumn] ?? null;
            if ($value && ($time = strtotime((string) $value)) !== false) {
                $yearOptions[(int) date('Y', $time)] = true;
            }
        }
        krsort($yearOptions);
        $yearOptions = array_keys($yearOptions);
    }
}

$period = (string) ($_GET['year'] ?? 'all');
if ($period !== 'all' && (!preg_match('/^(19|20)\d{2}$/', $period) || !in_array((int) $period, $yearOptions, true))) {
    $period = 'all';
}
$fromInput = trim((string) ($_GET['from'] ?? ''));
$toInput = trim((string) ($_GET['to'] ?? ''));
$fromDate = $toDate = null;
$rangeError = '';
if ($fromInput !== '' || $toInput !== '') {
    $fromParts = explode('-', $fromInput);
    $toParts = explode('-', $toInput);
    $fromValid = count($fromParts) === 3 && checkdate((int) $fromParts[1], (int) $fromParts[2], (int) $fromParts[0]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput);
    $toValid = count($toParts) === 3 && checkdate((int) $toParts[1], (int) $toParts[2], (int) $toParts[0]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput);
    if (!$fromValid || !$toValid || $fromInput > $toInput) {
        $rangeError = 'Pilih rentang tanggal lengkap dengan tanggal awal tidak melebihi tanggal akhir.';
    } else {
        $fromDate = $fromInput;
        $toDate = $toInput;
    }
}

$layoutEnabled = plan_limits($workspace)['builder'];
$results = [];
DatanixAI::setRemoteEnabled(false);
foreach ($widgets as $widget) {
    $config = json_decode((string) $widget['config_json'], true) ?: [];
    $datasetId = (int) ($widget['dataset_id'] ?: $dashboard['dataset_id']);
    $question = trim((string) ($config['question'] ?? ''));
    if ($fromDate && $toDate) {
        $question .= ' from ' . $fromDate . ' to ' . $toDate;
    } elseif ($period !== 'all') {
        $question .= ' ' . $period;
    }
    if ($question !== '' && $datasetId > 0) {
        $result = DatanixQueryEngine::analyze((int) $workspace['id'], $datasetId, $question);
        if (($result['status'] ?? '') === 'success') {
            if (in_array($widget['widget_type'], ['bar','line','donut'], true) && !empty($result['chart']['labels'])) {
                $result['chart']['type'] = $widget['widget_type'];
            } elseif ($widget['widget_type'] === 'metric') {
                $result['chart']['labels'] = [];
                $result['chart']['values'] = [];
            }
        }
        $results[(int) $widget['id']] = $result;
    }
}

require_once __DIR__ . '/../includes/header.php';
$layout = 'app';
datanix_header('Dashboard', 'dashboards', $layout);
?>
<div class="page-head">
    <div><div class="section-kicker">DASHBOARD / <?= e($workspace['name']) ?></div><h1><?= e($dashboard['name']) ?></h1><p><?= e($dashboard['description'] ?: 'Visualisasi dihitung dari dataset workspace.') ?></p></div>
    <div class="page-head-actions">
        <form method="get" class="date-range">
            <input type="hidden" name="id" value="<?= e($dashboard['id']) ?>">
            <label class="small-muted" for="dashboard-year">Tahun</label>
            <select id="dashboard-year" class="form-select form-select-sm" name="year"><option value="all">Semua</option><?php foreach ($yearOptions as $year): ?><option value="<?= e($year) ?>" <?= $period === (string) $year ? 'selected' : '' ?>><?= e($year) ?></option><?php endforeach; ?></select>
            <label class="small-muted" for="dashboard-from">Dari</label><input id="dashboard-from" type="date" class="form-control form-control-sm" name="from" value="<?= e($fromInput) ?>">
            <label class="small-muted" for="dashboard-to">Sampai</label><input id="dashboard-to" type="date" class="form-control form-control-sm" name="to" value="<?= e($toInput) ?>">
            <button class="btn btn-light-outline btn-sm">Terapkan</button>
            <?php if ($period !== 'all' || $fromInput || $toInput): ?><a class="btn btn-light-outline btn-sm" href="<?= e(url('dashboards/view.php?id=' . (int) $dashboard['id'])) ?>">Reset</a><?php endif; ?>
        </form>
        <?php if (can('build_dashboard', $workspace)): ?><button class="btn btn-primary" data-inline-edit="add-widget">+ Add widget</button><?php endif; ?>
    </div>
</div>
<?php if ($rangeError): ?><div class="alert alert-warning"><?= e($rangeError) ?></div><?php elseif ($fromDate && $toDate): ?><div class="source-note mb-3">Rentang tanggal <?= e($fromDate) ?>—<?= e($toDate) ?> diterapkan pada setiap widget yang memiliki kolom tanggal.</div><?php elseif ($period !== 'all'): ?><div class="source-note mb-3">Filter tahun <?= e($period) ?> diterapkan pada setiap widget yang memiliki kolom tanggal.</div><?php endif; ?>
<?php if ($widgets): ?>
<div class="widget-grid" data-widget-grid data-dashboard-id="<?= e($dashboard['id']) ?>" data-layout-editable="<?= $layoutEnabled ? '1' : '0' ?>">
    <?php foreach ($widgets as $widget):
        $result = $results[(int) $widget['id']] ?? null;
        $span = (int) $widget['width_units'] >= 12 ? 'span-12' : '';
        $config = json_decode((string) $widget['config_json'], true) ?: [];
    ?>
    <section class="panel dashboard-widget <?= e($span) ?>" data-widget-id="<?= e($widget['id']) ?>" draggable="<?= $layoutEnabled ? 'true' : 'false' ?>">
        <div class="widget-head"><div><strong><?= e($widget['title']) ?></strong><div class="panel-subtitle"><?= e($widget['dataset_name'] ?? 'Pilih dataset') ?><?php if ($widget['dataset_rows']): ?> · <?= e(format_number((int) $widget['dataset_rows'])) ?> rows<?php endif; ?></div></div>
            <div class="widget-controls">
                <?php if ($layoutEnabled): ?><button type="button" class="widget-size" data-widget-resize title="Ubah lebar widget">Resize</button><?php endif; ?>
                <?php if (can('build_dashboard', $workspace)): ?><a class="btn btn-light-outline btn-sm" href="<?= e(url('dashboards/widget-edit.php?dashboard_id=' . (int) $dashboard['id'] . '&widget_id=' . (int) $widget['id'])) ?>" aria-label="Edit <?= e($widget['title']) ?>">Edit</a><form method="post" action="<?= e(url('dashboards/widget-delete.php')) ?>" data-confirm="Hapus widget ini?"><?= csrf_field() ?><input type="hidden" name="widget_id" value="<?= e($widget['id']) ?>"><input type="hidden" name="dashboard_id" value="<?= e($dashboard['id']) ?>"><button class="btn btn-light-outline btn-sm" aria-label="Hapus widget">×</button></form><?php endif; ?>
            </div>
        </div>
        <div class="widget-body">
            <div class="small-muted mb-2"><strong>Question:</strong> <?= e($config['question'] ?? '') ?></div>
            <?php if (!$result): ?><div class="empty-state"><strong>Belum ada dataset atau pertanyaan.</strong><p>Ubah widget untuk menghubungkan query ke dataset.</p></div>
            <?php elseif (($result['status'] ?? '') === 'insufficient'): ?><div class="callout warning"><?= e($result['answer']) ?></div>
            <?php else: ?>
                <?php if ($widget['widget_type'] !== 'table' && !empty($result['metrics'])): ?><div class="analysis-kpis"><?php foreach (array_slice($result['metrics'], 0, 4) as $metric): $value = $metric['value'] ?? null; $display = is_numeric($value) ? (($metric['kind'] ?? '') === 'percent' ? format_number((float) $value, 2) . '%' : (!empty($metric['currency']) ? format_money((float) $value, (string) $metric['currency']) : format_number((float) $value, 2))) : ($value ?? '—'); ?><div class="analysis-kpi"><small><?= e($metric['label'] ?? '') ?></small><b><?= e($display) ?></b></div><?php endforeach; ?></div><?php endif; ?>
                <?php if (in_array($widget['widget_type'], ['bar','line','donut'], true) && !empty($result['chart']['labels'])): ?><div class="analysis-chart" data-chart="<?= e(json_encode($result['chart'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"></div><?php endif; ?>
                <?php if (in_array($widget['widget_type'], ['table','bar','line','donut'], true) && !empty($result['table'])): ?><div class="table-wrap"><table class="table table-sm analysis-table"><thead><tr><th>Group</th><th>Value</th><?php if (isset($result['table'][0]['rows'])): ?><th>Rows</th><?php endif; ?></tr></thead><tbody><?php foreach (array_slice($result['table'], 0, 12) as $row): ?><tr><td><?= e($row['label'] ?? '') ?></td><td class="mono"><?= e(is_numeric($row['value'] ?? null) ? format_number((float) $row['value'], 2) : ($row['value'] ?? '—')) ?></td><?php if (isset($result['table'][0]['rows'])): ?><td><?= e(format_number((int) ($row['rows'] ?? 0))) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                <?php if (!empty($result['insights'])): ?><div class="notice-soft mt-2"><strong>Insight</strong><?php foreach ($result['insights'] as $insight): ?><div><?= e($insight) ?></div><?php endforeach; ?></div><?php endif; ?>
                <?php if (!empty($result['recommendations'])): ?><div class="source-note mt-2"><strong>Recommendation</strong><?php foreach ($result['recommendations'] as $recommendation): ?><div><?= e($recommendation) ?></div><?php endforeach; ?></div><?php endif; ?>
                <div class="analysis-basis">Source: <?= e($result['basis']['dataset'] ?? $widget['dataset_name'] ?? '') ?> · <?= e(format_number((int) ($result['basis']['rows'] ?? 0))) ?> / <?= e(format_number((int) ($result['basis']['dataset_rows'] ?? 0))) ?> rows · deterministic calculation.<?php if (!empty($result['basis']['period']['start'])): ?> · <?= e($result['basis']['period']['start']) ?>—<?= e($result['basis']['period']['end']) ?><?php endif; ?></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel"><div class="panel-body"><div class="empty-state"><div class="empty-icon">▥</div><strong>Belum ada widget pada dashboard ini.</strong><p>Tambahkan pertanyaan spesifik yang terhubung dengan metrik dan kolom dataset.</p><?php if (can('build_dashboard', $workspace)): ?><button class="btn btn-primary btn-sm" data-inline-edit="add-widget">Add a data widget</button><?php endif; ?></div></div></div>
<?php endif; ?>
<?php if (can('build_dashboard', $workspace)): ?>
<section class="panel mt-3" id="add-widget" hidden><div class="panel-head"><div><h2 class="panel-title">Add a data widget</h2><div class="panel-subtitle">Metrik dihitung dari baris yang terpilih—tanpa angka contoh.</div></div></div><div class="panel-body"><form method="post" action="<?= e(url('dashboards/widget-create.php')) ?>" class="row g-2"><?= csrf_field() ?><input type="hidden" name="dashboard_id" value="<?= e($dashboard['id']) ?>">
    <div class="col-md-4"><label class="form-label">Dataset</label><select class="form-select" name="dataset_id" required><?php foreach ($datasets as $dataset): ?><option value="<?= e($dataset['id']) ?>" <?= ((int) $dataset['id'] === (int) $dashboard['dataset_id']) ? 'selected' : '' ?>><?= e($dataset['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Widget title</label><input class="form-control" name="title" maxlength="180" placeholder="Contoh: Product revenue" required></div>
    <div class="col-md-4"><label class="form-label">Chart type</label><select class="form-select" name="widget_type"><option value="metric">Key metric</option><option value="bar">Bar chart</option><option value="line">Line / trend</option><option value="table">Data table</option><option value="donut" <?= $layoutEnabled ? '' : 'disabled' ?>>Donut (Pro+)</option></select></div>
    <div class="col-12"><label class="form-label">Question / metric to calculate</label><input class="form-control" name="question" maxlength="1500" placeholder="e.g. total quantity by product, top countries by revenue, monthly order trend" required></div>
    <div class="col-12 d-flex justify-content-between align-items-center"><p class="small-muted m-0">Widget dihitung secara deterministik; tidak memanggil AI eksternal.</p><button class="btn btn-primary">Add widget</button></div>
</form></div></section>
<?php endif; ?>
<script src="<?= e(url('assets/js/dashboard.js')) ?>"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
