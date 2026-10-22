<?php
declare(strict_types=1);

final class DatanixAI
{
    private static bool $remoteEnabled = true;

    public static function setRemoteEnabled(bool $enabled): void
    {
        self::$remoteEnabled = $enabled;
    }

    private static function fallback(): array
    {
        return [
            'answer' => 'Angka, grafik, dan tabel di bawah dihitung langsung dari baris dataset terpilih. Ringkasan AI tidak digunakan untuk menentukan nilainya.',
            'insights' => [],
            'recommendations' => ['Periksa kolom, periode, dan baris yang ditampilkan sebelum mengambil keputusan.'],
            'provider' => 'deterministic',
            'model' => null,
        ];
    }

    private static function safeFacts(array $facts): array
    {
        $metrics = [];
        foreach (($facts['metrics'] ?? []) as $metric) {
            if (!is_array($metric) || !in_array($metric['kind'] ?? '', ['number', 'percent'], true) || !is_numeric($metric['value'] ?? null)) {
                continue;
            }
            $metrics[] = [
                'measure' => mb_substr((string) ($metric['label'] ?? 'measure'), 0, 80, 'UTF-8'),
                'value' => (float) $metric['value'],
                'kind' => (string) $metric['kind'],
            ];
        }
        $diagnostic = [];
        $allowedFactors = ['price', 'discount', 'rating', 'stock', 'margin'];
        foreach (array_slice(is_array($facts['diagnostic'] ?? null) ? $facts['diagnostic'] : [], 0, 5) as $item) {
            if (!is_array($item) || !in_array($item['factor'] ?? '', $allowedFactors, true)
                || !is_numeric($item['low_performance_average'] ?? null)
                || !is_numeric($item['high_performance_average'] ?? null)
                || !is_numeric($item['relative_gap_percent'] ?? null)) {
                continue;
            }
            $diagnostic[] = [
                'factor' => (string) $item['factor'],
                'low_performance_group_average' => (float) $item['low_performance_average'],
                'high_performance_group_average' => (float) $item['high_performance_average'],
                'higher_for_low_performers' => (bool) ($item['higher_for_low_performers'] ?? false),
                'relative_gap_percent' => (float) $item['relative_gap_percent'],
                'groups_with_data' => (int) ($item['groups_with_data'] ?? 0),
            ];
        }
        return [
            'analysis_type' => (string) ($facts['kind'] ?? 'aggregate'),
            'matched_row_count' => (int) ($facts['row_count'] ?? 0),
            'source_row_count' => (int) ($facts['dataset_total_rows'] ?? 0),
            'has_time_filter' => !empty($facts['period']),
            'time_range' => isset($facts['period']['start'], $facts['period']['end'])
                ? ['start' => (string) $facts['period']['start'], 'end' => (string) $facts['period']['end']]
                : null,
            'measures' => $metrics,
            'has_grouped_result' => !empty($facts['table']),
            'performance_factor_comparisons' => $diagnostic,
        ];
    }

    private static function cleanText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim(strip_tags($value));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        if ($text === '' || mb_strlen($text, 'UTF-8') > 480 || preg_match('/\d|%|(?:rp|idr|usd|gbp)\b/iu', $text)) {
            return null;
        }
        return $text;
    }

    public static function narrate(string $question, array $facts): array
    {
        $config = require __DIR__ . '/../config/ai.php';
        if (!self::$remoteEnabled || ($config['provider'] ?? '') !== 'openai-compatible' || empty($config['api_key']) || empty($config['api_base'])) {
            return self::fallback();
        }
        $base = rtrim((string) $config['api_base'], '/');
        $parts = parse_url($base);
        if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            error_log('Datanix AI provider disabled because AI_API_BASE is not a valid HTTPS endpoint.');
            return self::fallback();
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                'insights' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3],
                'recommendations' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3],
            ],
            'required' => ['answer', 'insights', 'recommendations'],
            'additionalProperties' => false,
        ];
        $messages = [
            [
                'role' => 'system',
                'content' => 'Anda asisten analis data Datanix. Anda memberi penjelasan sederhana dan ringkas hanya dari fakta agregat yang disediakan. Jangan mengulang angka, tanggal, persentase, mata uang, peringkat, nama dataset, nilai grup, nama kolom, atau teks PII; aplikasi menampilkan bukti itu secara lokal. Untuk diagnosis penjualan, bandingkan hanya faktor yang tersedia pada performance_factor_comparisons dan jelaskan sebagai pola/korelasi yang mungkin terkait, bukan penyebab yang terbukti. Jangan mengarang faktor yang tidak ada pada data. Jika tidak ada faktor pembanding yang mendukung, katakan data belum cukup untuk menjelaskan alasan. Sebutkan keterbatasan jika perlu. Jawab Bahasa Indonesia, tanpa Markdown, dan output wajib JSON sesuai schema.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'question' => mb_substr($question, 0, 1500, 'UTF-8'),
                    'verified_aggregate_facts' => self::safeFacts($facts),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ],
        ];
        $payload = [
            'model' => (string) $config['model'],
            'messages' => $messages,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'datanix_qualitative_insight',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            'max_completion_tokens' => 700,
        ];
        $curl = curl_init($base . '/chat/completions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . (string) $config['api_key'],
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => (int) $config['timeout_seconds'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => 200_000,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status < 200 || $status >= 300 || strlen($body) > 200_000) {
            error_log('Datanix AI provider unavailable (HTTP ' . $status . '): ' . substr($error, 0, 160));
            return self::fallback();
        }
        try {
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $content = $response['choices'][0]['message']['content'] ?? '';
            if (is_array($content)) {
                $content = $content[0]['text'] ?? '';
            }
            $data = json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
            $answer = self::cleanText($data['answer'] ?? null);
            $insights = array_values(array_filter(array_map([self::class, 'cleanText'], is_array($data['insights'] ?? null) ? $data['insights'] : [])));
            $recommendations = array_values(array_filter(array_map([self::class, 'cleanText'], is_array($data['recommendations'] ?? null) ? $data['recommendations'] : [])));
            if (!$answer) {
                return self::fallback();
            }
            return [
                'answer' => $answer,
                'insights' => array_slice($insights, 0, 3),
                'recommendations' => array_slice($recommendations, 0, 3),
                'provider' => 'openai-compatible',
                'model' => (string) $config['model'],
            ];
        } catch (Throwable $exception) {
            error_log('Datanix AI response rejected: ' . $exception->getMessage());
            return self::fallback();
        }
    }
}
