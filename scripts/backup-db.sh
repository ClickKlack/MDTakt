#!/usr/bin/env bash
#
# Sichert die MD-Takt-Datenbank per pg_dump in ein Verzeichnis und raeumt alte Dumps auf.
#
# Gedacht fuer den Aufgabenplaner des Collector-Hosts: Der Dump wird von dort GEZOGEN und
# liegt damit von vornherein ausserhalb des Hostings — getrennter Speicherort ohne
# zusaetzliche Mechanik.
#
# pg_dump laeuft in einem Container, weil die Client-Version mindestens so neu sein muss
# wie der Server. Aeltere Clients verweigern den Dump; auf NAS und Webhosting liegen
# jeweils zu alte Versionen.
#
#   ./backup-db.sh              regulaerer Lauf
#   ./backup-db.sh --verify-only  nur den letzten Dump pruefen
#
# Konfiguration: backup.local.env neben diesem Skript (gitignored).

set -euo pipefail

HIER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="$HIER/backup.local.env"

[[ -f "$CONFIG" ]] || { echo "Konfiguration fehlt: $CONFIG" >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONFIG"

: "${DB_HOST:?DB_HOST fehlt}"
: "${DB_NAME:?DB_NAME fehlt}"
: "${DB_USER:?DB_USER fehlt}"
: "${DB_PASSWORD:?DB_PASSWORD fehlt}"
: "${BACKUP_DIR:?BACKUP_DIR fehlt}"
PG_IMAGE="${PG_IMAGE:-postgres:17-alpine}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }

mkdir -p "$BACKUP_DIR"

# Eigener Container je Lauf; er wird danach entfernt. -Fc = custom format, komprimiert
# und selektiv wiederherstellbar.
dump() {
    local ziel="mdtakt-$(date -u +%F).dump"
    log "Dump nach $BACKUP_DIR/$ziel"

    "$DOCKER_BIN" run --rm \
        -e PGPASSWORD="$DB_PASSWORD" \
        -v "$BACKUP_DIR:/backup" \
        "$PG_IMAGE" \
        pg_dump -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USER" -d "$DB_NAME" \
                -Fc --no-owner --no-privileges -f "/backup/$ziel"

    local bytes
    bytes="$(stat -c %s "$BACKUP_DIR/$ziel" 2>/dev/null || stat -f %z "$BACKUP_DIR/$ziel")"
    (( bytes > 1024 )) || { log "FEHLER: Dump ist nur $bytes Byte gross"; exit 1; }
    log "Dump geschrieben: $bytes Byte"

    echo "$ziel"
}

# Ein Dump, der sich nicht lesen laesst, ist kein Backup. pg_restore -l liest das
# Inhaltsverzeichnis, ohne etwas wiederherzustellen.
verify() {
    local datei="$1"
    log "Pruefe $datei"

    local tabellen
    tabellen="$("$DOCKER_BIN" run --rm -v "$BACKUP_DIR:/backup" "$PG_IMAGE" \
        pg_restore -l "/backup/$datei" | grep -c 'TABLE DATA' || true)"

    (( tabellen > 0 )) || { log "FEHLER: Dump enthaelt keine Tabellendaten"; exit 1; }
    log "OK — $tabellen Tabellen im Dump"
}

aufraeumen() {
    local geloescht
    geloescht="$(find "$BACKUP_DIR" -name 'mdtakt-*.dump' -mtime "+$RETENTION_DAYS" -print -delete | wc -l)"
    (( geloescht > 0 )) && log "$geloescht Dump(s) aelter als $RETENTION_DAYS Tage entfernt" || true
}

if [[ "${1:-}" == "--verify-only" ]]; then
    letzter="$(ls -t "$BACKUP_DIR"/mdtakt-*.dump 2>/dev/null | head -1)" \
        || { log "Kein Dump vorhanden"; exit 1; }
    verify "$(basename "$letzter")"
    exit 0
fi

datei="$(dump | tail -1)"
verify "$datei"
aufraeumen
log "Fertig."
