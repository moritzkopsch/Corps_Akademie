<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

final class Database
{
    public readonly \PDO $pdo;
    private bool $transaction = false;

    public function __construct(public readonly Config $config)
    {
        umask(0077);
        $this->pdo = new \PDO('sqlite:' . $config->databasePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        chmod($config->databasePath, 0600);
        $version = $this->pdo->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($version, '3.51.3', '<') || $version === '3.52.0') {
            throw new \RuntimeException('Diese SQLite-Version ist für den WAL-Betrieb nicht freigegeben.');
        }
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        if ($this->pdo->query('PRAGMA journal_mode=WAL')->fetchColumn() !== 'wal') {
            throw new \RuntimeException('SQLite-WAL konnte nicht aktiviert werden.');
        }
        $this->pdo->exec('PRAGMA synchronous=FULL');
    }

    public function run(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    public function one(string $sql, array $parameters = []): ?array
    {
        $row = $this->run($sql, $parameters)->fetch();
        return $row === false ? null : $row;
    }

    public function immediate(callable $operation): mixed
    {
        if ($this->transaction) {
            return $operation();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->transaction = true;
        try {
            $result = $operation();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        } finally {
            $this->transaction = false;
        }
    }

    public function inTransaction(): bool { return $this->transaction; }

    public function backup(): string
    {
        if ($this->transaction) {
            throw new \LogicException('Backup ist innerhalb einer Transaktion nicht möglich.');
        }
        $directory = $this->config->storagePath . '/backups';
        if (!is_dir($directory)) { mkdir($directory, 0700, true); }
        $path = $directory . '/database-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.sqlite';
        $this->run('VACUUM INTO ?', [$path]);
        chmod($path, 0600);
        $copy = new \PDO('sqlite:' . $path);
        if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
            || $copy->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
            throw new \RuntimeException('Die Sicherungsprüfung ist fehlgeschlagen.');
        }
        return $path;
    }
}
