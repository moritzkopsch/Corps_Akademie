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
        $this->pdo = new \PDO($config->databaseDsn, $config->databaseUser, $config->databasePassword, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $version = (string) $this->pdo->query('SHOW server_version')->fetchColumn();
        // Identity columns and skip-locked leasing require PostgreSQL 10 or newer.
        if (version_compare($version, '10', '<')) {
            throw new \RuntimeException('Diese PostgreSQL-Version wird nicht unterstützt. Benötigt wird mindestens 10.');
        }
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
        $this->pdo->beginTransaction();
        $this->transaction = true;
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
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
        $path = $directory . '/database-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.sql';
        $command = [
            'pg_dump',
            '--host=' . $this->config->databaseHost,
            '--port=' . (string) $this->config->databasePort,
            '--username=' . $this->config->databaseUser,
            '--dbname=' . $this->config->databaseName,
            '--no-owner', '--no-privileges', '--format=plain', '--file=' . $path,
        ];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'PATH' => (string) getenv('PATH'),
            'PGPASSWORD' => $this->config->databasePassword,
            'LANG' => 'C',
            'LC_ALL' => 'C',
        ], ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new \RuntimeException('pg_dump konnte nicht gestartet werden.'); }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        // The trailing line of a complete plain-format dump is the only reliable integrity marker.
        if ($code !== 0 || !is_file($path) || !str_contains(self::tail($path), 'PostgreSQL database dump complete')) {
            if (is_file($path)) { unlink($path); }
            throw new \RuntimeException('Die Sicherung ist fehlgeschlagen. pg_dump, Zugangsdaten und Speicherplatz prüfen.');
        }
        chmod($path, 0600);
        return $path;
    }

    private static function tail(string $path): string
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) { return ''; }
        try {
            fseek($handle, max(0, $size - 256));
            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }
}
