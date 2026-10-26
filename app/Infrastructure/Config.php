<?php
declare(strict_types=1);

namespace Corpsakademie\Infrastructure;

use Symfony\Component\Dotenv\Dotenv;

final class Config
{
    public readonly string $environment;
    public readonly string $url;
    public readonly string $key;
    public readonly string $keyId;
    public readonly string $databaseHost;
    public readonly int $databasePort;
    public readonly string $databaseName;
    public readonly string $databaseUser;
    public readonly string $databasePassword;
    public readonly string $databaseDsn;
    public readonly string $storagePath;
    public readonly int $idleSeconds;
    public readonly int $absoluteSeconds;
    public readonly string $mailerDsn;
    public readonly string $mailFrom;
    public readonly bool $mailEnabled;
    public readonly string $newsletterFrom;
    public readonly string $newsletterDsn;
    public readonly string $mailReplyTo;
    public readonly string $mailContactTo;

    public function __construct(public readonly string $root, array $values)
    {
        if (!self::runtimeSupported(PHP_VERSION)) { throw new \RuntimeException('Benötigt wird ein PHP mit den Korrekturen für CVE-2026-109618/CVE-2026-109619: 8.2 ab 8.2.35, 8.3 ab 8.3.36, 8.4 ab 8.4.27 oder 8.5 ab 8.5.12.'); }
        $this->environment = (string) ($values['APP_ENV'] ?? 'production');
        if (!in_array($this->environment, ['local', 'test', 'staging', 'production'], true)) {
            throw new \RuntimeException('APP_ENV ist ungültig.');
        }
        $this->url = rtrim((string) ($values['APP_URL'] ?? ''), '/');
        $parts = parse_url($this->url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \RuntimeException('APP_URL muss eine vollständige Basis-URL ohne Unterverzeichnis sein.');
        }
        if ($this->environment === 'production' && $parts['scheme'] !== 'https') {
            throw new \RuntimeException('Die Produktion benötigt HTTPS.');
        }
        if ($parts['scheme'] === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new \RuntimeException('HTTP ist nur für lokale Adressen erlaubt.');
        }
        $key = base64_decode((string) ($values['APP_KEY'] ?? ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException('APP_KEY fehlt oder ist ungültig. Zuerst bin/console install ausführen.');
        }
        $this->key = $key;
        $this->keyId = (string) ($values['APP_KEY_ID'] ?? 'v1');
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$this->keyId)) { throw new \RuntimeException('APP_KEY_ID ist ungültig.'); }
        $this->storagePath = $this->privatePath((string) ($values['APP_STORAGE_PATH'] ?? 'storage'), true);
        $this->databaseHost = $this->databaseIdentifier($values['APP_DB_HOST'] ?? '', 'APP_DB_HOST');
        $this->databasePort = $this->port($values['APP_DB_PORT'] ?? 5432);
        $this->databaseName = $this->databaseIdentifier($values['APP_DB_NAME'] ?? '', 'APP_DB_NAME');
        $this->databaseUser = $this->databaseIdentifier($values['APP_DB_USER'] ?? '', 'APP_DB_USER');
        $password = $values['APP_DB_PASSWORD'] ?? '';
        if (!is_string($password) || $password === '' || str_contains($password, "\0")) {
            throw new \RuntimeException('APP_DB_PASSWORD fehlt oder ist ungültig.');
        }
        $this->databasePassword = $password;
        $this->databaseDsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->databaseHost, $this->databasePort, $this->databaseName);
        $this->idleSeconds = $this->positiveInteger($values['SESSION_IDLE_SECONDS'] ?? 1800);
        $this->absoluteSeconds = $this->positiveInteger($values['SESSION_ABSOLUTE_SECONDS'] ?? 43200);
        if ($this->idleSeconds > $this->absoluteSeconds) {
            throw new \RuntimeException('Die Sitzungs-Inaktivitätsfrist darf die Gesamtfrist nicht überschreiten.');
        }
        $mailEnabled=filter_var($values['APP_MAIL_ENABLED']??true,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if ($mailEnabled===null) { throw new \RuntimeException('APP_MAIL_ENABLED muss true oder false sein.'); }
        $this->mailEnabled=$mailEnabled;
        $this->mailerDsn = (string) ($values['MAILER_DSN'] ?? '');
        $this->mailFrom = (string) ($values['MAIL_FROM'] ?? '');
        $this->newsletterFrom=(string)($values['NEWSLETTER_MAIL_FROM']??$this->mailFrom);
        $this->newsletterDsn=(string)($values['NEWSLETTER_MAILER_DSN']??$this->mailerDsn);
        $this->mailReplyTo=(string)($values['MAIL_REPLY_TO']??$this->mailFrom);
        $this->mailContactTo=(string)($values['MAIL_CONTACT_TO']??'');
        if (!$this->mailEnabled) { return; }
        foreach ([$this->newsletterFrom,$this->mailReplyTo,...($this->mailContactTo===''?[]:[$this->mailContactTo])] as $address) {
            if (filter_var($address,FILTER_VALIDATE_EMAIL)===false) { throw new \RuntimeException('Newsletter-Absender oder Antwortadresse ist ungültig.'); }
        }
        $this->validateDsn($this->newsletterDsn);
        if (filter_var($this->mailFrom, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('MAIL_FROM ist ungültig.');
        }
        $this->validateDsn($this->mailerDsn);
    }

    private function validateDsn(string $mailerDsn): void
    {
        if ($this->environment !== 'production'
            && !preg_match('~^smtp://(?:127\.0\.0\.1|localhost):1025$~D', $mailerDsn)) {
            throw new \RuntimeException('Nichtproduktion verwendet ausschließlich den lokalen SMTP-Testserver.');
        }
        if ($this->environment === 'production') {
            $dsn = parse_url($mailerDsn);
            if ($dsn === false || !in_array($dsn['scheme'] ?? '', ['smtp', 'smtps'], true) || empty($dsn['host'])) {
                throw new \RuntimeException('Ein gültiger SMTP-Versandweg ist erforderlich.');
            }
            parse_str($dsn['query']??'', $options);
            if ((isset($options['verify_peer']) && !filter_var($options['verify_peer'],FILTER_VALIDATE_BOOLEAN))
                || (($dsn['scheme'] ?? '') !== 'smtps' && (!filter_var($options['require_tls']??false,FILTER_VALIDATE_BOOLEAN) || (isset($options['auto_tls']) && !filter_var($options['auto_tls'],FILTER_VALIDATE_BOOLEAN))))) {
                throw new \RuntimeException('Produktiver SMTP-Versand muss TLS erzwingen.');
            }
        }
    }

    public static function load(string $root): self
    {
        if (!is_file($root . '/.env')) {
            throw new \RuntimeException('Die Anwendung ist noch nicht installiert.');
        }
        (new Dotenv())->loadEnv($root . '/.env');
        return new self($root, array_replace($_SERVER, $_ENV));
    }

    public function secureCookies(): bool
    {
        return str_starts_with($this->url, 'https://');
    }

    public static function runtimeSupported(string $version): bool
    {
        // Every maintained branch patched for CVE-2026-109618 and CVE-2026-109619.
        static $patched=['8.2'=>'8.2.35','8.3'=>'8.3.36','8.4'=>'8.4.27','8.5'=>'8.5.12'];
        if (preg_match('/^(\d+\.\d+)\.\d+/',$version,$matches)!==1) { return false; }
        return isset($patched[$matches[1]]) && version_compare($version,$patched[$matches[1]],'>=');
    }

    private function databaseIdentifier(mixed $value, string $key): string
    {
        // No separators or whitespace: values are embedded in the DSN or passed to pg_dump as arguments.
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_.-]{1,63}$/D', $value)) {
            throw new \RuntimeException($key . ' fehlt oder ist ungültig.');
        }
        return $value;
    }

    private function port(mixed $value): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new \RuntimeException('APP_DB_PORT ist ungültig.');
        }
        return $port;
    }

    private function privatePath(string $path, bool $directory): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new \RuntimeException('Ungültiger privater Speicherpfad.');
        }
        $absolute = str_starts_with($path, '/') ? $path : $this->root . '/' . $path;
        $parent = $directory ? $absolute : dirname($absolute);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new \RuntimeException('Privater Speicherpfad konnte nicht erstellt werden.');
        }
        $resolvedParent = realpath($parent);
        $resolved = $directory ? $resolvedParent : (realpath($absolute) ?: $resolvedParent . '/' . basename($absolute));
        $webroot = realpath($this->root . '/public') ?: $this->root . '/public';
        if ($resolvedParent === false || $resolved === $webroot || str_starts_with($resolved . '/', $webroot . '/')) {
            throw new \RuntimeException('Private Dateien dürfen nicht im Webroot liegen.');
        }
        if (!is_writable($parent)) {
            throw new \RuntimeException('Privater Speicherpfad ist nicht beschreibbar.');
        }
        return $resolved;
    }

    private function positiveInteger(mixed $value): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < 1 || $number > 2592000) {
            throw new \RuntimeException('Ungültige Sitzungsfrist.');
        }
        return $number;
    }
}
