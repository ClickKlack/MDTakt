# Anforderungsspezifikation (SPEC.md) - MD-Takt
> Version 2.0 — erweitert auf Basis strukturierter Anforderungserhebung

---

## 1. Einleitung

Entwicklung einer spezialisierten Plattform zur Umlauf-Erkennung für den Magdeburger ÖPNV (**alle Linien der MVB — Tram und Bus**). Das System nutzt GTFS-Daten als Soll-Grundlage und ergänzt diese durch Realsichtungen aus dem externen Tool **MDKursTracker**.

> Hinweis: Der Scope wurde von „nur Straßenbahn" auf den gesamten MVB-Verkehr erweitert. Projektname (MD-Takt) und Domains (`strassenbahn-magdeburg.de`) bleiben vorerst bestehen.

**Kern-Ziel des MVP:** Einem Nutzer ermöglichen, manuell im Frontend erfasste Sichtungen (Kursnummer + Kontext) mit GTFS-Trips zu verknüpfen und so Umläufe eines Tages zu rekonstruieren und anzuzeigen.

---

## 2. Fachliches Datenmodell & Begriffsklärung

### 2.1 Begriffsklärung (Glossar)

| Begriff (DE) | Begriff (EN/GTFS) | Definition |
|---|---|---|
| **Fahrt** | `trip` | Eine einzelne Linienfahrt von A nach B (in GTFS: `trip_id`) |
| **Umlauf** | `block` | Folge von Fahrten, die ein Fahrzeug an einem Betriebstag hintereinander durchführt. **Nicht** aus GTFS ableitbar: `block_id` ist im gesamten Feed `NULL`. Geführt als **Kette verknüpfter Fahrten** (§2.4) |
| **Anschluss** | `trip link` | Die Aussage „dasselbe Fahrzeug fährt nach Fahrt A die Fahrt B" — an einer Haltestelle gepflegt |
| **Ausrücken / Einrücken** | — | Eine Kette beginnt bzw. endet **bewusst ohne Anschluss** (Betriebsfahrt, kommt besonders morgens vor). Eine Entscheidung, keine fehlende Pflege |
| **Kurs / Kursnummer** | `run` / `course_number` | Bezeichnung eines Umlaufs im Betriebsalltag (z.B. "03"); kommt aus MDKursTracker. Gehört dem **Umlauf**, nicht der Linie — beim Linienwechsel bleibt sie |
| **Sichtung** | `sighting` | Beobachtung eines Fahrzeugs: Kursnummer + Linie + Richtung + Uhrzeit + Haltestelle |
| **Haltestelle** | `stop` | GTFS `stop_id` + Name |
| **Betriebstag** | `service_date` | Kalendertag, für den ein Umlauf gilt (GTFS `calendar`/`calendar_dates`) |

### 2.2 GTFS-Datenquelle

- **Quelle:** [https://gtfs.de/de/feeds/de_nv/](https://gtfs.de/de/feeds/de_nv/) (statischer Feed)
- **Relevante GTFS-Dateien:**
  - `trips.txt` — Fahrten mit `trip_id`, `route_id`, `block_id`, `service_id`
  - `stop_times.txt` — Haltezeiten je Fahrt
  - `stops.txt` — Haltestellen
  - `routes.txt` — Linien (gefiltert auf die MVB-Agency; alle Verkehrsmittel, `route_type` wird übernommen)
  - `calendar.txt` / `calendar_dates.txt` — Betriebstage
- **Wichtig:** Das GTFS-Feld `block_id` *kann* Umläufe kodieren, ist aber nicht zuverlässig befüllt. Die Umlauf-Rekonstruktion aus Sichtungen ist daher der primäre Ansatz.

### 2.3 Sichtungs-Datenmodell (aus MDKursTracker)

Eine Sichtung enthält:
- `course_number` — Kursnummer (z.B. "03"). ~~Primärer Schlüssel der Umlauf-Identität~~ —
  **korrigiert 20.09.2026:** Der Umlauf ist die gepflegte **Kette** (§2.4); die Nummer ist ein
  Etikett daran. Die Nummer ist **je Linie bzw. Linienkombination** eindeutig, nicht netzweit
  (KURSE §2 K3)
- `line` — Linie **am gesichteten Halt** (z.B. "1", "6")
- `hafas_stop_id`, `stop_name` — Halt, an dem gesichtet wurde
- `service_date` — Betriebstag (Europe/Berlin) laut Tracker
- `departure_planned` — Soll-Abfahrt am Halt (UTC); `departure_actual` optional
- `observed_at` — Zeitpunkt der Erfassung (UTC)
- der **Laufweg** der Fahrt (Soll-Zeiten + Linie je Halt), per `schedule_fingerprint` einmal gespeichert

**Schnittstelle MDKursTracker → MD-Takt (umgesetzt 26.09.2026):** HTTP-API, `POST /api/v1/collector/sightings`
mit eigenem Token. Der Tracker schickt jede Sichtung sofort und holt Fehlgeschlagenes per Cron nach. Details:
[`INTEGRATION_MDKURSTRACKER.md`](INTEGRATION_MDKURSTRACKER.md) §5.1 und §8. `course_number` ist Nutzereingabe.

> ~~Umlauf-Schlüssel `(line, course_number, service_date)`~~ — **korrigiert 20.09.2026**, siehe
> [`KURSE.md`](KURSE.md) §2 K1: Ein Fahrzeug behält beim Linienwechsel seine Kursnummer (eine 1 wird in
> Sudenburg zur 13, angezeigt als `1/03` → `13/03`). Der Umlauf umfasst damit mehrere Linien, und das Tripel
> identifiziert ihn nicht. Er wird stattdessen als **Kette verknüpfter Fahrten** geführt (§2.4).

### 2.4 Umlauf-Ebene (I-14)

Der Umlauf ist **keine** Eigenschaft einer Fahrt und **nicht** aus GTFS ableitbar (`block_id` und `direction_id`
sind im gesamten gtfs.de-Feed `NULL`). Er entsteht durch Pflege im Admin und wird als **Kette** geführt:

- Eine `trip_links`-Zeile ist **eine Entscheidung**: der Anschluss zweier Fahrten an einer Haltestelle
  (`kind: link`), oder die bewusste Aussage, dass eine Kette hier beginnt (`start`, Ausrücken) bzw. endet
  (`end`, Einrücken). Keine Zeile heißt **noch nicht gepflegt** — das ist ausdrücklich etwas anderes.
- Unique auf `from_trip_id` und `to_trip_id` tragen die Fachregel: Ein Fahrzeug hat höchstens einen Vorgänger
  und höchstens einen Nachfolger.
- Ketten laufen **über Linien hinweg** und behalten dabei ihre Kursnummer.
- Die Kursnummer (`courses`, `course_trips`) ist ein **Etikett an der Kette**, für die ganze Kette gesetzt.
  Bewusst **ohne** Unique-Constraint, solange offen ist, ob sie netzweit eindeutig ist.

Vollständiges Konzept samt Begründungen: [`KURSE.md`](KURSE.md).

---

## 3. Kern-Algorithmus: Umlauf-Rekonstruktion

> ⚠️ Dies ist die fachlich komplexeste Komponente. Der Algorithmus ist **bewusst offen** — das System soll beim Matching unterstützen, nicht autonom entscheiden.

### 3.1 Ziel
Für eine gegebene Sichtung (Kursnummer + Linie + Richtung + Zeit + Haltestelle) soll das System **Kandidaten-Trips** aus den GTFS-Daten vorschlagen, die zeitlich und räumlich passen.

### 3.2 Matching-Logik (festgelegt 26.09.2026, umgesetzt in `SightingMatcher`)

> Ersetzt den früheren 4-stufigen Filter (Betriebstag → Linie → Haltestelle → ±N Minuten). Mit den Soll-Zeiten aus
> MDKursTracker ist der Match **deterministisch**; ein Zeitfenster und ein HAFAS↔GTFS-Haltestellen-Crosswalk entfallen.

1. **Laufweg teilen** — an jedem Wechsel der Linie je Halt. Eine Tracker-Fahrt L5 → L1 sind im Feed zwei Fahrten.
   Der Übergangshalt wird auf beiden Seiten versucht, am Endhalt Ankunft und Abfahrt.
2. **Uhrzeitfolge bilden** — jede Soll-Zeit mit ihrem eigenen Datum nach Europe/Berlin, `HH:MM`, gezählt ab dem
   Kalendertag des ersten Halts (`23:50, 24:05`). Eine Fahrt, die nach Mitternacht beginnt, steht mit `00:30` da.
3. **Betriebstag und Fahrplantyp** — aus dem Tag der Sichtung; vor der Betriebstag-Grenze gilt der Vortag
   (`OperatingDayResolver`), der Typ kommt aus `FahrplanTypClassifier` (vier Typen, inkl. Ferien).
4. **Signatur** `SHA256(Linie | Fahrplantyp | HH:MM-Folge)` — dieselbe Formel wie beim Import
   (`TripSignatureService::signatureFor`) — per Index in `consolidated_trips` suchen, in der Version, deren
   Intervall den Betriebstag einschließt.
5. **Ohne Treffer:** dieselbe Signatur in der **nächsten** Version der Linie, wenn sie höchstens 14 Tage später
   beginnt (`matched_next_version`, nie automatisch bestätigt). Sonst `waiting`; nach jedem GTFS-Import wird neu
   zugeordnet, nach 2 erfolglosen Importen `no_trip`. Mehrere Treffer → `ambiguous`.
6. **Vergleich und Entscheidung** — hängt der gesichtete Kurs schon an der Fahrt, ist die Sichtung `confirmed`.
   Sonst entscheidet der Admin (Prüfliste oder Fahrplan): **Annehmen** setzt die Nummer an die **ganze Kette**
   (§2.4) und nummeriert sie bei Abweichung um; **Ablehnen** lässt die Kursdaten unberührt.

### 3.3 Entschieden (vorher offen)
- **Toleranz des Zeitfensters:** keine — der Match ist exakt. `MATCHING_WINDOW_MINUTES` entfällt.
- **Sichtungen ohne passenden Trip** (Sonder-, Betriebsfahrten, noch nicht im Feed): `waiting` → `no_trip`,
  in der Prüfliste sichtbar und ablehnbar.
- **Eine Sichtung, mehrere Trips:** nein — eine Sichtung gehört zu genau einer Fahrt, der am gesichteten Halt.

---

## 4. System-Architektur

### 4.1 Übersicht (Hybrid-Modell)

```
[MDKursTracker / NaruaDB]
        |
        | (API oder DB-Zugriff, TBD)
        ↓
[Collector — NAS, PHP CLI]
   - GTFS-Feed herunterladen & importieren
   - Sichtungen aus MDKursTracker holen
   - Daten normalisieren & via API-Token pushen
        |
        ↓
[Engine — Laravel API, Hetzner]
   - PostgreSQL (GTFS + Sichtungen + Zuordnungen)
   - Umlauf-Rekonstruktions-Endpunkte
   - JSON-API für Viewer
        |
        ↓
[Viewer — Vue 3 SPA, Hetzner]
   - Tagesansicht: Liste aller Umläufe
   - Matching-Workflow: Sichtung → Trip-Kandidaten → Bestätigung
```

### 4.2 Module

| Modul | Pfad | Technologie | Hosting |
|---|---|---|---|
| Collector | `/collector` | PHP CLI | Lokales NAS |
| Engine (API) | `/engine` | Laravel 13, PHP 8.3+ | Hetzner, `api.strassenbahn-magdeburg.de` |
| Viewer (öffentliche Webseite) | `/viewer` | Vue 3, Vite, Tailwind | Hetzner, `app.strassenbahn-magdeburg.de` |
| Admin-Schaltzentrale | `/admin` | Vue 3, Vite, Tailwind | Hetzner, `admin.strassenbahn-magdeburg.de` (TBD) |
| Gemeinsame Defs | `/shared` | OpenAPI 3.x YAML | — |

---

## 5. API-Endpunkte (MVP)

Alle Antworten als JSON. Fehlerformat: `{ "error": { "code": int, "message": string } }`.

> Auth-Gruppierung: **öffentlich/read-only** (Viewer), **Collector** (interner Token), **Admin/Schaltzentrale** (Sanctum — alle schreibenden/kuratierenden Aktionen).

### Öffentliche Anzeige (Viewer — kein Auth, read-only)
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `GET` | `/api/v1/lines` | Alle MVB-Linien (Tram + Bus) |
| `GET` | `/api/v1/stops` | Alle MVB-Haltestellen (Haltestellenrecherche) |
| `GET` | `/api/v1/trips?date=&line=&stop=` | GTFS-Trips / Fahrplananzeige filtern |
| `GET` | `/api/v1/blocks?date=` | Alle Umläufe eines Tages (gruppiert nach `course_number`) |
| `GET` | `/api/v1/blocks/{course_number}?date=` | Einzelner Umlauf mit allen zugeordneten Trips |

### Collector (intern, API-Token geschützt)
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `POST` | `/api/v1/collector/gtfs-import` | GTFS-Feed-Import anstoßen |
| `GET` | `/api/v1/collector/imports` | Import-Historie & Datenstand (interne Token-Variante) |
| `POST` | `/api/v1/collector/sightings` | Sichtungs-Eingang aus MDKursTracker (Fluss 1) — **eigener Token** `MDKURSTRACKER_API_TOKEN` |
| `GET`/`POST` | `/api/v1/collector/course-lookup` | Kursauskunft für MDKursTracker (Fluss 2) je Abfahrt / je Tafel — Tracker-Token |

### Admin / Schaltzentrale (Sanctum-geschützt)
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `POST` | `/api/v1/admin/login` | Admin-Login, gibt Sanctum-Token zurück |
| `GET` | `/api/v1/admin/sightings?state=&line=&date_from=&date_to=&differs_only=` | Prüfliste der Sichtungen mit Vergleich zum lokalen Kurs |
| `GET` | `/api/v1/admin/sightings/counts` | Offene / wartende Sichtungen (Navigation) |
| `POST` | `/api/v1/admin/sightings/accept` | Annehmen — Kurs an die ganze Kette, ggf. umnummerieren |
| `POST` | `/api/v1/admin/sightings/reject` | Ablehnen, mit optionaler Notiz |
| `GET` | `/api/v1/admin/imports` | Import-Historie & Datenstand fürs Admin-Frontend |
| `GET` | `/api/v1/admin/stop-links?stop=&period=&day_type=&stand=` | Haltestellen-Editor: endende und beginnende Fahrten samt Entscheidungen (I-14) |
| `POST` | `/api/v1/admin/trip-links` | Anschluss anlegen oder eine Kette bewusst offen lassen (Betriebsfahrt) |
| `DELETE` | `/api/v1/admin/trip-links/{id}` | Entscheidung wieder lösen |
| `GET`/`POST` | `/api/v1/admin/courses` | Umläufe eines Strangs / Umlauf anlegen |
| `PUT`/`DELETE` | `/api/v1/admin/courses/{id}` | Kursnummer ändern / Umlauf löschen |
| `PUT`/`DELETE` | `/api/v1/admin/consolidated-trips/{id}/course` | Kurs der **ganzen Kette** setzen / lösen |
| `GET` | `/api/v1/admin/lines/{line}/courses` | Umläufe einer Linie: Ketten, Risse, Fahrten ohne Kurs |
| `GET`/`POST` | `/api/v1/admin/line-versions/{id}/course-carryover` | Übernahme beim Versionswechsel: Vorschau / anwenden |

> Weitere Admin-Endpunkte (Datenkorrektur, Fahrplanperioden-Erkennung) werden mit den jeweiligen ROADMAP-Iterationen ergänzt.

---

## 6. Authentifizierung

- **Collector → Engine:** Bearer-Token (statischer API-Key in `.env`, kein Login).
- **MDKursTracker → Engine:** eigener statischer Bearer-Token (`MDKURSTRACKER_API_TOKEN`), nur für den
  Sichtungs-Eingang und die Kursauskunft. Die beiden Tokens öffnen jeweils nur ihre eigenen Endpunkte.
- **Viewer → Engine:** Kein Auth — **rein lesend**. Der öffentliche Viewer ist eine informative Webseite ohne schreibende Aktionen.
- **Admin-Schaltzentrale → Engine:** Laravel Sanctum (Login + Token) für **alle** kuratierenden/verwaltenden Aktionen (Matching, Datenkorrektur, Steuerung, Auditing). Da der Matching-Workflow ins Admin-Frontend wandert, ist Sanctum **MVP-relevant** (nicht mehr Post-MVP). Single-Admin-Login; der Collector-Token bleibt rein intern und gelangt **nie** ins Browser-Frontend.
- **Zukunft:** Vollwertiges Multi-User-System baut auf demselben Sanctum-Fundament auf.

---

## 7. Datenbank-Schema (Kern-Tabellen)

```sql
-- GTFS Stammdaten (vom Collector befüllt)
routes          (route_id PK, route_short_name, route_type)
stops           (stop_id PK, stop_name, lat, lon)
trips           (trip_id PK, route_id FK, service_id, block_id, direction_id)
stop_times      (trip_id FK, stop_id FK, arrival_time, departure_time, stop_sequence)
calendar        (service_id PK, monday..sunday, start_date, end_date)   -- reguläres Wochenmuster
calendar_dates  (service_id, date, exception_type)                      -- Ausnahmen zum Wochenmuster

-- Betriebsdaten
-- Sichtungs-Eingang aus MDKursTracker (26.09.2026, INTEGRATION_MDKURSTRACKER §8)
mdkt_routes (
    id           BIGSERIAL PK,
    fingerprint  VARCHAR(128) UNIQUE,   -- schedule_fingerprint des Trackers
    mdkt_trip_id BIGINT,
    line         VARCHAR(8),
    direction    VARCHAR,
    stops        JSON                   -- Laufweg: seq, hafas_stop_id, stop_name, line, arrival/departure_planned (UTC)
)

sightings (
    id                   BIGSERIAL PK,
    mdkt_recording_id    BIGINT UNIQUE,                 -- Idempotenz
    mdkt_route_id        BIGINT FK mdkt_routes,
    line                 VARCHAR(8),                    -- Linie am gesichteten Halt
    course_number        VARCHAR(8),
    hafas_stop_id        VARCHAR(32),
    stop_name            VARCHAR,
    service_date         DATE,
    observed_at          TIMESTAMPTZ,
    departure_planned    TIMESTAMPTZ,
    departure_actual     TIMESTAMPTZ,
    trip_signature       CHAR(64),
    consolidated_trip_id BIGINT FK consolidated_trips,  -- NULL = keine Fahrt (mehr); nach Import neu zugeordnet
    match                VARCHAR(24),   -- matched | matched_next_version | waiting | no_trip | ambiguous
    match_attempts       SMALLINT,      -- Importe ohne Treffer
    status               VARCHAR(16),   -- pending | confirmed | accepted | rejected
    decided_at           TIMESTAMPTZ,
    decision_note        VARCHAR
)

-- Umlauf-Ebene (I-14, siehe KURSE.md). Haengt am Konsolidat, nicht am Roh-Bestand:
-- consolidated_trips.id ueberlebt den Import, die gtfs.de-trip_id nicht.
trip_links (
    id             BIGSERIAL PK,
    from_trip_id   BIGINT FK consolidated_trips UNIQUE,  -- NULL = Ausruecken
    to_trip_id     BIGINT FK consolidated_trips UNIQUE,  -- NULL = Einruecken
    stop_id        BIGINT FK consolidated_stops,
    kind           VARCHAR(8),                           -- link | start | end
    note           VARCHAR
)

courses (
    id         BIGSERIAL PK,
    period_id  BIGINT FK schedule_periods,
    day_type   VARCHAR(16),
    number     VARCHAR(8),        -- Kursnummer ohne Linien-Praefix, bewusst OHNE unique
    note       VARCHAR
)

course_trips (
    course_id            BIGINT FK courses,
    consolidated_trip_id BIGINT FK consolidated_trips UNIQUE
)
```

> Die Ur-MVP-Tabelle `sightings` (mit `assigned_trip_id` auf die volatile gtfs.de-`trip_id`) wurde am 26.09.2026
> ersetzt. Sichtungen hängen jetzt am Konsolidat; der Vergleich mit dem lokalen Kurs wird nicht gespeichert, sondern
> bei jeder Abfrage berechnet.

---

## 8. MVP-Scope & Abgrenzung

### Im MVP enthalten
- GTFS-Import (alle MVB-Linien — Tram + Bus — aus dem Magdeburg/MVB-Feed)
- Sichtungs-Import via Collector / MDKursTracker (Fluss 1)
- **Öffentlicher Viewer (read-only, informativ):** Linien-/Fahrplananzeige, Haltestellenrecherche, Umlauf-Tagesansicht
- **Admin-Schaltzentrale (Sanctum):** Single-Admin-Login + manueller Matching-Workflow (Sichtung → Trip-Kandidaten → Bestätigung)

### Explizit NICHT im MVP
- Automatische/KI-gestützte Umlauf-Zuordnung ohne Nutzerinteraktion
- Echtzeit-Fahrzeugpositionen / GTFS-RT
- S-Bahn, Regionalverkehr oder Verkehrsmittel anderer Verbünde (außerhalb der MVB)
- **Multi-User** (es gibt nur den Single-Admin-Login; der öffentliche Viewer bleibt ganz ohne Login)
- Mobile App

### Post-MVP-Erweiterungen (Ausbau der Admin-Schaltzentrale, siehe §10 / ROADMAP)
- GTFS-Import-Auditing (Historie, Datenstand, Fehler)
- Datenkorrektur (manuelle Korrektur von Zuordnungen/Stammdaten-Overrides)
- Erkennung neuer Fahrplanperioden (Re-Match-Bedarf erkennen)

---

## 9. Technische Anforderungen

- **Datenbank:** PostgreSQL — Nutzung von Window Functions & CTEs für Umlauf-Abfragen. Zeitstempel immer `TIMESTAMPTZ`.
- **PHP:** 8.3+, strikte Typisierung, Laravel 13.
- **Frontend:** Vue 3 Composition API (`<script setup>`), Tailwind CSS, Axios.
- **Primärschlüssel:** UUID oder `BIGSERIAL` je nach Tabelle.
- **Sprache:** Code & Kommentare Deutsch/Englisch-Mix (Fachbegriffe Englisch, Inline-Kommentare Deutsch).

---

## 10. Admin-Schaltzentrale (`/admin`)

Separates Admin-Frontend (`/admin`), **getrennt vom öffentlichen Viewer**, hinter Single-Admin-Login (Sanctum). Es ist die zentrale **Schaltzentrale** zum Verwalten und Steuern des Systems — **alle schreibenden/kuratierenden Aktionen** laufen hier, nie im Viewer.

### 10.0 Funktionsbereiche
- **Matching-Workflow** (MVP): Sichtung → Trip-Kandidaten → Bestätigung; Umlauf-Kuratierung. Wandert aus dem Viewer hierher.
- **Datenkorrektur**: manuelle Korrektur von Zuordnungen und ggf. Stammdaten-Overrides.
- **Fahrplanperioden-Erkennung**: erkennen, wenn ein neuer Feed-Build geänderte Zeiten bringt → betroffene Zuordnungen als *stale / neu zu bestätigen* markieren (siehe `INTEGRATION_MDKURSTRACKER.md` §4.2).
- **GTFS-Import-Auditing** (folgend detailliert): Historie, Datenstand, Fehlerdiagnose.

Der folgende Abschnitt detailliert zunächst das **Import-Auditing**; die übrigen Bereiche werden mit den jeweiligen ROADMAP-Iterationen ausspezifiziert.

### 10.1 Zweck (Import-Auditing)
- Überblick, **ob und wann** ein GTFS-Import lief und **ob er erfolgreich** war.
- **Datenstand**: aktueller Bestand je Tabelle (routes/stops/trips/stop_times/calendar_dates), Feed-Version und Gültigkeitszeitraum aus `feed_info.txt`.
- **Fehlerdiagnose**: fehlgeschlagene Läufe (`failed`) inkl. Fehlermeldung.

### 10.2 Datengrundlage
- Tabelle `gtfs_import_runs` (eingeführt in ROADMAP I-02b): pro Lauf `status`, `started_at`/`finished_at`, `counts`, `feed_version`, Gültigkeitszeitraum, `error_message`.
- Gelesen über `GET /api/v1/admin/imports` (Sanctum-geschützt). Die interne Token-Variante `GET /api/v1/collector/imports` bleibt für NAS-Skripte bestehen; beide nutzen dieselbe Abfrage-Logik (Engine-seitig in einem gemeinsamen Service gebündelt).

### 10.3 Sichten (read-only)
- **Import-Historie**: Liste der Läufe mit Status-Badge (`success`/`failed`/`running`), Start-/Endzeit, Counts, Feed-Version.
- **Datenstand**: aktueller Bestand je Tabelle + letzter erfolgreicher Import.
- **Lauf-Detail**: alle Felder eines Laufs, bei `failed` die Fehlermeldung.

### 10.4 Auth & Abgrenzung
- Zugriff nur nach Admin-Login (Laravel Sanctum, ROADMAP I-11). Collector-Token niemals im Browser.
- **Bzgl. Importe read-only**: Das *Anstoßen* von Importen bleibt CLI/Cron auf dem NAS (I-09) — kein Trigger-Button im geplanten Umfang. (Andere Schaltzentrale-Bereiche wie Matching/Datenkorrektur sind sehr wohl schreibend.)
- Zeit-Anzeige in **lokaler Browser-Zeitzone** (dt. Format) ausschließlich in `admin/src/utils/timezone.ts` (analog zur Viewer-Regel).
