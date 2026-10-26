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
    public readonly string $databasePath;
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
        if (!self::runtimeSupported(PHP_VERSION)) { throw new \RuntimeException('Benötigt wird ein korrigiertes PHP 8.4 ab 8.4.27 oder PHP 8.5 ab 8.5.12.'); }
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
        $this->databasePath = $this->privatePath((string) ($values['APP_DB_PATH'] ?? 'storage/database/application.sqlite'), false);
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
        return (version_compare($version,'8.4.27','>=') && version_compare($version,'8.5.0','<'))
            || (version_compare($version,'8.5.12','>=') && version_compare($version,'8.6.0','<'));
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
