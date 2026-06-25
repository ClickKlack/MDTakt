# ROADMAP.md — MD-Takt
> Iterative Umsetzungsplanung. Jede Iteration ist in sich abgeschlossen und liefert ein testbares Ergebnis.

---

## Übersicht

| Iteration | Titel | Modul(e) | Ziel |
|---|---|---|---|
| **I-01** | Fundament | Engine | Laravel-Projekt läuft, DB-Schema steht |
| **I-02** | GTFS-Import | Collector + Engine | MVB-Fahrplandaten (Tram + Bus) sind in der DB |
| **I-02b** | Import-Audit | Engine + Collector | Import-Historie & Datenstand nachvollziehbar |
| **I-03** | Stammdaten-API | Engine + Shared | Linien, Haltestellen, Trips abrufbar |
| **I-04** | Sichtungs-API | Engine + Shared | Sichtungen können gespeichert & gelesen werden |
| **I-05** | Matching-Logik | Engine | Trip-Kandidaten werden für eine Sichtung berechnet |
| **I-06** | Zuordnung & Umläufe | Engine | Zuordnung bestätigen, Umlauf-Tagesansicht per API |
| **I-07** | Viewer Grundgerüst | Viewer | Vue-App läuft, Tagesansicht zeigt Umläufe |
| **I-08** | Matching-Workflow | Viewer | Kompletter manueller Matching-Workflow im Browser |
| **I-09** | Collector-Integration | Collector | Automatischer GTFS-Import & Sichtungs-Sync vom NAS |
| **I-10** | Stabilisierung | Alle | Logging, Fehlerbehandlung, Bruno-Tests vervollständigen |
| **I-11** | Auth-Fundament | Engine | Laravel Sanctum: Admin-Login & geschützte `/admin`-Endpunkte |
| **I-12** | Admin-Tool: Import-Audit | Admin + Engine | Separates Admin-Frontend zeigt Import-Historie & Datenstand |

> **I-11/I-12** sind Post-MVP-Erweiterungen (Admin-Tool fürs GTFS-Import-Auditing, SPEC §10). Sie hängen nur an Engine + **I-02b** — nicht am öffentlichen Viewer — und können daher unabhängig vom MVP-Strang (I-03…I-10) vorgezogen werden.

---

## I-01 — Fundament

**Ziel:** Das Projekt ist aufgesetzt, die Datenbank läuft, das Schema ist migriert.

### Aufgaben
- [x] Mono-Repo anlegen (`/collector`, `/engine`, `/viewer`, `/shared`)
- [x] Laravel 13 Projekt in `/engine` initialisieren
- [x] `.env.example` mit `DB_TIMEZONE=UTC`, `APP_TIMEZONE=UTC` anlegen
- [x] PostgreSQL-Verbindung konfigurieren und testen
- [x] Migrationen erstellen und ausführen:
  - `routes` (MVB-Linien)
  - `stops` (Haltestellen)
  - `trips` (Fahrten)
  - `stop_times` (Haltezeiten)
  - `calendar` (Wochenmuster Betriebstage) — nachträglich in I-02 ergänzt
  - `calendar_dates` (Ausnahmen zum Wochenmuster)
  - `sightings` (Realsichtungen inkl. `assigned_trip_id`)
- [x] Alle Zeitstempel-Felder als `TIMESTAMPTZ` prüfen
- [x] Indizes anlegen: `course_number`, `observed_at`, `assigned_trip_id`

### Abnahmekriterium
`php artisan migrate` läuft fehlerfrei, alle Tabellen existieren mit korrektem Schema.

---

## I-02 — GTFS-Import

**Ziel:** Aktuelle MVB-Fahrplandaten (Tram + Bus) aus dem GTFS-Feed sind in der Datenbank.

### Aufgaben
- [x] PHP CLI Projekt in `/collector` initialisieren (Composer, PSR-4)
- [x] `GtfsFeedService` implementieren:
  - GTFS-ZIP von `https://gtfs.de/de/feeds/de_nv/` herunterladen
  - Entpacken, relevante `.txt`-Dateien einlesen
  - Filtern **allein auf die MVB-Agency** (`GTFS_AGENCY_FILTER`) — alle Verkehrsmittel (Tram + Bus), `route_type` wird übernommen
- [x] Daten normalisieren (Zeit-Strings HH:MM:SS inkl. >24h, Datum YYYYMMDD→ISO, BOM/Encoding)
- [x] Import-Endpunkt in Engine: `POST /api/v1/collector/gtfs-import`
  - Bearer-Token-Middleware
  - Upsert-Logik (kein Duplikat bei erneutem Import)
- [x] `GtfsImportCommand` (CLI) ruft Service auf und pusht an Engine
- [x] Unit Test: `GtfsFeedServiceTest` — Agency-Filter (alle Verkehrsmittel), Zeitstempel-Normalisierung
- [x] Bruno-Datei: `shared/bruno/collector/gtfs-import.bru`
- [x] Logging: Import-Start (`INFO`), Anzahl importierter Trips (`INFO`), Fehler (`ERROR`)

### Abnahmekriterium
Nach Ausführen des CLI-Commands sind alle MVB-Linien (Tram + Bus), Haltestellen und Fahrten des aktuellen Feeds in der DB. Kein zweiter Import erzeugt Duplikate.

---

## I-02b — Import-Audit (Nachvollziehbarkeit)

**Ziel:** Jeder GTFS-Import wird protokolliert; Erfolg, Datenstand und Historie sind per API abrufbar.

> Nachträglich ergänzt (nicht im ursprünglichen Plan), weil die GTFS-Tabellen keine Zeitstempel haben und es bis dahin keine Möglichkeit gab, den Import-Datenstand nachzuvollziehen.

### Aufgaben
- [x] Migration `gtfs_import_runs` (Status, started/finished, Feed-Version + Gültigkeit, Counts `jsonb`, Fehler)
- [x] Enum `GtfsImportStatus` (`running|success|failed`), Eloquent-Model `GtfsImportRun`
- [x] `GtfsImportService` protokolliert jeden Lauf: Anlegen vor der Transaktion (überlebt Rollback), Erfolg/Fehler-Update danach
- [x] Collector liest `feed_info.txt` (Feed-Version, Gültigkeitszeitraum) und sendet sie mit
- [x] Endpunkt `GET /api/v1/collector/imports` — letzte Läufe + aktueller Datenbestand (Token-geschützt)
- [x] `openapi.yaml` + Bruno-Datei `collector/imports.bru`
- [x] Tests: Lauf-Aufzeichnung inkl. feed_info (Engine), Status-Endpunkt, feed_info-Parsing (Collector)

### Abnahmekriterium
`GET /api/v1/collector/imports` liefert die Historie der Import-Läufe (Status, Zeitpunkte, Counts, Feed-Version) und den aktuellen Datenbestand. Ein fehlgeschlagener Lauf wird als `failed` mit Fehlermeldung protokolliert.

---

## I-03 — Stammdaten-API

**Ziel:** Linien, Haltestellen und Trips sind über die API abrufbar. Basis für den Viewer und das Matching.

### Aufgaben
- [x] Eloquent Models: `Route`, `Stop`, `Trip`, `StopTime`, `CalendarDate` (+ `Calendar` für Betriebstag-Logik)
- [x] Laravel API Resources für alle Models (`RouteResource`, `StopResource`, `TripResource`)
- [x] Endpunkte implementieren:
  - `GET /api/v1/lines` — alle MVB-Linien (Tram + Bus)
  - `GET /api/v1/stops` — alle Haltestellen
  - `GET /api/v1/trips?date=&line=&stop=` — gefilterte Trips (Vorbereitung Matching); `time=`/Toleranz bewusst auf I-05 verschoben
- [x] `openapi.yaml` in `/shared` für diese Endpunkte pflegen
- [x] Unit Tests: `TripFilterServiceTest` — Filter nach Datum, Linie, Haltestelle (calendar-Wochenmuster + calendar_dates-Ausnahmen); Zeitfenster folgt mit `time=` in I-05
- [x] Bruno-Dateien: `lines/list.bru`, `stops/list.bru`, `trips/find-candidates.bru` (Environment `local.bru` bestand bereits)

### Abnahmekriterium
Alle drei Endpunkte liefern korrekte JSON-Antworten. Bruno-Tests laufen grün.

> **Hinweis:** Der `time=`-Filter und die Zeitfenster-Toleranz (`MATCHING_WINDOW_MINUTES`)
> wurden bewusst auf I-05 verschoben (Stopp-Regel: Matching-Algorithmus). I-03 liefert
> die strukturellen Filter Datum/Linie/Haltestelle; I-05 erweitert `GET /trips` um die zeitliche Feinauswahl.

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
> 📄 **Matching-Ansatz am realen Datensatz validiert (2026-06-22):** siehe
> [`INTEGRATION_MDKURSTRACKER.md`](INTEGRATION_MDKURSTRACKER.md) §4 — deterministischer
> Soll-Zeit-Sequenz-Match, 1 HAFAS-Fahrt → N GTFS-Trips, kein Stop-ID-Crosswalk. §3.2-Neufassung noch offen.

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
> 📄 **Schnittstelle geklärt (2026-06-22):** über **HTTP-API** (MDKursTracker = MariaDB nur lokal). Engine
> bleibt reiner Server; Sync-Cron empfohlen in MDKursTracker; Collector nur GTFS. Details + offene Punkte:
> [`INTEGRATION_MDKURSTRACKER.md`](INTEGRATION_MDKURSTRACKER.md) §3/§5.

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

## I-11 — Auth-Fundament (Sanctum)

**Ziel:** Geschützter Admin-Zugang zur Engine als Basis für das Admin-Tool (I-12).

> Post-MVP. Vorgezogen aus dem „Zukunft"-Punkt der SPEC §6, weil das Admin-Tool einen Login braucht. Setzt nur Engine + I-02b voraus, nicht den Viewer.

### Aufgaben
- [ ] Laravel Sanctum installieren und konfigurieren
- [ ] Admin-Zugang: Single-Admin (Seed/`.env`) **oder** `users`-Tabelle + Migration — *offener Punkt, vorab klären*
- [ ] Login-Endpunkt `POST /api/v1/admin/login` (gibt Sanctum-Token zurück), Logout
- [ ] Middleware/Guard für alle `/api/v1/admin/*`-Routen (`auth:sanctum`)
- [ ] `openapi.yaml` + Bruno-Dateien: `admin/login.bru`, `environments/local.bru` um Admin-Token erweitern
- [ ] Tests: Login Erfolg/Fehlschlag, geschützter Endpunkt ohne/mit Token (401 vs. 200)

### Abnahmekriterium
Ein Admin meldet sich an und erhält ein Sanctum-Token. Jeder `/api/v1/admin/*`-Aufruf ohne gültiges Token wird mit `401` im Fehler-Envelope abgewiesen.

---

## I-12 — Admin-Tool: Import-Auditing

**Ziel:** Ein separates Admin-Frontend macht den GTFS-Import nachvollziehbar (Historie, Datenstand, Fehler).

> ⚠️ Vor Implementierung: I-11 (Auth) muss stehen. Konzept siehe SPEC §10.

### Aufgaben
- [ ] Engine: gemeinsamen `GtfsImportStatusService` extrahieren (Abfrage-Logik aus dem bestehenden `/collector/imports`-Controller herauslösen, damit kein Duplikat entsteht)
- [ ] Engine: `GET /api/v1/admin/imports` (Sanctum-geschützt) — nutzt denselben Service, ohne Collector-Token
- [ ] Vue 3 Projekt in `/admin` initialisieren (Vite, TypeScript, Tailwind) — **getrennt** vom Viewer
- [ ] Sanctum-Login-Flow im Admin-Frontend (Token speichern, Axios-Interceptor, Logout)
- [ ] `timezone.ts` im Admin (`admin/src/utils/timezone.ts`): UTC → `Europe/Berlin`, nur hier
- [ ] View „Import-Historie": Liste der Läufe mit Status-Badge (`success`/`failed`/`running`), Start-/Endzeit, Counts, Feed-Version/Gültigkeit
- [ ] View „Datenstand": aktueller Bestand je Tabelle + letzter erfolgreicher Import
- [ ] Detailansicht eines Laufs inkl. Fehlermeldung bei `failed`
- [ ] `openapi.yaml` + Bruno-Datei: `admin/imports.bru`

### Abnahmekriterium
Nach Login zeigt das Admin-Tool die Historie der GTFS-Importe und den aktuellen Datenstand. Ein fehlgeschlagener Lauf ist als `failed` mit Fehlermeldung erkennbar. Uhrzeiten erscheinen in `Europe/Berlin`.

---

## Offene Punkte (vor jeweiliger Iteration zu klären)

| Thema | Relevant ab | Status |
|---|---|---|
| Zeitfenster-Toleranz beim Matching (±N Minuten) | I-05 | ❓ offen |
| Umgang mit Sichtungen ohne GTFS-Trip (Betriebsfahrten) | I-05 | ❓ offen |
| Schnittstelle MDKursTracker (API vs. NaruaDB) | I-09 | ✅ geklärt: HTTP-API (siehe INTEGRATION_MDKURSTRACKER.md) |
| Cron-Intervall für Sichtungs-Sync | I-09 | ❓ offen |
| Admin-Zugangsmodell (Single-Admin via Seed vs. `users`-Tabelle) | I-11 | ❓ offen |
| Subdomain/Hosting fürs Admin-Tool (`admin.strassenbahn-magdeburg.de`?) | I-12 | ❓ offen |
