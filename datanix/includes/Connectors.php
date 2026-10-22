<?php
declare(strict_types=1);

require_once __DIR__ . '/Importer.php';

final class DatanixConnectors
{
    private const CONNECTOR_ROW_LIMIT = 5000;

    private static function port(mixed $value, int $default): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        return $port ?: $default;
    }

    private static function normalizeRows(array $rows): array
    {
        if (!$rows) {
            throw new RuntimeException('Sumber berhasil dihubungi tetapi belum menghasilkan baris.');
        }
        $rows = array_slice($rows, 0, self::CONNECTOR_ROW_LIMIT);
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                continue;
            }
            $record = [];
            foreach ($row as $column => $value) {
                $column = trim((string) $column);
                if ($column === '') {
                    continue;
                }
                $record[$column] = is_scalar($value) || $value === null
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            if ($record) {
                $normalized[] = $record;
            }
        }
        if (!$normalized) {
            throw new RuntimeException('Sumber tidak menghasilkan objek baris yang dapat digunakan.');
        }
        return $normalized;
    }

    private static function fetchSql(array $config, string $type): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $database = trim((string) ($config['database'] ?? ''));
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $table = trim((string) ($config['table'] ?? ''));

        if ($host === '' || $database === '' || $username === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('Host, nama database, dan username harus valid.');
        }
        if (!connector_host_allowed($host)) {
            throw new RuntimeException('Host privat diblokir. Gunakan hostname publik yang disetujui operator atau atur allowlist khusus.');
        }
        $parts = explode('.', $table);
        if (count($parts) > 2 || $table === '') {
            throw new RuntimeException('Nama tabel harus berupa identifier atau schema.table.');
        }
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                throw new RuntimeException('Nama tabel hanya boleh memuat huruf, angka, dan underscore.');
            }
        }
        $driver = $type === 'mysql' ? 'pdo_mysql' : 'pdo_pgsql';
        if (!extension_loaded($driver)) {
            throw new RuntimeException('Driver PDO untuk konektor ini belum terpasang.');
        }
        $port = self::port($config['port'] ?? null, $type === 'mysql' ? 3306 : 5432);
        $caVariable = $type === 'mysql' ? 'CONNECTOR_MYSQL_SSL_CA' : 'CONNECTOR_PGSQL_SSL_CA';
        $caPath = realpath((string) (getenv($caVariable) ?: ''));
        if ($caPath === false || !is_file($caPath) || str_contains($caPath, ';')) {
            throw new RuntimeException('Koneksi database eksternal memerlukan CA bundle TLS terverifikasi melalui ' . $caVariable . '.');
        }
        $dsn = $type === 'mysql'
            ? "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4"
            : "pgsql:host={$host};port={$port};dbname={$database};sslmode=verify-full;sslrootcert={$caPath}";
        $quote = $type === 'mysql' ? '`' : '"';
        $quotedTable = implode('.', array_map(static fn ($part) => $quote . $part . $quote, $parts));
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];
        if ($type === 'mysql') {
            if (!defined('PDO::MYSQL_ATTR_SSL_CA') || !defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new RuntimeException('Driver MySQL tidak menyediakan verifikasi TLS yang diperlukan.');
            }
            $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $caPath;
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
        }
        $remote = new PDO($dsn, $username, $password, $options);
        if ($type === 'mysql') {
            $remote->exec('SET SESSION TRANSACTION READ ONLY');
        } else {
            $remote->exec('SET default_transaction_read_only = on');
        }
        $statement = $remote->query('SELECT * FROM ' . $quotedTable . ' LIMIT ' . self::CONNECTOR_ROW_LIMIT);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $remote = null;
        return self::normalizeRows($rows);
    }

    private static function fetchApi(array $config): array
    {
        $url = trim((string) ($config['url'] ?? ''));
        $parts = parse_url($url);
        if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('API harus memakai HTTPS; jangan simpan kredensial di URL.');
        }
        if (!connector_host_allowed((string) $parts['host'])) {
            throw new RuntimeException('Host API privat diblokir; minta operator menambahkan host yang telah disetujui ke allowlist.');
        }
        $headers = ['Accept: application/json', 'User-Agent: Datanix-Connector/1.0'];
        $token = (string) ($config['token'] ?? '');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => 2_000_000,
        ];
        $apiHost = strtolower(rtrim((string) $parts['host'], '.'));
        if (!filter_var($apiHost, FILTER_VALIDATE_IP)) {
            $pinnedIps = connector_public_ips($apiHost);
            if ($pinnedIps) {
                $resolve = [];
                $apiPort = (int) ($parts['port'] ?? 443);
                foreach ($pinnedIps as $ip) {
                    $resolve[] = $apiHost . ':' . $apiPort . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
                }
                $curlOptions[CURLOPT_RESOLVE] = $resolve;
            }
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, $curlOptions);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status < 200 || $status >= 300 || strlen($body) > 2_000_000) {
            throw new RuntimeException('API tidak merespons sukses atau melampaui batas 2 MB. ' . ($status ? 'HTTP ' . $status . '.' : '') . ($error ? ' Periksa alamat dan koneksi.' : ''));
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('API mengembalikan JSON yang tidak valid.');
        }
        $path = trim((string) ($config['json_path'] ?? ''));
        if ($path !== '') {
            foreach (explode('.', $path) as $part) {
                if ($part === '' || !is_array($json) || !array_key_exists($part, $json)) {
                    throw new RuntimeException('JSON path tidak ditemukan pada respons API.');
                }
                $json = $json[$part];
            }
        } elseif (!array_is_list($json)) {
            $json = $json['data'] ?? $json['items'] ?? $json['results'] ?? null;
        }
        if (!is_array($json) || !array_is_list($json)) {
            throw new RuntimeException('API harus mengembalikan array baris atau pilih JSON path ke array baris.');
        }
        return self::normalizeRows($json);
    }

    private static function fetch(array $config, string $type): array
    {
        return $type === 'api' ? self::fetchApi($config) : self::fetchSql($config, $type);
    }

    public static function connectAndImport(int $workspaceId, int $userId, array $input): int
    {
        $type = (string) ($input['source_type'] ?? '');
        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 160) {
            throw new RuntimeException('Nama sumber harus 2–160 karakter.');
        }
        if (!in_array($type, ['mysql', 'pgsql', 'api'], true)) {
            throw new RuntimeException('Pilih MySQL, PostgreSQL, atau API.');
        }
        if ($type === 'api' && !plan_limits(current_workspace())['api']) {
            throw new RuntimeException('Integrasi API tersedia pada paket Business.');
        }
        $config = $type === 'api' ? [
            'url' => trim((string) ($input['url'] ?? '')),
            'token' => (string) ($input['token'] ?? ''),
            'json_path' => trim((string) ($input['json_path'] ?? '')),
        ] : [
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => self::port($input['port'] ?? null, $type === 'mysql' ? 3306 : 5432),
            'database' => trim((string) ($input['database'] ?? '')),
            'username' => trim((string) ($input['username'] ?? '')),
            'password' => (string) ($input['password'] ?? ''),
            'table' => trim((string) ($input['table'] ?? '')),
        ];
        $rows = self::fetch($config, $type);
        return DatanixImporter::importExternalRows($workspaceId, $userId, $rows, $type, $name, $config);
    }

    public static function sync(int $sourceId, int $workspaceId, int $userId): int
    {
        $db = $GLOBALS['pdo'];
        $stmt = $db->prepare('SELECT * FROM data_sources WHERE id=? AND workspace_id=? LIMIT 1');
        $stmt->execute([$sourceId, $workspaceId]);
        $source = $stmt->fetch();
        if (!$source || !in_array($source['source_type'], ['mysql', 'pgsql', 'api'], true)) {
            throw new RuntimeException('Sumber eksternal tidak ditemukan.');
        }
        $json = decrypt_secret($source['connection_ciphertext'], $source['connection_nonce'], $source['connection_tag']);
        if ($json === null) {
            throw new RuntimeException('Secret tidak dapat didekripsi. Periksa APP_ENCRYPTION_KEY yang sama dengan saat koneksi dibuat.');
        }
        $config = json_decode($json, true);
        if (!is_array($config)) {
            throw new RuntimeException('Konfigurasi konektor tersimpan tidak valid.');
        }
        $rows = self::fetch($config, (string) $source['source_type']);
        $newDatasetId = DatanixImporter::importExternalRows($workspaceId, $userId, $rows, (string) $source['source_type'], (string) $source['name'], $config);
        $stmt = $db->prepare('SELECT source_id FROM datasets WHERE id=?');
        $stmt->execute([$newDatasetId]);
        $newSourceId = (int) $stmt->fetchColumn();
        $stmt = $db->prepare('SELECT id FROM datasets WHERE source_id=? ORDER BY id LIMIT 1');
        $stmt->execute([$sourceId]);
        $oldDatasetId = (int) $stmt->fetchColumn();

        if (!$oldDatasetId) {
            $db->prepare('UPDATE datasets SET source_id=? WHERE id=?')->execute([$sourceId, $newDatasetId]);
            $db->prepare('DELETE FROM data_sources WHERE id=?')->execute([$newSourceId]);
            return $newDatasetId;
        }

        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM dataset_rows WHERE dataset_id=?')->execute([$oldDatasetId]);
            $db->prepare('DELETE FROM dataset_columns WHERE dataset_id=?')->execute([$oldDatasetId]);
            $db->prepare('INSERT INTO dataset_rows(dataset_id,`row_number`,row_json) SELECT ?,`row_number`,row_json FROM dataset_rows WHERE dataset_id=?')->execute([$oldDatasetId, $newDatasetId]);
            $db->prepare('INSERT INTO dataset_columns(dataset_id,column_name,data_type,ordinal,nullable,sample_values_json) SELECT ?,column_name,data_type,ordinal,nullable,sample_values_json FROM dataset_columns WHERE dataset_id=?')->execute([$oldDatasetId, $newDatasetId]);
            $db->prepare('UPDATE datasets SET row_count=(SELECT COUNT(*) FROM dataset_rows WHERE dataset_id=?),column_count=(SELECT COUNT(*) FROM dataset_columns WHERE dataset_id=?),schema_json=(SELECT schema_json FROM datasets WHERE id=?),updated_at=NOW() WHERE id=?')->execute([$oldDatasetId, $oldDatasetId, $newDatasetId, $oldDatasetId]);
            $db->prepare('UPDATE data_sources SET schema_json=(SELECT schema_json FROM datasets WHERE id=?),row_count=(SELECT row_count FROM datasets WHERE id=?),last_updated_at=NOW(),status="connected",error_message=NULL WHERE id=?')->execute([$newDatasetId, $newDatasetId, $sourceId]);
            $db->prepare('DELETE FROM datasets WHERE id=?')->execute([$newDatasetId]);
            $db->prepare('DELETE FROM data_sources WHERE id=?')->execute([$newSourceId]);
            $db->commit();
            audit_log('source.synced', 'data_source', $sourceId, ['rows' => count($rows)]);
            return $oldDatasetId;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}
