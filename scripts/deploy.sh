#!/usr/bin/env bash
#
# Deployment für MD-Takt auf Managed Webhosting (Hetzner/konsoleH).
#
#   ./scripts/deploy.sh engine     nur die Laravel-API
#   ./scripts/deploy.sh admin      nur das Admin-Frontend
#   ./scripts/deploy.sh all        beides
#   ./scripts/deploy.sh preflight  nur prüfen, nichts ändern
#
# Optionen:
#   --dry-run       rsync nur simulieren
#   --allow-dirty   Deploy trotz uncommitteter Änderungen
#
# Konfiguration: scripts/deploy.local.env (gitignored) — siehe .example.

set -euo pipefail

WURZEL="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="$WURZEL/scripts/deploy.local.env"

rot=$'\033[31m'; gruen=$'\033[32m'; gelb=$'\033[33m'; grau=$'\033[90m'; aus=$'\033[0m'
info()  { echo "${grau}▸${aus} $*"; }
ok()    { echo "${gruen}✓${aus} $*"; }
warn()  { echo "${gelb}!${aus} $*"; }
fehler() { echo "${rot}✗${aus} $*" >&2; exit 1; }

[[ -f "$CONFIG" ]] || fehler "Konfiguration fehlt: scripts/deploy.local.env
  cp scripts/deploy.local.env.example scripts/deploy.local.env  und Werte eintragen."

# shellcheck source=/dev/null
source "$CONFIG"
: "${SSH_ALIAS:?SSH_ALIAS fehlt in deploy.local.env}"
: "${REMOTE_BASE:?REMOTE_BASE fehlt in deploy.local.env}"
PHP_BIN="${PHP_BIN:-php}"
API_BASE_URL="${API_BASE_URL:-}"

ZIEL="${1:-}"
shift || true
DRY=""
ALLOW_DIRTY=0
for arg in "$@"; do
    case "$arg" in
        --dry-run)     DRY="--dry-run" ;;
        --allow-dirty) ALLOW_DIRTY=1 ;;
        *) fehler "Unbekannte Option: $arg" ;;
    esac
done

fern() { ssh "$SSH_ALIAS" "$@"; }

# ── Preflight ────────────────────────────────────────────────────────────────
preflight() {
    info "Verbindung zu $SSH_ALIAS"
    fern "true" 2>/dev/null || fehler "SSH-Verbindung zu $SSH_ALIAS fehlgeschlagen."
    ok "SSH erreichbar"

    local version
    version="$(fern "$PHP_BIN -r 'echo PHP_VERSION;'" 2>/dev/null)" || fehler "$PHP_BIN nicht gefunden."
    if [[ "$(printf '%s\n8.3.0\n' "$version" | sort -V | head -1)" != "8.3.0" ]]; then
        fehler "PHP $version auf dem Server — Laravel 13 braucht 8.3 oder neuer."
    fi
    ok "PHP $version (CLI)"

    fern "test -d '$REMOTE_BASE/api/public' && test -d '$REMOTE_BASE/admin/public'" \
        || fehler "Zielstruktur fehlt unter $REMOTE_BASE (erwartet api/public und admin/public)."
    ok "Zielverzeichnisse vorhanden"

    local stand
    stand="$(git -C "$WURZEL" rev-parse --short HEAD)"
    if [[ -n "$(git -C "$WURZEL" status --porcelain)" ]]; then
        (( ALLOW_DIRTY == 1 )) || fehler "Working Tree ist nicht sauber. Erst committen oder --allow-dirty setzen."
        warn "Working Tree hat uncommittete Änderungen — deployt wird der Dateistand, nicht $stand."
    else
        ok "Working Tree sauber ($stand)"
    fi
}

# ── Engine ───────────────────────────────────────────────────────────────────
deploy_engine() {
    info "Engine → $REMOTE_BASE/api"

    fern "test -f '$REMOTE_BASE/api/.env'" || fehler \
"Auf dem Server fehlt $REMOTE_BASE/api/.env — sie enthält Geheimnisse und wird bewusst
  nicht übertragen. Einmalig anlegen (Vorlage: engine/.env.example), dann:
     ssh $SSH_ALIAS '$PHP_BIN $REMOTE_BASE/api/artisan key:generate'"

    # vendor/ wird auf dem Server installiert (passende Plattform), storage/ und .env bleiben stehen.
    rsync -az --delete $DRY \
        --exclude '.env' \
        --exclude '.git/' \
        --exclude 'vendor/' \
        --exclude 'node_modules/' \
        --exclude 'tests/' \
        --exclude 'storage/' \
        --exclude 'bootstrap/cache/' \
        --exclude '.phpunit.result.cache' \
        --exclude '*.log' \
        "$WURZEL/engine/" "$SSH_ALIAS:$REMOTE_BASE/api/"
    ok "Dateien übertragen"

    [[ -n "$DRY" ]] && { warn "Dry-Run — keine Remote-Befehle ausgeführt."; return; }

    fern "set -e
        cd '$REMOTE_BASE/api'
        mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app bootstrap/cache
        chmod -R u+rwX storage bootstrap/cache
        # Der System-Composer (2.5.5 unter PHP 8.4) schuettet Deprecation-Warnungen aus und
        # setzt error_reporting selbst, -d verpufft also. Ausgabe deshalb einsammeln und nur
        # im Fehlerfall zeigen — gefiltert, damit echte Meldungen sichtbar bleiben.
        if ! composer install --no-dev --optimize-autoloader --no-interaction --quiet > .composer-out.log 2>&1; then
            grep -v '^Deprecated:' .composer-out.log >&2 || true
            rm -f .composer-out.log
            echo 'composer install fehlgeschlagen' >&2
            exit 1
        fi
        rm -f .composer-out.log

        # Beim ersten Deploy ist APP_KEY noch leer — ohne ihn scheitert jeder Request.
        if ! grep -qE '^APP_KEY=.+' .env; then
            echo '  APP_KEY fehlt — wird erzeugt'
            $PHP_BIN artisan key:generate --force
        fi

        $PHP_BIN artisan optimize:clear
        $PHP_BIN artisan migrate --force

        # Single-Admin nach SPEC: die .env ist die Quelle der Wahrheit. Der Seeder ist
        # idempotent und überspringt sich selbst, wenn ADMIN_EMAIL/ADMIN_PASSWORD leer sind.
        $PHP_BIN artisan db:seed --class=AdminSeeder --force

        $PHP_BIN artisan optimize
    "
    ok "Abhängigkeiten, Migrationen, Admin-Seed und Caches erledigt"
}

# ── Admin ────────────────────────────────────────────────────────────────────
deploy_admin() {
    [[ -n "$API_BASE_URL" ]] || fehler "API_BASE_URL fehlt in deploy.local.env — sie wird ins Bundle kompiliert."
    info "Admin → $REMOTE_BASE/admin/public  (API: $API_BASE_URL)"

    ( cd "$WURZEL/admin" && npm ci --silent && VITE_API_BASE_URL="$API_BASE_URL" npm run build >/dev/null )
    ok "Bundle gebaut"

    rsync -az --delete $DRY "$WURZEL/admin/dist/" "$SSH_ALIAS:$REMOTE_BASE/admin/public/"
    ok "Bundle übertragen"
}

# ── Abschlussprüfung ─────────────────────────────────────────────────────────
verify() {
    [[ -n "$DRY" ]] && return
    [[ -n "$API_BASE_URL" ]] || return

    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$API_BASE_URL/up" || echo 000)"
    [[ "$code" == "200" ]] && ok "Health-Check $API_BASE_URL/up → 200" \
                           || warn "Health-Check $API_BASE_URL/up → $code (erwartet 200)"

    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$API_BASE_URL/.env" || echo 000)"
    [[ "$code" == "200" ]] && fehler "$API_BASE_URL/.env ist abrufbar! Document-Root zeigt nicht auf public/. Geheimnisse sofort erneuern." \
                           || ok ".env nicht abrufbar (HTTP $code)"
}

case "$ZIEL" in
    preflight) preflight ;;
    engine)    preflight; deploy_engine; verify ;;
    admin)     preflight; deploy_admin ;;
    all)       preflight; deploy_engine; deploy_admin; verify ;;
    *) fehler "Aufruf: ./scripts/deploy.sh {preflight|engine|admin|all} [--dry-run] [--allow-dirty]" ;;
esac

ok "Fertig."
