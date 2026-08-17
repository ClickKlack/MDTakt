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
| **I-12** | Admin-Schaltzentrale | Admin + Engine | Matching-Workflow, Datenkorrektur, Fahrplanperioden-Erkennung, Import-Auditing | 🟡 a, c, e-A, f |

> **Stand am 17.08.2026.** Umgesetzt sind Fundament, Import inkl. Audit, Stammdaten-API, Auth und von der
> Admin-Schaltzentrale die Bereiche (a) Grundgerüst, (c) Import-Auditing, (e) Phase A (Fahrplantypen) und
> (f) Linien-/Fahrten-Ansicht. **Als Nächstes: I-04 → I-05 → I-06 → I-12b** — das ist der MVP-Pfad zum
> Matching-Workflow. Fahrplanperioden Phase B/C laufen daneben als Ausbaustufe.

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
| 3 | **I-04** Sichtungs-API | Engine-Grundlage: Sichtungen speichern/lesen | ⬅️ **als Nächstes** |
| 4 | **I-05** Matching-Logik | Engine-Kern fürs Matching | ⬜ |
| 5 | **I-06** Zuordnung & Umläufe | Zuordnen + Umlauf-Abfrage | ⬜ |
| 6 | **I-12 b** Admin-Matching-Workflow | Matching-UI auf den Engine-APIs (mit Seed-/Test-Sichtungen erprobbar) | ⬜ |
| 7 | **I-09** Collector-/**MDKursTracker-Integration** | Live-Datenfluss (Fluss 1/2) **nach** dem Admin-Frontend | ⬜ |
| 8 | **I-10** Stabilisierung | Härten, Tests, Doku | ⬜ |
| 9 | **I-07 + I-08** Viewer | Öffentliche Webseite **ganz zuletzt** | ⬜ |

> **Dazwischengeschoben:** Die Bereiche **I-12 (e) Phase A** (Fahrplantypen) und **I-12 (f)**
> (Linien-/Fahrten-Ansicht) sind vorgezogen umgesetzt worden — beide entstanden aus der Arbeit am
> Import-Auditing heraus und sind für den Matching-Workflow nützlich, aber keine Voraussetzung.
> Der MVP-Pfad bleibt I-04 → I-05 → I-06 → I-12b.

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
- [ ] Deployment-Checkliste: Hetzner-Config, Subdomain-Setup, Cron-Einträge

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

**Phase B — Versionierung (Metadaten)** — *offen*
- [ ] `schedule_periods` (Admin-CRUD: anlegen/aktiv setzen) + `line_versions` je (Linie, Fahrplantyp)
- [ ] Fingerprint-Vergleich beim Import → neue Version / verlängern / einfrieren
- [ ] Periodenwechsel **vorschlagen**, wenn viele Linien gleichzeitig betroffen sind (§4.3); Schwelle noch festzulegen
- [ ] Admin-Ansichten „Fahrplanperioden" + „Linien-Versionen" (Historie je Linie/Typ)
- [ ] Periodenwechsel → betroffene Zuordnungen als *stale/neu zu bestätigen* markieren (siehe `INTEGRATION_MDKURSTRACKER.md` §4.2)

**Phase C — Konsolidat-Datenbestand** — *offen, der große Umbau*
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
| Startdatum der Sommerferien Sachsen-Anhalt 2026 | — | ❓ offen — Ende ist der 16.08.2026 (bestätigt); der Beginn steht in Test-Fixtures und Bruno-Beispielen noch als unbelegtes `2026-07-13` |
