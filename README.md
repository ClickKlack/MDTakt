# MD-Takt

Plattform zur manuellen Umlauf-Rekonstruktion für den Magdeburger Nahverkehr (MVB — Tram und Bus) — auf Basis von
GTFS-Fahrplandaten und Realsichtungen aus MDKursTracker.

Ein *Umlauf* ist die Abfolge aller Fahrten, die ein einzelnes Fahrzeug an einem Betriebstag leistet. Diese Information
veröffentlicht der Verkehrsbetrieb nicht — MD-Takt rekonstruiert sie, indem es Realsichtungen (Fahrzeug X wurde um
12:43 Uhr an Haltestelle Y auf Kurs Z gesehen) den passenden GTFS-Fahrten zuordnet und die bestätigten Zuordnungen
je Kursnummer zu einem Tagesumlauf zusammensetzt.

---

## Architektur

Mono-Repo aus vier Modulen plus gemeinsamen Definitionen:

| Modul | Technik | Rolle | Betrieb |
|---|---|---|---|
| [`engine/`](engine/) | Laravel 13, PHP 8.3, PostgreSQL | REST-API, Datenhaltung, Matching-Logik | Hetzner Shared Hosting |
| [`collector/`](collector/) | PHP 8.3 CLI (Symfony Console) | GTFS-Import, Sichtungs-Sync | Lokales NAS |
| [`admin/`](admin/) | Vue 3, Vite, TypeScript, Tailwind | Admin-Schaltzentrale (Login via Sanctum) | Hetzner Shared Hosting |
| [`viewer/`](viewer/) | Vue 3 *(noch nicht initialisiert)* | Öffentliche, rein lesende Info-Webseite | Hetzner Shared Hosting |
| [`shared/`](shared/) | OpenAPI, Bruno, Markdown | API-Kontrakt, API-Tests, Fachdokumentation | — |

### Datenfluss

```
[gtfs.de Feed]
      │  täglicher HTTP-Download
      ▼
[Collector — NAS]              lädt GTFS-ZIP, filtert auf die MVB-Agency,
      │                        normalisiert Zeitstempel auf UTC
      │  POST /api/v1/collector/*  (Bearer-Token)
      ▼
[Engine — Laravel API]         validiert, speichert in PostgreSQL (UTC / TIMESTAMPTZ),
      │                        stellt Matching- und Stammdaten-Endpunkte bereit
      ├──────────────────────────────────────────┐
      │  GET /api/v1/*  (öffentlich)             │  /api/v1/admin/*  (Sanctum)
      ▼                                          ▼
[Viewer — read-only]                    [Admin-Schaltzentrale]
 Linien, Fahrpläne, Haltestellen,        Matching-Workflow, Datenkorrektur,
 Tagesübersicht der Umläufe              Fahrplanperioden, Import-Auditing
```

Alle schreibenden und kuratierenden Aktionen liegen in der Admin-Schaltzentrale. Der Viewer ist ausschließlich lesend.

---

## Setup

**Voraussetzungen:** PHP 8.3+ (mit `ext-zip`, `ext-mbstring`), Composer, PostgreSQL 14+, Node.js 20+.

### 1. Engine (API)

```bash
cd engine
composer install
cp .env.example .env
php artisan key:generate
```

In `.env` setzen: `DB_*` (PostgreSQL-Zugang), `COLLECTOR_API_TOKEN` (mind. 32 Zeichen),
`ADMIN_EMAIL` + `ADMIN_PASSWORD` (Single-Admin-Zugang). Dann:

```bash
php artisan migrate
php artisan db:seed        # legt den Admin-Benutzer aus der .env an
php artisan serve          # http://localhost:8000
```

### 2. Collector (GTFS-Import)

```bash
cd collector
composer install
cp .env.example .env
```

In `.env` `COLLECTOR_API_TOKEN` auf denselben Wert wie in der Engine setzen. Dann importieren:

```bash
php bin/collector collector:import-gtfs
```

Der Import lädt den bundesweiten Feed, filtert auf `GTFS_AGENCY_FILTER` (Standard: „Magdeburger Verkehrsbetriebe")
und pusht das Ergebnis an die Engine. Unveränderte Feeds werden anhand von ETag/SHA-256 übersprungen.

### 3. Admin-Schaltzentrale

```bash
cd admin
npm install
cp .env.example .env        # VITE_API_BASE_URL auf die Engine zeigen lassen
npm run dev                 # http://localhost:5173
```

Login mit den Zugangsdaten aus `ADMIN_EMAIL` / `ADMIN_PASSWORD` der Engine.

### 4. Viewer

Noch nicht initialisiert — siehe Iteration I-07 in der [ROADMAP](shared/ROADMAP.md).

---

## Tests

```bash
cd engine && composer test          # PHPUnit: Unit- + Feature-Tests
cd collector && vendor/bin/phpunit  # PHPUnit
```

API-Tests liegen als [Bruno](https://www.usebruno.com/)-Collection in [shared/bruno/](shared/bruno/). Die Umgebung
`environments/local.bru` enthält Tokens und ist bewusst nicht eingecheckt.

---

## Konventionen

| Thema | Regel |
|---|---|
| Code (Funktionen, Variablen, DB-Spalten) | Englisch — DB in `snake_case` |
| Kommentare und Fachdokumentation | Deutsch |
| Zeitstempel in der DB | `TIMESTAMPTZ`, intern durchgängig UTC |
| Zeitanzeige | Konvertierung ausschließlich in `timezone.ts` des jeweiligen Frontends |
| Fehlerformat der API | `{ "error": { "code": int, "message": string } }` |
| Schema-Änderungen | Immer als Laravel-Migration, kein manuelles DDL |
| Neue Endpunkte | Zuerst [`shared/openapi.yaml`](shared/openapi.yaml) + Bruno-Datei, dann implementieren |

---

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [shared/SPEC.md](shared/SPEC.md) | Fachliche Anforderungen, Datenmodell, Matching-Algorithmus |
| [shared/PROJECT_MAP.md](shared/PROJECT_MAP.md) | Verzeichnisstruktur, Infrastruktur, Datenfluss im Detail |
| [shared/ROADMAP.md](shared/ROADMAP.md) | Iterationsplan und aktueller Umsetzungsstand |
| [shared/DEPLOYMENT.md](shared/DEPLOYMENT.md) | Live-Betrieb: Engine, Collector, Cron, Backup, Überwachung |
| [shared/FAHRPLANPERIODEN.md](shared/FAHRPLANPERIODEN.md) | Konzept Fahrplanperioden und Fahrplantypen |
| [shared/INTEGRATION_MDKURSTRACKER.md](shared/INTEGRATION_MDKURSTRACKER.md) | Anbindung an MDKursTracker, validierter Matching-Ansatz |
| [shared/openapi.yaml](shared/openapi.yaml) | API-Kontrakt (OpenAPI 3.x) |
| [CLAUDE.md](CLAUDE.md) | Arbeitsanweisungen für KI-Coding-Agenten |

---

## Stand

Umgesetzt sind Fundament, GTFS-Import inklusive Audit-Historie, Stammdaten-API, Sanctum-Auth sowie aus der
Admin-Schaltzentrale das Grundgerüst, das Import-Auditing, die Fahrplantyp-Klassifikation und die Linien-/Fahrten-Ansicht.

Als Nächstes folgt der MVP-Pfad zum Matching-Workflow: Sichtungs-API → Matching-Logik → Zuordnung & Umläufe →
Admin-Matching-UI. Details in der [ROADMAP](shared/ROADMAP.md).

---

## Lizenz

[MIT](LICENSE) © 2026 Jörg Schönebaum

Die GTFS-Fahrplandaten stammen von [gtfs.de](https://gtfs.de/) und unterliegen deren eigenen Lizenzbedingungen.
