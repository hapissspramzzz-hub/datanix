<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$aiConfig = require __DIR__ . '/../config/ai.php';
$user = require_login();
$workspace = require_permission('ask');
$workspaceId = (int) $workspace['id'];
$aiEnabled = ($aiConfig['provider'] ?? '') === 'openai-compatible' && !empty($aiConfig['api_base']) && !empty($aiConfig['api_key']);
$stmt = $GLOBALS['pdo']->prepare('SELECT id,name,row_count,schema_json FROM datasets WHERE workspace_id=? ORDER BY created_at DESC');
$stmt->execute([$workspaceId]);
$datasets = $stmt->fetchAll();
$selectedId = (int) ($_GET['dataset_id'] ?? ($datasets[0]['id'] ?? 0));
$validIds = array_map(static fn ($dataset) => (int) $dataset['id'], $datasets);
if ($selectedId && !in_array($selectedId, $validIds, true)) {
    $selectedId = (int) ($datasets[0]['id'] ?? 0);
}
$conversationId = filter_var($_GET['conversation_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$conversation = null;
$queryRows = [];
if ($conversationId) {
    $stmt = $GLOBALS['pdo']->prepare('SELECT * FROM conversations WHERE id=? AND workspace_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$conversationId, $workspaceId, $user['id']]);
    $conversation = $stmt->fetch() ?: null;
    if (!$conversation) {
        $conversationId = null;
    } else {
        if (!empty($conversation['dataset_id']) && in_array((int) $conversation['dataset_id'], $validIds, true)) {
            $selectedId = (int) $conversation['dataset_id'];
        }
        $stmt = $GLOBALS['pdo']->prepare('SELECT q.id,q.question,q.result_json,q.status,q.created_at FROM ai_queries q WHERE q.conversation_id=? AND q.workspace_id=? AND q.user_id=? ORDER BY q.created_at');
        $stmt->execute([$conversationId, $workspaceId, $user['id']]);
        $queryRows = $stmt->fetchAll();
    }
}
$stmt = $GLOBALS['pdo']->prepare('SELECT c.id,c.title,c.updated_at,d.name dataset_name FROM conversations c LEFT JOIN datasets d ON d.id=c.dataset_id WHERE c.workspace_id=? AND c.user_id=? ORDER BY c.updated_at DESC LIMIT 30');
$stmt->execute([$workspaceId, $user['id']]);
$history = $stmt->fetchAll();
$prefill = trim((string) ($_GET['question'] ?? ''));
$queryUsed = usage_count('ai_query', $workspaceId);
$queryLimit = plan_limits($workspace)['queries'];
$canSaveInsights = plan_limits($workspace)['saved_insights'];
$canCreateReports = plan_limits($workspace)['exports'];

function chat_result_html(array $result, int $queryId, bool $canSave, bool $canReport): string
{
    $out = '<div class="result-summary">' . e($result['answer'] ?? '') . '</div>';
    if (($result['status'] ?? '') === 'insufficient') {
        return '<div class="callout warning mt-2"><strong>' . e($result['answer'] ?? 'Saya belum bisa memastikan karena data yang tersedia tidak mencukupi.') . '</strong></div>';
    }
    if (!empty($result['metrics'])) {
        $out .= '<div class="result-metrics">';
        foreach ($result['metrics'] as $metric) {
            $value = $metric['value'] ?? null;
            if (is_numeric($value)) {
                $formatted = ($metric['kind'] ?? '') === 'percent'
                    ? format_number((float) $value, 2) . '%'
                    : (!empty($metric['currency']) ? format_money((float) $value, (string) $metric['currency']) : format_number((float) $value, 2));
            } else {
                $formatted = (string) ($value ?? '—');
            }
            $out .= '<div class="result-metric"><small>' . e($metric['label'] ?? '') . '</small><strong>' . e($formatted) . '</strong></div>';
        }
        $out .= '</div>';
    }
    foreach (($result['insights'] ?? []) as $insight) {
        $out .= '<div class="result-card"><strong class="small-caps">INSIGHT</strong><div class="small-muted mt-1">' . e($insight) . '</div></div>';
    }
    $chart = $result['chart'] ?? [];
    if (!empty($chart['labels'])) {
        $out .= '<div class="result-card"><strong class="small-caps">VISUALISASI</strong><div class="chart-canvas" data-chart="' . e(json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)) . '"></div></div>';
    }
    if (!empty($result['table'])) {
        $basis = $result['basis'] ?? [];
        $metricType = (string) ($basis['metric']['type'] ?? '');
        $isPercent = ($result['kind'] ?? '') === 'growth' || $metricType === 'ratio';
        $currency = $basis['currency'] ?? null;
        $out .= '<div class="result-card"><strong class="small-caps">DATA PENDUKUNG</strong><div class="table-wrap"><table class="data-table"><thead><tr><th>Grup / periode</th><th>Nilai</th><th>Baris</th></tr></thead><tbody>';
        foreach (array_slice($result['table'], 0, 20) as $row) {
            $value = $row['value'] ?? null;
            $formatted = is_numeric($value)
                ? ($isPercent ? format_number((float) $value, 2) . '%' : (!empty($currency) ? format_money((float) $value, (string) $currency) : format_number((float) $value, 2)))
                : (string) ($value ?? '—');
            $out .= '<tr><td>' . e($row['label'] ?? '') . '</td><td class="mono">' . e($formatted) . '</td><td>' . e(isset($row['rows']) ? format_number((int) $row['rows']) : '—') . '</td></tr>';
        }
        $out .= '</tbody></table></div></div>';
    }
    if (!empty($result['recommendations'])) {
        $out .= '<div class="result-card"><strong class="small-caps">NEXT STEPS</strong><ul class="small-muted mt-2 mb-0">';
        foreach ($result['recommendations'] as $recommendation) {
            $out .= '<li>' . e($recommendation) . '</li>';
        }
        $out .= '</ul></div>';
    }
    $basis = $result['basis'] ?? [];
    $out .= '<div class="result-basis">Sumber: ' . e($basis['dataset'] ?? 'dataset terpilih') . ' · ' . e(format_number((int) ($basis['rows'] ?? 0))) . ' / ' . e(format_number((int) ($basis['dataset_rows'] ?? 0))) . ' baris';
    if (!empty($basis['period'])) {
        $out .= ' · ' . e($basis['period']['start'] ?? '') . '—' . e($basis['period']['end'] ?? '');
    }
    if (!empty($basis['derived_metric'])) {
        $out .= ' · Derived: ' . e($basis['derived_metric']);
    }
    if (!empty($basis['currency'])) {
        $out .= ' · Currency: ' . e($basis['currency']);
    }
    $out .= ' · ' . e($result['ai_provider'] ?? 'deterministic') . '</div><div class="chat-result-actions">';
    $out .= $canSave
        ? '<button class="btn btn-light-outline btn-sm" data-save-insight data-query-id="' . e($queryId) . '" data-title="' . e($result['metrics'][0]['label'] ?? 'Insight') . '">Save Insight</button>'
        : '<a class="btn btn-light-outline btn-sm" href="' . e(url('plans.php')) . '">Save Insight · Pro</a>';
    $out .= $canReport
        ? '<a class="btn btn-light-outline btn-sm" href="' . e(url('reports/create.php?query_id=' . $queryId)) . '">Buat report</a>'
        : '<a class="btn btn-light-outline btn-sm" href="' . e(url('plans.php')) . '">Report · Pro</a>';
    return $out . '</div>';
}

require_once __DIR__ . '/../includes/header.php';
$layout = 'app';
datanix_header('AI Chat', 'chat', $layout);
?>
<div class="page-head"><div><div class="section-kicker">DATA ANALYST / <?= e($workspace['name']) ?></div><h1>Ask your data anything.</h1><p>Ajukan pertanyaan. Kalkulasi, periode, chart, serta tabel dihitung langsung dari sumber yang dipilih.</p></div><div class="page-head-actions"><span class="usage-pill" data-query-usage data-used="<?= e($queryUsed) ?>" data-limit="<?= e($queryLimit === -1 ? 'unlimited' : $queryLimit) ?>">AI Usage · <?= e(format_number($queryUsed)) ?> / <?= e($queryLimit === -1 ? '∞' : format_number($queryLimit)) ?> bulan ini</span><a class="btn btn-light-outline" href="<?= e(url('datasets/sources.php')) ?>">Add source</a></div></div>
<div class="ai-chat-layout">
    <section class="panel chat-panel">
        <div class="panel-head"><div><h2 class="panel-title">New analysis</h2><div class="panel-subtitle">Workspace <?= e($workspace['name']) ?> · <?= e($workspace['member_role']) ?></div></div>
            <form method="get"><select aria-label="Dataset aktif" class="form-select form-select-sm" name="dataset_id" onchange="this.form.submit()" style="max-width:260px" <?= $datasets ? '' : 'disabled' ?>><?php foreach ($datasets as $dataset): ?><option value="<?= e($dataset['id']) ?>" <?= ((int) $dataset['id'] === $selectedId) ? 'selected' : '' ?>><?= e($dataset['name']) ?> · <?= e(format_number((int) $dataset['row_count'])) ?> rows</option><?php endforeach; ?></select></form>
        </div>
        <div class="chat-messages" data-chat-messages>
            <?php if ($conversation): ?><div class="source-note">Percakapan: <?= e($conversation['title']) ?> · dataset terikat pada percakapan.</div><?php endif; ?>
            <?php foreach ($queryRows as $query): ?><div class="chat-bubble user"><?= e($query['question']) ?></div><div class="chat-bubble assistant"><?php $result = json_decode((string) $query['result_json'], true) ?: []; echo chat_result_html($result, (int) $query['id'], $canSaveInsights, $canCreateReports); ?></div><?php endforeach; ?>
            <?php if (!$queryRows): ?><div class="empty-state" style="margin:auto 0"><div class="empty-icon">✳</div><strong><?= $datasets ? 'Ask something about your data.' : 'Tambahkan dataset terlebih dahulu.' ?></strong><p><?= $datasets ? 'Hasil menyebut dataset, periode, dan baris yang diperiksa.' : 'Unggah CSV/XLSX/JSON atau muat sample UCI beratribusi untuk mencoba.' ?></p><?php if (!$datasets): ?><a class="btn btn-light-outline btn-sm" href="<?= e(url('datasets/upload.php')) ?>">Unggah dataset</a> <button class="btn btn-primary btn-sm" data-sample-load>Load UCI sample</button><?php endif; ?></div><?php endif; ?>
        </div>
        <?php if ($datasets): ?>
        <div class="prompt-suggestions"><span class="small-muted">Try:</span><button type="button" class="btn btn-light-outline btn-sm" data-suggest-text="revenue this month">Revenue this month</button><button type="button" class="btn btn-light-outline btn-sm" data-suggest-text="fastest-growing product">Fastest-growing product</button><button type="button" class="btn btn-light-outline btn-sm" data-suggest-text="year-over-year revenue">Year-over-year revenue</button></div>
        <form class="chat-composer" data-query-form data-consent-form data-ai-consent="<?= $user['ai_disclosure_at'] ? '1' : '0' ?>" data-dataset-id="<?= e($selectedId) ?>" data-conversation-id="<?= e($conversationId ?? '') ?>">
            <div class="w-100">
                <?php if ($aiEnabled && !$user['ai_disclosure_at']): ?><div class="ai-disclosure mb-2"><strong>Sebelum memakai narasi AI:</strong> teks pertanyaan dan fakta agregat diperlukan akan dikirim ke provider yang dikonfigurasi. File mentah, seluruh baris dataset, dan credential tidak dikirim. <a class="link-brand" href="<?= e(url('privacy.php')) ?>">Detail privasi</a><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="ai_consent" id="ai_consent" value="1" required><label class="form-check-label" for="ai_consent">Saya membaca dan menyetujui pemrosesan tersebut.</label></div></div>
                <?php elseif (!$aiEnabled): ?><div class="small-muted mb-2">Provider AI belum dikonfigurasi; kalkulasi deterministik tetap tersedia dan tidak ada data yang dikirim ke model.</div><?php endif; ?>
                <div class="d-flex gap-2"><textarea class="form-control" name="question" rows="2" maxlength="1500" placeholder="What would you like to know?" required><?= e($prefill) ?></textarea><button class="btn btn-primary" type="submit">Ask <span>↗</span></button></div>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <aside>
        <section class="panel"><div class="panel-head"><div><h2 class="panel-title">Conversation history</h2><div class="panel-subtitle">Private to your Datanix account</div></div></div>
            <?php if ($history): ?><div class="panel-body" style="padding-top:0"><?php foreach ($history as $item): ?><a class="d-block p-2 rounded mb-1 <?= $conversationId === (int) $item['id'] ? 'bg-light' : '' ?>" href="<?= e(url('chat/index.php?conversation_id=' . $item['id'])) ?>"><strong style="font-size:10px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($item['title']) ?></strong><small class="text-subtle" style="font-size:9px"><?= e($item['dataset_name'] ?? 'Dataset dihapus') ?> · <?= e(date('d M H:i', strtotime($item['updated_at']))) ?></small></a><?php endforeach; ?></div>
            <?php else: ?><div class="panel-body"><div class="empty-state"><strong>Belum ada percakapan.</strong><p>Setiap pertanyaan menampilkan bukti dari dataset.</p></div></div><?php endif; ?>
        </section>
        <section class="panel mt-3"><div class="panel-body"><h2 class="panel-title">How numbers work</h2><p class="small-muted mt-2">Aggregation, ranking, comparison, dates, charts and table values are computed by PHP. AI may supply qualitative commentary only after your consent.</p><div class="source-note">Jika kolom atau rentang data tidak ada, respons: “Saya belum bisa memastikan karena data yang tersedia tidak mencukupi.”</div></div></section>
    </aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
