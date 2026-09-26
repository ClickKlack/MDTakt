# Anforderungen an MDKursTracker — Integration mit MD-Takt

> **Adressat:** die MDKursTracker-Seite (Entwickler/Coding-Agent). **Stand: 2026-09-26.** Die MD-Takt-Seite **beider Flüsse** ist fertig und live-bereit — es fehlen §2.1 und §2.2 auf eurer Seite.
> Dieses Dokument ist **eigenständig lesbar** und beschreibt, was MD-Takt von MDKursTracker erwartet,
> was MDKursTracker bauen muss, und welche Annahmen MD-Takt über eure Daten trifft — letztere explizit,
> damit ein **Mismatch früh auffällt**. Gegenstück (MD-Takt-Sicht): `INTEGRATION_MDKURSTRACKER.md`.

---

## 1. Einordnung

**MD-Takt** rekonstruiert Fahrzeug-**Umläufe** der MVB aus GTFS-Fahrplandaten + euren Real-Sichtungen. Es matcht
eure beobachteten Fahrten auf GTFS-Trips und ordnet ihnen die von euch erfassten **Kursnummern** zu. Sobald ein Kurs
einem GTFS-Trip zugeordnet ist, kennt MD-Takt den Kurs für die **gesamte Fahrt** (alle Halte, alle Tage des Tagestyps)
— nicht nur dort, wo ihr ihn beobachtet habt.

Daraus zwei Datenflüsse:

| | Fluss 1 — Ingest | Fluss 2 — Auskunft |
|---|---|---|
| **Richtung** | MDKursTracker → MD-Takt | MDKursTracker → MD-Takt |
| **Modus** | Cron mit Karenzzeit | on-demand je Abfahrt oder je Tafel |
| **Zweck** | neue Sichtungen liefern | „Kennt MD-Takt für diese Abfahrt einen Kurs?" |
| **Wer baut** | Sync-Cron in MDKursTracker | Lookup-Call (je Abfahrt oder je Tafel) + UI-Anzeige in MDKursTracker |

**Grundprinzip:** MD-Takt ist ein **reiner Server** und ruft MDKursTracker **nie** von sich aus auf. **MDKursTracker ist
in beide Richtungen der aktive Client.** MD-Takt hält die DB-Verbindung niemals zu euch; alles läuft über HTTP.

---

## 2. Was MDKursTracker bauen muss

### 2.1 Fluss 1 — Cron mit Karenzzeit (festgelegt vom Tracker, 26.09.2026)

1. **Nur per Cron.** Ein Cron schickt Sichtungen, deren **Karenzzeit** abgelaufen ist — so lange darf der Nutzer eine
   Sichtung noch korrigieren oder löschen, bevor sie MD-Takt erreicht. Intervall und Karenzzeit legt der Tracker fest.
   Blöcke von höchstens 500 Sichtungen und 200 Laufwegen.
2. **Maßgeblich ist die Sync-Spalte des Trackers**, nicht der High-Water-Mark aus der Antwort. Nach einer 2xx-Antwort
   die gesendeten Sichtungen als übertragen markieren; bei einem Fehler nicht — der nächste Lauf wiederholt.
   Doppelt gesendete Sichtungen sind unschädlich: MD-Takt antwortet `outcome: unchanged`. Beim ersten Lauf lässt sich
   so der **Altbestand** einspielen.
3. **Geänderte Sichtung** nach der Übertragung (z. B. korrigierte Kursnummer): Sync-Spalte zurücksetzen, der nächste Lauf
   sendet sie mit derselben `mdkt_recording_id` erneut. MD-Takt legt sie dann wieder zur Prüfung vor.
4. **Löschungen werden dauerhaft nicht übertragen.** Eine nach der Übertragung gelöschte Sichtung bleibt in MD-Takt
   stehen und wird dort bei Bedarf abgelehnt.
5. **Laufweg:** `trips[].stops` = vollständiger Laufweg der Fahrt aus `route_stops`, **Linie je Halt**, Soll-Zeiten in UTC.
   Die Zeiten tragen das **Datum des ersten HAFAS-Abrufs** — das ist in Ordnung: MD-Takt wertet nur die Uhrzeit aus und
   nutzt das Datum lediglich für den Versatz zu UTC (Sommer-/Winterzeit). Am Endhalt darf die **Ankunft im Feld
   `departure_planned`** stehen. Einen Laufweg, den MD-Takt schon kennt, dürft ihr weglassen; mitsenden ist aber immer
   richtig und am einfachsten.
6. **Nichts selbst berechnen:** Die Fahrt-Signatur, den Fahrplantyp (inkl. Ferien) und den Betriebstag bestimmt
   MD-Takt. `day_type` ist nur informativ. Das **reale Datum** der Sichtung kommt aus `departure_planned` und
   `service_date` der Sichtung selbst, nicht aus dem Laufweg.

### 2.2 Fluss 2 — Kursauskunft + Anzeige (geändert 26.09.2026)
- Beim Rendern der Abfahrtstafel fragt MDKursTracker MD-Takt nach den Kursen (§3.2) — **am besten gesammelt**:
  ein `POST` mit allen Abfahrten der Tafel (bis 100) statt je Abfahrt ein `GET`.
- **Mitsenden, was die Tafel hat:** `hafas_stop`, `line` (Linie an diesem Halt), `time` (Soll-Abfahrt UTC), dazu
  **`stop_name` und `direction`** (Richtung/Ziel der Abfahrt). MD-Takt lernt die HAFAS-Halte erst aus euren Sichtungen;
  bis dahin grenzen Name und Richtung ein. Ohne Richtung bleiben z. B. an der Rostocker Straße beide Richtungen
  zur selben Minute mehrdeutig.
- **Caching:** höchstens **eine Stunde** je `(hafas_stop, line, time)` — die Antwort sagt es per
  `Cache-Control: private, max-age=3600`. Ein gerade in MD-Takt angenommener Kurs soll noch am selben Tag ankommen.
- **Graceful Degradation:** Ist MD-Takt nicht erreichbar oder `found:false`, zeigt die Tafel einfach nur eure eigene
  Fingerprint-Info — kein harter Fehler.
- **Anzeige:** Der gelieferte Kurs ist **die gepflegte Wahrheit aus MD-Takt** — viele Kurse entstehen dort durch
  logisches Fortschreiben, nicht nur aus Sichtungen. Eine Konfidenz gibt es deshalb nicht. Vorschlag: vierte
  `courseSource`-Stufe `mdtakt` neben `manual`/`recorded`/`heuristic`.
- **Kein Persistieren als Sichtung:** Die Auskunft darf **niemals** als eigene Sichtung in Fluss 1 zurückfließen
  (Feedback-Loop-Verbot) — sonst bestätigte MD-Takt sich selbst.

---

## 3. Schnittstellen-Kontrakt

### 3.1 Fluss 1 — `POST https://api.mdtakt.strassenbahn-magdeburg.de/api/v1/collector/sightings`
- **Header:** `Authorization: Bearer <MDKURSTRACKER_API_TOKEN>` (eigener Token, **nicht** der des NAS-Collectors;
  von MD-Takt vergeben), `Content-Type: application/json`, optional `Content-Encoding: gzip`.
- **Grenzen:** höchstens 500 Sichtungen, 200 Laufwege, 150 Halte je Laufweg; 120 Requests/Minute. Darüber → 422 bzw. 429.
- Maschinenlesbarer Vertrag: `shared/openapi.yaml` (`POST /api/v1/collector/sightings`).
- **Body:** zwei Abschnitte. `trips` (Routendefinitionen, je `schedule_fingerprint` **einmal**) liefern den Laufweg
  fürs Matching; `sightings` (die neuen Beobachtungen) referenzieren einen Trip per Fingerprint.

```jsonc
{
  "sync":  { "since": "2026-06-22T00:00:00Z", "generated_at": "2026-06-23T01:00:00Z" },
  "trips": [
    {
      "mdkt_trip_id": 776,
      "schedule_fingerprint": "44351eb41af0…",
      "line": "1",
      "direction": "Sudenburg",
      "day_type": "MO-FR",                 // genau: MO-FR | SA | SO
      "service_nr": "139916_35",           // HAFAS ZI_TA, informativ
      "stops": [                           // VOLLSTÄNDIGER Laufweg, nach seq geordnet
        { "seq": 1, "hafas_stop_id": "300730901", "stop_name": "Magdeburg, Klinikum Olvenstedt",
          "line": "5", "departure_planned": "2026-04-15T15:21:00Z" }
        // … alle Halte; bei Linienübergang ändert sich "line" pro Halt
      ]
    }
  ],
  "sightings": [
    {
      "mdkt_recording_id": 1935,           // eindeutig, Idempotenz-Schlüssel
      "schedule_fingerprint": "44351eb41af0…",
      "hafas_stop_id": "301968501",
      "line": "9",
      "course_number": "13",
      "service_date": "2026-06-18",
      "observed_at": "2026-06-18T16:42:35Z",
      "departure_planned": "2026-06-18T16:43:00Z",
      "departure_actual":  "2026-06-18T16:42:00Z"   // nullable
    }
  ]
}
```

- **Alle Zeitstempel ISO-8601 UTC** (`…Z`). `service_date` = Berlin-Betriebstag (`YYYY-MM-DD`).
- **Idempotenz:** Wiederholtes Senden derselben `mdkt_recording_id` erzeugt **kein** Duplikat (Upsert).
- **Zeitformat streng:** genau `YYYY-MM-DDTHH:MM:SSZ`. `+02:00` oder Zeiten ohne Zone werden mit 422 abgewiesen.
- **Response 200:**
```jsonc
{ "data": {
  "received": { "trips": 1, "sightings": 1 },
  "results": [ { "mdkt_recording_id": 1935, "outcome": "created", "match": "matched",
                 "status": "pending", "consolidated_trip_id": 26286 } ],
  "unmatched_fingerprints": [],
  "watermark": { "max_recording_id": 1935, "max_observed_at": "2026-06-18T16:42:35Z" }
} }
```
  - `outcome`: `created` | `updated` | `unchanged` | `unknown_fingerprint` (Laufweg fehlte und war MD-Takt unbekannt →
    mit `trips[]` erneut senden).
  - `watermark` ist **informativ** — maßgeblich für den Tracker ist seine Sync-Spalte.
  - `match: waiting` ist **kein Fehler**: MD-Takts Fahrplan kennt die Fahrt noch nicht (z. B. Baustellenfahrplan, den
    HAFAS früher hat als der Feed). MD-Takt ordnet nach jedem Fahrplan-Import selbst neu zu — nichts erneut senden.
- **Fehler:** 401 (Token), 422 (Validierung), 429 (Rate-Limit) — alle im Format `{ "error": { "code", "message" } }`.

### 3.2 Fluss 2 — `GET|POST https://api.mdtakt.strassenbahn-magdeburg.de/api/v1/collector/course-lookup`
- **Auth:** derselbe Bearer-Token wie Fluss 1 (`MDKURSTRACKER_API_TOKEN`). **Limit:** 600 Requests/Minute.
- Maschinenlesbarer Vertrag: `shared/openapi.yaml`.
- **Einzeln:** `GET …/course-lookup?hafas_stop=301968501&line=1&time=2026-06-18T16:43:00Z&stop_name=Magdeburg, City Carré&direction=Magdeburg, Sudenburg`
- **Gesammelt:** `POST …/course-lookup` mit `{ "departures": [ { "ref": "…", "hafas_stop": "…", "line": "…", "time": "…",
  "stop_name": "…", "direction": "…" } ] }` — höchstens 100; die Antwort ist eine Liste in derselben Reihenfolge,
  jedes Ergebnis mit eurem `ref`.

| Param | Pflicht | Quelle in MDKursTracker |
|---|---|---|
| `hafas_stop` | ja | HAFAS-extId der Abfahrt (inkl. Steig) |
| `line` | ja | Linie **an diesem Halt** |
| `time` | ja | Soll-Abfahrt, **UTC mit `Z`** |
| `stop_name` | empfohlen | Haltname der Tafel |
| `direction` | empfohlen | Richtung/Ziel der Abfahrt |
| `date` | nein | informativ |

- **Response 200 (gefunden):**
```jsonc
{ "data": { "found": true, "course_number": "03", "display": "1/03", "line": "1",
            "matched_trip": { "id": 26286, "line_version_id": 338, "departure_local": "17:55:00" },
            "stop_resolved_via": "sighting" } }
```
- **Response 200 (nicht gefunden):** `{ "data": { "found": false, "reason": "no-trip-match" | "ambiguous" | "no-course-assigned" } }`
- `display` ist fertig präfixiert (`Linie/Nummer`) — die Nummer gehört dem Umlauf, der Präfix der Linie an diesem Halt.
- **Kursnummer wie gespeichert:** `course_number` und `display` kommen genau so, wie der Kurs in MD-Takt gepflegt ist —
  mit oder ohne führende Null (`3`/`03`, `10/3`/`10/03`). MD-Takt normalisiert nicht. Soll die Tafel zweistellig
  anzeigen, **ergänzt MDKursTracker die führende Null selbst**; beim Vergleich mit eigenen Nummern führende Nullen
  ignorieren.

---

## 4. Erwartungen von MD-Takt an eure Daten — **hier Mismatch prüfen**

MD-Takt baut auf folgenden Annahmen über euer Datenmodell auf. **Stimmt eine nicht, brecht ab und meldet es** —
sonst matcht das System still falsch.

| # | MD-Takt erwartet | Stützt sich auf (MDKursTracker) | Bricht, wenn… |
|---|---|---|---|
| E1 | `course_number` ist die **am Fahrzeug angeschlagene** Kursnummer (Nutzereingabe), **mit oder ohne führende Null** — MD-Takt vergleicht ohne sie („3" = „03", angepasst 26.09.2026) | `recordings.course_number` | sie aus HAFAS abgeleitet/geraten ist |
| E2 | `course_number` ist die Bezeichnung des **Umlaufs**, und der Umlauf kann **über mehrere Linien** laufen (siehe Hinweis unten) | Fachlogik MVB | ein Fahrzeug beim Linienwechsel eine **andere** Nummer bekommt |
| E3 | ~~Tagestypen sind genau MO-FR / SA / SO(+Feiertag)~~ — **entfällt (26.09.2026):** MD-Takt bestimmt den Fahrplantyp selbst aus dem Betriebstag, `day_type` ist nur informativ | `trips.day_type` | — |
| E4 | Pro Fahrt gibt es einen **vollständigen Laufweg mit Soll-Zeit je Halt** | `route_stops` (departure_planned, line, seq) | Laufweg unvollständig ist oder Soll-Zeiten fehlen |
| E5 | **Alle Zeiten in UTC**; `service_date` = **Berlin**-Betriebstag (Fahrtstart) | `recordings`/`route_stops` UTC, `service_date` | Zeiten lokal/naiv sind oder service_date anders definiert |
| E6 | `schedule_fingerprint` ist **fahrplanstabil & tagesunabhängig** und identifiziert die Route eindeutig | `trips.schedule_fingerprint` | er je Tag/Abruf variiert |
| E7 | Bei **Linienübergängen** trägt **`route_stops.line` die Linie pro Halt** (nicht nur die Start-Linie) | `route_stops.line` | nur eine Gesamt-Linie pro Fahrt existiert |
| E8 | Die **HAFAS-`extId`** (inkl. Steig) ist je Abfahrt verfügbar und stabil benannt | `recordings.stop_id` | Halt nur als Klartext ohne ID vorliegt |
| E9 | Es gibt eine **aufgelöste Kursnummer pro Fahrt** (Mehrheit/Override) + idealerweise Erfassungszahl | Mehrheitsregel / `manual_course_number` | keine Auflösung möglich/lieferbar ist |

> **E2 wurde am 20.09.2026 korrigiert.** Ursprünglich stand hier: „`course_number` ist **nur je Linie** eindeutig →
> Umlauf = `(line, course_number, service_date)`". Das trägt nicht. Ein Fahrzeug wechselt im Betrieb die Linie — eine
> 1 wird in Sudenburg zur 13 — und **behält dabei seine Kursnummer**; angezeigt wird sie je Linie präfixiert
> (`1/03` → `13/03`). Der Umlauf ist damit **größer** als das Tripel, und das Tripel identifiziert ihn nicht.
>
> Für euch ändert das nichts an dem, was ihr liefert: Die Kursnummer bleibt die am Fahrzeug angeschlagene
> Nutzereingabe je Sichtung (E1). MD-Takt setzt die Umlauf-Kette selbst zusammen, aus gepflegten Anschlüssen je
> Haltestelle (siehe [`KURSE.md`](KURSE.md)). **Offen bleibt**, ob die Nummer netzweit eindeutig ist oder ob
> gleichzeitig zwei verschiedene Umläufe „03" heißen können — falls ihr das aus eurem Bestand beantworten könnt,
> wäre das die nützlichste Rückmeldung zu diesem Dokument.
>
> **Wichtigste Risikopunkte:** E2 (Reichweite der Kursnummer), E4/E7 (vollständiger Laufweg inkl. Linie pro Halt),
> E5 (Zeitzonen). Diese bitte zuerst gegenprüfen.

---

## 5. Offene Fragen an die MDKursTracker-Seite

1. **Laufweg-Export:** Gibt es (oder lässt sich leicht bauen) ein Export/Endpoint, der pro Trip den **vollständigen
   Laufweg mit Soll-Zeit + Linie je Halt** liefert (Input für `trips[].stops`)? Heutiger Kandidat: `route_stops`
   intern, `GET /api/recordings/{id}/route` extern — reicht das, oder braucht es einen trip-zentrierten Export?
2. ~~**Inkrementelle Auswahl**~~ — **geklärt:** eine eigene Sync-Spalte des Trackers (§2.1).
3. ~~**Konfidenz**~~ — **entfällt:** Die Auskunft liefert keine Konfidenz; MD-Takt ist die Wahrheit (§2.2).
4. **`day_type`-Werte:** Welche genauen Strings nutzt ihr (`MO-FR`/`SA`/`SO`/Feiertag?), und wie behandelt ihr Feiertage?
5. **Anzeige-Ort Fluss 2:** Bestätigt ihr die vierte `courseSource`-Stufe `mdtakt` (§2.2) in der Trip-Gruppen-Detailansicht?

---

## 6. Betrieb, Auth, Fehlerverhalten
- **Token (beide Flüsse):** eigener statischer Bearer-Token (`MDKURSTRACKER_API_TOKEN` auf MD-Takt-Seite), von MD-Takt
  vergeben, in MDKursTracker-Config (nicht ins Frontend, nicht ins Log). Er öffnet nur Sichtungs-Eingang und Kursauskunft —
  die Abfrage läuft also **serverseitig** im Tracker, nie aus dem Browser.
- **Fehler Fluss 1:** Bei Nicht-2xx die Sichtungen **nicht** als übertragen markieren → der nächste Lauf wiederholt
  (idempotent dank `mdkt_recording_id`).
- **Fehler Fluss 2:** Timeout/Non-2xx → stiller Fallback auf eigene Anzeige; kurzer Client-Timeout (z. B. 2 s) + Cache ≤ 1 h.
- **Datenschutz:** `user_token` o. ä. personenbezogene Felder **nicht** mitsenden — MD-Takt braucht sie nicht.
