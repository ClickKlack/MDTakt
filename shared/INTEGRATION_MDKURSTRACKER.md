# Integration MDKursTracker ↔ MD-Takt

> **Stand: 2026-06-23.** Architektur- und Matching-Konzept, am realen Datensatz **validiert**.
> Wiedereinstiegspunkt für die Integration. Offene fachliche Festlegungen sind unten markiert (Stopp-Regel).
> Anforderungen an die MDKursTracker-Seite separat in [`MDKURSTRACKER_REQUIREMENTS.md`](MDKURSTRACKER_REQUIREMENTS.md).

---

## TL;DR (Wiedereinstieg in 60 Sekunden)

- **Richtung:** MD-Takt-Engine = **reiner Server, ruft nie raus**. MDKursTracker ist Client beide Richtungen.
  NAS-Collector macht **nur GTFS**.
- **Fluss 1 (Ingest):** MDKursTracker-Cron schiebt **nächtlich** alle neuen Sichtungen (auf Routen-Ebene) →
  `POST /api/v1/collector/sightings`.
- **Fluss 2 (Auskunft):** MDKursTracker fragt **on-demand** je Abfahrt → `GET /api/v1/course-lookup`
  (Parameter: HAFAS-`stop_id`, Linie, Soll-Zeit, Datum) → Kursnummer falls bekannt.
- **Matching validiert & deterministisch:** `(Linie, Tagestyp, Soll-Zeit-Sequenz lokal)`. Kein HAFAS↔GTFS-Stop-ID-Crosswalk nötig.
- **Stabile Schlüssel sind name-frei:** Stop = **gerundete Koordinaten**, Trip = **Signatur(Linie + Zeitsequenz)**.
  Die volatilen gtfs.de-Surrogat-IDs sind nur refreshbare Pointer.
- **1 HAFAS-Fahrt → N GTFS-Trips** (Linienübergänge).

---

## 1. Netz-Topologie

| Komponente | Ort |
|---|---|
| NAS-Collector | Heim-NAS (privat) |
| MD-Takt-Engine | Hetzner |
| MDKursTracker | Hetzner (gleiches Hosting) |

Sichtungen über den Heim-NAS zu leiten wäre ein sinnloser Umweg. Der Collector bleibt auf seinem Job:
externen GTFS-Feed laden und in die Engine pushen.

---

## 2. MDKursTracker-Datenmodell (faktisch belegt aus Code + Live-DB)

DBMS: **MariaDB ≥10.4, nur lokal**. Kein DB-Direktzugriff → Integration über die **HTTP-API**.

- `recordings` (eine Sichtung einer Abfahrt): `trip_id`, `recorded_at` (UTC), `hafas_trip_id` (jid),
  `service_date` (Europe/Berlin-Betriebstag), `stop_id` (HAFAS extId inkl. Steig), `departure_planned` (Soll, **UTC**),
  `departure_actual` (UTC, NULL ohne RT), `course_number` (CHAR(2), **Nutzereingabe**, **nur je Linie eindeutig**), …
- `trips` (logische Fahrt, aus recordings aggregiert): `line`, `direction`, `day_type`, `service_nr` (=HAFAS `ZI_TA`),
  `schedule_fingerprint` (SHA über `StopID+HH:MM`-Sequenz, **tagesunabhängig**), `manual_course_number` (Admin-Override).
- `route_stops`: Laufweg je Trip mit **Soll-Zeit pro Halt** (UTC) und **Linie pro Halt** (Linienübergänge!).
- Zeiten UTC; `service_date` Berlin-Betriebstag. **Keine** Geo-Koordinaten persistiert.
- REST-API vorhanden (`GET /api/trips`, `/api/recordings`, `/api/recordings/{id}/route`, …). **Kein Scheduler/Outbound**
  (curl nur Richtung HAFAS) — Cron ist aber baubar. Kein „bestätigt"-Flag; nächstes Konzept: `manual_course_number`
  + `courseSource`-Label (`manual`/`recorded`/`heuristic`).

---

## 3. Richtung & Datenflüsse

**Leitprinzip:** Wer die Wahrheit besitzt, ist Server. Engine besitzt „bestätigter Umlauf", MDKursTracker besitzt Sichtungen.

```
NAS-Collector  ──GTFS-Feed──▶  MD-Takt-Engine            (unverändert, nur GTFS)
                               (reiner Server, ruft nie raus)
MDKursTracker-Cron ──nächtlich, Batch──▶  POST /collector/sightings     Fluss 1 (Ingest)
MDKursTracker      ──on-demand je Abfahrt──▶  GET /course-lookup        Fluss 2 (Auskunft)
```

- **Fluss 1 = nächtlicher Batch-Push** aller neuen Sichtungen (auf **Routen-Ebene**: voller Laufweg + Soll-Zeiten
  + aufgelöste `course_number`). Treiber: Cron in MDKursTracker, inkrementell per High-Water-Mark.
- **Fluss 2 = On-demand-Einzelabfrage.** MDKursTracker ist query-getrieben (Live-HAFAS-Abfahrten je Halt) und hat keinen
  gespeicherten Fahrplan zum Vor-Annotieren → ein **Cron-Pull *nach* MDKursTracker ergibt keinen Sinn**. Stattdessen
  fragt es beim Rendern der Abfahrtstafel je Abfahrt live: „Kennt MDTakt für diesen Halt/Linie/Soll-Zeit einen Kurs?"
- **Wert von Fluss 2:** MDKursTracker kennt Kurse nur punktuell (eigene Fingerprints). MDTakt kennt einen Kurs nach
  dem Matching für den **gesamten GTFS-Trip** — alle Halte, alle Tage des Tagestyps. Fluss 2 macht aus spärlichen
  Beobachtungen **flächige Kursauskunft**.
- **Dependency:** Das „externer Call + Abhängigkeit"-Bedenken ist beherrschbar — die Antwort ist stabil und **cachebar
  je Tag**; fällt MDTakt aus, zeigt die Abfahrtstafel einfach nur die eigene Fingerprint-Info (graceful degradation).

---

## 4. Matching & stabile Schlüssel

### 4.1 Matching-Verfahren (validiert)
Pro Linien-Segment einer MDKursTracker-Fahrt den GTFS-Trip über **`(route_short_name, Tagestyp via calendar,
Soll-Zeit-Sequenz HH:MM lokal)`** finden. Soll-Zeit (`departure_planned`, UTC) → **`Europe/Berlin` (DST-bewusst!)** →
GTFS-`stop_times`-Lokalzeit (kann >24:00:00 sein).

**Der Beweis (Trip 776: L5→L1 durchgebunden, MO-FR, Kurs 03), geprüft gegen die Engine-DB:**

| MDKursTracker (Soll, lokal) | GTFS-Treffer (Engine-DB) |
|---|---|
| L5 Klinikum Olvenstedt 17:21 → City Carré 17:55 | Trip `106173`, 27 Halte, **17:21 → 17:55**, svc 1966 |
| L1 City Carré 17:55 → Sudenburg (Kroatenweg) 18:12 | Trip `1316520`, 14 Halte, **17:55 → 18:12**, svc 1966 |
| day_type MO-FR | `calendar` 1966 = Mo–Fr=1, Sa/So=0 ✓ |

Über den gesamten Laufweg **minutengenau**, Match **ohne** Stop-ID-Crosswalk (Linie + Zeit). Eine HAFAS-Fahrt → **N** GTFS-Trips.

### 4.2 Stabile Schlüssel sind name-frei (ID-Stabilität, Schritt 1)
gtfs.de/nv_free vergibt **build-lokale Surrogat-Integer** als `stop_id`/`trip_id` (kein DHID/IFOPT — DHID ist nicht verfügbar).
Sie sind *meist*, aber **nicht garantiert** stabil über Builds. **Haltestellennamen können sich ebenfalls ändern.**
Deshalb schlüsseln wir gelerntes Wissen **weder auf die IDs noch auf Namen**:

| Asset | **stabiler** Schlüssel | refreshbarer Pointer |
|---|---|---|
| **Stop-Map** | **gerundete Koordinaten** (Nearest-Neighbor, ~≤20 m; Name nur als Label) | aktueller `gtfs_stop_id` |
| **Kurs↔Trip-Zuordnung** | **Trip-Signatur** = SHA(`route_short_name` + `day_type` + geordnete Abfahrts-`HH:MM`-Sequenz) | aktueller `gtfs_trip_id` |

Bei jedem Re-Import werden die Pointer neu aufgelöst (Identität → aktuelle ID). → **Gelerntes Wissen überlebt jeden Feed-Import.**

- **Koordinaten statt Name** für Stops: stabil unter Umbenennung; unterscheidet Steige (Hasselbachplatz: 9 stop_ids,
  identischer Name, je eigene Koordinaten); jitter-tolerant per Nearest-Neighbor.
- **Trip-Signatur ist period-frei.** Sie identifiziert „den 17:21-Mo-Fr-Lauf auf Linie 5" unabhängig von der
  Fahrplanperiode. Ob/wann er an einem Datum fährt, beantwortet die `calendar`-Schicht (I-03 `TripFilterService`),
  nicht die Signatur. Gleiche Zeiten über Perioden → gleiche Signatur → Zuordnung gilt weiter; geänderte Zeiten →
  Signatur fehlt im neuen Feed → Zuordnung als *stale/neu zu bestätigen* markieren.
- **`day_type` bleibt in der Signatur.** MD-Takt nutzt darin die **vier** Fahrplantypen aus FAHRPLANPERIODEN §2
  (`mo_fr`, `mo_fr_ferien`, `sa`, `so_feiertag`; entschieden 18.08.2026), nicht die drei Werte aus dem Fluss-1-Payload.
  Kein Konflikt: Jede Sichtung trägt ein `service_date`, die Engine klassifiziert es selbst und leitet daraus den
  Typ ab — der Tracker muss Ferien nicht kennen. Die **Periodengrenzen** bleiben aus der Signatur heraus.

### 4.3 Selbstlernende Stop-Map
Nebenprodukt des Matchings (das die Map **nicht** voraussetzt): Nach dem Trip-Match beide Laufwege **per Zeit im
Gleichschritt** durchgehen und `HAFAS-extId @ HH:MM` ↔ `GTFS-Koordinaten @ HH:MM` mit **+1 Konfidenz** buchen. Über
viele Fahrten konvergiert die Map, Fehlpaare werden überstimmt. Lernen auf `(trip, stop, Zeit)`-Ebene wegen
Ringverläufen (gleicher Halt zweimal). Ein Trip-Match liefert ~alle Halt-Paare der Fahrt auf einmal.

### 4.4 GTFS-Datenstand-Notizen (Engine-DB, geprüft 2026-06-22)
- `stop_id` = kurze gtfs.de-Eigen-IDs (`503024`, …), **mehrere pro Name** (Steige, mit lat/lon). Nicht HAFAS-Schema.
- `route_short_name`: Tram `1,2,3,4,5,6,8,9,10,13`, Bus `48,51–73`, Nacht `N1–N9`. Matcht HAFAS-Linienlabel direkt.
- `stop_sequence` beginnt bei **0**. Feed-Build aktuell `2026-06-20` (rollierendes `latest.zip`).

---

## 5. Endpunkt-Definitionen (MD-Takt-Engine)

> Vertrag-Entwurf. Die formale Aufnahme in `openapi.yaml` + Bruno erfolgt bei der Implementierung (I-04/I-05), sobald
> das Datenmodell final ist (Stopp-Regel). MDKursTracker-Sicht separat in [`MDKURSTRACKER_REQUIREMENTS.md`](MDKURSTRACKER_REQUIREMENTS.md).

### 5.1 Fluss 1 — `POST /api/v1/collector/sightings`
**Zweck:** Nächtlicher Batch-Import aller neuen Sichtungen. **Auth:** Bearer Collector-Token (`collector.token`-Middleware),
gzip-Body erlaubt (`decompress`), wie die übrigen Collector-Endpunkte.

**Body:** zwei Abschnitte — `trips` (Routendefinitionen, dedupliziert per `schedule_fingerprint`, liefern den Laufweg
fürs Matching + Stop-Lernen) und `sightings` (die einzelnen neuen Beobachtungen, referenzieren einen Trip per Fingerprint).

```jsonc
{
  "sync":  { "since": "2026-06-22T00:00:00Z", "generated_at": "2026-06-23T01:00:00Z" },
  "trips": [
    {
      "mdkt_trip_id": 776,
      "schedule_fingerprint": "44351eb41af0…",
      "line": "1",                       // Linie der Gesamtfahrt (Start/POST)
      "direction": "Sudenburg",
      "day_type": "MO-FR",               // MO-FR | SA | SO
      "service_nr": "139916_35",         // HAFAS ZI_TA, informativ
      "stops": [                         // vollständiger Laufweg, geordnet
        { "seq": 1,  "hafas_stop_id": "300730901", "stop_name": "Magdeburg, Klinikum Olvenstedt",
          "line": "5", "departure_planned": "2026-04-15T15:21:00Z" },
        { "seq": 27, "hafas_stop_id": "300384602", "stop_name": "Magdeburg, City Carré",
          "line": "1", "departure_planned": "2026-04-15T15:55:00Z" }
        // …
      ]
    }
  ],
  "sightings": [
    {
      "mdkt_recording_id": 1935,          // Idempotenz-Schlüssel (kein Duplikat bei Re-Sync)
      "schedule_fingerprint": "44351eb41af0…",
      "hafas_stop_id": "301968501",
      "line": "9",
      "course_number": "13",
      "service_date": "2026-06-18",
      "observed_at": "2026-06-18T16:42:35Z",
      "departure_planned": "2026-06-18T16:43:00Z",
      "departure_actual":  "2026-06-18T16:42:00Z"  // nullable
    }
  ]
}
```

**Verarbeitung (Engine):** je `trips[]` → GTFS-Trip(s) matchen (4.1), Stop-Map lernen (4.3), Kurs↔Signatur-Zuordnung
ableiten; je `sightings[]` → speichern (Upsert auf `mdkt_recording_id`), Konfidenz/Mehrheit fortschreiben.

**Response 200:**
```jsonc
{ "data": {
  "received":  { "trips": 1, "sightings": 1 },
  "matched":   { "trips": 1, "segments": 2, "unmatched_fingerprints": [] },
  "stop_map":  { "pairs_learned": 41, "newly_confirmed": 3 },
  "watermark": { "max_recording_id": 1935, "max_recorded_at": "2026-06-18T16:42:35Z" }
} }
```
`unmatched_fingerprints` macht **Mismatches sichtbar** (Routen, für die kein GTFS-Trip gefunden wurde → manuell prüfen).

### 5.2 Fluss 2 — `GET /api/v1/course-lookup`
**Zweck:** On-demand-Auskunft, ob für eine Abfahrt eine Kursnummer bekannt ist. **Auth:** öffentlicher Read-Endpunkt
(kein Auth im MVP, analog Viewer; Kursnummern sind öffentliche Anzeige-Info). Cachebar.

**Query-Parameter:**
| Param | Pflicht | Bsp | Bedeutung |
|---|---|---|---|
| `hafas_stop` | ja | `301968501` | HAFAS extId der Haltestelle (inkl. Steig) |
| `line` | ja | `1` | Linie **an diesem Halt** (Linienübergänge!) |
| `time` | ja | `2026-06-18T16:43:00Z` | Soll-Abfahrt, ISO-8601 **UTC** |
| `date` | ja | `2026-06-18` | Betriebstag (Berlin), für Tagestyp + Periode |
| `stop_name` | optional | `Magdeburg, …` | Cold-Start-Fallback, wenn Stop-Map den HAFAS-Stop noch nicht kennt |

**Auflösung (Engine):** `hafas_stop` → GTFS-Stop via Stop-Map (sonst Namens-Fallback) → aktive `service_id`s für `date`
(I-03) → `time` UTC→Berlin-Lokalzeit → GTFS-`stop_time` bei `(gtfs_stop_id, line, Lokalzeit, aktiver service_id)` → Trip
→ Kurs-Zuordnung über die Signatur.

**Response 200 (gefunden):**
```jsonc
{ "data": {
  "found": true,
  "course_number": "03",
  "line": "1",
  "confidence": "majority",        // confirmed | majority | single | heuristic  (Semantik s. §6)
  "source": "mdkurstracker-sighting",
  "matched_trip":  { "signature": "…", "gtfs_trip_id": "1316520", "departure_local": "17:55:00" },
  "stop_mapping":  { "resolved_via": "learned", "gtfs_stop_id": "498258" }
} }
```
**Response 200 (nicht gefunden):** `{ "data": { "found": false, "reason": "stop-unmapped" | "no-trip-match" | "no-course-assigned" } }`

`confidence`/`source` lässt MDKursTracker selbst entscheiden, wie es den Kurs anzeigt; `reason` macht **Mismatches
diagnostizierbar**. Caching: Antwort stabil je `(hafas_stop, line, time, date)` bis zur (Neu-)Zuordnung →
`Cache-Control`/ETag; MDKursTracker cacht je Tag.

---

## 6. Offene Punkte (vor Implementierung zu klären/festzulegen)

1. **§3.2-Algorithmus fachlich festschreiben** (Stopp-Regel): Zeit-Sequenz-Match statt Stop-ID-Filter; ersetzt/präzisiert SPEC §3.2.
2. **`confidence`-Semantik / „Kurs feststehend":** Wann gilt eine Kurszuordnung als `confirmed` vs. `majority`/`single`?
   (z. B. Admin-Override → confirmed; n übereinstimmende Sichtungen → majority …). Bestimmt die Aussagekraft von Fluss 2.
3. **MDKursTracker-Endpunkt für den Soll-Zeit-Laufweg** pro Trip prüfen/bereitstellen (Input für `trips[]` in Fluss 1).
4. **Datenmodell-Neufassung MD-Takt** (Stopp-Regel): importierte Routen + Stop-Map (Koordinaten + Konfidenz) +
   Kurs↔Signatur-Zuordnung (1:N) + Lookup-Index. Berührt SPEC §7 und I-04/I-05/I-06.
5. **`MATCHING_WINDOW_MINUTES`** (I-05): nur noch Sicherheitsmarge (Soll-gegen-Soll ist minutengenau) — Wert wählen.
6. **Feedback-Loop vermeiden:** MDKursTracker darf `course-lookup`-Antworten **nie** wieder als eigene Sichtung einspeisen.

---

## 7. Bezug zu SPEC/ROADMAP & weiteren Dokumenten
- Anforderungen an die MDKursTracker-Seite: [`MDKURSTRACKER_REQUIREMENTS.md`](MDKURSTRACKER_REQUIREMENTS.md).
- Ersetzt langfristig Teile von **SPEC §2.3** (Schnittstelle) und **§3** (Matching) — dort verlinkt, noch nicht überschrieben.
- Betrifft **ROADMAP I-05** (Matching) und **I-09** (Collector-Integration / Schnittstellen-Entscheidung).
