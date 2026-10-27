<?php

declare(strict_types=1);

final class RateLimiter
{
    private const IP_LIMIT = 30;
    private const IP_WINDOW_SECONDS = 600;
    private const PHONE_LIMIT = 3;
    private const PHONE_WINDOW_SECONDS = 3600;
    private const RETENTION_SECONDS = 3600;

    public function __construct(private PDO $pdo)
    {
    }

    public function allowIp(string $ip): bool
    {
        return $this->allow('ip', $ip, self::IP_LIMIT, self::IP_WINDOW_SECONDS);
    }

    public function allowPhone(string $phone): bool
    {
        return $this->allow('phone', $phone, self::PHONE_LIMIT, self::PHONE_WINDOW_SECONDS);
    }

    private function allow(string $bucket, string $subject, int $limit, int $window): bool
    {
        if ($subject === '') {
            return false;
        }

        $now = time();
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM rate_hits WHERE created_at < ?');
            $delete->execute([$now - self::RETENTION_SECONDS]);

            $countStatement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM rate_hits WHERE bucket = ? AND subject = ? AND created_at >= ?'
            );
            $countStatement->execute([$bucket, $subject, $now - $window]);
            $count = (int) $countStatement->fetchColumn();
            if ($count >= $limit) {
                $this->pdo->commit();
                return false;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO rate_hits (bucket, subject, created_at) VALUES (?, ?, ?)'
            );
            $insert->execute([$bucket, $subject, $now]);
            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
