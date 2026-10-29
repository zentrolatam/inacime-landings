<?php

declare(strict_types=1);

final class Storage
{
    private static ?self $instance = null;

    private PDO $pdo;

    private function __construct()
    {
        if (!is_dir(STORAGE_ROOT) && !mkdir(STORAGE_ROOT, 0775, true) && !is_dir(STORAGE_ROOT)) {
            throw new RuntimeException('storage');
        }

        $this->pdo = new PDO('sqlite:' . STORAGE_ROOT . '/leads.sqlite');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    public static function open(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS leads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                folio TEXT NOT NULL UNIQUE,
                slug TEXT NOT NULL,
                programa TEXT NOT NULL,
                nombre TEXT NOT NULL,
                telefono TEXT NOT NULL,
                utms TEXT,
                fbc TEXT,
                fbp TEXT,
                ad_id TEXT,
                ip TEXT,
                user_agent TEXT,
                created_at TEXT NOT NULL,
                crm_status TEXT NOT NULL DEFAULT \'pending\',
                capi_status TEXT NOT NULL DEFAULT \'pending\',
                event_id TEXT,
                event_time INTEGER,
                page_url TEXT
            )'
        );
        $this->migrateRateHits();
    }

    private function migrateRateHits(): void
    {
        $columns = $this->tableColumns('rate_hits');
        if ($columns === []) {
            $this->createRateHits();
            return;
        }

        if (in_array('bucket', $columns, true) && in_array('subject', $columns, true)) {
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_rate_bucket_subject_time ON rate_hits (bucket, subject, created_at)'
            );
            return;
        }

        $this->pdo->exec('ALTER TABLE rate_hits RENAME TO rate_hits_legacy');
        $this->createRateHits();
        if (in_array('ip', $columns, true)) {
            $this->pdo->exec(
                "INSERT INTO rate_hits (bucket, subject, created_at)
                 SELECT 'ip', ip, created_at FROM rate_hits_legacy
                 WHERE ip IS NOT NULL AND ip != ''"
            );
        }
        $this->pdo->exec('DROP TABLE rate_hits_legacy');
    }

    private function createRateHits(): void
    {
        $this->pdo->exec(
            'CREATE TABLE rate_hits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket TEXT NOT NULL,
                subject TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE INDEX idx_rate_bucket_subject_time ON rate_hits (bucket, subject, created_at)'
        );
    }

    /**
     * @return list<string>
     */
    private function tableColumns(string $table): array
    {
        $safe = str_replace("'", "''", $table);
        $rows = $this->pdo->query("PRAGMA table_info('" . $safe . "')")->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($rows as $row) {
            if (isset($row['name']) && is_string($row['name'])) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $landing
     * @param array<string, string> $lead
     * @return array<string, mixed>
     */
    public function insertLead(array $landing, array $lead, string $ip, string $ua, string $createdAt, int $eventTime): array
    {
        $prefix = (string) ($landing['folio_prefix'] ?? 'INACIME');
        $utms = json_encode([
            'utm_source' => $lead['utm_source'],
            'utm_campaign' => $lead['utm_campaign'],
            'utm_content' => $lead['utm_content'],
            'ga_client_id' => (string) ($lead['ga_client_id'] ?? ''),
            'ga_session_id' => (string) ($lead['ga_session_id'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($utms === false) {
            $utms = '{}';
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO leads (
                folio, slug, programa, nombre, telefono, utms, fbc, fbp, ad_id,
                ip, user_agent, created_at, crm_status, capi_status, event_id, event_time, page_url
            ) VALUES (
                :folio, :slug, :programa, :nombre, :telefono, :utms, :fbc, :fbp, :ad_id,
                :ip, :user_agent, :created_at, \'pending\', \'pending\', :event_id, :event_time, :page_url
            )'
        );

        $folio = null;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $candidate = Folio::make($prefix);
            $this->pdo->beginTransaction();
            try {
                $statement->execute([
                    ':folio' => $candidate,
                    ':slug' => $lead['slug'],
                    ':programa' => $lead['programa'],
                    ':nombre' => $lead['nombre'],
                    ':telefono' => $lead['telefono'],
                    ':utms' => $utms,
                    ':fbc' => $lead['fbc'] !== '' ? $lead['fbc'] : null,
                    ':fbp' => $lead['fbp'] !== '' ? $lead['fbp'] : null,
                    ':ad_id' => $lead['ad_id'] !== '' ? $lead['ad_id'] : null,
                    ':ip' => $ip,
                    ':user_agent' => $ua !== '' ? $ua : null,
                    ':created_at' => $createdAt,
                    ':event_id' => $lead['event_id'],
                    ':event_time' => $eventTime,
                    ':page_url' => $lead['page_url'] !== '' ? $lead['page_url'] : null,
                ]);
                $this->pdo->commit();
                $folio = $candidate;
                break;
            } catch (PDOException $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if (!$this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        if ($folio === null) {
            throw new RuntimeException('folio');
        }

        $lead['id'] = (int) $this->pdo->lastInsertId();
        $lead['folio'] = $folio;
        $lead['ip'] = $ip;
        $lead['user_agent'] = $ua;
        $lead['created_at'] = $createdAt;
        $lead['event_time'] = $eventTime;
        $lead['crm_status'] = 'pending';
        $lead['capi_status'] = 'pending';

        return $lead;
    }

    public function setStatus(int $id, string $column, string $value): void
    {
        if (!in_array($column, ['crm_status', 'capi_status'], true)) {
            throw new InvalidArgumentException('column');
        }
        if (!in_array($value, ['pending', 'sent'], true)) {
            throw new InvalidArgumentException('value');
        }

        $statement = $this->pdo->prepare('UPDATE leads SET ' . $column . ' = :value WHERE id = :id');
        $statement->execute([':value' => $value, ':id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->query(
            'SELECT id, folio, slug, programa, nombre, telefono, utms, fbc, fbp, ad_id,
                    ip, user_agent, created_at, crm_status, capi_status, event_id, event_time, page_url
             FROM leads
             WHERE crm_status = \'pending\' OR capi_status = \'pending\'
             ORDER BY id ASC
             LIMIT ' . $limit
        );
        $rows = $statement === false ? [] : $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $leads = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $utms = json_decode((string) ($row['utms'] ?? ''), true);
            if (!is_array($utms)) {
                $utms = [];
            }
            $row['utm_source'] = (string) ($utms['utm_source'] ?? '');
            $row['utm_campaign'] = (string) ($utms['utm_campaign'] ?? '');
            $row['utm_content'] = (string) ($utms['utm_content'] ?? '');
            $leads[] = $row;
        }

        return $leads;
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            || str_contains($exception->getMessage(), 'UNIQUE');
    }
}
