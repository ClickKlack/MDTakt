# Integration MDKursTracker ↔ MD-Takt

> **Stand: 2026-09-26.** Beide Flüsse sind auf Engine-Seite **umgesetzt**: Sichtungs-Eingang samt Prüfliste und
> Entscheidung im Fahrplan (§8) und Kursauskunft (§5.2). Offen ist die Tracker-Seite beider Flüsse.
> Ursprüngliches Konzept vom 23.06.2026, am realen Datensatz validiert.
> Anforderungen an die MDKursTracker-Seite separat in [`MDKURSTRACKER_REQUIREMENTS.md`](MDKURSTRACKER_REQUIREMENTS.md).

---

## TL;DR (Wiedereinstieg in 60 Sekunden)

- **Richtung:** MD-Takt-Engine = **reiner Server, ruft nie raus**. MDKursTracker ist Client beide Richtungen.
  NAS-Collector macht **nur GTFS**.
- **Fluss 1 (Ingest):** Ein **Cron in MDKursTracker** schickt Sichtungen nach einer **Karenzzeit** an
  `POST /api/v1/collector/sightings` (vom Tracker festgelegt 26.09.2026; maßgeblich ist seine Sync-Spalte).
  Löschungen werden dauerhaft nicht übertragen. Eigener Token, getrennt vom NAS-Collector.
- **Fluss 2 (Auskunft):** MDKursTracker fragt **on-demand** je Abfahrt oder je Tafel → `GET|POST /api/v1/collector/course-lookup`
  (HAFAS-Halt, Linie, Soll-Zeit, optional Haltname und Richtung; Tracker-Token) → Kursnummer falls bekannt (§5.2).
- **Matching validiert & deterministisch:** `(Linie, Tagestyp, Soll-Zeit-Sequenz lokal)`. Kein HAFAS↔GTFS-Stop-ID-Crosswalk nötig.
  Die **Engine** bildet die Fahrt-Signatur aus dem mitgesendeten Laufweg selbst — der Tracker hasht nichts (§8.1).
- **Sichtungen laufen in eine Tabelle mit Status** und werden im Admin angenommen oder abgelehnt — in einer
  Prüfliste und direkt im Fahrplan. Annehmen setzt den Kurs an die **ganze Kette** (§8.3).
- **Stabile Schlüssel sind name-frei:** Stop = **gerundete Koordinaten**, Trip = **Signatur(Linie + Zeitsequenz)**.
  Die volatilen gtfs.de-Surrogat-IDs sind nur refreshbare Pointer.
- **1 HAFAS-Fahrt → N GTFS-Trips** (Linienübergänge).
- **Der Umlauf ist eine Kette, nicht ein Tripel** (korrigiert 20.09.2026, siehe [`KURSE.md`](KURSE.md)): Ein Fahrzeug
  behält beim Linienwechsel seine Kursnummer (`1/03` → `13/03`), der Umlauf umfasst also mehrere Linien. Die frühere
  Formel `(line, course_number, service_date)` identifiziert ihn nicht. MD-Takt setzt die Kette aus **gepflegten
  Anschlüssen** je Haltestelle zusammen (I-14) — die Sichtungen liefern die Kursnummern in dieses Gefüge hinein.

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
MDKursTracker-Cron ──nach Karenzzeit──▶  POST /collector/sightings     Fluss 1 (Ingest)
MDKursTracker      ──je Abfahrt / Tafel──▶  GET|POST /collector/course-lookup   Fluss 2 (Auskunft)
```

- **Fluss 1 = Cron mit Karenzzeit** (vom Tracker festgelegt 26.09.2026; vorher geplant: Sofort-Push + Nachhol-Cron).
  Jede Sichtung bringt ihren Laufweg mit (Soll-Zeiten + Linie je Halt). Die Karenzzeit gibt dem Nutzer Gelegenheit,
  eine Sichtung zu korrigieren oder zu löschen, bevor sie MD-Takt erreicht — **Löschungen werden danach dauerhaft nicht
  übertragen**. Maßgeblich für die Auswahl ist die Sync-Spalte des Trackers; `watermark` in der Antwort ist informativ.
  **Laufzeit ist kein Argument** — bei einigen Dutzend Sichtungen am Tag ist die Zuordnung ein Index-Lookup je Sichtung.
  Entscheidend ist die Latenz, und die bestimmt der Tracker mit Intervall und Karenzzeit.
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
**Zweck:** Sichtungs-Eingang — per Cron des Trackers nach Karenzzeit. **Auth:** eigener Bearer-Token
`MDKURSTRACKER_API_TOKEN` (`collector.token:mdkurstracker`) — der Collector-Token gilt hier nicht und umgekehrt.
gzip-Body erlaubt (`decompress`), Throttle 120/min. Grenzen je Request: 500 Sichtungen, 200 Routen, 150 Halte je Route.
Zeiten nur als ISO-8601 UTC mit `Z`. **Maßgeblich ist `openapi.yaml`** — hier der Überblick.

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
          "line": "5", "departure_planned": "2026-04-15T15:21:00Z" },   // arrival_planned optional je Halt
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

**Verarbeitung (Engine, umgesetzt):** je `trips[]` → Laufweg per Fingerprint speichern (`mdkt_routes`); je
`sightings[]` → Upsert auf `mdkt_recording_id`, dann Zuordnung (§8.1) und Status (§8.2). Ein schon bekannter
Fingerprint darf in `trips[]` fehlen. `day_type` ist informativ — die Engine bestimmt den Fahrplantyp selbst.
Die Stop-Map (§4.3) ist **nicht** umgesetzt; die Zuordnung braucht sie nicht.

**Response 200:**
```jsonc
{ "data": {
  "received": { "trips": 1, "sightings": 1 },
  "results": [
    { "mdkt_recording_id": 1935, "outcome": "created",        // created | updated | unchanged | unknown_fingerprint
      "match": "matched", "status": "pending", "consolidated_trip_id": 26286 }
  ],
  "unmatched_fingerprints": [],
  "watermark": { "max_recording_id": 1935, "max_observed_at": "2026-06-18T16:42:35Z" }
} }
```
`unmatched_fingerprints` macht **Mismatches sichtbar** (Routen, für die kein GTFS-Trip gefunden wurde → manuell prüfen).

### 5.2 Fluss 2 — `GET|POST /api/v1/collector/course-lookup` (umgesetzt 26.09.2026)
**Zweck:** Kursauskunft je Abfahrt der Tafel. **Auth:** derselbe Tracker-Token wie Fluss 1 (entschieden 26.09.2026,
vorher „öffentlich") — die Umlaufdaten sollen nicht massenhaft abziehbar sein. Eigenes Limit 600/min.
`GET` für eine Abfahrt, `POST` mit `departures[]` für bis zu 100 (je mit `ref`, das zurückkommt).
**Maßgeblich ist `openapi.yaml`.**

| Param | Pflicht | Bedeutung |
|---|---|---|
| `hafas_stop` | ja | HAFAS-extId der Abfahrt (inkl. Steig) |
| `line` | ja | Linie **an diesem Halt** |
| `time` | ja | Soll-Abfahrt, UTC mit `Z` |
| `stop_name` | nein | Rückfall, solange die HAFAS-ID noch nicht gelernt ist |
| `direction` | nein | Richtung/Ziel wie auf der Tafel — trennt zwei Richtungen zur selben Minute |
| `date` | nein | informativ; den Betriebstag bestimmt die Engine aus `time` |

**Auflösung (Engine, `DepartureCourseLookupService`):**
1. Soll-Zeit → Netz-Zeit. Gesucht wird in allen drei Schreibweisen des Feeds: `HH:MM` am Kalendertag,
   `HH:MM` am Vortag (Fahrt beginnt nach Mitternacht vor der Betriebstag-Grenze), `HH+24:MM` am Vortag.
2. Kandidaten: Fahrten der Linie in der am Betriebstag gültigen Version, die an einem Halt **genau** zu dieser Minute
   abfahren. Keine Toleranz.
3. Eingrenzen über den Halt: zuerst die **aus Sichtungen gelernte** HAFAS-ID (Halt der zugeordneten Fahrt zur
   Soll-Uhrzeit der Sichtung; keine eigene Tabelle, eine Stunde gecacht — die Stop-Map aus §4.3 in einfacher Form),
   sonst der **Haltname** (normalisiert, „Magdeburg, …" und „Str." eingeebnet). Bleiben mehrere, trennt `direction`
   über den Zielhalt der Fahrt.
4. Genau eine Fahrt mit Kurs → `found: true`. Sonst `reason`: `no-trip-match` | `ambiguous` | `no-course-assigned`.

**Response 200 (gefunden):**
```jsonc
{ "data": {
  "found": true, "course_number": "03", "display": "10/03", "line": "10",
  "matched_trip": { "id": 26286, "line_version_id": 338, "departure_local": "06:10:00" },
  "stop_resolved_via": "sighting"     // sighting | name | time-only, ggf. +direction
} }
```

**Kursnummer wie gespeichert:** `course_number` und `display` liefert MD-Takt genau so, wie der Kurs gepflegt ist —
mit oder ohne führende Null (`3` oder `03`, `10/3` oder `10/03`). MD-Takt normalisiert nicht. Braucht die Tracker-Anzeige
zweistellige Nummern, **ergänzt MDKursTracker die führende Null selbst**; beim Vergleich mit eigenen Nummern führende
Nullen ignorieren.

**Keine Konfidenz (entschieden 26.09.2026):** MD-Takt verwaltet die Wahrheit. Viele Kurse entstehen durch logisches
Fortschreiben statt aus Sichtungen und sind deshalb nicht weniger richtig — die Antwort unterscheidet das nicht.

**Caching:** `Cache-Control: private, max-age=3600` — eine Stunde, damit ein gerade angenommener Kurs noch am selben
Tag ankommt. **Feedback-Loop-Verbot:** Eine Auskunft darf nie als Sichtung in Fluss 1 zurückfließen.

**Probe am Bestand (26.09.2026):** 200 zufällige Abfahrten der Linie 10 mit Haltname und Richtung — 200 gefunden,
keine falsch. Ohne Richtung blieben 26 mehrdeutig: An der Rostocker Straße fahren beide Richtungen zur selben
Minute an gleichnamigen Bahnsteigen ab.

---

## 6. Offene Punkte

> **Stand 26.09.2026:** Punkte 1, 2, 4 und 5 sind entschieden und umgesetzt (§5.2, §8). Offen bleiben 3 (Laufweg-Export
> auf Tracker-Seite) und 6 (Feedback-Loop — Aufgabe des Trackers).

1. ~~**§3.2-Algorithmus fachlich festschreiben**~~ — **erledigt:** exakter Signatur-Match, SPEC §3.2 neu gefasst.
2. ~~**`confidence`-Semantik**~~ — **entfällt:** Die Auskunft liefert keine Konfidenz, MD-Takt ist die Wahrheit (§5.2). Ursprünglich: **„Kurs feststehend":** Wann gilt eine Kurszuordnung als `confirmed` vs. `majority`/`single`?
   (z. B. Admin-Override → confirmed; n übereinstimmende Sichtungen → majority …). Bestimmt die Aussagekraft von Fluss 2.
3. **MDKursTracker-Endpunkt für den Soll-Zeit-Laufweg** pro Trip prüfen/bereitstellen (Input für `trips[]` in Fluss 1).
4. ~~**Datenmodell-Neufassung MD-Takt**~~ — **erledigt** für Fluss 1 (`mdkt_routes`, `sightings`; Stop-Map entfällt vorerst). Ursprünglich:: importierte Routen + Stop-Map (Koordinaten + Konfidenz) +
   Kurs↔Signatur-Zuordnung (1:N) + Lookup-Index. Berührt SPEC §7 und I-04/I-05/I-06.
5. ~~**`MATCHING_WINDOW_MINUTES`**~~ — **entfällt:** keine Toleranz, der Match ist exakt (entschieden 26.09.2026).
   Was nicht minutengenau passt, wartet auf den nächsten Import (§8.2).
6. **Feedback-Loop vermeiden:** MDKursTracker darf `course-lookup`-Antworten **nie** wieder als eigene Sichtung einspeisen.

---

## 7. Bezug zu SPEC/ROADMAP & weiteren Dokumenten
- Anforderungen an die MDKursTracker-Seite: [`MDKURSTRACKER_REQUIREMENTS.md`](MDKURSTRACKER_REQUIREMENTS.md).
- SPEC §2.3, §3.2, §5 und §7 sind auf den Stand von §8 gebracht (26.09.2026).
- Betrifft **ROADMAP I-05** (Matching) und **I-09** (Collector-Integration / Schnittstellen-Entscheidung).

---

## 8. Umsetzung Fluss 1 (26.09.2026)

Engine-Seite in drei Stufen umgesetzt (Commits `189b469`, `c2a41ae`, `487da24`). Entscheidungen vom 26.09.2026.

### 8.1 Zuordnung (`SightingMatcher`)
Die Engine bildet aus dem Laufweg genau die Signatur nach, die der Import je Fahrt schreibt
(`TripSignatureService::signatureFor`: `SHA256(Linie | Fahrplantyp | HH:MM-Folge)`), und sucht sie per Index.

- **Warum die Engine hasht, nicht der Tracker:** Der Tracker müsste dafür die vier Fahrplantypen (Ferien!), die
  Zeitschreibweise um Mitternacht und die Aufteilung an Linienwechseln exakt nachbauen. Jede kleine Abweichung
  hieße „keine Fahrt". Bei einigen Dutzend Sichtungen am Tag ist die Größe der Übertragung dagegen egal.
- **Linienwechsel:** Der Laufweg wird je Linie geteilt. Zu welchem Teil der Übergangshalt gehört, ist aus HAFAS nicht
  sicher — er wird auf beiden Seiten versucht, am Endhalt Ankunft und Abfahrt.
- **Mitternacht:** Der Feed schreibt **nicht** `25:10`. Eine Fahrt, die vor Mitternacht beginnt, läuft als `24:08`
  weiter; eine, die danach beginnt, steht mit `00:30` da und gehört zum **Vortag** und dessen Fahrplantyp
  (`OperatingDayResolver`).
- **Im Laufweg zählt nur die Uhrzeit** (Rückmeldung des Trackers, 26.09.2026): Alle Zeiten tragen das Datum des ersten
  HAFAS-Abrufs, auch die nach Mitternacht. Den Tageswechsel liest die Engine daraus ab, dass die Uhrzeit entlang des
  Laufwegs zurückspringt; das Datum dient nur dem Versatz zu UTC (Sommer-/Winterzeit). Der Betriebstag kommt aus der
  Sichtung selbst. Ein im Sommer erfasster Laufweg passt so auch im Winter.
- **Endhalt:** Der Tracker legt die Ankunft am Endhalt in `departure_planned` ab — die Engine versucht dort ohnehin
  Ankunft und Abfahrt.
- **Keine Toleranz.** Gültig ist die Version, deren Intervall den Betriebstag einschließt.
- **Folgeversion (Baustelle Linie 10):** Der Tracker kennt einen geänderten Laufweg über HAFAS sofort, der Feed oft erst
  eine Woche später. Ohne Treffer am Tag wird deshalb die nächste Version der Linie gesucht, wenn sie **höchstens
  14 Tage** nach dem Betriebstag beginnt → `matched_next_version`, markiert, nie automatisch bestätigt.
- **Mehrere Treffer** → `ambiguous`, es wird nichts geraten.

**Probe am Bestand (26.09.2026):** 400 zufällige Fahrten + 11 Linienwechsel als künstlicher Tracker-Export —
422 von 422 Sichtungen treffen die richtige Fahrt. Echte Tracker-Daten stehen noch aus
(`php artisan sightings:ingest-file export.json --dry-run` meldet die Trefferquote, ohne zu speichern).

### 8.2 Status
| `match` | Bedeutung |
|---|---|
| `matched` / `matched_next_version` | Fahrt gefunden (s. o.) |
| `waiting` | Noch kein Treffer. **Nach jedem GTFS-Import** wird automatisch neu zugeordnet |
| `no_trip` | Nach **2 Importen** weiter ohne Treffer (Betriebsfahrt, abweichender Laufweg) — nur ablehnbar |
| `ambiguous` | Mehrere Fahrten mit derselben Signatur — nur ablehnbar |

`status`: `pending` → `accepted` / `rejected` von Hand; **`confirmed` setzt die Engine selbst**, wenn der gesichtete
Kurs schon an der Fahrt hängt („03" = „3"; der Tracker sendet ohne führende Null). Auch beim Annehmen zählt die Null
nicht: Eine Sichtung „3" schließt sich dem vorhandenen Kurs „03" an, statt einen zweiten anzulegen. Ändert der Tracker eine entschiedene Sichtung, wird sie wieder `pending`.
Löschungen im Tracker werden **dauerhaft nicht** übertragen (Festlegung des Trackers) — die Karenzzeit fängt sie ab,
eine später gelöschte Sichtung wird in MD-Takt abgelehnt.

### 8.3 Prüfen und Entscheiden (Admin)
- **Prüfliste** (`/sichtungen`): Standard ist die Warteschlange (offen und entscheidbar); „wartet auf Fahrplan" und
  Entschiedenes über den Filter. Filter nach Linie, Betriebstag, „nur Abweichungen". Der Vergleich mit dem lokalen Kurs
  (`same`/`differs`/`none`/`no_trip`) wird bei jeder Abfrage berechnet, nicht gespeichert.
- **Fahrplan:** Zeile „Sichtungen" über der Kurszeile, je Spalte die meistgenannte Nummer mit ✓/✗ — hier lässt sich die
  Richtigkeit am besten beurteilen.
- **Annehmen** setzt die Nummer an die **ganze Kette** der Fahrt (KURSE §2 K2). Trägt die Kette einen anderen Kurs,
  wird sie **umnummeriert** — nach Rückfrage mit der Kettenlänge. Offene Sichtungen derselben Kette, die danach
  stimmen, werden bestätigt.
