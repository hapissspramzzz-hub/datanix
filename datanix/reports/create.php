<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/QueryEngine.php';
require_once __DIR__ . '/../includes/AIService.php';

$user = require_login();
$workspace = require_permission('build_dashboard');
$db = $GLOBALS['pdo'];
if (!plan_limits($workspace)['exports']) {
    http_response_code(403);
    $title = 'Report Generator';
    require_once __DIR__ . '/../includes/header.php';
    $layout = 'app';
    datanix_header($title, 'reports', $layout);
    ?>
    <div class="page-head"><div><div class="section-kicker">PRO FEATURE</div><h1>Report Generator</h1><p>Report exports are available on Pro and Business.</p></div></div>
    <div class="notice-soft">Paket Free tetap dapat memakai dashboard dasar. Tidak ada pembayaran yang diproses di situs ini. <a href="<?= e(url('plans.php')) ?>">Lihat paket</a>.</div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$queryId = filter_var($_GET['query_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$savedQuery = null;
if ($queryId) {
    $stmt = $db->prepare('SELECT dataset_id,question FROM ai_queries WHERE id=? AND workspace_id=? AND user_id=? AND status="success" LIMIT 1');
    $stmt->execute([$queryId, $workspace['id'], $user['id']]);
    $savedQuery = $stmt->fetch() ?: null;
}
$stmt = $db->prepare('SELECT id,name,row_count,schema_json,is_sample,source_citation,source_license FROM datasets WHERE workspace_id=? ORDER BY name');
$stmt->execute([$workspace['id']]);
$datasets = $stmt->fetchAll();
$selectedDataset = (int) ($_POST['dataset_id'] ?? ($_GET['dataset_id'] ?? ($savedQuery['dataset_id'] ?? 0)));
$selectedYear = (string) ($_POST['year'] ?? '');
$fromDate = trim((string) ($_POST['from_date'] ?? ''));
$toDate = trim((string) ($_POST['to_date'] ?? ''));
$questionValue = (string) ($_POST['question'] ?? ($savedQuery['question'] ?? 'Tampilkan tren pendapatan per bulan'));
$titleValue = (string) ($_POST['title'] ?? 'Laporan analisis data');
$years = [];
foreach ($datasets as $dataset) {
    if ((int) $dataset['id'] !== $selectedDataset) {
        continue;
    }
    $schema = json_decode((string) $dataset['schema_json'], true) ?: [];
    $dateColumn = null;
    foreach ($schema as $column) {
        if (preg_match('/date|time|tanggal|waktu|period|year/i', (string) ($column['name'] ?? ''))) {
            $dateColumn = (string) $column['name'];
            break;
        }
    }
    if ($dateColumn) {
        $stmt = $db->prepare('SELECT row_json FROM dataset_rows WHERE dataset_id=? ORDER BY `row_number` LIMIT 10000');
        $stmt->execute([$selectedDataset]);
        while ($raw = $stmt->fetchColumn()) {
            $row = json_decode((string) $raw, true) ?: [];
            $value = $row[$dateColumn] ?? null;
            if ($value && ($timestamp = strtotime((string) $value)) !== false) {
                $years[(int) date('Y', $timestamp)] = true;
            }
        }
        krsort($years);
        $years = array_keys($years);
    }
    break;
}
$errors = [];
$aiConfig = require __DIR__ . '/../config/ai.php';
$aiConfigured = ($aiConfig['provider'] ?? '') === 'openai-compatible' && !empty($aiConfig['api_key']) && !empty($aiConfig['api_base']);
$queryLimit = plan_limits($workspace)['queries'];
$queryUsed = usage_count('ai_query', (int) $workspace['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $datasetId = filter_var($_POST['dataset_id'] ?? null, FILTER_VALIDATE_INT);
    $title = trim((string) ($_POST['title'] ?? ''));
    $question = trim((string) ($_POST['question'] ?? ''));
    $year = trim((string) ($_POST['year'] ?? ''));
    $from = trim((string) ($_POST['from_date'] ?? ''));
    $to = trim((string) ($_POST['to_date'] ?? ''));
    if (!$datasetId || $title === '' || mb_strlen($title, 'UTF-8') > 220 || $question === '' || mb_strlen($question, 'UTF-8') > 1500) {
        $errors[] = 'Lengkapi judul, dataset dan pertanyaan (maksimal 1.500 karakter).';
    }
    if ($year !== '' && (!preg_match('/^(19|20)\d{2}$/', $year) || !in_array((int) $year, $years, true))) {
        $errors[] = 'Pilih tahun yang tersedia di dataset.';
    }
    $validDate = static function (string $date): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    };
    $hasFrom = $from !== '';
    $hasTo = $to !== '';
    if ($hasFrom !== $hasTo || ($hasFrom && (!$validDate($from) || !$validDate($to) || $from > $to))) {
        $errors[] = 'Rentang tanggal harus lengkap, valid, dan berurutan.';
    }
    $dataset = null;
    if (!$errors) {
        $stmt = $db->prepare('SELECT id,name,row_count,schema_json,source_citation,is_sample FROM datasets WHERE id=? AND workspace_id=? LIMIT 1');
        $stmt->execute([$datasetId, $workspace['id']]);
        $dataset = $stmt->fetch();
        if (!$dataset) {
            $errors[] = 'Dataset tidak ditemukan dalam workspace aktif.';
        }
    }
    if (!$errors && $queryLimit !== -1 && $queryUsed >= $queryLimit) {
        $errors[] = 'AI usage limit reached. Upgrade your plan to continue.';
    }
    if (!$errors) {
        if ($from !== '' && $to !== '') {
            $question .= ' from ' . $from . ' to ' . $to;
        } elseif ($year !== '') {
            $question .= ' ' . $year;
        }
        $remoteAllowed = $aiConfigured && !empty($user['ai_disclosure_at']);
        DatanixAI::setRemoteEnabled($remoteAllowed);
        $result = DatanixQueryEngine::analyze((int) $workspace['id'], (int) $datasetId, $question, null);
        if (($result['status'] ?? '') !== 'success') {
            $errors[] = $result['answer'] ?? 'Saya belum bisa memastikan karena data yang tersedia tidak mencukupi.';
        } else {
            $periodStart = $result['basis']['period']['start'] ?? ($year !== '' ? $year . '-01-01' : null);
            $periodEnd = $result['basis']['period']['end'] ?? ($year !== '' ? $year . '-12-31' : null);
            $summary = (string) ($result['answer'] ?? '');
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('INSERT INTO reports(workspace_id,dataset_id,created_by,title,period_start,period_end,summary,result_json,format) VALUES(?,?,?,?,?,?,?, ?,"html")');
                $stmt->execute([$workspace['id'], $datasetId, $user['id'], $title, $periodStart, $periodEnd, $summary, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
                $reportId = (int) $db->lastInsertId();
                $db->prepare('INSERT INTO usage_logs(workspace_id,user_id,usage_type,units,month_key,metadata_json) VALUES(?,? ,"ai_query",1,?,?)')->execute([$workspace['id'], $user['id'], date('Y-m'), json_encode(['report_id' => $reportId, 'kind' => $result['kind'] ?? null])]);
                $db->commit();
                audit_log('report.created', 'report', $reportId, ['dataset_id' => (int) $datasetId, 'period_start' => $periodStart, 'period_end' => $periodEnd, 'provider' => $result['ai_provider'] ?? 'deterministic']);
                redirect('reports/print.php?id=' . $reportId);
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Datanix report generation failed: ' . $exception->getMessage());
                $errors[] = 'Report belum dapat disimpan. Periksa koneksi database dan ulangi.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
$layout = 'app';
datanix_header('Create report', 'reports', $layout);
?>
<div class="page-head"><div><div class="section-kicker">REPORTS / NEW</div><h1>Create a data-backed report</h1><p>Pilih pertanyaan yang dapat dihitung dan rentang yang benar-benar tersedia dalam dataset.</p></div><div class="page-head-actions"><a class="btn btn-light-outline" href="<?= e(url('reports/index.php')) ?>">← Reports</a></div></div>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
<?php if (!$datasets): ?><div class="notice-soft warning">Tambahkan dataset sebelum membuat report. <a href="<?= e(url('datasets/upload.php')) ?>">Unggah file</a>.</div>
<?php else: ?>
<form class="panel" method="post"><?= csrf_field() ?><div class="panel-body row g-3">
    <div class="col-12"><label class="form-label" for="report-title">Judul report</label><input id="report-title" class="form-control" name="title" maxlength="220" value="<?= e($titleValue) ?>" required></div>
    <div class="col-md-6"><label class="form-label" for="report-dataset">Dataset</label><select id="report-dataset" class="form-select" name="dataset_id" required onchange="location.href='?dataset_id='+this.value"><option value="">Pilih dataset</option><?php foreach ($datasets as $dataset): ?><option value="<?= e($dataset['id']) ?>" <?= $selectedDataset === (int) $dataset['id'] ? 'selected' : '' ?>><?= e($dataset['name']) ?> · <?= e(format_number((int) $dataset['row_count'])) ?> rows</option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label" for="report-year">Shortcut tahun</label><select id="report-year" class="form-select" name="year"><option value="">Semua periode</option><?php foreach ($years as $year): ?><option value="<?= e($year) ?>" <?= $selectedYear === (string) $year ? 'selected' : '' ?>><?= e($year) ?></option><?php endforeach; ?></select><div class="field-help">Tahun hanya tersedia jika ditemukan dalam kolom tanggal.</div></div>
    <div class="col-md-6"><label class="form-label" for="report-from">Dari tanggal</label><input id="report-from" class="form-control" type="date" name="from_date" value="<?= e($fromDate) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="report-to">Sampai tanggal</label><input id="report-to" class="form-control" type="date" name="to_date" value="<?= e($toDate) ?>"></div>
    <div class="col-12"><label class="form-label" for="report-question">Pertanyaan / analisis</label><textarea id="report-question" class="form-control" name="question" rows="3" maxlength="1500" required><?= e($questionValue) ?></textarea><div class="field-help">Contoh: total revenue; jumlah transaksi; produk dengan penjualan tertinggi; tren pendapatan per bulan; anomaly revenue. Profit hanya dapat dianalisis jika kolom laba tersedia.</div></div>
    <div class="col-12"><div class="notice-soft">Angka, chart, pembanding, tabel dan rekomendasi terukur dihitung dari dataset. <?= $aiConfigured ? (!empty($user['ai_disclosure_at']) ? 'Persetujuan AI Anda tercatat; narasi kualitatif dapat digunakan.' : 'AI eksternal belum disetujui; narasi tetap lokal.') : 'Provider AI belum dikonfigurasi; proses tetap deterministik dan lokal.' ?> Satu report memakai satu query dari kuota workspace.</div></div>
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2"><span class="usage-pill">AI Usage · <?= e(format_number($queryUsed)) ?> / <?= e($queryLimit === -1 ? '∞' : format_number($queryLimit)) ?></span><button class="btn btn-primary">Generate report</button></div>
</div></form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
