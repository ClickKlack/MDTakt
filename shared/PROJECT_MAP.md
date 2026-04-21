# PROJECT_MAP: MD-Takt
> Version 2.0 — optimiert für KI-Coding-Agenten (Claude Code, Cursor)

---

## Mono-Repo Struktur

```
md-takt/
│
├── collector/                        # PHP CLI Tool — läuft auf lokalem NAS
│   ├── src/
│   │   ├── Commands/                 # CLI-Einstiegspunkte (GTFS-Import, Sichtungs-Sync)
│   │   ├── Services/                 # Business-Logik (GtfsFeedService, SightingImportService)
│   │   └── Http/                     # HTTP-Client für Engine-API
│   ├── tests/
│   ├── composer.json
│   └── .env.example
│
├── engine/                           # Laravel 11 API — Hetzner Shared Hosting
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/          # Dünn — nur Request/Response, keine Logik
│   │   │   ├── Middleware/           # z.B. CollectorTokenMiddleware
│   │   │   └── Resources/            # Laravel API Resources (JSON-Ausgabe)
│   │   ├── Models/                   # Eloquent Models (Trip, Stop, Sighting, ...)
│   │   └── Services/                 # Business-Logik (TripMatchingService, BlockResolverService)
│   ├── database/
│   │   ├── migrations/               # Jede Schema-Änderung als Migration — kein manuelles DDL
│   │   └── factories/                # TripFactory, SightingFactory, StopTimeFactory
│   ├── routes/
│   │   └── api.php                   # Alle API-Routen (v1)
│   ├── tests/
│   │   ├── Unit/                     # Service-Tests (PHPUnit)
│   │   └── Feature/                  # Endpunkt-Tests
│   ├── config/
│   │   └── app.php                   # timezone: 'UTC' — nicht ändern
│   └── .env.example                  # inkl. DB_TIMEZONE=UTC
│
├── viewer/                           # Vue 3 SPA — Hetzner Shared Hosting
│   ├── src/
│   │   ├── components/               # Wiederverwendbare Vue-Komponenten
│   │   ├── views/                    # Seiten (DayView, BlockDetailView, MatchingView)
│   │   ├── services/
│   │   │   └── api.ts                # Zentraler Axios-Client (inkl. Interceptor)
│   │   └── utils/
│   │       └── timezone.ts           # Europe/Berlin-Konvertierung — NUR hier
│   ├── public/
│   ├── vite.config.ts
│   └── package.json
│
└── shared/                           # Gemeinsame Definitionen — kein ausführbarer Code
    ├── openapi.yaml                  # API-Kontrakt (OpenAPI 3.x) — vor Implementierung pflegen
    └── bruno/                        # API-Tests (Bruno)
        ├── environments/
        │   ├── local.bru             # Base-URL: http://localhost, API-Token aus Env
        │   └── production.bru        # Base-URL: https://api.strassenbahn-magdeburg.de
        ├── sightings/
        │   ├── list.bru
        │   ├── create.bru
        │   └── assign-trip.bru
        ├── blocks/
        │   ├── list-by-date.bru
        │   └── get-by-course.bru
        ├── trips/
        │   └── find-candidates.bru
        └── collector/
            ├── gtfs-import.bru
            └── sightings-batch.bru
```

---

## Infrastruktur

| Umgebung | URL | Modul |
|---|---|---|
| Frontend (Live) | https://app.strassenbahn-magdeburg.de | `/viewer` |
| Backend-API (Live) | https://api.strassenbahn-magdeburg.de | `/engine` |
| Hauptdomain | https://strassenbahn-magdeburg.de | — |
| Collector | Lokales NAS (kein öffentlicher Zugriff) | `/collector` |

---

## Datenfluss

```
[gtfs.de Feed]
      |
      | HTTP-Download (täglich)
      ↓
[Collector — NAS]
      |-- lädt GTFS-ZIP, entpackt, filtert auf Tram (route_type=0)
      |-- holt Sichtungen aus MDKursTracker (API oder NaruaDB, TBD)
      |-- normalisiert Zeitstempel auf UTC
      |
      | POST /api/v1/collector/* (Bearer-Token)
      ↓
[Engine — Laravel API]
      |-- validiert Eingabedaten
      |-- speichert in PostgreSQL (alles UTC / TIMESTAMPTZ)
      |-- stellt Matching-Endpunkte bereit
      |
      | GET /api/v1/* (kein Auth im MVP)
      ↓
[Viewer — Vue 3 SPA]
      |-- zeigt Tagesübersicht der Umläufe
      |-- Matching-Workflow: Sichtung → Kandidaten → Bestätigung
      |-- konvertiert UTC → Europe/Berlin nur in timezone.ts
```

---

## Arbeitsregeln für Agenten

- **Vor jeder Aufgabe:** `SPEC.md` und diese Datei lesen.
- **Neuer API-Endpunkt:** zuerst `shared/openapi.yaml` + Bruno-Datei anlegen, dann implementieren.
- **Schema-Änderung:** zuerst Migration in `engine/database/migrations/`, dann Model/Service.
- **Zeitzone:** Konvertierung nach `Europe/Berlin` ausschließlich in `viewer/src/utils/timezone.ts`.
- **Unklarheit zur Fachlogik:** stoppen und nachfragen — nicht eigenständig entscheiden.
