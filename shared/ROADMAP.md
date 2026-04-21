# ROADMAP.md — MD-Takt
> Iterative Umsetzungsplanung. Jede Iteration ist in sich abgeschlossen und liefert ein testbares Ergebnis.

---

## Übersicht

| Iteration | Titel | Modul(e) | Ziel |
|---|---|---|---|
| **I-01** | Fundament | Engine | Laravel-Projekt läuft, DB-Schema steht |
| **I-02** | GTFS-Import | Collector + Engine | Tram-Fahrplandaten sind in der DB |
| **I-03** | Stammdaten-API | Engine + Shared | Linien, Haltestellen, Trips abrufbar |
| **I-04** | Sichtungs-API | Engine + Shared | Sichtungen können gespeichert & gelesen werden |
| **I-05** | Matching-Logik | Engine | Trip-Kandidaten werden für eine Sichtung berechnet |
| **I-06** | Zuordnung & Umläufe | Engine | Zuordnung bestätigen, Umlauf-Tagesansicht per API |
| **I-07** | Viewer Grundgerüst | Viewer | Vue-App läuft, Tagesansicht zeigt Umläufe |
| **I-08** | Matching-Workflow | Viewer | Kompletter manueller Matching-Workflow im Browser |
| **I-09** | Collector-Integration | Collector | Automatischer GTFS-Import & Sichtungs-Sync vom NAS |
| **I-10** | Stabilisierung | Alle | Logging, Fehlerbehandlung, Bruno-Tests vervollständigen |

---

## I-01 — Fundament

**Ziel:** Das Projekt ist aufgesetzt, die Datenbank läuft, das Schema ist migriert.

### Aufgaben
- [ ] Mono-Repo anlegen (`/collector`, `/engine`, `/viewer`, `/shared`)
- [ ] Laravel 13 Projekt in `/engine` initialisieren
- [ ] `.env.example` mit `DB_TIMEZONE=UTC`, `APP_TIMEZONE=UTC` anlegen
- [ ] PostgreSQL-Verbindung konfigurieren und testen
- [ ] Migrationen erstellen und ausführen:
  - `routes` (Tram-Linien)
  - `stops` (Haltestellen)
  - `trips` (Fahrten)
  - `stop_times` (Haltezeiten)
  - `calendar_dates` (Betriebstage)
  - `sightings` (Realsichtungen inkl. `assigned_trip_id`)
- [ ] Alle Zeitstempel-Felder als `TIMESTAMPTZ` prüfen
- [ ] Indizes anlegen: `course_number`, `observed_at`, `assigned_trip_id`

### Abnahmekriterium
`php artisan migrate` läuft fehlerfrei, alle Tabellen existieren mit korrektem Schema.

---

## I-02 — GTFS-Import

**Ziel:** Aktuelle Tram-Fahrplandaten aus dem MVB-GTFS-Feed sind in der Datenbank.

### Aufgaben
- [ ] PHP CLI Projekt in `/collector` initialisieren (Composer, PSR-4)
- [ ] `GtfsFeedService` implementieren:
  - GTFS-ZIP von `https://gtfs.de/de/feeds/de_nv/` herunterladen
  - Entpacken, relevante `.txt`-Dateien einlesen
  - Filtern auf `route_type = 0` (nur Tram)
- [ ] Daten normalisieren (Zeitstempel → UTC, Encoding prüfen)
- [ ] Import-Endpunkt in Engine: `POST /api/v1/collector/gtfs-import`
  - Bearer-Token-Middleware
  - Upsert-Logik (kein Duplikat bei erneutem Import)
- [ ] `GtfsImportCommand` (CLI) ruft Service auf und pusht an Engine
- [ ] Unit Test: `GtfsFeedServiceTest` — Filtern auf route_type=0, Zeitstempel-Normalisierung
- [ ] Bruno-Datei: `shared/bruno/collector/gtfs-import.bru`
- [ ] Logging: Import-Start (`INFO`), Anzahl importierter Trips (`INFO`), Fehler (`ERROR`)

### Abnahmekriterium
Nach Ausführen des CLI-Commands sind Tram-Linien, Haltestellen und Fahrten des aktuellen MVB-Feeds in der DB. Kein zweiter Import erzeugt Duplikate.

---

## I-03 — Stammdaten-API

**Ziel:** Linien, Haltestellen und Trips sind über die API abrufbar. Basis für den Viewer und das Matching.

### Aufgaben
- [ ] Eloquent Models: `Route`, `Stop`, `Trip`, `StopTime`, `CalendarDate`
- [ ] Laravel API Resources für alle Models
- [ ] Endpunkte implementieren:
  - `GET /api/v1/lines` — alle Tram-Linien
  - `GET /api/v1/stops` — alle Haltestellen
  - `GET /api/v1/trips?date=&line=&stop=&time=` — gefilterte Trips (Vorbereitung Matching)
- [ ] `openapi.yaml` in `/shared` für diese Endpunkte pflegen
- [ ] Unit Tests: `TripFilterServiceTest` — Filter nach Datum, Linie, Haltestelle, Zeitfenster
- [ ] Bruno-Dateien: `trips/find-candidates.bru`, `shared/bruno/environments/local.bru`

### Abnahmekriterium
Alle drei Endpunkte liefern korrekte JSON-Antworten. Bruno-Tests laufen grün.

---

## I-04 — Sichtungs-API

**Ziel:** Sichtungen aus MDKursTracker können gespeichert und tagesweise abgerufen werden.

### Aufgaben
- [ ] Eloquent Model: `Sighting`
- [ ] `SightingService` mit Methoden `storeSighting()`, `getSightingsByDate()`
- [ ] Endpunkte implementieren:
  - `POST /api/v1/collector/sightings` — Batch-Import (Bearer-Token geschützt)
  - `GET /api/v1/sightings?date=` — Sichtungen eines Betriebstags
- [ ] Validierung: `course_number`, `line`, `observed_at` sind Pflichtfelder
- [ ] Duplikat-Erkennung: gleiche `course_number` + `observed_at` + `stop_name` nicht doppelt speichern
- [ ] Unit Tests: `SightingServiceTest` — Speichern, Duplikat-Erkennung, Datumsfilter
- [ ] Bruno-Dateien: `sightings/create.bru`, `sightings/list.bru`
- [ ] Logging: Neue Sichtung (`INFO`), Duplikat übersprungen (`WARNING`)

### Abnahmekriterium
Sichtungen können per POST gespeichert und per GET tagesweise abgerufen werden. Duplikate werden still ignoriert.

---

## I-05 — Matching-Logik

**Ziel:** Für eine Sichtung werden passende GTFS-Trip-Kandidaten berechnet und zurückgegeben.

> ⚠️ Vor Implementierung: Zeitfenster-Toleranz (±N Minuten) mit Jörg abstimmen.

### Aufgaben
- [ ] `TripMatchingService` implementieren:
  - `findCandidates(Sighting $sighting): Collection` — 4-stufiger Filter (siehe SPEC §3.2)
  - Zeitfenster-Toleranz als konfigurierbare Konstante (`.env: MATCHING_WINDOW_MINUTES`)
- [ ] Endpunkt: `GET /api/v1/trips?date=&line=&stop=&time=` (aus I-03 erweitern)
  - Gibt geordnete Kandidatenliste zurück (nächster zeitlicher Match zuerst)
- [ ] Sonderfall: keine Kandidaten → leeres Array + `WARNING`-Log, kein Fehler
- [ ] Unit Tests: `TripMatchingServiceTest`
  - Happy Path: Sichtung mit eindeutigem Treffer
  - Kein Treffer: leeres Ergebnis
  - Grenzfall: Sichtung genau am Rand des Zeitfensters
  - Grenzfall: Sichtung kurz vor Mitternacht (Betriebstag-Wechsel)
- [ ] Bruno-Datei: `trips/find-candidates.bru` mit Assertion auf Kandidatenliste

### Abnahmekriterium
Für eine Beispiel-Sichtung vom aktuellen Tag liefert der Endpunkt mindestens einen plausiblen Trip-Kandidaten zurück.

---

## I-06 — Zuordnung & Umläufe

**Ziel:** Eine Sichtung kann einem Trip zugeordnet werden. Umläufe eines Tages sind per API abrufbar.

### Aufgaben
- [ ] `BlockResolverService` implementieren:
  - `assignTripToSighting(int $sightingId, string $tripId): Sighting`
  - `getBlocksByDate(string $date): Collection` — gruppiert nach `course_number`
  - `getBlockDetail(string $courseNumber, string $date): array`
- [ ] Endpunkte implementieren:
  - `POST /api/v1/sightings/{id}/assign` — `trip_id` im Body, speichert in `assigned_trip_id`
  - `GET /api/v1/blocks?date=` — alle Umläufe eines Tages
  - `GET /api/v1/blocks/{course_number}?date=` — Umlauf-Detail mit allen Trips
- [ ] Umlauf-Abfrage als PostgreSQL-CTE formulieren (keine Subqueries in PHP)
- [ ] Unit Tests: `BlockResolverServiceTest` — Zuordnung, Umlauf-Gruppierung, leerer Tag
- [ ] Bruno-Dateien: `sightings/assign-trip.bru`, `blocks/list-by-date.bru`, `blocks/get-by-course.bru`
- [ ] Logging: Zuordnung gespeichert (`INFO`), Zuordnung überschrieben (`WARNING`)

### Abnahmekriterium
Eine Sichtung kann einem Trip zugeordnet werden. `GET /api/v1/blocks?date=heute` liefert alle Umläufe mit ihren Trips als gruppierte JSON-Antwort.

---

## I-07 — Viewer Grundgerüst

**Ziel:** Die Vue-App läuft und zeigt die Tagesübersicht der Umläufe.

### Aufgaben
- [ ] Vue 3 Projekt in `/viewer` initialisieren (Vite, TypeScript, Tailwind)
- [ ] Zentraler API-Service: `/viewer/src/services/api.ts` (Axios, Basis-URL aus `.env`)
- [ ] Axios-Interceptor für zentrales Fehler-Handling
- [ ] `timezone.ts` Utility: UTC → `Europe/Berlin` Konvertierung
- [ ] Vue Router einrichten: `/` → DayView, `/blocks/:course` → BlockDetailView
- [ ] `DayView` implementieren:
  - Datumsauswahl (Standard: heute)
  - Liste aller Umläufe des Tages (`GET /api/v1/blocks?date=`)
  - Je Umlauf: Kursnummer, Anzahl Trips, erster/letzter Trip
- [ ] Ladeindikator und leerer Zustand ("Keine Umläufe für diesen Tag")

### Abnahmekriterium
Im Browser ist eine Liste aller Umläufe des heutigen Tages sichtbar. Uhrzeiten werden in `Europe/Berlin` angezeigt.

---

## I-08 — Matching-Workflow im Viewer

**Ziel:** Der komplette manuelle Matching-Workflow ist im Browser nutzbar.

### Aufgaben
- [ ] `MatchingView` implementieren:
  - Eingabe: Kursnummer, Linie, Richtung, Uhrzeit, Haltestelle
  - Button „Kandidaten suchen" → `GET /api/v1/trips?...`
  - Kandidatenliste mit Uhrzeit, Linie, Start-/Endhaltestelle
  - Button „Zuordnen" je Kandidat → `POST /api/v1/sightings/{id}/assign`
  - Bestätigungs-Feedback nach erfolgreicher Zuordnung
- [ ] `BlockDetailView` implementieren:
  - Alle zugeordneten Trips eines Umlaufs chronologisch
  - Noch nicht zugeordnete Sichtungen der gleichen `course_number` hervorheben
- [ ] Sonderfall: keine Kandidaten → Hinweistext mit Handlungsempfehlung
- [ ] Navigationsleiste: DayView ↔ MatchingView

### Abnahmekriterium
Eine Sichtung kann vollständig im Browser von der Eingabe bis zur Trip-Zuordnung durchgeführt werden, ohne die API direkt aufzurufen.

---

## I-09 — Collector-Integration

**Ziel:** GTFS-Import und Sichtungs-Sync laufen automatisiert vom NAS.

> ⚠️ Vor Implementierung: Schnittstelle zu MDKursTracker (API vs. NaruaDB-Direktzugriff) final entscheiden.

### Aufgaben
- [ ] Schnittstellen-Entscheidung MDKursTracker dokumentieren
- [ ] `SightingImportService` im Collector:
  - Sichtungen aus MDKursTracker lesen (API oder DB)
  - Normalisieren & als Batch an `POST /api/v1/collector/sightings` senden
- [ ] CLI-Commands:
  - `collector:import-gtfs` — GTFS-Feed laden & importieren
  - `collector:sync-sightings` — neue Sichtungen synchronisieren
- [ ] Fehlerbehandlung: HTTP-Timeouts, DB-Verbindungsfehler, Feed nicht erreichbar
- [ ] Logging: alle Schritte auf `stdout` + tägliche Logdatei (Monolog `StreamHandler`)
- [ ] Cron-Eintrag auf NAS dokumentieren (z.B. GTFS täglich 03:00, Sichtungen stündlich)

### Abnahmekriterium
Beide CLI-Commands laufen auf dem NAS fehlerfrei durch. Neue Sichtungen erscheinen nach dem Sync im Viewer.

---

## I-10 — Stabilisierung

**Ziel:** Das System ist produktionsreif. Logging, Tests und Dokumentation sind vollständig.

### Aufgaben
- [ ] Alle Bruno-Dateien vervollständigen und gegen Live-API testen
- [ ] `openapi.yaml` mit allen finalen Endpunkten abgleichen
- [ ] Feature-Tests für alle API-Endpunkte (`/engine/tests/Feature/`)
- [ ] Test-Coverage-Report erstellen, Lücken schließen
- [ ] Log-Ausgaben auf allen Ebenen prüfen (kein DEBUG im Production-Channel)
- [ ] `.env.example` für alle drei Module finalisieren
- [ ] `README.md` im Repo-Root: Setup-Anleitung für alle drei Module
- [ ] Deployment-Checkliste: Hetzner-Config, Subdomain-Setup, Cron-Einträge

### Abnahmekriterium
Ein frischer Checkout mit `README.md` als einziger Anleitung führt zu einem lauffähigen System. Alle Bruno-Tests laufen grün gegen die Live-API.

---

## Offene Punkte (vor jeweiliger Iteration zu klären)

| Thema | Relevant ab | Status |
|---|---|---|
| Zeitfenster-Toleranz beim Matching (±N Minuten) | I-05 | ❓ offen |
| Umgang mit Sichtungen ohne GTFS-Trip (Betriebsfahrten) | I-05 | ❓ offen |
| Schnittstelle MDKursTracker (API vs. NaruaDB) | I-09 | ❓ offen |
| Cron-Intervall für Sichtungs-Sync | I-09 | ❓ offen |
