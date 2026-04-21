# Anforderungsspezifikation (SPEC.md) - MD-Takt
> Version 2.0 — erweitert auf Basis strukturierter Anforderungserhebung

---

## 1. Einleitung

Entwicklung einer spezialisierten Plattform zur Umlauf-Erkennung für den Magdeburger ÖPNV (**ausschließlich Straßenbahn/Tram, MVB**). Das System nutzt GTFS-Daten als Soll-Grundlage und ergänzt diese durch Realsichtungen aus dem externen Tool **MDKursTracker**.

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
  - `routes.txt` — Linien (gefiltert auf `route_type = 0` = Tram)
  - `calendar.txt` / `calendar_dates.txt` — Betriebstage
- **Wichtig:** Das GTFS-Feld `block_id` *kann* Umläufe kodieren, ist aber nicht zuverlässig befüllt. Die Umlauf-Rekonstruktion aus Sichtungen ist daher der primäre Ansatz.

### 2.3 Sichtungs-Datenmodell (aus MDKursTracker)

Eine Sichtung enthält:
- `course_number` — Kursnummer (z.B. "K12") — **primärer Schlüssel der Umlauf-Identität**
- `line` — Linienbezeichnung (z.B. "1", "6")
- `direction` — Fahrtrichtung (z.B. "Steubenallee", "Kampstraße")
- `observed_at` — Zeitstempel der Sichtung (Datum + Uhrzeit)
- `stop_name` — Haltestelle, an der gesichtet wurde

**Schnittstelle MDKursTracker → MD-Takt:** Noch offen (API oder Direktzugriff auf NaruaDB). Wird in separatem Dokument spezifiziert. Der Collector auf dem NAS ist der Integrationspunkt.

---

## 3. Kern-Algorithmus: Umlauf-Rekonstruktion

> ⚠️ Dies ist die fachlich komplexeste Komponente. Der Algorithmus ist **bewusst offen** — das System soll beim Matching unterstützen, nicht autonom entscheiden.

### 3.1 Ziel
Für eine gegebene Sichtung (Kursnummer + Linie + Richtung + Zeit + Haltestelle) soll das System **Kandidaten-Trips** aus den GTFS-Daten vorschlagen, die zeitlich und räumlich passen.

### 3.2 Matching-Logik (Vorschlag, im Frontend manuell bestätigbar)

1. **Filter nach Betriebstag** — aus `observed_at` den korrekten GTFS `service_date` ermitteln.
2. **Filter nach Linie** — `routes.txt` nach `route_short_name` filtern (nur Trams, `route_type=0`).
3. **Filter nach Haltestelle** — `stop_times.txt` auf Fahrten einschränken, die die gesichtete Haltestelle bedienen.
4. **Filter nach Zeitfenster** — Abfahrts-/Ankunftszeit ±N Minuten um `observed_at`.
5. **Ergebnis:** Liste von `trip_id`-Kandidaten → Nutzer wählt den korrekten Trip aus.
6. **Zuordnung speichern:** `course_number` wird der bestätigten `trip_id` zugeordnet.
7. **Umlauf-Ableitung:** Alle `trip_ids` mit derselben `course_number` am selben `service_date` bilden einen Umlauf.

### 3.3 Offene Frage (vor Implementierung zu klären)
- Toleranz des Zeitfensters (±5 min? ±10 min?) — konfigurierbar machen.
- Umgang mit Sichtungen ohne passendem GTFS-Trip (Sonderfahrten, Betriebsfahrten).

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
| Engine (API) | `/engine` | Laravel 11, PHP 8.3+ | Hetzner, `api.strassenbahn-magdeburg.de` |
| Viewer (Frontend) | `/viewer` | Vue 3, Vite, Tailwind | Hetzner, `app.strassenbahn-magdeburg.de` |
| Gemeinsame Defs | `/shared` | OpenAPI 3.x YAML | — |

---

## 5. API-Endpunkte (MVP)

Alle Antworten als JSON. Fehlerformat: `{ "error": { "code": int, "message": string } }`.

### GTFS / Stammdaten
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `GET` | `/api/v1/trips?date=&line=&stop=&time=` | GTFS-Trips nach Datum, Linie, Haltestelle, Zeit filtern (Matching-Kandidaten) |
| `GET` | `/api/v1/stops` | Alle Tram-Haltestellen |
| `GET` | `/api/v1/lines` | Alle Tram-Linien |

### Sichtungen
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `GET` | `/api/v1/sightings?date=` | Alle Sichtungen eines Betriebstags |
| `POST` | `/api/v1/sightings` | Neue Sichtung anlegen (vom Collector) |

### Umläufe
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `GET` | `/api/v1/blocks?date=` | Alle Umläufe eines Tages (gruppiert nach `course_number`) |
| `GET` | `/api/v1/blocks/{course_number}?date=` | Einzelner Umlauf mit allen zugeordneten Trips |
| `POST` | `/api/v1/sightings/{id}/assign` | Trip-Zuordnung zu einer Sichtung bestätigen |

### Collector (intern, API-Token geschützt)
| Method | Endpunkt | Beschreibung |
|---|---|---|
| `POST` | `/api/v1/collector/gtfs-import` | GTFS-Feed-Import anstoßen |
| `POST` | `/api/v1/collector/sightings` | Sichtungen im Batch importieren |

---

## 6. Authentifizierung

- **Collector → Engine:** Bearer-Token (statischer API-Key in `.env`, kein Login).
- **Viewer → Engine:** Kein Auth im MVP (öffentliche Lesezugriffe + manuelles Matching ohne Login).
- **Zukunft:** Falls Multi-User benötigt, Laravel Sanctum nachrüsten.

---

## 7. Datenbank-Schema (Kern-Tabellen)

```sql
-- GTFS Stammdaten (vom Collector befüllt)
routes          (route_id PK, route_short_name, route_type)
stops           (stop_id PK, stop_name, lat, lon)
trips           (trip_id PK, route_id FK, service_id, block_id, direction_id)
stop_times      (trip_id FK, stop_id FK, arrival_time, departure_time, stop_sequence)
calendar_dates  (service_id, date, exception_type)

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
- GTFS-Import (Tram-Linien, Magdeburg/MVB-Feed)
- Sichtungs-Import via Collector
- Manueller Matching-Workflow im Viewer (Sichtung → Trip-Kandidaten → Bestätigung)
- Tagesansicht: Liste aller Umläufe mit ihren Trips

### Explizit NICHT im MVP
- Automatische/KI-gestützte Umlauf-Zuordnung ohne Nutzerinteraktion
- Echtzeit-Fahrzeugpositionen / GTFS-RT
- Bus, S-Bahn oder andere Verkehrsmittel
- Multi-User / Login-System
- Mobile App

---

## 9. Technische Anforderungen

- **Datenbank:** PostgreSQL — Nutzung von Window Functions & CTEs für Umlauf-Abfragen. Zeitstempel immer `TIMESTAMPTZ`.
- **PHP:** 8.3+, strikte Typisierung, Laravel 11.
- **Frontend:** Vue 3 Composition API (`<script setup>`), Tailwind CSS, Axios.
- **Primärschlüssel:** UUID oder `BIGSERIAL` je nach Tabelle.
- **Sprache:** Code & Kommentare Deutsch/Englisch-Mix (Fachbegriffe Englisch, Inline-Kommentare Deutsch).
