<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $directory) {}

    public function migrate(): array
    {
        $this->db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL UNIQUE, checksum TEXT NOT NULL, applied_at INTEGER NOT NULL, release_version TEXT NOT NULL)');
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            $existing = $this->db->one('SELECT checksum FROM schema_migrations WHERE migration=?', [$name]);
            if ($existing !== null && !hash_equals($existing['checksum'], $checksum)) {
                throw new \RuntimeException('Eine bereits angewandte Migration wurde verändert: ' . $name);
            }
            if ($existing === null) { $pending[$name] = [$file, $checksum]; }
        }
        if ($pending !== [] && (int) $this->db->run('SELECT count(*) FROM schema_migrations')->fetchColumn() > 0) {
            $this->db->backup();
        }
        $applied = [];
        foreach ($pending as $name => [$file, $checksum]) {
            $this->db->immediate(function () use ($name, $file, $checksum, &$applied): void {
                if ($this->db->one('SELECT id FROM schema_migrations WHERE migration=?', [$name]) !== null) { return; }
                $this->db->pdo->exec(file_get_contents($file));
                if ($this->db->pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                    throw new \RuntimeException('Fremdschlüsselprüfung der Migration fehlgeschlagen.');
                }
                $this->db->run('INSERT INTO schema_migrations(migration,checksum,applied_at,release_version) VALUES(?,?,?,?)', [$name, $checksum, time(), '0.1.0']);
                $applied[] = $name;
            });
        }
        return $applied;
    }
}
