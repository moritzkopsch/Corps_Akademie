<?php
declare(strict_types=1);

// Local SMTP probe. Production mail will use a maintained PHP mail library.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function localInbox(string $path): array
{
    $request = curl_init('http://127.0.0.1:8025/api/v1/' . $path);
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROXY => '',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($request);
    if ($body === false || curl_getinfo($request, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException('Lokales Postfach nicht erreichbar. Zuerst python3 scripts/mailpit.py starten.');
    }
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

try {
    $info = localInbox('webui');
    if (($info['Label'] ?? '') !== 'Corpsakademie Lokal') {
        throw new RuntimeException('Auf Port 8025 läuft nicht das erwartete Projektpostfach.');
    }
    $marker = bin2hex(random_bytes(8));
    $subject = 'Corpsakademie: lokaler Mailtest ' . $marker;
    $boundary = 'corpsakademie-' . $marker;
    $plain = "Die lokale SMTP-Verbindung funktioniert.\nPrüfkennung: " . $marker;
    $html = '<!doctype html><html lang="de"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;color:#14263d">'
        . '<h1>Das lokale Postfach funktioniert.</h1><p>Die Nachricht wurde aus PHP über SMTP an Mailpit übergeben.</p>'
        . '<p>Prüfkennung: <strong>' . $marker . '</strong></p></body></html>';
    $message = implode("\r\n", [
        'From: Corpsakademie Lokal <system@example.test>',
        'To: kunde@example.test',
        'Subject: ' . $subject,
        'Date: ' . gmdate('D, d M Y H:i:s O'),
        'Message-ID: <' . $marker . '@example.test>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        '', '--' . $boundary,
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: base64',
        '', chunk_split(base64_encode($plain), 76, "\r\n"),
        '--' . $boundary,
        'Content-Type: text/html; charset=utf-8',
        'Content-Transfer-Encoding: base64',
        '', chunk_split(base64_encode($html), 76, "\r\n"),
        '--' . $boundary . '--', '',
    ]);

    // The maintained Mailpit sendmail client performs the SMTP exchange.
    $process = proc_open([
        'mailpit', 'sendmail', '-S', '127.0.0.1:1025',
        '-f', 'system@example.test', 'kunde@example.test',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Mailpit-Sendmail konnte nicht gestartet werden.');
    }
    $remaining = $message;
    while ($remaining !== '') {
        $written = fwrite($pipes[0], $remaining);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Nachricht konnte nicht vollständig übergeben werden.');
        }
        $remaining = substr($remaining, $written);
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('SMTP-Test fehlgeschlagen: ' . trim($errors));
    }

    $matches = [];
    // Mailpit stores messages asynchronously; wait up to five seconds.
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $messages = localInbox('messages?limit=100');
        $matches = array_values(array_filter($messages['messages'] ?? [], fn(array $mail): bool => $mail['Subject'] === $subject));
        if ($matches !== []) {
            break;
        }
        usleep(100000);
    }
    if (count($matches) !== 1) {
        throw new RuntimeException('Die Testnachricht wurde nicht genau einmal im lokalen Postfach gefunden.');
    }
    $mail = localInbox('message/' . rawurlencode($matches[0]['ID']));
    if (($mail['To'][0]['Address'] ?? '') !== 'kunde@example.test'
        || ($mail['From']['Address'] ?? '') !== 'system@example.test'
        || !str_contains($mail['Text'] ?? '', $marker)
        || !str_contains($mail['HTML'] ?? '', $marker)) {
        throw new RuntimeException('Empfänger, Absender oder Nachrichteninhalt stimmen nicht.');
    }

    // This attempt stays on localhost and must fail at the recipient check.
    $blocked = proc_open([
        'mailpit', 'sendmail', '-S', '127.0.0.1:1025',
        '-f', 'system@example.test', 'blocked@example.com',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $blockedPipes);
    if (!is_resource($blocked)) {
        throw new RuntimeException('Empfänger-Schutzprüfung konnte nicht gestartet werden.');
    }
    fwrite($blockedPipes[0], "From: system@example.test\r\nTo: blocked@example.com\r\nSubject: Must be rejected\r\n\r\nLocal recipient restriction check.\r\n");
    fclose($blockedPipes[0]);
    $blockedOutput = stream_get_contents($blockedPipes[1]) . stream_get_contents($blockedPipes[2]);
    fclose($blockedPipes[1]);
    fclose($blockedPipes[2]);
    if (proc_close($blocked) === 0 || preg_match('/\b5\d\d\b/', $blockedOutput) !== 1) {
        throw new RuntimeException('Eine Adresse außerhalb von @example.test wurde nicht erwartungsgemäß per SMTP abgewiesen.');
    }
    echo "[OK] PHP → SMTP → Mailpit: genau eine Testnachricht gefunden.\n";
    echo "[OK] Absender, Empfänger, Text und HTML stimmen.\n";
    echo "[OK] Andere Empfängerdomains werden per SMTP abgewiesen.\n";
    echo "Postfach: http://127.0.0.1:8025\n";
    echo "Betreff: " . $subject . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[FEHLER] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
