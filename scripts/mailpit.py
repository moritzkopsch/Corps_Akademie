#!/usr/bin/env python3
"""Start the project's local SMTP inbox; stop it with Ctrl+C."""

import os
from pathlib import Path
import shutil
import sys


def main():
    binary = shutil.which("mailpit")
    if binary is None:
        sys.exit("Mailpit fehlt. Installation auf macOS: brew install mailpit")

    os.umask(0o077)
    data = Path(__file__).resolve().parent.parent / ".local" / "mailpit"
    data.mkdir(mode=0o700, parents=True, exist_ok=True)

    # Ignore inherited Mailpit configuration, especially relay/forward settings.
    local_env = {
        key: value for key, value in os.environ.items() if not key.startswith("MP_")
    }
    arguments = [
        binary,
        "--listen", "127.0.0.1:8025",
        "--smtp", "127.0.0.1:1025",
        "--allowed-hosts", "127.0.0.1,localhost",
        "--database", str(data / "mailpit.sqlite"),
        "--label", "Corpsakademie Lokal",
        "--smtp-allowed-recipients", "(?i)@example[.]test$",
        "--smtp-disable-rdns",
        "--disable-version-check",
        "--block-remote-css-and-fonts",
        "--max", "1000",
        "--max-age", "7d",
    ]
    print("Lokales Postfach: http://127.0.0.1:8025", flush=True)
    print("SMTP: 127.0.0.1:1025; Empfänger ausschließlich @example.test", flush=True)
    print("Stoppen: Ctrl+C. Nachrichten bleiben lokal gespeichert.", flush=True)
    os.execve(binary, arguments, local_env)


if __name__ == "__main__":
    main()
