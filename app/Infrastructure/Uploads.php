<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Dateiablage für öffentlich ausgelieferte Medien unterhalb von public/uploads.
 * Dateinamen werden immer neu vergeben, damit hochgeladene Namen nichts auslösen können.
 */
final class Uploads
{
    /** Erlaubte Typen je Verwendungszweck: MIME-Typ => Dateiendung. */
    private const TYPES = [
        'image' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'pdf' => ['application/pdf' => 'pdf'],
    ];

    private const LIMITS = ['image' => 2097152, 'pdf' => 5242880];

    public static function store(?UploadedFile $file, string $folder, string $type, string $root): string
    {
        if ($file === null) { return ''; }
        $error = $file->getError();
        if ($error === UPLOAD_ERR_NO_FILE) { return ''; }
        if ($error !== UPLOAD_ERR_OK) { throw new \DomainException(self::errorMessage($error)); }
        if (!isset(self::TYPES[$type])) { throw new \DomainException('Ungültiger Dateityp.'); }
        if (!$file->isValid()) { throw new \DomainException('Die Datei konnte nicht hochgeladen werden.'); }
        $limit = self::LIMITS[$type];
        if ($file->getSize() > $limit) { throw new \DomainException($type === 'image' ? 'Das Bild darf höchstens 2 MB groß sein.' : 'Die Datei darf höchstens 5 MB groß sein.'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extensions = self::TYPES[$type];
        if (!is_string($mime) || !isset($extensions[$mime])) {
            throw new \DomainException($type === 'image' ? 'Erlaubt sind Bilder im Format JPG, PNG oder WebP.' : 'Erlaubt sind PDF-Dateien.');
        }
        if ($type === 'image' && @getimagesize($file->getPathname()) === false) { throw new \DomainException('Die Bilddatei ist beschädigt.'); }
        $directory = $root . '/public/uploads/' . $folder;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) { throw new \DomainException('Der Ablageordner ist nicht beschreibbar.'); }
        $name = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
        $file->move($directory, $name);
        chmod($directory . '/' . $name, 0644);
        return 'uploads/' . $folder . '/' . $name;
    }

    public static function remove(?string $relative, string $root): void
    {
        if ($relative === null || $relative === '' || !str_starts_with($relative, 'uploads/') || str_contains($relative, '..')) { return; }
        $path = $root . '/public/' . $relative;
        if (is_file($path)) { unlink($path); }
    }

    private static function errorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß. Bitte eine kleinere Datei verwenden.',
            UPLOAD_ERR_PARTIAL => 'Der Upload wurde unterbrochen. Bitte erneut versuchen.',
            default => 'Die Datei konnte nicht hochgeladen werden.',
        };
    }
}
