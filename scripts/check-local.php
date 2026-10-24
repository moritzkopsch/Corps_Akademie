<?php
declare(strict_types=1);

// Development diagnostics only; this is not the application's installer.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$checks = ['PHP mindestens 8.5.12' => version_compare(PHP_VERSION, '8.5.12', '>=')];
printf("PHP %s\n", PHP_VERSION);
foreach (['pdo_sqlite', 'sqlite3', 'mbstring', 'intl', 'fileinfo', 'openssl', 'sodium', 'gd', 'dom', 'xml', 'curl', 'zip'] as $extension) {
    $checks['Erweiterung ' . $extension] = extension_loaded($extension);
}
$checks['Argon2id verfügbar'] = defined('PASSWORD_ARGON2ID');
$checks['Prüfprozesse verfügbar'] = function_exists('proc_open');

$temporaryDirectory = null;
$db = null;
$backup = null;
$statement = null;
try {
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Die Voraussetzungen für den Datenbanktest fehlen.');
    }
    umask(0077);
    $temporaryDirectory = sys_get_temp_dir() . '/corpsakademie-probe-' . bin2hex(random_bytes(8));
    if (!mkdir($temporaryDirectory, 0700)) {
        throw new RuntimeException('Temporärer Prüfpfad konnte nicht erstellt werden.');
    }
    $dbPath = $temporaryDirectory . '/probe.sqlite';
    $backupPath = $temporaryDirectory . '/backup.sqlite';
    $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sqliteVersion = $db->query('SELECT sqlite_version()')->fetchColumn();
    printf("SQLite über PHP %s\n", $sqliteVersion);
    $checks['SQLite mindestens 3.51.3, ohne zurückgezogenes 3.52.0'] =
        version_compare($sqliteVersion, '3.51.3', '>=') && $sqliteVersion !== '3.52.0';
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=100');
    $checks['WAL aktiviert'] = $db->query('PRAGMA journal_mode=WAL')->fetchColumn() === 'wal';
    $checks['Foreign Keys aktiviert'] = (int) $db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    $db->exec('CREATE TABLE probe_parent (id INTEGER PRIMARY KEY)');
    $db->exec('CREATE TABLE probe_child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES probe_parent(id))');
    $db->exec('INSERT INTO probe_parent VALUES (1)');
    $checks['Ungültiger Fremdschlüssel abgewiesen'] = false;
    try {
        $db->exec('INSERT INTO probe_child VALUES (1, 999)');
    } catch (PDOException $error) {
        $checks['Ungültiger Fremdschlüssel abgewiesen'] = ($error->errorInfo[1] ?? null) === 19;
    }

    $db->exec('BEGIN IMMEDIATE');
    $db->exec('INSERT INTO probe_parent VALUES (2)');
    $childCode = <<<'CHILD'
try {
    $db = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=100');
    $db->exec('BEGIN IMMEDIATE');
    $db->exec('ROLLBACK');
    exit(2);
} catch (PDOException $error) {
    exit(($error->errorInfo[1] ?? null) === 5 ? 0 : 3);
}
CHILD;
    $process = proc_open([PHP_BINARY, '-r', $childCode, $dbPath], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Zweiter PHP-Prozess konnte nicht gestartet werden.');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $childErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $checks['Konkurrierender Schreibprozess blockiert'] = proc_close($process) === 0 && $childErrors === '';
    $db->exec('ROLLBACK');
    $checks['Rollback erfolgreich'] = (int) $db->query('SELECT count(*) FROM probe_parent')->fetchColumn() === 1;

    $statement = $db->prepare('VACUUM INTO ?');
    $statement->execute([$backupPath]);
    $statement = null;
    $backup = new PDO('sqlite:' . $backupPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $checks['Backup-Integrität korrekt'] = $backup->query('PRAGMA integrity_check')->fetchColumn() === 'ok';
    $checks['Backup-Daten vollständig'] = (int) $backup->query('SELECT count(*) FROM probe_parent')->fetchColumn() === 1;
    $checks['Backup ohne Fremdschlüsselfehler'] = $backup->query('PRAGMA foreign_key_check')->fetchAll() === [];
} catch (Throwable $error) {
    $checks['Datenbankprüfung abgeschlossen'] = false;
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
} finally {
    $statement = null;
    $backup = null;
    $db = null;
    if ($temporaryDirectory !== null && is_dir($temporaryDirectory)) {
        foreach (glob($temporaryDirectory . '/*') ?: [] as $probeFile) {
            unlink($probeFile);
        }
        rmdir($temporaryDirectory);
    }
}

foreach ($checks as $label => $passed) {
    printf("[%s] %s\n", $passed ? 'OK' : 'FEHLER', $label);
}
echo "Geprüft wird die lokale Umgebung; fachliche Anwendungstests folgen separat.\n";
exit(in_array(false, $checks, true) ? 1 : 0);
