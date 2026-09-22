# ROADMAP.md — MD-Takt
> Iterative Umsetzungsplanung. Jede Iteration ist in sich abgeschlossen und liefert ein testbares Ergebnis.

---

## Übersicht

| Iteration | Titel | Modul(e) | Ziel | Stand |
|---|---|---|---|---|
| **I-01** | Fundament | Engine | Laravel-Projekt läuft, DB-Schema steht | ✅ |
| **I-02** | GTFS-Import | Collector + Engine | MVB-Fahrplandaten (Tram + Bus) sind in der DB | ✅ |
| **I-02b** | Import-Audit | Engine + Collector | Import-Historie & Datenstand nachvollziehbar | ✅ |
| **I-03** | Stammdaten-API | Engine + Shared | Linien, Haltestellen, Trips abrufbar | ✅ |
| **I-04** | Sichtungs-API | Engine + Shared | Sichtungen können gespeichert & gelesen werden | ⬜ |
| **I-05** | Matching-Logik | Engine | Trip-Kandidaten werden für eine Sichtung berechnet | ⬜ |
| **I-06** | Zuordnung & Umläufe | Engine | Zuordnung bestätigen, Umlauf-Tagesansicht per API | ⬜ |
| **I-07** | Viewer Grundgerüst | Viewer | Öffentliche read-only Webseite, Tagesansicht der Umläufe | ⬜ |
| **I-08** | Viewer-Ausbau (Info) | Viewer | Linien-/Fahrplananzeige, Haltestellenrecherche | ⬜ |
| **I-09** | Collector-Integration | Collector | Automatischer GTFS-Import & Sichtungs-Sync vom NAS | ⬜ |
| **I-10** | Stabilisierung | Alle | Logging, Fehlerbehandlung, Bruno-Tests vervollständigen | ⬜ |
| **I-11** | Auth-Fundament | Engine | Laravel Sanctum: Admin-Login & geschützte `/admin`-Endpunkte (Voraussetzung fürs Matching) | ✅ |
| **I-12** | Admin-Schaltzentrale | Admin + Engine | Matching-Workflow, Datenkorrektur, Fahrplanperioden-Erkennung, Import-Auditing | 🟡 a, c, e-A, f, Fahrplan + Diff |
| **I-13** | **Fahrplan-Konsolidat** | Engine + Admin | Dauerhafter Fahrplan-Bestand mit allen Änderungen — aus vielen Importen zusammengeführt | ✅ |
| **I-14** | **Kurse & Umläufe** | Engine + Admin | Umlauf-Ebene manuell pflegbar: Fahrten verketten, Kursnummern vergeben | ✅ |
| **I-15** | **Mengen-Pflege** | Engine + Admin | Wiederkehrende Muster über einen Zeitraum setzen und wieder lösen | ✅ |

> **Stand am 18.08.2026.** Umgesetzt sind Fundament, Import inkl. Audit, Stammdaten-API, Auth und von der
> Admin-Schaltzentrale die Bereiche (a) Grundgerüst, (c) Import-Auditing, (e) Phase A (Fahrplantypen) und
> (f) Linien-/Fahrten-Ansicht.
>
> **Als Nächstes: I-13 (Fahrplan-Konsolidat)** — vorgezogen vor den Matching-Pfad. Danach I-04 → I-05 → I-06 → I-12b.

> **Frontend-Zuschnitt:** Der **Viewer** ist die **öffentliche, rein lesende Info-Webseite** (Linien, Fahrpläne,
> Haltestellen, Umläufe). Die **Admin-Schaltzentrale** (`/admin`, Sanctum) bündelt **alle kuratierenden/steuernden
> Aktionen** — insbesondere den **Matching-Workflow** (aus dem Viewer hierher verschoben). Folge: **I-11 (Auth) +
> der Matching-Teil von I-12 sind MVP-kritisch** (das Matching braucht Login). Die übrigen Schaltzentrale-Bereiche
> (Datenkorrektur, Fahrplanperioden-Erkennung, Import-Auditing) sind Ausbaustufen. Siehe SPEC §10.

### Umsetzungsreihenfolge (Priorisierung)

Die Iterations-Nummern sind stabile IDs, **nicht** die Reihenfolge der Umsetzung. Gewünschte Reihenfolge:

| # | Iteration | Warum hier | Stand |
|---|---|---|---|
| 1 | **I-11** Auth-Fundament (Sanctum) | Login-Voraussetzung — **Single-Admin via .env/Seed** | ✅ |
| 2 | **I-12 a/c** Admin-Grundgerüst + Import-Auditing | **Zuerst sichtbar = Vertrauen** — zeigt sofort echte GTFS-Daten | ✅ |
| 3 | **I-13** Fahrplan-Konsolidat | **Zeitkritisch** — sammelt Fahrplan-Historie, die sonst verloren geht | ✅ Phasen B und C |
| 3b | **I-14** Kurse & Umläufe | Baut das Gefüge, in das die Sichtungs-API ihre Kursnummern liefert | ✅ |
| 4 | **I-04** Sichtungs-API | Engine-Grundlage: Sichtungen speichern/lesen | ⬜ |
| 5 | **I-05** Matching-Logik | Engine-Kern fürs Matching — setzt stabile Fahrt-Identität aus I-13 voraus | ⬜ |
| 6 | **I-06** Zuordnung & Umläufe | Zuordnen + Umlauf-Abfrage | ⬜ |
| 7 | **I-12 b** Admin-Matching-Workflow | Matching-UI auf den Engine-APIs (mit Seed-/Test-Sichtungen erprobbar) | ⬜ |
| 8 | **I-09** Collector-/**MDKursTracker-Integration** | Live-Datenfluss (Fluss 1/2) **nach** dem Admin-Frontend | ⬜ |
| 9 | **I-10** Stabilisierung | Härten, Tests, Doku | ⬜ |
| 10 | **I-07 + I-08** Viewer | Öffentliche Webseite **ganz zuletzt** | ⬜ |

> **Vorgezogen (18.08.2026):** **I-13** rückt vor den Matching-Pfad. Zwei Gründe, beide am Code/Datenstand belegt:
>
> 1. **Fahrplan-Historie verfällt.** Ein Import deckt nur ein Fenster ab (aktuell 15.08.–06.09.) und **ersetzt** den
>    Bestand (`GtfsImportService::clearAllGtfsData()`). Ein vollständiger Fahrplan **mit** Baustellen, Ersatzverkehren
>    und Fahrplanwechseln entsteht nur durch Konsolidierung über viele Importe. Jeder Tag ohne sie kostet Historie —
>    das ist der einzige Arbeitsschritt hier, der sich **nicht** nachholen lässt.
> 2. **Matching stünde sonst auf Sand.** `sightings.assigned_trip_id` zeigt auf die gtfs.de-`trip_id`, die pro Build
>    neu vergeben wird; der FK nullt per `nullOnDelete` **alle Zuordnungen bei jedem Import**. Erst die stabile
>    Fahrt-Signatur aus I-13 macht Zuordnungen dauerhaft.
>
> **Kein Ziel:** rückwirkende Zuordnung von Alt-Sichtungen (entschieden 18.08.2026). Es geht um den Fahrplan selbst.
>
> Ebenfalls vorgezogen umgesetzt: **I-12 (e) Phase A** (Fahrplantypen) und **I-12 (f)** (Linien-/Fahrten-Ansicht).

**Begründung:** Das Admin-Frontend zuerst zu bauen schafft Vertrauen — der Betreiber sieht unmittelbar, was das System
tut (Import-Stand, Matching-Ergebnisse), bevor die MDKursTracker-Anbindung live geht. Der öffentliche Viewer ist die
End-Ausgabe und kommt zuletzt. Vor dem Live-Sync wird der Admin-Matching-Workflow mit **Seed-/manuell angelegten
Sichtungen** (I-04 `POST /api/v1/sightings` bzw. Test-Factories) erprobt.

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
> ⚠️ **Setzt I-13 voraus:** Zuordnungen müssen an der stabilen Fahrt-Signatur hängen, nicht an der gtfs.de-`trip_id` —
> die wird pro Build neu vergeben, und der FK `sightings.assigned_trip_id` nullt bei jedem Import alle Zuordnungen.
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

## I-07 — Viewer Grundgerüst (öffentliche Info-Webseite)

**Ziel:** Die Vue-App läuft als **öffentliche, rein lesende** Webseite und zeigt die Tagesübersicht der Umläufe.

> Der Viewer enthält **keine** schreibenden Aktionen. Der Matching-Workflow liegt in der Admin-Schaltzentrale (I-12).
> ⏱️ **Umsetzung laut Priorisierung ganz zuletzt** (nach Admin-Frontend, MDKursTracker-Sync und Stabilisierung).

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

## I-08 — Viewer-Ausbau: Linien, Fahrpläne, Haltestellen

**Ziel:** Der öffentliche Viewer wird zur informativen Webseite — Linien- und Fahrplananzeige sowie Haltestellenrecherche.

> Der frühere „Matching-Workflow im Viewer" ist in die **Admin-Schaltzentrale (I-12)** verschoben (hinter Login).

### Aufgaben
- [ ] `LinesView`: alle MVB-Linien (`GET /api/v1/lines`), Filter Tram/Bus über `mode`
- [ ] `TimetableView`: Fahrplan je Linie/Haltestelle (`GET /api/v1/trips?date=&line=&stop=`), Soll-Zeiten in `Europe/Berlin`
- [ ] `StopSearchView`: Haltestellenrecherche (`GET /api/v1/stops`), Suche/Filter nach Name
- [ ] `BlockDetailView`: alle Trips eines Umlaufs chronologisch (read-only Anzeige)
- [ ] Navigationsleiste: DayView ↔ Linien ↔ Fahrplan ↔ Haltestellen
- [ ] Lade-/Leerzustände je Ansicht

### Abnahmekriterium
Ein Nutzer kann ohne Login Linien durchsehen, einen Fahrplan je Linie/Haltestelle anzeigen, Haltestellen suchen und einen Umlauf im Detail betrachten. Alle Uhrzeiten in `Europe/Berlin`.

---

## I-09 — Collector-Integration

**Ziel:** GTFS-Import und Sichtungs-Sync laufen automatisiert vom NAS.

> ⏱️ **Reihenfolge:** Die MDKursTracker-Live-Anbindung (Fluss 1/2) erfolgt laut Priorisierung **nach** der
> Admin-Schaltzentrale (I-12) — das Admin-Frontend soll zuerst Sichtbarkeit/Vertrauen schaffen.
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
- [x] Deployment-Checkliste: [`DEPLOYMENT.md`](DEPLOYMENT.md) — Engine, Collector, Cron, Backup, Überwachung
      (vorgezogen aus I-10, weil der Live-Betrieb nicht auf die Stabilisierung warten sollte: jede nicht
      importierte Woche kostet unwiederbringlich Fahrplan-Historie)

### Abnahmekriterium
Ein frischer Checkout mit `README.md` als einziger Anleitung führt zu einem lauffähigen System. Alle Bruno-Tests laufen grün gegen die Live-API.

---

## I-11 — Auth-Fundament (Sanctum)

**Ziel:** Geschützter Admin-Zugang zur Engine als Basis für die Admin-Schaltzentrale (I-12).

> **MVP-relevant:** Da der Matching-Workflow in die Admin-Schaltzentrale wandert, ist der Login dafür Voraussetzung —
> Sanctum ist damit kein reines Post-MVP-Thema mehr. Setzt nur Engine voraus, nicht den Viewer; vor dem Matching-Teil von I-12 umzusetzen.

### Aufgaben
- [x] Laravel Sanctum installieren und konfigurieren
- [x] Admin-Zugang: **Single-Admin via `.env`/Seed** (entschieden 2026-06-25). Minimale `users`-Tabelle (Sanctum-Standard), genau ein Eintrag aus `ADMIN_EMAIL` + `ADMIN_PASSWORD` (bcrypt) via Seeder; kein Self-Signup, keine Rollen
- [x] Login-Endpunkt `POST /api/v1/admin/login` (gibt Sanctum-Token zurück), Logout
- [x] Middleware/Guard für alle `/api/v1/admin/*`-Routen (`auth:sanctum`)
- [x] `openapi.yaml` + Bruno-Dateien: `admin/login.bru`, `environments/local.bru` um Admin-Token erweitern
- [x] Tests: Login Erfolg/Fehlschlag, geschützter Endpunkt ohne/mit Token (401 vs. 200)

### Abnahmekriterium
Ein Admin meldet sich an und erhält ein Sanctum-Token. Jeder `/api/v1/admin/*`-Aufruf ohne gültiges Token wird mit `401` im Fehler-Envelope abgewiesen.

---

## I-12 — Admin-Schaltzentrale

**Ziel:** Ein separates Admin-Frontend (`/admin`, Sanctum) als zentrale Schaltzentrale — Matching, Datenkorrektur,
Fahrplanperioden-Erkennung und Import-Auditing. Alle schreibenden/kuratierenden Aktionen leben hier, getrennt vom Viewer.

> ⚠️ Vor Implementierung: I-11 (Auth) muss stehen. Konzept siehe SPEC §10. Wegen des Umfangs in Phasen (a–e) gegliedert;
> Detail-Tasks der Ausbaubereiche (d/e) werden vor der jeweiligen Phase ausspezifiziert.

### (a) Grundgerüst + Login
- [x] Vue 3 Projekt in `/admin` initialisieren (Vite, TypeScript, Tailwind) — **getrennt** vom Viewer
- [x] Sanctum-Login-Flow (Token speichern, Axios-Interceptor, Logout)
- [x] `timezone.ts` im Admin (`admin/src/utils/timezone.ts`): UTC → lokale Browser-Zeitzone, nur hier

### (b) Matching-Workflow — **MVP** (aus dem Viewer hierher verschoben)
- [ ] `MatchingView`: Sichtungsliste (`GET /api/v1/sightings?date=`), Kandidaten (`GET /api/v1/trips?...`), Zuordnen (`POST /api/v1/sightings/{id}/assign`)
- [ ] Umlauf-Kuratierung: zugeordnete Trips chronologisch, offene Sichtungen gleicher `course_number` hervorheben
- [ ] Sonderfall keine Kandidaten → Hinweis + Handlungsempfehlung
- [ ] `openapi.yaml` + Bruno für die Matching-/Assign-Endpunkte

### (c) Import-Auditing
- [x] Engine: gemeinsamen `GtfsImportStatusService` extrahieren (Abfrage-Logik aus dem `/collector/imports`-Controller herauslösen, kein Duplikat)
- [x] Engine: `GET /api/v1/admin/imports` (Sanctum) — nutzt denselben Service, ohne Collector-Token
- [x] View „Import-Historie" (Status-Badge, Zeiten, Counts, Feed-Version) + „Datenstand" + Fehlermeldung je Lauf; Historie paginiert
- [x] `openapi.yaml` + Bruno-Datei: `admin/imports.bru`

### (d) Datenkorrektur — *Ausbau, Detaillierung folgt*
- [ ] Manuelle Korrektur/Überschreibung von Zuordnungen; ggf. Stammdaten-Overrides

### (e) Fahrplanperioden-Erkennung — *Konzept steht, siehe [`FAHRPLANPERIODEN.md`](FAHRPLANPERIODEN.md)*

Gegliedert nach dem Bauplan in FAHRPLANPERIODEN §7.

**Phase A — Config & Fahrplantyp** ✅ *(abgeschlossen)*
- [x] `school_holidays` (Migration, Model, Admin-CRUD) — Ferienzeiten sind nicht berechenbar
- [x] `HolidayService`: Feiertage Sachsen-Anhalts je Jahr berechnet (fest + oster-relativ via Computus), nicht persistiert
- [x] `FahrplanTyp`-Enum + `FahrplanTypClassifier` (`classify(date)` nach FAHRPLANPERIODEN §2.1)
- [x] Admin-Ansicht „Kalender": Ferien-CRUD + Feiertage read-only
- [x] Fahrplantyp am realen Fahrplan nutzbar: `GET /lines/{line}/trips?day_type=` filtert auf einen Betriebstag-Typ, aufgelöst über einen Stichtag im Feed-Fenster (häufigste Service-Zusammensetzung)

**Phase B/C sind nach I-13 ausgelagert** — sie sind seit 18.08.2026 eigenständig priorisiert und stehen dort
mit vollständiger Aufgabenliste. Die folgenden Punkte bleiben als Kurzfassung stehen.

**Phase B — Versionierung (Metadaten)** — *offen, siehe I-13*
- [x] `schedule_periods` + `line_versions` je (Linie, Fahrplantyp) — Tabellen und Fortschreibung; der erste Lauf
      legt mangels Admin-CRUD eine `bootstrap`-Periode an
- [ ] Admin-CRUD für Perioden (anlegen, aktiv setzen, einfrieren)
- [ ] Fingerprint-Vergleich beim Import → neue Version / verlängern / einfrieren
- [ ] Periodenwechsel **vorschlagen**, wenn viele Linien gleichzeitig betroffen sind (§4.3); Schwelle noch festzulegen
- [x] Admin-Ansicht „Fahrplan-Versionen" — Historie je Linie/Typ mit Intervallen, Grenzen (gesichert/offen),
      Periode und Abdeckung; `GET /api/v1/admin/line-versions` + openapi + Bruno
- [ ] Admin-Ansicht „Fahrplanperioden" (anlegen, aktiv setzen, einfrieren)
- [ ] Periodenwechsel → betroffene Zuordnungen als *stale/neu zu bestätigen* markieren (siehe `INTEGRATION_MDKURSTRACKER.md` §4.2)

**Phase C — Konsolidat-Datenbestand** — *offen, siehe I-13*
- [ ] `consolidated_*` an `line_versions`, Merge je (Linie, Typ) nach §5.3, historische Perioden einfrieren
- [ ] App-Endpunkte (Linien/Fahrplan/Umläufe/Matching) auf das Konsolidat umstellen — berührt I-03/I-05/I-06

### (f) Linien- & Fahrten-Ansicht — *nachträglich ergänzt, nicht im ursprünglichen Plan*
- [x] `GET /api/v1/lines` — **eine Zeile je Linienbezeichnung**, nicht je GTFS-Route (dieselbe Nummer kann auf mehreren Routen liegen, z. B. Schienenersatzverkehr als Bus)
- [x] `GET /api/v1/lines/{line}/trips` — Fahrten gruppiert nach Start → Ziel, je Fahrt Verkehrstage und Verkehrsmittel
- [x] Admin-Ansicht „Linien": Signets, pflegbare Linienfarben, Fahrtenliste je Richtung/Variante mit Fahrplantyp-Filter

### Abnahmekriterium
Nach Login kann ein Admin den Matching-Workflow vollständig durchführen (Sichtung → Kandidat → Zuordnung) und die
GTFS-Import-Historie inkl. Datenstand und Fehlern einsehen. Uhrzeiten erscheinen in `Europe/Berlin`. (Bereiche d/e folgen als Ausbaustufen.)

---

## I-13 — Fahrplan-Konsolidat (Perioden, Versionen, datierte Ausnahmen)

**Ziel:** Ein **dauerhafter, vollständiger Fahrplan-Bestand** — mit allen Änderungen (Baustellen, Ersatzverkehre,
Fahrplanwechsel) — der aus vielen rollierenden Importen zusammenwächst und Feed-Importe überlebt.

> ⚠️ **Zeitkritisch.** Der Feed deckt nur ein Fenster ab (aktuell 15.08.–06.09.) und der Import **ersetzt** den
> Bestand. Konsolidiert wird nur, was zum Import-Zeitpunkt im Fenster liegt — **verpasste Zeiträume sind endgültig
> verloren.** Das ist der einzige Arbeitsschritt des Projekts, der sich nicht nachholen lässt.
>
> 📄 Konzept: [`FAHRPLANPERIODEN.md`](FAHRPLANPERIODEN.md) — Phasen B und C aus §7, ergänzt um die datierten
> Ausnahmen (§5.4). Phase A (Fahrplantypen, Ferien/Feiertage) ist als I-12 (e) bereits umgesetzt.

### Vor der Implementierung zu entscheiden (Stopp-Regel — DB-Änderung + SPEC §7)
- [x] **Kurze Änderungen erzeugen eine eigene Linien-Version** (entschieden 18.08.2026) — kein separater
      Ausnahme-Mechanismus
- [x] **Linien-Schlüssel: `route_short_name` allein** (entschieden 18.08.2026) — das Verkehrsmittel ist Attribut der
      Fahrt, nicht Teil des Schlüssels. N2 bekommt Bus- und Tram-Fahrplan damit als **zwei Versionen derselben Linie**
- [x] **`day_type` in der Fahrt-Signatur: vier Werte** (`mo_fr`, `mo_fr_ferien`, `sa`, `so_feiertag`, wie
      `FahrplanTyp`) — entschieden 18.08.2026. MDKursTracker liefert nur drei; das genügt, weil jede Sichtung ein
      `service_date` mitbringt und die Engine daraus selbst klassifiziert
- [x] **Signatur ohne Halte** (entschieden 18.08.2026) — nur `route_short_name` + `day_type` + Abfahrts-Zeitsequenz,
      damit MDKursTracker dieselbe Signatur berechnen kann (HAFAS-IDs statt GTFS-Koordinaten)
- [x] **`consolidated_stops`: global mit versionierten Attributen** (entschieden 18.08.2026) — eine Zeile je
      physischem Halt (Identität = gerundete Koordinaten), Umbenennungen und Verlegungen als Attribut-Historie in
      `consolidated_stop_versions`. Historisch treue Anzeige ohne vervielfachte Identität
- [x] **Import-Takt: wöchentlich** (entschieden 18.08.2026) — die Quelle wird selbst nur wöchentlich aktualisiert.
      Bei 23 Tagen Fenster überlappen aufeinanderfolgende Läufe um gut zwei Wochen, das genügt für lückenlose Abdeckung
- [x] **Dedup-Schwelle für Halte festgelegt** (entschieden 21.08.2026): **≤ 12 m *und* normalisierter Name gleich**.
      Am Live-Bestand gemessen — bis 15 m verschmelzen ausschließlich namensgleiche Halte (149 Paare, null
      Fehlverschmelzungen); die erste echte liegt bei 17,1 m. Die „338 von 730" waren falsch gruppiert: 181 der 190
      Paare heißen identisch
- [x] **Steige verschmelzen bewusst** (entschieden 21.08.2026): 9 Paare liegen auf **exakt identischen** Koordinaten
      und tragen die Richtungen; keine Schwelle trennt sie, `trips.direction_id` ist im Feed durchgehend `NULL`.
      Ein Konsolidat-Halt je Punkt, Richtung aus der Fahrt-Sequenz (FAHRPLANPERIODEN §5.1)

> 📐 **Datenmodell-Entwurf liegt vor:** FAHRPLANPERIODEN §6.1 (Tabellen) und §6.2 (Fortschreibung beim Import).

### (B) Versionierung & stabile Identität
- [x] Fahrt-**Signatur** je **(Trip, Fahrplantyp)** berechnen: `SHA(route_short_name + day_type + HH:MM-Sequenz)`
      → Tabelle `trip_signatures`; die volatile `trip_id` wird nur noch Zeiger (§6.1)
- [x] `schedule_periods` (Admin-CRUD) + `line_versions` je (Linie, Fahrplantyp) — Perioden bilden eine
      lückenlose Kette; `valid_to` und `status` sind daraus abgeleitet, nur `valid_from` wird gepflegt.
      Überspannt ein Feed-Fenster eine Periodengrenze, wird die Periode **je Tag** bestimmt und der Abschnitt
      geteilt — in der neuen Periode startet jede Linie wieder bei Version 1
- [x] **Tagesweise** Fingerprint-Auswertung beim Import-`finish` (§6.2) statt „repräsentativer Tag" — nur so
      entstehen echte Intervalle und die Unterscheidung gesichert/offen
- [x] **Version über Fingerprint identifizieren, Gültigkeit als Intervall-Menge** (§5.4 a) — Rückkehr zum alten
      Fahrplan hängt ein Intervall an die bestehende Version, statt eine identische neue anzulegen
- [x] **Grenzen als gesichert/offen führen** (§5.4 b) — nur ein *innerhalb* eines Fensters beobachteter Wechsel ist
      eine echte Grenze; Fensterkanten sind Untergrenzen und dürfen nicht als Fahrplanwechsel erscheinen
- [x] **Fehlender Fahrplantyp ≠ Änderung:** Deckt ein Lauf einen Typ nicht ab (Feed 17.08.2026 enthielt keinen
      Ferien-Werktag), darf das den Versions-Strang nicht einfrieren
- [x] **Ausfalltag unterbricht die Gültigkeit:** Fährt eine Linie an einem Tag nicht, während andere fahren, ist das
      eine Beobachtung — die Gültigkeit läuft nicht darüber hinweg
- [x] Periodenwechsel **vorschlagen**, wenn viele Linien gleichzeitig betroffen sind (§4.3) — Schwelle
      **≥ 33 %** der an dem Tag verkehrenden Linien (entschieden 21.08.2026, `PERIOD_OFFER_MIN_SHARE`).
      Annehmen legt die Periode an und nimmt die Versionen ab dem Wechseltag zurück; Ablehnen belässt sie
      als gewöhnliche Linien-Versionen
- [x] Admin-Ansichten „Fahrplanperioden" (CRUD + Vorschlags-Banner) und „Versionen" (Historie je Linie/Typ)

### (D) Fahrplan-Ansicht und Versions-Diff (ergänzt 30.08.2026)

Nicht ursprünglich geplant, sondern aus dem Bedarf entstanden, den konsolidierten Fahrplan
überhaupt sehen zu können. Gehört zu I-12 (b), nicht zu einer eigenen Iteration.

- [x] `GET /api/v1/admin/line-versions/{id}/timetable` — Halte als Zeilen, Fahrten als Spalten,
      gruppiert nach Richtung. An der `line_version` verankert, nicht an (Linie, Fahrplantyp):
      Für ein Paar bestehen mehrere Versionen nebeneinander
- [x] **Zeilenachse = Position in der Haltefolge**, nicht Halt-Identität. 1.022 von 18.193 Fahrten
      berühren denselben Halt zweimal (Wendeschleifen, Stichabstecher)
- [x] `StopSequenceAligner` bringt abweichende Laufwege einer Richtung auf eine gemeinsame Achse
      (progressive Verschmelzung über die längste gemeinsame Teilfolge). Am Realbestand: 937 von
      965 Richtungen ergeben die Achse in Länge der längsten Variante, keine überschreitet die
      1,5-fach-Warnschwelle, schlechtester Quotient 1,29
- [x] `GET /api/v1/admin/line-version-diff` — Unterschied zweier Versionen auf Fahrt-Ebene.
      Multiset-Abgleich der Signaturen, Gegenprüfung der Haltefolge (die Signatur ist haltfrei —
      ein reiner Laufweg-Wechsel erschiene sonst als „unverändert"), Paarung des Rests über
      Start/Ziel und nächstgelegene Abfahrt, Toleranz 60 Minuten
- [x] Admin: Reiter „Fahrplan" mit Auswahl Linie/Typ/Version; A-B-Auswahl und Vergleichsansicht
      in „Versionen"
- [x] **Kurs je Fahrt anzeigen** — erledigt mit I-14 (2). Die ursprüngliche Annahme, das setze
      I-04 bis I-06 voraus, traf nicht zu: Der Kurs kommt aus der **gepflegten Kette**, nicht aus
      Sichtungen. Die Sichtungs-API liefert später Nummern in dieses Gefüge hinein
- [x] **Historie über Periodengrenzen** (21.09.2026, FAHRPLANPERIODEN §4.4/§4.5) — ausgelöst durch einen
      Fehler im Betrieb: Nach dem ersten echten Periodenwechsel war der Fahrplan der Vorperiode nicht mehr
      erreichbar, und die neue Periode wurde nirgends als die laufende geführt
      - `status` ist **keine Spalte** mehr, sondern wird beim Lesen aus `valid_from`/`valid_to` gegen den
        heutigen Tag gerechnet. Als gespeicherter Wert zog ihn nur ein Schreibvorgang nach — wer am Vortag
        eine Periode für den Folgetag anlegte, hatte am Folgetag zwei falsche Werte
        (Migration `2026_09_21_100000_drop_status_from_schedule_periods`)
      - `GET /admin/line-versions` nimmt `?period=`; die Admin-Ansichten „Versionen" und „Fahrplan" haben
        eine Periodenauswahl
      - **Versionsvergleich und Kursübernahme über die Periodengrenze erlaubt.** Die alte Sperre trug für
        zwei beliebige Versionen, nicht aber an der Grenze: Die letzte Version der alten und Version 1 der
        neuen Periode folgen unmittelbar aufeinander. Bei der Kursübernahme war sie am teuersten — ein
        Periodenwechsel hätte die gesamte Kurs- und Anschlusspflege verworfen
      - Gemessen am Wechsel zum 21.09.2026 (Linie 1, `mo_fr`): 390 Fahrten unverändert, 3 verschoben

### (C) Konsolidat-Datenbestand
- [x] `consolidated_stops` + `consolidated_stop_versions` (Dedup: ≤ 12 m + normalisierter Name),
      `consolidated_trips`, `consolidated_stop_times`. Am Realbestand: **730 Roh-Halte → 614 Identitäten**,
      12.320 Fahrten, 230.254 Haltzeiten; Erstlauf ~11 s, Folgelauf ~2 s
- [x] Merge beim Import-`finish` nach §5.3 — idempotent ohne Vergleichslogik: Der Fingerprint einer Version
      **ist** die sortierte Menge ihrer Fahrt-Signaturen, also schreibt ein Folge-Import nur neue Versionen
- [x] ~~`dated_exceptions`~~ — **entfällt** (folgt aus der Entscheidung vom 18.08.2026, FAHRPLANPERIODEN §5.4:
      kurze Änderungen sind eigene Linien-Versionen, ein Ausnahme-Mechanismus daneben wäre ein zweiter Weg
      zum selben Ziel)
- [x] Abdeckungs-Anzeige im Admin (`GET /api/v1/admin/coverage`, Ansicht „Abdeckung“): je Linie und
      Fahrplantyp die abgedeckten Zeiträume, die Lücken und die offenen Grenzen. Abschnitte und Lücken sind
      **fahrplantyp-bezogen** — zwischen zwei Samstagen liegt für den `sa`-Strang keine Lücke. Abgedeckt heißt
      **mit Inhalt**: Versionen ohne konsolidierte Fahrten zählen nicht mit, sondern werden ausgewiesen
- [x] App-Endpunkte auf das Konsolidat umgestellt (`GET /lines`, `/lines/{line}/trips`, `/trips`) — mit
      `?source=raw|consolidated`. **Vorgabe ist das Konsolidat**; der Roh-Bestand bleibt für die
      Schaltzentrale erreichbar (Kontrollblick auf den letzten Import), der öffentliche Viewer bekommt später
      ausschließlich das Konsolidat. `meta.source` nennt die gelieferte Quelle. Admin: Umschalter in der
      Linien-Ansicht, Fahrten zeigen dort statt Wochenmuster ihre Version samt beobachteter Gültigkeit

### Abnahmekriterium
Nach mehreren Importen über einen Zeitraum, der eine Fahrplanänderung enthält, liefert die Engine für **jedes Datum
innerhalb der konsolidierten Zeiträume** den an diesem Tag gültigen Fahrplan — einschließlich Ersatzverkehren und
Sonderfahrplänen — obwohl der Roh-Bestand jeweils nur das letzte Feed-Fenster enthält. Die Abdeckungs-Anzeige weist
Lücken aus, statt sie zu verschweigen.

---

## I-14 — Kurse & Umläufe (manuelle Pflege)

**Ziel:** Die **Umlauf-Ebene** wird pflegbar — welche Fahrten dasselbe Fahrzeug nacheinander fährt und welche
Kursnummer dieser Umlauf trägt.

> 📄 Konzept und die Entscheidungen K1–K5: [`KURSE.md`](KURSE.md).
>
> **Warum vor I-04:** Die Sichtungs-API liefert Kursnummern *in dieses Gefüge hinein*. Ohne die Umlauf-Struktur
> hätten die eingehenden Kurse nichts, woran sie andocken könnten. GTFS hilft hier nicht: `trips.block_id` ist im
> gesamten Feed `NULL`, `direction_id` ebenso.

### Vor der Implementierung entschieden (Stopp-Regel — DB-Änderung + neue Fachlogik)
- [x] **Beim Linienwechsel bleibt die Nummer** (K1, entschieden 20.09.2026) — `1/03` wird zu `13/03`; die Nummer
      gehört dem Umlauf, der Linien-Präfix ist Anzeige
- [x] **Die Verkettung führt** (K2) — gespeichert wird der Anschluss A→B plus die bewusste Entscheidung
      „beginnt/endet hier". Nur so ist der Betriebsfahrt-Fall von „noch nicht gepflegt" unterscheidbar
- [x] **Die Kursnummer ist je Linie eindeutig** (K3, geklärt 21.09.2026 am Realbestand) — die „2" der 8 ist ein
      anderer Umlauf als die „2" der 6. Ein bestehender Kurs wird beim Eintippen nur wiederverwendet, wenn er eine
      Linie mit der Kette teilt; als Dublette gilt nur dieselbe Nummer auf überschneidenden Linien. Weiterhin kein
      Unique-Index — die Linienmenge steht in `course_trips`, nicht in einer Spalte. **Korrigiert Annahme E2** in
      `MDKURSTRACKER_REQUIREMENTS.md`
- [x] **Versionswechsel überträgt nichts von selbst** (K4) — Übernahme aus Version N−1 nur auf Knopfdruck, mit Vorschau
- [x] **Editor auf Periode + Fahrplantyp** (K5), ergänzt um den **Versionsstand**: Innerhalb einer Periode kann eine
      Linie mehrere Versionen haben, an einem Umsteigepunkt stehen zwei Linien dann auf verschiedenen Ständen
- [x] **Die Haltestelle ist eine eigene Ebene über dem Halt** (K6, entschieden 20.09.2026 nach einem Befund am
      Realbestand): Die Konsolidierung verschmilzt Halte nur bei ≤ 12 m und gleichem Namen — für die Halt-Identität
      richtig, für den Betrieb zu eng. An „Herrenkrug" enden Fahrten auf dem einen Bahnsteig und beginnen 72 m
      weiter auf dem anderen; **64 von 104 Endstellen** sind so gebaut, **54,6 % aller Fahrt-Endpunkte**. Gruppierung
      automatisch über den Namen (631 Halte → 315 Haltestellen, einseitige Endstellen von 64 auf **14**), der Rest
      von Hand. **Keine Abstands-Automatik:** Die verbleibenden Abstände (201–675 m) gehören zu echten
      Betriebsfahrten und sollen als Aus-/Einrücken festgehalten, nicht verschmolzen werden

### (1) Datenmodell, Verknüpfungs-API, Haltestellen-Editor ✅
- [x] Migration `trip_links`, `courses`, `course_trips`; Indizes auf `consolidated_trips.first_stop_id`/`.last_stop_id`
      (PostgreSQL indiziert Fremdschlüssel nicht — genau darauf fragt der Editor ab)
- [x] **Betriebstag-Wechsel** (`OperatingDayResolver`, FAHRPLANPERIODEN §10) — löst den seit 18.08.2026 offenen
      Nachtlinien-Punkt, den erst der Haltestellen-Editor sichtbar machte: An Herrenkrug entstanden 16 Fahrplanstände
      über vier Wochen, weil N1 im `mo_fr`-Strang montags einen anderen Fingerprint hatte als Di–Fr. Greift in
      `TripSignatureService`, `ScheduleVersionService` und `TripConsolidationService`
- [x] **Versionsstände über die Versionsmenge statt über zusammenhängende Zeiträume** — ein Stand trägt mehrere
      Zeiträume, analog zur Linien-Version (§5.4 a); Abschnitte ohne einen Tag des gewählten Typs entfallen, die
      übrigen werden auf ihren ersten und letzten passenden Tag beschnitten. Zusammen mit dem Betriebstag-Wechsel:
      Herrenkrug von 16 auf 3 Stände
- [x] Migration `stop_groups`, `stop_group_members` (K6) + `StopGroupService` (Automatik über den Namen,
      `manual`-Zuordnungen gegen die Automatik gesperrt) + `StopGroupDirectoryService`; Automatik läuft beim
      Import-Abschluss mit
- [x] `TripLinkKind`-Enum, Models `TripLink`, `Course`, `CourseTrip` samt Factories
- [x] `ConsolidatedTripTimeResolver` — Abfahrt/Ankunft aus `ConsolidatedScheduleService` herausgelöst, weil
      inzwischen drei Aufrufer dieselbe Ableitung brauchen. Dabei die lexikalische Sortierung beseitigt, die
      `GtfsTime` namentlich als Altlast vermerkt hatte (`7:00:00` stand hinter `23:50:00`)
- [x] `ConsolidatedTripInfoResolver`, `TripLinkService` (Kette, Zyklus-Prüfung, Wendezeit), `StopLinkBoardService`
      (Versionsstände durch Zerlegen und Wiederverschmelzen des Perioden-Zeitstrahls)
- [x] `GET /api/v1/stops?source=consolidated` — Halte als physische Punkte mit dauerhafter ID.
      **Vorgabe ist jetzt das Konsolidat**, wie bei `/lines` und `/trips`
- [x] `GET /api/v1/admin/stop-links?stop_group=`, `POST`/`DELETE /api/v1/admin/trip-links` — die Anschluss-Prüfung
      läuft über die **Haltestelle**, nicht über die Halt-Identität
- [x] `GET`/`POST /api/v1/admin/stop-groups`, `GET`/`PUT /{id}`, `POST`/`DELETE /{id}/stops`, `POST /{id}/merge`
      — inkl. Vorschlägen in Laufweite (350 m), die ein Mensch bestätigt
- [x] `openapi.yaml` + Bruno (`stops/consolidated.bru`, `admin/stop-links.bru`, `admin/trip-links-{create,delete}.bru`,
      `admin/stop-groups{,-merge}.bru`)
- [x] Admin-Ansicht „Anschlüsse" — Haltestelle, Periode, Fahrplantyp, Versionsstand; zwei Spalten
      endend/beginnend, Paarbildung per Klick, Betriebsfahrt-Knöpfe, Wendezeit, Zähler „noch offen".
      Zur Auswahl stehen nur Endstellen — an reinen Durchfahrts-Halten gibt es nichts zu pflegen. Verknüpfte Fahrten
      stehen **nebeneinander**, auch wenn dadurch Lücken entstehen; der Kurs steht neben dem Liniensignet; beim
      Speichern bleibt die Scrollposition erhalten (das Board wird gedimmt, nicht ersetzt)
- [x] Admin-Ansicht „Haltestellen" — Zuordnungs-Editor mit Filter „nur einseitige", Umbenennen, Herauslösen
      und Zusammenlegen per Vorschlag
- [x] Tests: `TripLinkTest` (23), `StopLinkBoardTest` (20), `StopGroupTest` (10), `OperatingDayTest` (7),
      `ConsolidatedTripTimeResolverTest` (5) — inkl. Mitternacht-Grenzfall (`24:50` → `25:10` ist ein gültiger
      Anschluss), Linienwechsel 1 → 13, getrennte Bahnsteige an einer Endstelle, der Gegenprobe, dass zwei
      verschiedene Haltestellen **keinen** Anschluss bilden, und dem Nachweis, dass eine nächtlich verkehrende
      Linie **einen** Mo-Fr-Strang hat statt zweier

### (2) Kursnummern ✅
- [x] `CourseService`: Kurs der **ganzen Kette** zuweisen und lösen; Zuweisung über die Nummer legt
      einen noch unbekannten Umlauf an. Dubletten-Warnung statt Unique-Index (K3)
- [x] `CourseLookup` als eigener Dienst ohne Abhängigkeiten — drei Aufrufer brauchen dieselbe
      Auskunft, und über `CourseService` entstünde ein Ring (`TripLinkService` →
      `ConsolidatedTripInfoResolver` → zurück)
- [x] `GET`/`POST`/`PUT`/`DELETE /api/v1/admin/courses`, `PUT`/`DELETE /api/v1/admin/consolidated-trips/{id}/course`
- [x] `TimetableService` reicht je Fahrt `course` durch → **schließt I-13 (D) „Kurs je Fahrt anzeigen"**
- [x] Admin: Kurszeile in der Fahrplan-Matrix **und** im Anschluss-Editor, inline bearbeitbar;
      leeres Feld löst den Kurs
- [x] **Ein Anschluss überträgt den Kurs von selbst** — zwei verknüpfte Fahrten sind dasselbe
      Fahrzeug, also derselbe Kurs. Verschiedene Nummern auf beiden Seiten werden gemeldet
      (`course_conflict`), nicht überschrieben
- [x] **Anschlüsse sind gattungsrein** — eine Tram wird nie zum Bus (422). Der Linienwechsel
      bleibt erlaubt; die Unterscheidung zählt, weil N2 zeitweise als Tram *und* als Bus vorliegt
- [x] Anschluss-Editor, Sortierung: **Die Ankunft führt** — Zeilen mit linker Seite stehen nach
      ihr, die linke Spalte steigt lückenlos. Kreuzen sich zwei Anschlüsse, springt dafür die
      rechte; beides zugleich geht nicht. Eine Abfahrt **ohne** Ankunft kreuzt dagegen nichts und
      wird deshalb unter den Abfahrten einsortiert — sonst stünde eine Ausrück-Fahrt um 04:36
      unter einem Anschluss, der erst um 04:51 abfährt
- [x] Entschiedene Fahrten bleiben stehen, aber gedämpft; beim Überfahren wieder voll lesbar
- [x] Admin: Filter im Anschluss-Editor nach Verkehrsmittel und Linie. Eine Zeile bleibt sichtbar,
      wenn **eine** ihrer Seiten passt — so zerfällt ein verknüpftes Paar nicht, und ein
      Linienwechsel 1 → 13 bleibt unter beiden Linienfiltern als Ganzes lesbar
- [x] **Wendezeit entlang des Betriebstags** — dabei gefunden und behoben: Sie wurde nach der Uhr
      gerechnet, wodurch jeder Nachtlinien-Anschluss über Mitternacht (`23:20` → `00:19`) als
      negative Wendezeit abgewiesen worden wäre. Der Feed notiert solche Fahrten nicht als
      `24:19`, sondern als `00:19`
- [x] `openapi.yaml` + Bruno (`admin/courses.bru`, `admin/trip-course.bru`)
- [x] Tests: `CourseTest` (19) — Kette statt Einzelfahrt, Linienwechsel im Umlauf, Dublette,
      getrennte Stränge, Eckzeiten über Mitternacht

### (3) Kursübersicht und Übernahme ✅
- [x] `GET /api/v1/admin/lines/{line}/courses` — je Kurs die Fahrten in Fahrreihenfolge, die **Risse** in der Kette
      und die Fahrten ohne Kurs. Ein Umlauf erscheint bei **jeder** Linie, die er berührt, mit allen seinen Fahrten
- [x] **Abstand und Anschluss getrennt ausgewiesen** (`gap_before_seconds`, `linked_to_previous`): Ein großer
      Abstand *mit* Anschluss ist eine lange Wende, ein Abstand *ohne* Anschluss eine gerissene Kette. Nötig, weil
      ein Umlauf keine durchgehende Kette sein muss — beim Lösen eines Anschlusses bleibt der Kurs an beiden Teilen
- [x] `LineVersionDiffService::pairing()` — die Fahrt→Fahrt-Zuordnung, die der Diff bisher nur zählte. Die
      Vergleichslogik bleibt an einer Stelle, statt für die Übernahme gedoppelt zu werden
- [x] `CourseCarryoverService`: Vorschau (**garantiert folgenlos**, eigener Endpunkt statt Schalter) und Anwendung.
      Übertragen werden Kursnummer (auch bei verschobener Zeit), Aus-/Einrücken und Anschlüsse **innerhalb** der
      Version. Bereits gesetzte Pflege gewinnt, deshalb ist ein zweiter Lauf folgenlos
- [x] **Anschlüsse auf eine andere Linie bleiben liegen** und werden gemeldet: Die Gegenfahrt hängt noch an der
      alten Fahrt, und ein Fahrzeug hat höchstens einen Vorgänger. Sie zu *verschieben* nähme der alten Version
      ihre Kette, die für ihre verbliebenen Gültigkeitstage weiter stimmt. Das ist der Linienwechsel-Fall, also
      kein Randfall — falls er im Betrieb häufig auftritt, ist der Entwurf hier neu zu bedenken
- [x] `openapi.yaml` + Bruno (`admin/line-courses.bru`, `admin/course-carryover.bru`)
- [x] Admin-Ansicht „Kurse" (Ketten je Linie, Filter „nur gerissene") + Übernahme-Dialog in „Versionen"
- [x] Tests: `CourseCarryoverTest` (13), `CourseOverviewTest` (10) — inkl. folgenloser Vorschau, Idempotenz,
      Linienwechsel-Sperre und Kette über Mitternacht

### Abnahmekriterium
Ein Admin kann an einer Endstelle die dort endenden mit den dort beginnenden Fahrten verketten — auch über Linien
hinweg —, Betriebsfahrten bewusst offen lassen, der Kette eine Kursnummer geben und sie in der Kursübersicht je
Linie wiederfinden. Perioden und Versionen bleiben dabei getrennt.

---

## I-15 — Mengen-Pflege (Muster über einen Zeitraum)

**Ziel:** Wiederkehrende Muster werden in einem Zug gesetzt statt je Übergang geklickt — und lassen sich ebenso
wieder lösen.

> 📄 Entscheidungen **K7** (FIFO paart, der Mensch begrenzt), **K8** (Nummernfolge zyklisch über die markierten
> Spalten) und **K9** (Auflösen und Entfernen sind zwei Richtungen): [`KURSE.md`](KURSE.md) §2.
>
> **Warum es das braucht:** I-14 macht die Umlauf-Ebene pflegbar, aber je Übergang einzeln. An einer Endstelle mit
> Takt wiederholt sich derselbe Griff über einen Morgen hinweg dutzendfach, und eine Linie mit acht chronologisch
> umlaufenden Kursen ließ sich nur Spalte für Spalte benummern. Beides ist Fleißarbeit ohne eigene Aussage.

### Umgesetzt
- [x] **Zulässigkeitsregeln geteilt statt gedoppelt:** `TripLinkRuleService` + `TripLinkRejection` — dieselben
      Prüfungen für Einzelklick (422) und Mengen-Lauf (Zeile überspringen). `TripLinkRequest` bleibt der Ort, an
      dem aus einem Verstoß ein 422 wird. Regressionsanker: `TripLinkTest` und `StopLinkBoardTest` blieben ohne
      eine Zeile Teständerung grün
- [x] **`TripChainGraph`** — die Ketten im Speicher, samt der **erst geplanten** Kanten eines Laufs. Ohne das
      vergäbe ein Lauf dieselbe Abfahrt zweimal und liefe beim Schreiben in den Unique-Constraint statt in eine
      Meldung; außerdem fragte `chainFor()` je Kettensprung die Datenbank
- [x] `TripLinkAutoService` mit `action=link|unlink`, `CourseSequenceService` mit `action=assign|clear` —
      Vorschau (**garantiert folgenlos**) und Anwenden als getrennte Endpunkte, wie bei der Versions-Übernahme
- [x] `CourseNumberSequence` — `1-8`, `31-35`, `01-08`, `1-4, 7, 9-12`, absteigend. **Führende Nullen folgen der
      Eingabe:** `03` und `3` sind zwei verschiedene Kurse
- [x] `COURSE_MAX_TURNAROUND_MINUTES` (Vorgabe 20) — die Höchstwende ist der Abbruch, nicht eine Warnschwelle
- [x] **Linienfilter auf Mehrfachauswahl** erweitert: 1 und 13 gemeinsam, die 6 daneben unberührt. Mit genau einer
      wählbaren Linie ließe sich der gewollte Linienwechsel nur über „Alle" automatisieren
- [x] Bereichsmodus in `StopLinkBoard.vue` (linke Spalte) und `TimetableGrid.vue` (Spaltennummern) — beide als
      ausdrücklicher Modus neben der unveränderten Einzelbedienung
- [x] `openapi.yaml` + Bruno (`auto-trip-links{,-apply,-unlink}.bru`, `course-sequence{,-apply,-clear}.bru`)
- [x] Tests: `AutoTripLinkTest` (33), `CourseSequenceTest` (25), `CourseNumberSequenceTest` (14),
      `TripChainGraphTest` (7) — inkl. folgenloser Vorschau, Idempotenz, Zeitfenster-Grenzfällen auf die Sekunde,
      Nachtlinie über Mitternacht und dem Nacht-auf-Tag-Übergang am Morgen

### Was dabei aufgefallen ist
- **Ein Ring ist über diesen Weg nicht erreichbar.** Jeder Anschluss geht auf dem Betriebstag vorwärts, ein Zyklus
  müsste irgendwo rückwärts — die Zyklusprüfung bleibt als Schutz gegen Altbestand, ließ sich aber nicht über die
  Schnittstelle auslösen. Festgenagelt ist sie deshalb am `TripChainGraph`, nicht am HTTP-Lauf
- **Der Nacht-auf-Tag-Übergang am Morgen** erzeugt scheinbar negative Wendezeiten, weil jede Seite die
  Betriebstag-Grenze ihrer eigenen Linie trägt (N1 05:00 sortiert hinter Linie 1 um 05:20). Er wird eigens
  gemeldet — „keine Abfahrt in 20 Minuten" schickte den Pflegenden einen Datenfehler suchen, den es nicht gibt

### Abnahmekriterium
Ein Admin markiert an einer Endstelle Start- und Endfahrt, wählt die beteiligten Linien und legt die Übergänge
eines Morgens in einem Zug an — mit Vorschau vorher und einer Begründung für jede übersprungene Fahrt. Ebenso
schreibt er die Kursfolge einer Linie über einen Spaltenbereich fort. Beides lässt sich über dieselbe Markierung
wieder lösen, und ein zweiter Lauf ist jeweils folgenlos.

---

## Offene Punkte (vor jeweiliger Iteration zu klären)

| Thema | Relevant ab | Status |
|---|---|---|
| Zeitfenster-Toleranz beim Matching (±N Minuten) | I-05 | ❓ offen |
| Umgang mit Sichtungen ohne GTFS-Trip (Betriebsfahrten) | I-05 | ❓ offen |
| Schnittstelle MDKursTracker (API vs. NaruaDB) | I-09 | ✅ geklärt: HTTP-API (siehe INTEGRATION_MDKURSTRACKER.md) |
| Cron-Intervall für Sichtungs-Sync | I-09 | ❓ offen |
| Admin-Zugangsmodell (Single-Admin via Seed vs. `users`-Tabelle) | I-11 | ✅ entschieden: Single-Admin via `.env`/Seed (minimale `users`-Tabelle) |
| Subdomain/Hosting für die Admin-Schaltzentrale (`admin.strassenbahn-magdeburg.de`?) | I-12 | ❓ offen |
| Linien-Schlüssel im Konsolidat: `route_short_name` allein oder mit `route_type`? | I-12 (e) Phase B | ❓ offen — N2 liegt als Tram- **und** Bus-Route vor (Schienenersatzverkehr). Für die **Anzeige** ist zusammengefasst richtig (Bereich f); fürs **Konsolidat** gehören zwei Verkehrsmittel vermutlich in getrennte Versions-Stränge, sonst sieht das Ende eines Ersatzverkehrs wie eine Fahrplanänderung aus |
| Fahrplantyp ohne Abdeckung im Feed-Fenster | I-12 (e) Phase B | ❓ offen — der rollierende ~2-Wochen-Feed enthält oft **nicht alle vier Typen** (Import 17.08.2026: kein einziger Ferien-Werktag). Der Fingerprint-Vergleich darf einen fehlenden Typ nicht als Änderung werten, sonst friert er ganze Versions-Stränge fälschlich ein |
| Umgang mit kurzen Fahrplanänderungen | I-13 | ✅ entschieden 18.08.2026: **eigene Linien-Version**; dafür Gültigkeit als Intervall-Menge je Version (FAHRPLANPERIODEN §5.4) |
| `day_type` in der Fahrt-Signatur | I-13 | ✅ entschieden 18.08.2026: **vier Werte** wie `FahrplanTyp`; Tracker-Seite braucht sie nicht zu kennen (`service_date` genügt) |
| Fahrt-Signatur mit oder ohne Halte | I-13 | ✅ entschieden 18.08.2026: **ohne** — sonst systemübergreifend nicht vergleichbar |
| Linien-Schlüssel im Konsolidat | I-13 | ✅ entschieden 18.08.2026: **`route_short_name` allein**; Verkehrsmittel ist Fahrt-Attribut, N2 wird zu zwei Versionen einer Linie |
| Dedup-Schwelle für Haltestellen-Koordinaten | I-13 | ✅ entschieden 21.08.2026: **≤ 12 m + normalisierter Name**; am Live-Bestand gemessen, erste Fehlverschmelzung erst bei 17,1 m |
| Steig-Trennung im Konsolidat | I-13 | ✅ entschieden 21.08.2026: **ein Halt je physischem Punkt** — 18 Halte liegen auf exakt identischen Koordinaten, `direction_id` ist im Feed `NULL`; Richtung kommt aus der Fahrt-Sequenz |
| Koordinaten-Drift zwischen zwei Builds | I-13 | ❓ offen — mangels zweitem Build ungemessen; mit dem Import am 24.08.2026 nachzuholen (Sind `stop_id`s stabil? Wandern die Koordinaten?) |
| Import-Takt | I-13 | ✅ entschieden 18.08.2026: **wöchentlich** — die Quelle aktualisiert selbst nur wöchentlich |
| Rückwirkende Zuordnung von Alt-Sichtungen | I-09 | ✅ entschieden 18.08.2026: **kein Ziel** — es geht um den Fahrplan-Bestand |
| Nachtlinien: Betriebstag ≠ Kalendertag (N1 fährt montags anders als Di–Fr) | I-13 | ✅ entschieden 20.09.2026: **zwei Grenzen** — Taglinien 03:00, Nachtlinien 12:00 (FAHRPLANPERIODEN §10). Am Bestand gemessen: Beide Netze überlappen 03:45–06:40, eine gemeinsame Grenze ginge nicht; zwischen 07:00 und 22:00 fährt keine Nachtlinie. **Rückwirkend angewandt** durch Neuaufbau des Konsolidats aus dem Feed-Archiv (6 Feeds, 19.08.–20.09.) — die gesamte Historie ab 15.08. trägt das korrigierte Modell |
| Letzter Tag des Feed-Fensters | I-13 | ✅ entschieden 21.09.2026: **wird nicht ausgewertet** (FAHRPLANPERIODEN §10). Sein Betriebstag endet erst in der Nacht auf den Folgetag, der außerhalb liegt — am 16.10.2026 fehlten dadurch 13 von 18 Fahrten je Nachtlinie, und 16 Linien täuschten einen Periodenwechsel vor. Der Tag kommt im nächsten Import als Innentag wieder |
| Abweichung vs. Fahrplanwechsel | I-13 | ✅ entschieden 21.09.2026: **ein Wechsel bleibt, eine Abweichung kehrt zurück** (§4.3). Kehrt hinter einem Abschnitt der vorherige Fingerprint zurück, zählt er nicht für den Periodenvorschlag — die Version bleibt trotzdem bestehen. Ausgelöst vom 03.10.2026 (Feiertag am Samstag): 10 von 30 Linien, jede mit einer Eintagsversion |
| Nachtverkehr folgt eigenem Rhythmus | I-13 | ❓ offen — die Nacht eines Betriebstags folgt nicht seinem Fahrplantyp, sondern der Frage, ob der **Folgetag ein Ruhetag** ist (03.10.2026: Sonntags-Tagfahrplan, Samstagnacht; 02.10.2026: Werktag mit Samstagnacht). Erzeugt derzeit nur Eintagsversionen; ein Umbau verlangt neue Signaturen und einen Neuaufbau aus dem Feed-Archiv (FAHRPLANPERIODEN §10) |
| Kursnummer beim Linienwechsel | I-14 | ✅ entschieden 20.09.2026: **Nummer bleibt, Linie wechselt** (`1/03` → `13/03`); die Nummer gehört dem Umlauf (KURSE §2 K1) |
| Führende Pflege-Wahrheit: Kette oder Kursnummer | I-14 | ✅ entschieden 20.09.2026: **die Verkettung führt**; die Kursnummer ist ein Etikett an der Kette (K2) |
| Eindeutigkeit der Kursnummer (netzweit oder je Linie) | I-14 | ✅ geklärt 21.09.2026 — **je Linie bzw. Linienkombination**, nicht netzweit (K3). Nachschlagen beim Eintippen ist linienbezogen; kein Unique-Index, da die Linienmenge in `course_trips` steht |
| Haltestelle als Ebene über dem Halt | I-14 | ✅ entschieden 20.09.2026: **eigene Entität** (`stop_groups`), automatisch über den Namen, von Hand nachpflegbar. Keine Abstands-Automatik — die weiten Fälle sind Betriebsfahrten (KURSE §2 K6) |
| Übernahme gepflegter Kurse beim Versionswechsel | I-14 | ✅ entschieden 20.09.2026: **nur auf Knopfdruck**, mit Vorschau (K4) — eine verschobene Abfahrt kann die Wendezeit gekippt haben |
| Betriebshof an Aus- und Einrücken | I-14 | ✅ entschieden 22.09.2026: **eigene Tabelle `depots`**, per Frontend gepflegt, `trip_links.depot_id` nullable (KURSE §3.2). Magdeburg hat drei — Nord und Westerhüsen (Tram), Kroatenwuhne (Bus, an keiner Haltestelle); Aus- und Einrückhof sind **nicht zwangsläufig derselbe**, deshalb hängt der Hof an der einzelnen Entscheidung und nicht am Kurs. Je Hof **mehrere Haltestellen** (`depot_stop_groups`) — Westerhüsen rückt fast immer an der Schleswiger Straße aus. Daraus folgt die automatische Zuordnung beim Markieren; passt nichts Eindeutiges, bleibt der Hof offen |
| Lücken-Auswertung „Kurs beginnt/endet ohne Betriebshof-Marke" | I-14 | ❓ offen — die drei Zustände stehen bereits je Kurs in der API (`terminal_out`/`terminal_in`, KURSE §3.2); es fehlt die Zählung im Kurs-Überblick und die Filterung darauf. Bewusst getrennt entschieden 22.09.2026: eine eigene Aussage, die nicht am Schema hängt |
| Umgang mit Sichtungen, die einer gepflegten Kette widersprechen | I-04/I-05 | ❓ offen — siehe KURSE §5 |
| Startdatum der Sommerferien Sachsen-Anhalt 2026 | — | ❓ offen — Ende ist der 16.08.2026 (bestätigt); der Beginn steht in Test-Fixtures und Bruno-Beispielen noch als unbelegtes `2026-07-13` |
