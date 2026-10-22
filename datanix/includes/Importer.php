<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class DatanixImporter
{
    public const MAX_BYTES = 8_000_000;
    public const MAX_ROWS = 10_000;
    public const MAX_COLUMNS = 100;

    public static function importUpload(int $workspaceId, int $userId, array $file, string $name = ''): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Unggahan gagal atau terlalu besar.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Ukuran file harus antara 1 byte dan 8 MB.');
        }
        $filename = basename((string) ($file['name'] ?? 'dataset.csv'));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'json'], true)) {
            throw new RuntimeException('Format yang didukung: CSV, XLSX, dan JSON.');
        }
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        if (!is_file($temporaryPath)) {
            throw new RuntimeException('File sementara tidak ditemukan.');
        }
        self::validateFile($temporaryPath, $extension);
        $directory = APP_ROOT . '/storage/private';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Folder penyimpanan privat tidak tersedia.');
        }
        $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
        $storedPath = $directory . '/' . $storedName;
        if (!move_uploaded_file($temporaryPath, $storedPath) && !copy($temporaryPath, $storedPath)) {
            throw new RuntimeException('File tidak dapat disimpan.');
        }
        @chmod($storedPath, 0600);
        try {
            $parsed = self::parse($storedPath, $extension);
            return self::createDataset(
                $workspaceId,
                $userId,
                $parsed,
                $filename,
                $extension,
                $name !== '' ? $name : pathinfo($filename, PATHINFO_FILENAME),
                null,
                null,
                $storedPath,
                null
            );
        } catch (Throwable $exception) {
            @unlink($storedPath);
            throw $exception;
        }
    }

    public static function importUciSample(int $workspaceId, int $userId): int
    {
        $samplePath = APP_ROOT . '/samples/uci-online-retail.csv';
        if (!is_file($samplePath)) {
            throw new RuntimeException('File contoh UCI belum tersedia.');
        }
        $parsed = self::parse($samplePath, 'csv');
        return self::createDataset(
            $workspaceId,
            $userId,
            $parsed,
            'uci-online-retail.csv',
            'csv',
            'UCI Online Retail · 5.000 transaksi historis',
            'CC BY 4.0',
            'Chen, D. (2015). Online Retail [Dataset]. UCI Machine Learning Repository. https://doi.org/10.24432/C5BW33. Historical data from 2010-12-01 to 2011-12-09; UnitPrice in GBP.',
            null,
            null
        );
    }

    public static function importExternalRows(
        int $workspaceId,
        int $userId,
        array $records,
        string $sourceType,
        string $sourceName,
        array $connection
    ): int {
        if (!in_array($sourceType, ['mysql', 'pgsql', 'api'], true)) {
            throw new RuntimeException('Jenis konektor tidak dikenal.');
        }
        if (!$records || count($records) > self::MAX_ROWS) {
            throw new RuntimeException('Konektor harus menghasilkan 1–10.000 baris.');
        }
        $originalHeaders = [];
        foreach (array_slice($records, 0, 100) as $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw new RuntimeException('Respons konektor harus berupa objek baris.');
            }
            foreach (array_keys($record) as $header) {
                if (!in_array((string) $header, $originalHeaders, true)) {
                    $originalHeaders[] = (string) $header;
                }
            }
        }
        $headers = self::cleanHeaders($originalHeaders);
        $rows = [];
        foreach (array_slice($records, 0, self::MAX_ROWS) as $record) {
            $row = [];
            foreach ($headers as $index => $header) {
                $value = $record[$originalHeaders[$index]] ?? null;
                $row[$header] = is_scalar($value) || $value === null
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            $rows[] = $row;
        }
        return self::createDataset(
            $workspaceId,
            $userId,
            ['headers' => $headers, 'rows' => $rows],
            $sourceType . ': snapshot',
            $sourceType,
            $sourceName,
            null,
            null,
            null,
            $connection
        );
    }

    private static function validateFile(string $path, string $extension): void
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $allowed = match ($extension) {
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'],
            'json' => ['application/json', 'text/plain', 'application/octet-stream'],
            'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'],
        };
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Jenis konten file tidak cocok dengan ekstensi yang dipilih.');
        }
        if ($extension === 'xlsx') {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new RuntimeException('Struktur file XLSX tidak valid.');
            }
            if ($zip->numFiles < 3 || $zip->numFiles > 1000) {
                $zip->close();
                throw new RuntimeException('Workbook XLSX memiliki jumlah file internal yang tidak wajar.');
            }
            $expandedBytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                $stat = $zip->statIndex($i);
                if (!is_string($entry) || str_starts_with($entry, '/') || str_contains($entry, '..') || str_contains($entry, '\\')
                    || str_starts_with($entry, 'xl/externalLinks/') || str_ends_with(strtolower($entry), 'vbaproject.bin')) {
                    $zip->close();
                    throw new RuntimeException('Workbook berisi struktur atau konten aktif yang tidak didukung.');
                }
                $entryBytes = (int) ($stat['size'] ?? 0);
                if ($entryBytes > 8_000_000 || ($expandedBytes += $entryBytes) > 24_000_000) {
                    $zip->close();
                    throw new RuntimeException('Ukuran XLSX setelah ekstraksi melebihi batas aman 24 MB.');
                }
            }
            $zip->close();
        }
    }

    private static function cleanHeaders(array $rawHeaders): array
    {
        if (count($rawHeaders) > self::MAX_COLUMNS) {
            throw new RuntimeException('Dataset dibatasi hingga 100 kolom.');
        }
        $headers = [];
        $seen = [];
        foreach ($rawHeaders as $index => $header) {
            $header = trim(preg_replace('/\x{FEFF}/u', '', (string) $header) ?? (string) $header);
            if ($header === '') {
                $header = 'column_' . ($index + 1);
            }
            $base = mb_substr($header, 0, 180, 'UTF-8');
            $candidate = $base;
            $suffix = 1;
            while (isset($seen[mb_strtolower($candidate, 'UTF-8')])) {
                $suffix++;
                $candidate = mb_substr($base, 0, 170, 'UTF-8') . '_' . $suffix;
            }
            $seen[mb_strtolower($candidate, 'UTF-8')] = true;
            $headers[] = $candidate;
        }
        if (!$headers) {
            throw new RuntimeException('Baris header tidak ditemukan.');
        }
        return $headers;
    }

    private static function rowsFromMatrix(array $matrix): array
    {
        if (count($matrix) < 2) {
            throw new RuntimeException('Dataset harus memiliki header dan setidaknya satu baris.');
        }
        $headers = self::cleanHeaders((array) array_shift($matrix));
        $rows = [];
        foreach ($matrix as $line) {
            if (!is_array($line)) {
                continue;
            }
            $record = [];
            $hasValue = false;
            foreach ($headers as $index => $header) {
                $value = $line[$index] ?? null;
                if (is_string($value)) {
                    $value = trim($value);
                }
                if ($value !== null && $value !== '') {
                    $hasValue = true;
                }
                $record[$header] = $value;
            }
            if ($hasValue) {
                $rows[] = $record;
                if (count($rows) > self::MAX_ROWS) {
                    throw new RuntimeException('Dataset dibatasi hingga 10.000 baris per impor.');
                }
            }
        }
        if (!$rows) {
            throw new RuntimeException('Tidak ada baris data yang dapat dibaca.');
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function parse(string $path, string $extension): array
    {
        return match ($extension) {
            'csv' => self::parseCsv($path),
            'json' => self::parseJson($path),
            'xlsx' => self::parseXlsx($path),
            default => throw new RuntimeException('Format tidak didukung.'),
        };
    }

    private static function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('File CSV tidak dapat dibuka.');
        }
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('File CSV kosong.');
        }
        $counts = [];
        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $counts[$delimiter] = count(str_getcsv($firstLine, $delimiter, '"', '\\'));
        }
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        rewind($handle);
        $matrix = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($matrix === [] && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
            }
            $matrix[] = $row;
            if (count($matrix) > self::MAX_ROWS + 1) {
                fclose($handle);
                throw new RuntimeException('Dataset dibatasi hingga 10.000 baris per impor.');
            }
        }
        fclose($handle);
        return self::rowsFromMatrix($matrix);
    }

    private static function parseJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) > self::MAX_BYTES) {
            throw new RuntimeException('File JSON tidak dapat dibaca.');
        }
        $records = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($records) && !array_is_list($records)) {
            $records = $records['data'] ?? $records['items'] ?? $records['results'] ?? null;
        }
        if (!is_array($records) || !array_is_list($records) || count($records) < 1 || count($records) > self::MAX_ROWS) {
            throw new RuntimeException('JSON harus berupa array berisi 1–10.000 objek/baris.');
        }
        $originalHeaders = [];
        foreach (array_slice($records, 0, 100) as $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw new RuntimeException('Setiap baris JSON harus berupa objek bernama kolom.');
            }
            foreach (array_keys($record) as $header) {
                if (!in_array((string) $header, $originalHeaders, true)) {
                    $originalHeaders[] = (string) $header;
                }
            }
        }
        $headers = self::cleanHeaders($originalHeaders);
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($headers as $index => $header) {
                $value = $record[$originalHeaders[$index]] ?? null;
                $row[$header] = is_scalar($value) || $value === null
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            $rows[] = $row;
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function parseXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('XLSX tidak dapat dibuka.');
        }
        try {
            $sharedStrings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $root = simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
                if ($root) {
                    $ns = $root->getNamespaces(true);
                    $mainNs = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
                    foreach ($root->children($mainNs)->si as $item) {
                        $parts = [];
                        foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $text) {
                            $parts[] = (string) $text;
                        }
                        $sharedStrings[] = implode('', $parts);
                    }
                }
            }
            $dateStyles = self::dateStyles($zip);
            $worksheetName = self::firstWorksheetPath($zip);
            $sheetXml = $zip->getFromName($worksheetName);
            if ($sheetXml === false) {
                throw new RuntimeException('Worksheet pertama tidak tersedia.');
            }
            $root = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
            if (!$root) {
                throw new RuntimeException('Worksheet XLSX rusak.');
            }
            $ns = $root->getNamespaces(true);
            $mainNs = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $matrix = [];
            $sheetData = $root->children($mainNs)->sheetData;
            foreach ($sheetData->children($mainNs)->row as $row) {
                $cells = [];
                foreach ($row->children($mainNs)->c as $cell) {
                    preg_match('/^([A-Z]+)/i', (string) $cell['r'], $matches);
                    $columnIndex = 0;
                    foreach (str_split(strtoupper($matches[1] ?? 'A')) as $letter) {
                        $columnIndex = $columnIndex * 26 + ord($letter) - 64;
                    }
                    $columnIndex--;
                    $type = (string) $cell['t'];
                    $value = (string) ($cell->children($mainNs)->v ?? '');
                    if ($type === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $parts = [];
                        foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $text) {
                            $parts[] = (string) $text;
                        }
                        $value = implode('', $parts);
                    } elseif ($type === 'b') {
                        $value = $value === '1' ? 'true' : 'false';
                    } elseif ($value !== '' && isset($dateStyles[(int) $cell['s']])) {
                        $timestamp = ((float) $value - 25569) * 86400;
                        $value = gmdate('Y-m-d H:i:s', (int) $timestamp);
                    }
                    $cells[$columnIndex] = $value;
                }
                $line = [];
                if ($cells) {
                    for ($column = 0, $last = max(array_keys($cells)); $column <= $last; $column++) {
                        $line[] = $cells[$column] ?? '';
                    }
                }
                $matrix[] = $line;
                if (count($matrix) > self::MAX_ROWS + 1) {
                    throw new RuntimeException('Dataset XLSX dibatasi hingga 10.000 baris.');
                }
            }
            return self::rowsFromMatrix($matrix);
        } finally {
            $zip->close();
        }
    }

    private static function dateStyles(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $root = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$root) {
            return [];
        }
        $ns = $root->getNamespaces(true);
        $mainNs = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $custom = [];
        $numberFormats = $root->children($mainNs)->numFmts;
        if ($numberFormats) {
            foreach ($numberFormats->children($mainNs)->numFmt as $format) {
                if (preg_match('/[dy]/i', (string) $format['formatCode'])) {
                    $custom[(int) $format['numFmtId']] = true;
                }
            }
        }
        $styles = [];
        $cellFormats = $root->children($mainNs)->cellXfs;
        if ($cellFormats) {
            foreach ($cellFormats->children($mainNs)->xf as $index => $format) {
                $numberFormat = (int) $format['numFmtId'];
                if (($numberFormat >= 14 && $numberFormat <= 22) || ($numberFormat >= 45 && $numberFormat <= 47) || isset($custom[$numberFormat])) {
                    $styles[(int) $index] = true;
                }
            }
        }
        return $styles;
    }

    private static function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relationsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }
        $workbook = simplexml_load_string($workbookXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
        $relations = simplexml_load_string($relationsXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$workbook || !$relations) {
            return 'xl/worksheets/sheet1.xml';
        }
        $workbookNs = $workbook->getNamespaces(true);
        $relationNs = $relations->getNamespaces(true);
        $mainNs = $workbookNs[''] ?? '';
        $relationshipNs = $workbookNs['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $firstSheet = $workbook->children($mainNs)->sheets->children($mainNs)->sheet[0] ?? null;
        if (!$firstSheet) {
            return 'xl/worksheets/sheet1.xml';
        }
        $relationshipId = (string) $firstSheet->attributes($relationshipNs)['id'];
        $relationMainNs = $relationNs[''] ?? '';
        foreach ($relations->children($relationMainNs)->Relationship as $relation) {
            if ((string) $relation['Id'] === $relationshipId) {
                $target = ltrim((string) $relation['Target'], '/');
                return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function inferType(array $values): string
    {
        $numeric = $dates = $booleans = $filled = 0;
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filled++;
            $text = trim((string) $value);
            if (in_array(strtolower($text), ['true', 'false'], true)) {
                $booleans++;
            } elseif (is_numeric(str_replace([',', ' '], '', $text))) {
                $numeric++;
            } elseif (strtotime($text) !== false && preg_match('/\d{1,4}[-\/.]\d{1,2}[-\/.]\d{1,4}|\d{4}-\d{2}/', $text)) {
                $dates++;
            }
        }
        if ($filled === 0) {
            return 'text';
        }
        if ($booleans / $filled > 0.9) {
            return 'boolean';
        }
        if ($dates / $filled > 0.75) {
            return 'date';
        }
        if ($numeric / $filled > 0.85) {
            foreach ($values as $value) {
                if (is_string($value) && preg_match('/^0\d+$/', $value)) {
                    return 'text';
                }
                if (is_numeric($value) && (float) $value !== (float) (int) $value) {
                    return 'decimal';
                }
            }
            return 'integer';
        }
        return 'text';
    }

    private static function createDataset(
        int $workspaceId,
        int $userId,
        array $parsed,
        string $filename,
        string $sourceType,
        string $datasetName,
        ?string $license,
        ?string $citation,
        ?string $storedPath,
        ?array $connection
    ): int {
        $rows = $parsed['rows'];
        $headers = $parsed['headers'];
        if (!$rows || !$headers) {
            throw new RuntimeException('Dataset kosong atau tidak memiliki schema.');
        }
        $schema = [];
        foreach ($headers as $index => $header) {
            $sample = [];
            foreach (array_slice($rows, 0, 100) as $row) {
                $sample[] = $row[$header] ?? null;
            }
            $unique = array_values(array_unique(array_filter($sample, static fn ($value) => $value !== null && $value !== '')));
            $columnMetadata = [
                'name' => $header,
                'type' => self::inferType($sample),
                'ordinal' => $index,
                'sample_values' => array_slice($unique, 0, 12),
            ];
            if ($license === 'CC BY 4.0' && preg_match('/^unit[_ ]?price$/i', (string) $header)) {
                $columnMetadata['currency'] = 'GBP';
            }
            $schema[] = $columnMetadata;
        }
        $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $encrypted = $connection ? encrypt_secret(json_encode($connection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null;
        $sourceName = $license ? 'UCI Online Retail (CC BY 4.0)' : $filename;
        $size = $storedPath && is_file($storedPath) ? (int) filesize($storedPath) : 0;
        $db = $GLOBALS['pdo'];
        $db->beginTransaction();
        try {
            $insertSource = $db->prepare('INSERT INTO data_sources(workspace_id,owner_id,name,source_type,status,schema_json,connection_ciphertext,connection_nonce,connection_tag,storage_path,row_count,last_updated_at) VALUES(?,?,?,?,"connected",?,?,?,?,?,?,NOW())');
            $insertSource->execute([
                $workspaceId,
                $userId,
                $sourceName,
                $sourceType,
                $schemaJson,
                $encrypted['ciphertext'] ?? null,
                $encrypted['nonce'] ?? null,
                $encrypted['tag'] ?? null,
                $storedPath,
                count($rows),
            ]);
            $sourceId = (int) $db->lastInsertId();
            $insertDataset = $db->prepare('INSERT INTO datasets(workspace_id,source_id,created_by,name,file_name,row_count,column_count,schema_json,source_license,source_citation,is_sample,storage_bytes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $insertDataset->execute([
                $workspaceId,
                $sourceId,
                $userId,
                mb_substr($datasetName, 0, 180, 'UTF-8'),
                $filename,
                count($rows),
                count($headers),
                $schemaJson,
                $license,
                $citation,
                $license ? 1 : 0,
                $size,
            ]);
            $datasetId = (int) $db->lastInsertId();
            $insertColumn = $db->prepare('INSERT INTO dataset_columns(dataset_id,column_name,data_type,ordinal,sample_values_json) VALUES(?,?,?,?,?)');
            foreach ($schema as $column) {
                $insertColumn->execute([
                    $datasetId,
                    $column['name'],
                    $column['type'],
                    $column['ordinal'],
                    json_encode($column['sample_values'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
            }
            $insertRow = $db->prepare('INSERT INTO dataset_rows(dataset_id,`row_number`,row_json) VALUES(?,?,?)');
            foreach ($rows as $index => $record) {
                $insertRow->execute([
                    $datasetId,
                    $index + 1,
                    json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
            }
            $db->prepare('INSERT INTO usage_logs(workspace_id,user_id,usage_type,units,month_key,metadata_json) VALUES(?,?,"dataset_upload",1,?,?)')
                ->execute([$workspaceId, $userId, date('Y-m'), json_encode(['dataset_id' => $datasetId, 'sample' => (bool) $license])]);
            audit_log('dataset.imported', 'dataset', $datasetId, ['rows' => count($rows), 'columns' => count($headers), 'sample' => (bool) $license]);
            $db->commit();
            return $datasetId;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}
