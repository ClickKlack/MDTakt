# Anforderungen an MDKursTracker — Integration mit MD-Takt

> **Adressat:** die MDKursTracker-Seite (Entwickler/Coding-Agent). **Stand: 2026-09-26.** Die MD-Takt-Seite von Fluss 1 ist **fertig und live-bereit** — es fehlt nur noch §2.1 auf eurer Seite.
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
| **Modus** | sofort je Sichtung + Nachhol-Cron | on-demand je Abfahrt |
| **Zweck** | neue Sichtungen liefern | „Kennt MD-Takt für diese Abfahrt einen Kurs?" |
| **Wer baut** | Push nach dem Speichern + Nachhol-Cron in MDKursTracker | Lookup-Call + UI-Anzeige in MDKursTracker (MD-Takt-Seite noch nicht gebaut) |

**Grundprinzip:** MD-Takt ist ein **reiner Server** und ruft MDKursTracker **nie** von sich aus auf. **MDKursTracker ist
in beide Richtungen der aktive Client.** MD-Takt hält die DB-Verbindung niemals zu euch; alles läuft über HTTP.

---

## 2. Was MDKursTracker bauen muss

### 2.1 Fluss 1 — Push je Sichtung + Nachhol-Cron (geändert 26.09.2026)

Vorher war ein nächtlicher Batch vorgesehen. Jetzt gilt: **Jede Sichtung geht sofort raus**, damit sie in MD-Takt noch
am selben Tag geprüft werden kann. Ein Cron fängt auf, was dabei nicht ankam.

1. **Push nach dem Speichern.** Sobald eine `recording` mit Kursnummer gespeichert oder geändert ist, schickt
   MDKursTracker sie per `POST` an MD-Takt (§3.1) — mit dem Laufweg der Fahrt in `trips[]`.
   - **Den Nutzer nicht blockieren:** Der Aufruf läuft nach der Antwort an den Nutzer bzw. asynchron, mit kurzem
     Timeout (z. B. 5 s). Ein Fehler hier ist kein Fehler für den Nutzer.
2. **Nachhol-Cron** (z. B. alle 15 Minuten): sendet alle Sichtungen **über dem High-Water-Mark** erneut, in Blöcken
   von höchstens 500 Sichtungen und 200 Laufwegen. Nach einer **2xx-Antwort** den High-Water-Mark auf
   `watermark.max_recording_id` der Antwort setzen; bei einem Fehler **nicht** vorrücken.
   - Doppelt gesendete Sichtungen sind unschädlich: MD-Takt antwortet `outcome: unchanged`.
   - Beim ersten Lauf lässt sich so der **Altbestand** einspielen.
3. **Geänderte Sichtung** (z. B. korrigierte Kursnummer): einfach erneut senden, gleiche `mdkt_recording_id`.
   MD-Takt legt sie dann wieder zur Prüfung vor. **Löschungen** werden (noch) nicht übertragen.
4. **Laufweg:** `trips[].stops` = vollständiger Laufweg der Fahrt aus `route_stops`, **Linie je Halt**, Soll-Zeiten in UTC.
   Wenn vorhanden bitte je Halt auch `arrival_planned` mitsenden (optional) — am Übergangshalt eines
   Linienwechsels hilft die Ankunft beim Zuordnen. Einen Laufweg, den MD-Takt schon kennt, dürft ihr weglassen;
   mitsenden ist aber immer richtig und am einfachsten.
5. **Nichts selbst berechnen:** Die Fahrt-Signatur, den Fahrplantyp (inkl. Ferien) und den Betriebstag bestimmt
   MD-Takt. `day_type` ist nur informativ.

### 2.2 Fluss 2 — On-demand-Lookup + Anzeige
- Beim Rendern der Abfahrtstafel ruft MDKursTracker **je Abfahrt** `GET /api/v1/course-lookup` bei MD-Takt auf (§3.2).
- **Caching:** Die Antwort ist je `(hafas_stop, line, time, date)` stabil → tageweise cachen, nicht pro Seitenaufruf neu abfragen.
- **Graceful Degradation:** Ist MD-Takt nicht erreichbar oder `found:false`, zeigt die Tafel einfach nur eure eigene
  Fingerprint-Info — kein harter Fehler.
- **Anzeige:** Der gelieferte Kurs ist eine **zusätzliche Quelle**. Vorschlag: vierte `courseSource`-Stufe
  `confirmed`/`extern` neben euren bestehenden `manual`/`recorded`/`heuristic`, angezeigt in der Trip-Gruppen-Detailansicht.
- **Kein Persistieren als Sichtung:** Die Lookup-Antwort darf **niemals** wieder als eigene Sichtung in Fluss 1
  zurückfließen (Feedback-Loop-Verbot).

---

## 3. Schnittstellen-Kontrakt

### 3.1 Fluss 1 — `POST https://api.strassenbahn-magdeburg.de/api/v1/collector/sightings`
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
  - `match: waiting` ist **kein Fehler**: MD-Takts Fahrplan kennt die Fahrt noch nicht (z. B. Baustellenfahrplan, den
    HAFAS früher hat als der Feed). MD-Takt ordnet nach jedem Fahrplan-Import selbst neu zu — nichts erneut senden.
- **Fehler:** 401 (Token), 422 (Validierung), 429 (Rate-Limit) — alle im Format `{ "error": { "code", "message" } }`.

### 3.2 Fluss 2 — `GET https://api.strassenbahn-magdeburg.de/api/v1/course-lookup`
- **Auth:** keine (öffentlicher Read-Endpunkt im MVP).
- **Query-Parameter:**

| Param | Pflicht | Beispiel | Quelle in MDKursTracker |
|---|---|---|---|
| `hafas_stop` | ja | `301968501` | `recordings.stop_id` bzw. die HAFAS-extId der angezeigten Abfahrt |
| `line` | ja | `1` | Linie **an diesem Halt** (bei Übergängen `route_stops.line`, nicht die Gesamt-Linie) |
| `time` | ja | `2026-06-18T16:43:00Z` | Soll-Abfahrt der HAFAS-Abfahrt, **UTC** |
| `date` | ja | `2026-06-18` | Betriebstag (Berlin) |
| `stop_name` | optional | `Magdeburg, …` | Fallback, falls MD-Takt den Halt noch nicht gelernt hat |

- **Response 200 (gefunden):**
```jsonc
{ "data": { "found": true, "course_number": "03", "line": "1",
            "confidence": "majority", "source": "mdkurstracker-sighting",
            "matched_trip": { "gtfs_trip_id": "1316520", "departure_local": "17:55:00" } } }
```
- **Response 200 (nicht gefunden):** `{ "data": { "found": false, "reason": "stop-unmapped" | "no-trip-match" | "no-course-assigned" } }`
- `confidence`/`source` → ihr entscheidet die Darstellung; `reason` → Diagnose bei Fehlanzeige.

---

## 4. Erwartungen von MD-Takt an eure Daten — **hier Mismatch prüfen**

MD-Takt baut auf folgenden Annahmen über euer Datenmodell auf. **Stimmt eine nicht, brecht ab und meldet es** —
sonst matcht das System still falsch.

| # | MD-Takt erwartet | Stützt sich auf (MDKursTracker) | Bricht, wenn… |
|---|---|---|---|
| E1 | `course_number` ist die **am Fahrzeug angeschlagene** Kursnummer (Nutzereingabe), 2-stellig | `recordings.course_number` | sie aus HAFAS abgeleitet/geraten ist |
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
2. **Inkrementelle Auswahl** (für den Nachhol-Cron): Welches Feld trägt den **High-Water-Mark** zuverlässig — `recordings.id` oder
   `recorded_at` (Soft-Deletes/Nachträge beachten)?
3. **Konfidenz:** Könnt ihr je Fahrt **Erfassungszahl + Einigkeit** (und ob `manual_course_number` gesetzt) mitliefern?
   MD-Takt nutzt das für die `confidence`-Stufe in Fluss 2.
4. **`day_type`-Werte:** Welche genauen Strings nutzt ihr (`MO-FR`/`SA`/`SO`/Feiertag?), und wie behandelt ihr Feiertage?
5. **Anzeige-Ort Fluss 2:** Bestätigt ihr die vierte `courseSource`-Stufe `confirmed` in der Trip-Gruppen-Detailansicht?

---

## 6. Betrieb, Auth, Fehlerverhalten
- **Token (Fluss 1):** eigener statischer Bearer-Token (`MDKURSTRACKER_API_TOKEN` auf MD-Takt-Seite), von MD-Takt vergeben,
  in MDKursTracker-Config (nicht ins Frontend, nicht ins Log). Der Token öffnet nur den Sichtungs-Eingang.
- **Fehler Fluss 1:** Beim Push: ignorieren, der Nachhol-Cron holt nach. Beim Cron: bei Nicht-2xx den High-Water-Mark
  **nicht** vorrücken → der nächste Lauf wiederholt (idempotent dank `mdkt_recording_id`).
- **Fehler Fluss 2:** Timeout/Non-2xx → stiller Fallback auf eigene Anzeige; kurzer Client-Timeout (z. B. 2 s) + Tages-Cache.
- **Datenschutz:** `user_token` o. ä. personenbezogene Felder **nicht** mitsenden — MD-Takt braucht sie nicht.
