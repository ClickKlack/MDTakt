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
| **Umlauf** | `block` | Folge von Fahrten, die ein Fahrzeug an einem Betriebstag hintereinander durchführt |
| **Kurs / Kursnummer** | `run` / `course_number` | Bezeichnung eines Umlaufs im Betriebsalltag (z.B. "K12"); kommt aus MDKursTracker |
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
- `course_number` — Kursnummer (z.B. "K12") — **primärer Schlüssel der Umlauf-Identität**
- `line` — Linienbezeichnung (z.B. "1", "6")
- `direction` — Fahrtrichtung (z.B. "Steubenallee", "Kampstraße")
- `observed_at` — Zeitstempel der Sichtung (Datum + Uhrzeit)
- `stop_name` — Haltestelle, an der gesichtet wurde

**Schnittstelle MDKursTracker → MD-Takt:** Konzept erarbeitet & validiert (2026-06-22) — siehe
[`INTEGRATION_MDKURSTRACKER.md`](INTEGRATION_MDKURSTRACKER.md). Kurzfassung: Integration über die
**HTTP-API** (MDKursTracker = MariaDB nur lokal, kein DB-Direktzugriff). `course_number` ist
Nutzereingabe und **nur je Linie eindeutig** → Umlauf-Schlüssel `(line, course_number, service_date)`.

---

## 3. Kern-Algorithmus: Umlauf-Rekonstruktion

> ⚠️ Dies ist die fachlich komplexeste Komponente. Der Algorithmus ist **bewusst offen** — das System soll beim Matching unterstützen, nicht autonom entscheiden.

### 3.1 Ziel
Für eine gegebene Sichtung (Kursnummer + Linie + Richtung + Zeit + Haltestelle) soll das System **Kandidaten-Trips** aus den GTFS-Daten vorschlagen, die zeitlich und räumlich passen.

### 3.2 Matching-Logik (Vorschlag, im Frontend manuell bestätigbar)

1. **Filter nach Betriebstag** — aus `observed_at` den korrekten GTFS `service_date` ermitteln; gültige `service_id`s ergeben sich aus `calendar` (Wochenmuster) kombiniert mit `calendar_dates` (Ausnahmen).
2. **Filter nach Linie** — `routes.txt` nach `route_short_name` filtern (alle MVB-Linien, Tram + Bus).
3. **Filter nach Haltestelle** — `stop_times.txt` auf Fahrten einschränken, die die gesichtete Haltestelle bedienen.
4. **Filter nach Zeitfenster** — Abfahrts-/Ankunftszeit ±N Minuten um `observed_at`.
5. **Ergebnis:** Liste von `trip_id`-Kandidaten → Nutzer wählt den korrekten Trip aus.
6. **Zuordnung speichern:** `course_number` wird der bestätigten `trip_id` zugeordnet.
7. **Umlauf-Ableitung:** Alle `trip_ids` mit derselben `course_number` am selben `service_date` bilden einen Umlauf.

### 3.3 Offene Frage (vor Implementierung zu klären)
- Toleranz des Zeitfensters (±5 min? ±10 min?) — konfigurierbar machen.
- Umgang mit Sichtungen ohne passendem GTFS-Trip (Sonderfahrten, Betriebsfahrten).

> **Update 2026-06-22 (am realen Datensatz validiert, siehe [`INTEGRATION_MDKURSTRACKER.md`](INTEGRATION_MDKURSTRACKER.md) §4):**
> Mit den MDKursTracker-**Soll-Zeiten** wird das Matching **deterministisch** über
> `(Linie, Tagestyp, Soll-Zeit-Sequenz lokal)` — kein HAFAS↔GTFS-Stop-ID-Crosswalk nötig, nur
> Namens-Normalisierung. Das ±Zeitfenster aus Stufe 4 wird damit nur noch Sicherheitsmarge. Eine
> HAFAS-Fahrt mappt auf **N** GTFS-Trips (Linienübergänge). Die formale Neufassung von §3.2 ist noch
> offen (Stopp-Regel) und vor I-05 mit Jörg festzulegen.

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
| `GET` | `/api/v1/course-lookup?hafas_stop=&line=&time=&date=` | Kursauskunft für MDKursTracker (Fluss 2, siehe `INTEGRATION_MDKURSTRACKER.md`) |

### Collector (intern, API-Token geschützt)
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `POST` | `/api/v1/collector/gtfs-import` | GTFS-Feed-Import anstoßen |
| `GET` | `/api/v1/collector/imports` | Import-Historie & Datenstand (interne Token-Variante) |
| `POST` | `/api/v1/collector/sightings` | Sichtungen im Batch importieren (Fluss 1) |

### Admin / Schaltzentrale (Sanctum-geschützt)
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `POST` | `/api/v1/admin/login` | Admin-Login, gibt Sanctum-Token zurück |
| `GET` | `/api/v1/sightings?date=` | Sichtungen eines Betriebstags (Kuratierung/Matching) |
| `POST` | `/api/v1/sightings/{id}/assign` | Trip-Zuordnung zu einer Sichtung bestätigen (Matching) |
| `GET` | `/api/v1/admin/imports` | Import-Historie & Datenstand fürs Admin-Frontend |

> Weitere Admin-Endpunkte (Datenkorrektur, Fahrplanperioden-Erkennung) werden mit den jeweiligen ROADMAP-Iterationen ergänzt.

---

## 6. Authentifizierung

- **Collector → Engine:** Bearer-Token (statischer API-Key in `.env`, kein Login).
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
sightings (
    id            BIGSERIAL PK,
    course_number VARCHAR NOT NULL,      -- Kursnummer aus MDKursTracker
    line          VARCHAR NOT NULL,
    direction     VARCHAR,
    observed_at   TIMESTAMPTZ NOT NULL,
    stop_name     VARCHAR,
    assigned_trip_id VARCHAR FK trips,  -- NULL = noch nicht zugeordnet
    created_at    TIMESTAMPTZ DEFAULT now()
)
```

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
- Zeitzonen-Konvertierung nach `Europe/Berlin` ausschließlich in `admin/src/utils/timezone.ts` (analog zur Viewer-Regel).
