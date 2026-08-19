# MD-Takt Collector — ephemeres Image für den wöchentlichen GTFS-Import.
#
# Der Container wird pro Lauf gestartet und danach verworfen (`docker compose run --rm`).
# Ein Dauerbetrieb lohnt nicht: Der Job läuft wöchentlich und dauert wenige Minuten.

# ── Abhängigkeiten ───────────────────────────────────────────────────────────
FROM composer:2 AS deps
WORKDIR /app
# Quellen mitkopieren, damit --optimize-autoloader eine vollständige Classmap bauen kann.
COPY composer.json composer.lock ./
COPY src/ ./src/
COPY bin/ ./bin/
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# ── Laufzeit ─────────────────────────────────────────────────────────────────
FROM php:8.4-cli-alpine

# ext-zip für den GTFS-Feed (ZIP), ext-mbstring ist im offiziellen Image enthalten.
# Die Build-Abhängigkeiten fliegen im selben Layer wieder raus.
RUN apk add --no-cache libzip ca-certificates \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev \
 && docker-php-ext-install -j"$(nproc)" zip \
 && apk del .build-deps

WORKDIR /app
COPY --from=deps /app/vendor ./vendor
COPY src/ ./src/
COPY bin/ ./bin/

# Alles Veränderliche liegt unter /data und wird als Volume eingebunden:
#   /data/archive   GTFS-ZIPs (~260 MB je Lauf) — ohne sie ist Fahrplan-Historie unwiederbringlich
#   /data/state     last-import.json — ohne sie importiert jeder Lauf erneut
#   /data/logs      rotierende Logdatei
ENV GTFS_ARCHIVE_PATH=/data/archive \
    COLLECTOR_STATE_PATH=/data/state/last-import.json \
    COLLECTOR_LOG_PATH=/data/logs/collector.log

VOLUME ["/data"]

ENTRYPOINT ["php", "bin/collector"]
CMD ["collector:import-gtfs"]
