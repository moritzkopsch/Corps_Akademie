#!/bin/bash
set -euo pipefail

SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"

# cPanel's interactive and deployment PHP defaults need not match. Never change
# the account-wide PHP setting or bypass the application's version requirements.
if [[ -n "${CORPS_DEPLOY_PHP:-}" ]]; then
  CANDIDATES=("$CORPS_DEPLOY_PHP")
else
  CANDIDATES=(
    /opt/alt/php85/usr/bin/php
    /opt/alt/php84/usr/bin/php
    /opt/cloudlinux/alt-php85/root/usr/bin/php
    /opt/cloudlinux/alt-php84/root/usr/bin/php
    /opt/cpanel/ea-php85/root/usr/bin/php
    /opt/cpanel/ea-php84/root/usr/bin/php
    "$(command -v php || true)"
  )
fi

for PHP_CANDIDATE in "${CANDIDATES[@]}"; do
  [[ -x "$PHP_CANDIDATE" ]] || continue
  if "$PHP_CANDIDATE" -r '
    require $argv[1]."/app/Infrastructure/Config.php";
    $extensions=["curl","intl","mbstring","pdo_pgsql","pgsql","sodium","xmlwriter","phar"];
    $complete=array_filter($extensions,static fn($extension)=>!extension_loaded($extension))===[];
    exit(PHP_SAPI === "cli" && $complete && \Corpsakademie\Infrastructure\Config::runtimeSupported(PHP_VERSION) ? 0 : 1);
  ' "$SOURCE_DIR" >/dev/null 2>&1; then
    exec "$PHP_CANDIDATE" "$SOURCE_DIR/deployment/cpanel/run.php" "$@"
  fi
done

echo '[FEHLER] Kein freigegebenes CLI-PHP gefunden. Benötigt: PHP ab 8.2.35, 8.3.36, 8.4.27 oder 8.5.12 (Sicherheitskorrekturen CVE-2026-109618/619).' >&2
echo 'Einen bestätigten Pfad gegebenenfalls mit CORPS_DEPLOY_PHP=/absoluter/pfad/php vorgeben.' >&2
exit 1
