#!/usr/bin/env bash
#
# dev.sh — startet die lokale Entwicklungsumgebung von MD-Takt:
#   - Engine (Laravel API)  → http://localhost:8000
#   - Admin-Schaltzentrale  → http://localhost:5173  (Vite)
#
# Voraussetzungen (lokal vorhanden/laufend):
#   - PHP 8.3+, Composer, Node/npm
#   - PostgreSQL erreichbar gemäß engine/.env (DB_HOST/DB_PORT/...)
#
# Optionen:
#   --no-install   Abhängigkeiten nicht (neu) installieren
#   --no-migrate   Keine Migrationen/Seeds ausführen
#
# Beenden mit Strg+C — stoppt beide Server.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENGINE="$ROOT/engine"
ADMIN="$ROOT/admin"

DO_INSTALL=1
DO_MIGRATE=1
for arg in "$@"; do
  case "$arg" in
    --no-install) DO_INSTALL=0 ;;
    --no-migrate) DO_MIGRATE=0 ;;
    *) echo "Unbekannte Option: $arg" >&2; exit 2 ;;
  esac
done

# --- kleine Log-Helfer ---------------------------------------------------------
c_blue=$'\033[34m'; c_green=$'\033[32m'; c_yellow=$'\033[33m'; c_red=$'\033[31m'; c_off=$'\033[0m'
log()  { printf '%s==>%s %s\n' "$c_blue"  "$c_off" "$*"; }
ok()   { printf '%s ✓ %s %s\n' "$c_green" "$c_off" "$*"; }
warn() { printf '%s ! %s %s\n' "$c_yellow" "$c_off" "$*"; }
die()  { printf '%s ✗ %s %s\n' "$c_red"   "$c_off" "$*" >&2; exit 1; }

require() { command -v "$1" >/dev/null 2>&1 || die "Benötigtes Tool fehlt: $1"; }

# --- Pre-Flight ---------------------------------------------------------------
log "Pre-Flight-Checks"
require php
require composer
require node
require npm
[ -f "$ENGINE/artisan" ] || die "engine/artisan nicht gefunden — falsches Verzeichnis?"
ok "PHP $(php -r 'echo PHP_VERSION;'), Node $(node -v), npm $(npm -v)"

# --- Engine-Setup -------------------------------------------------------------
log "Engine vorbereiten ($ENGINE)"

if [ ! -f "$ENGINE/.env" ]; then
  cp "$ENGINE/.env.example" "$ENGINE/.env"
  warn ".env aus .env.example erzeugt — bitte DB-Zugang und ADMIN_EMAIL/ADMIN_PASSWORD setzen."
fi

if [ "$DO_INSTALL" -eq 1 ] && [ ! -d "$ENGINE/vendor" ]; then
  log "composer install (Engine)"
  (cd "$ENGINE" && composer install)
fi

# APP_KEY erzeugen, falls leer
if ! grep -qE '^APP_KEY=.+' "$ENGINE/.env"; then
  log "APP_KEY generieren"
  (cd "$ENGINE" && php artisan key:generate --force)
fi

if [ "$DO_MIGRATE" -eq 1 ]; then
  log "Migrationen ausführen"
  if (cd "$ENGINE" && php artisan migrate --force); then
    # Single-Admin aus .env anlegen (übersprungen, wenn ADMIN_EMAIL/PASSWORD leer)
    (cd "$ENGINE" && php artisan db:seed --class="Database\\Seeders\\AdminSeeder" --force) || \
      warn "Admin-Seed übersprungen/fehlgeschlagen (ADMIN_EMAIL/ADMIN_PASSWORD gesetzt?)"
  else
    warn "Migration fehlgeschlagen — läuft PostgreSQL und stimmt engine/.env? Server starten trotzdem."
  fi
fi

# --- Admin-Setup --------------------------------------------------------------
log "Admin-Frontend vorbereiten ($ADMIN)"

if [ ! -f "$ADMIN/.env" ]; then
  cp "$ADMIN/.env.example" "$ADMIN/.env"
  ok "admin/.env aus .env.example erzeugt"
fi

if [ "$DO_INSTALL" -eq 1 ] && [ ! -d "$ADMIN/node_modules" ]; then
  log "npm install (Admin)"
  (cd "$ADMIN" && npm install)
fi

# --- Server starten -----------------------------------------------------------
pids=()
cleanup() {
  trap - INT TERM EXIT
  echo
  log "Stoppe Server …"
  for p in "${pids[@]}"; do kill "$p" 2>/dev/null || true; done
  wait 2>/dev/null || true
  ok "beendet"
}
trap cleanup INT TERM EXIT

log "Starte Engine → http://localhost:8000"
(cd "$ENGINE" && php artisan serve --host=127.0.0.1 --port=8000) &
pids+=($!)

log "Starte Admin → http://localhost:5173"
(cd "$ADMIN" && npm run dev -- --port 5173) &
pids+=($!)

printf '\n%s──────────────────────────────────────────────%s\n' "$c_green" "$c_off"
ok "Engine:  http://localhost:8000/api/v1/lines"
ok "Admin:   http://localhost:5173"
printf '%s   (Strg+C beendet beide Server)%s\n\n' "$c_yellow" "$c_off"

wait
