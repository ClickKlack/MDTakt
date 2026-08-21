#!/usr/bin/env bash
#
# Überträgt den Collector auf den Zielhost und baut bei Bedarf das Image neu.
#
#   ./scripts/deploy-collector.sh            übertragen, bei Bedarf zum Rebuild auffordern
#   ./scripts/deploy-collector.sh --build    ohne Rückfrage neu bauen
#   ./scripts/deploy-collector.sh --no-build nur übertragen
#   ./scripts/deploy-collector.sh --dry-run  nur zeigen, was übertragen würde
#
# Konfiguration: scripts/deploy-collector.local.env (gitignored).
#
# Warum tar statt rsync: Auf Synology ist /usr/bin/rsync setuid root und verlangt beim
# Aufruf über SSH eine zusätzliche Authentifizierung — der Transfer bleibt an einer
# Passwortabfrage hängen. tar über SSH umgeht das; bei ~300 KB Quelltext ist der
# Verzicht auf inkrementelle Übertragung ohne Belang.

set -euo pipefail

WURZEL="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="$WURZEL/scripts/deploy-collector.local.env"

rot=$'\033[31m'; gruen=$'\033[32m'; gelb=$'\033[33m'; grau=$'\033[90m'; aus=$'\033[0m'
info()   { echo "${grau}▸${aus} $*"; }
ok()     { echo "${gruen}✓${aus} $*"; }
warn()   { echo "${gelb}!${aus} $*"; }
fehler() { echo "${rot}✗${aus} $*" >&2; exit 1; }

[[ -f "$CONFIG" ]] || fehler "Konfiguration fehlt: scripts/deploy-collector.local.env
  cp scripts/deploy-collector.local.env.example scripts/deploy-collector.local.env"

# shellcheck source=/dev/null
source "$CONFIG"
: "${SSH_ALIAS:?SSH_ALIAS fehlt}"
: "${REMOTE_DIR:?REMOTE_DIR fehlt}"
DOCKER_BIN="${DOCKER_BIN:-/usr/local/bin/docker}"
SUDO="${REMOTE_SUDO:-sudo}"

DRY=0; BUILD="frage"; ALLOW_DIRTY=0
for arg in "$@"; do
    case "$arg" in
        --dry-run)     DRY=1 ;;
        --build)       BUILD="ja" ;;
        --no-build)    BUILD="nein" ;;
        --allow-dirty) ALLOW_DIRTY=1 ;;
        *) fehler "Unbekannte Option: $arg" ;;
    esac
done

# Diese Pfade landen im Image — ändert sich hier etwas, ist ein Rebuild nötig.
BUILD_PFADE=(Dockerfile .dockerignore composer.json composer.lock src bin)

build_hash() {
    ( cd "$WURZEL/collector" && find "${BUILD_PFADE[@]}" -type f -print0 2>/dev/null \
        | sort -z | xargs -0 shasum -a 256 | shasum -a 256 | cut -d' ' -f1 )
}

# ── Preflight ────────────────────────────────────────────────────────────────
ssh "$SSH_ALIAS" true 2>/dev/null || fehler "SSH-Verbindung zu $SSH_ALIAS fehlgeschlagen."
ok "SSH erreichbar"

ssh "$SSH_ALIAS" "test -d '$REMOTE_DIR' && test -f '$REMOTE_DIR/.env'" \
    || fehler "$REMOTE_DIR fehlt oder enthält keine .env (die wird bewusst nicht übertragen)."
ok "Zielverzeichnis und .env vorhanden"

stand="$(git -C "$WURZEL" rev-parse --short HEAD)"
if [[ -n "$(git -C "$WURZEL" status --porcelain)" ]]; then
    (( ALLOW_DIRTY == 1 )) || fehler "Working Tree ist nicht sauber. Erst committen oder --allow-dirty setzen."
    warn "Working Tree hat uncommittete Änderungen — übertragen wird der Dateistand, nicht $stand."
else
    ok "Working Tree sauber ($stand)"
fi

hash_lokal="$(build_hash)"
hash_fern="$(ssh "$SSH_ALIAS" "cat '$REMOTE_DIR/.build-stamp' 2>/dev/null" || true)"

if (( DRY == 1 )); then
    info "Würde übertragen:"
    ( cd "$WURZEL/collector" && find . -type f \
        -not -path './.env' -not -path './storage/*' -not -path './vendor/*' \
        -not -path './tests/*' -not -name '.DS_Store' -not -path './@eaDir/*' \
        | sed 's|^\./|    |' | sort )
    [[ "$hash_lokal" == "$hash_fern" ]] && info "Image ist auf Stand." || warn "Image müsste neu gebaut werden."
    exit 0
fi

# ── Übertragen ───────────────────────────────────────────────────────────────
info "Collector → $SSH_ALIAS:$REMOTE_DIR"

# .env und storage/ gehören dem Zielhost und bleiben unangetastet.
# macOS haengt jeder Datei erweiterte Attribute an; GNU tar auf der Gegenseite klagt
# darueber bei jeder einzelnen. --no-xattrs und --no-mac-metadata lassen sie weg.
COPYFILE_DISABLE=1 tar czf - -C "$WURZEL/collector" \
    --no-xattrs --no-mac-metadata \
    --exclude '.env' --exclude 'storage' --exclude 'vendor' --exclude 'tests' \
    --exclude '.DS_Store' --exclude '@eaDir' --exclude '.phpunit.result.cache' \
    . 2>/dev/null \
  | ssh "$SSH_ALIAS" "tar xzf - -C '$REMOTE_DIR'" \
  || fehler "Übertragung fehlgeschlagen."
ssh "$SSH_ALIAS" "chmod +x '$REMOTE_DIR/run-import.sh' 2>/dev/null || true"
ok "Dateien übertragen"

# ── Rebuild ──────────────────────────────────────────────────────────────────
if [[ "$hash_lokal" == "$hash_fern" ]]; then
    info "Keine build-relevanten Änderungen — Rebuild nicht nötig."
    [[ "$BUILD" == "frage" ]] && BUILD="nein"
elif [[ "$BUILD" == "frage" ]]; then
    warn "Build-relevante Dateien haben sich geändert — ohne Rebuild läuft weiter das alte Image."
    BUILD="ja"
    if [[ -t 0 ]]; then
        read -r -p "Image jetzt neu bauen? [J/n] " antwort
        [[ "$antwort" =~ ^[nN] ]] && BUILD="nein"
    else
        warn "Keine Eingabe möglich — Rebuild wird ausgeführt."
    fi
fi

if [[ "$BUILD" == "ja" ]]; then
    info "Image bauen (sudo auf $SSH_ALIAS — Passwortabfrage möglich)"
    # -t für ein Terminal, damit sudo nach dem Passwort fragen kann.
    ssh -t "$SSH_ALIAS" "cd '$REMOTE_DIR' && $SUDO $DOCKER_BIN compose build" \
        || fehler "Rebuild fehlgeschlagen."
    # Stempel erst nach erfolgreichem Bau — sonst gilt ein gescheiterter Lauf als erledigt.
    ssh "$SSH_ALIAS" "printf '%s' '$hash_lokal' > '$REMOTE_DIR/.build-stamp'"
    ok "Image neu gebaut"
elif [[ "$hash_lokal" != "$hash_fern" ]]; then
    warn "Rebuild übersprungen — das laufende Image entspricht nicht dem übertragenen Stand."
fi

ok "Fertig."
