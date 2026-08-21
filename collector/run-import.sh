#!/bin/sh
#
# Startet den GTFS-Import als einmaligen Container. Gedacht als Eintrag im
# Aufgabenplaner der NAS — dort genuegt dann der Pfad zu dieser Datei.
#
# Warum ein Skript statt einer Befehlszeile im Planer:
#   - Der Planer startet mit minimalem PATH und in einem anderen Verzeichnis.
#     Beides wird hier verlaesslich gesetzt, statt es in einem UI-Feld zu wiederholen.
#   - Aenderungen sind versioniert und per SSH testbar, ohne die DSM-Oberflaeche.
#   - Der Exit-Code wird sauber durchgereicht, damit die Benachrichtigung
#     "nur bei ungewoehnlicher Beendigung" greift.

set -eu

HIER="$(cd "$(dirname "$0")" && pwd)"
cd "$HIER"

DOCKER="${DOCKER_BIN:-/usr/local/bin/docker}"
LOG="$HIER/storage/logs/cron.log"
LOCK="$HIER/storage/import.lock"
MAX_LOG_BYTES=5242880   # 5 MB

mkdir -p "$HIER/storage/logs"

# Einfache Rotation: Der Collector rotiert sein eigenes Log, dieses hier nicht.
if [ -f "$LOG" ]; then
    bytes="$(wc -c < "$LOG" | tr -d ' ')"
    [ "$bytes" -gt "$MAX_LOG_BYTES" ] && mv "$LOG" "$LOG.1"
fi

exec >> "$LOG" 2>&1

start="$(date -u +%s)"
echo "=== $(date -u +%Y-%m-%dT%H:%M:%SZ) Import gestartet ==="

[ -f "$HIER/.env" ] || { echo "FEHLER: .env fehlt in $HIER"; exit 1; }
[ -x "$DOCKER" ]    || { echo "FEHLER: docker nicht gefunden unter $DOCKER"; exit 1; }

# -T: keine Pseudo-TTY, der Planer hat kein Terminal.
# flock -n: haengt noch ein Lauf, bricht dieser hier ab, statt sich dazwischenzuschieben.
rc=0
flock -n "$LOCK" "$DOCKER" compose run --rm -T collector || rc=$?

dauer=$(( $(date -u +%s) - start ))

if [ "$rc" -eq 0 ]; then
    echo "=== $(date -u +%Y-%m-%dT%H:%M:%SZ) Import erfolgreich (${dauer}s) ==="
else
    echo "=== $(date -u +%Y-%m-%dT%H:%M:%SZ) Import FEHLGESCHLAGEN, Exit-Code $rc (${dauer}s) ==="
fi

exit "$rc"
