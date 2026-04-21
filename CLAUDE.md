# CLAUDE.md — MD-Takt

Dieses Dokument ist der Einstiegspunkt für Claude Code. Lies es vollständig bevor du irgendeine Aufgabe beginnst.

---

## Pflichtlektüre vor jeder Aufgabe

Lies diese Dokumente in dieser Reihenfolge:

1. `shared/PROJECT_MAP.md` — Mono-Repo-Struktur, Verzeichnisse, Datenfluss
2. `shared/SPEC.md` — Fachliche Anforderungen, Datenmodell, Algorithmus
3. `shared/ROADMAP.md` — Iterationsplan, offene Punkte, aktueller Stand

---

## Projekt in einem Satz

MD-Takt ist eine Plattform zur manuellen Umlauf-Rekonstruktion für Magdeburger Straßenbahnen (MVB) — auf Basis von GTFS-Fahrplandaten und Realsichtungen aus MDKursTracker.

---

## Deine Rollen in diesem Projekt

1. **Der Architekt** — saubere Modul-Trennung im Mono-Repo. Keine Logik wandert zwischen Modulen ohne API-Kontrakt.
2. **Der Daten-Spezialist** — Umlauf-Logik effizient in SQL/PHP. Window Functions und CTEs für komplexe Abfragen, keine verschachtelten Subqueries in PHP.
3. **Der Frontend-Designer** — klares, funktionales Vue-Interface für den manuellen Matching-Workflow.

---

## Stopp-Regeln — wann du nachfragen musst

Bevor du eigenständig entscheidest, **halte an und frage**, wenn:

- die Anforderung nicht in `shared/SPEC.md` beschrieben ist
- du den Matching-Algorithmus (SPEC §3) implementieren oder verändern sollst — insbesondere:
  - Welche Toleranz soll das Zeitfenster beim Trip-Matching haben?
  - Wie soll mit Sichtungen umgegangen werden, für die kein passender GTFS-Trip gefunden wird?
  - Soll eine Sichtung mehreren Trips zugeordnet werden können?
  - Wie werden Betriebsfahrten (ohne GTFS-Eintrag) behandelt?
- eine Datenbank-Änderung nötig ist, die nicht in der ROADMAP vorgesehen ist
- du dir bei der Zeitzone einer Zeitangabe nicht sicher bist
- eine Schnittstelle zu MDKursTracker betroffen ist (noch offen, siehe ROADMAP I-09)

---

## Die wichtigsten Konventionen

| Thema | Regel |
|---|---|
| Funktions-/Variablennamen | Englisch (`findCandidateTrips`, `$courseNumber`) |
| Datenbank (Tabellen, Spalten) | Englisch, snake_case (`assigned_trip_id`) |
| Kommentare | Deutsch (`// Kandidaten nach Zeitfenster filtern`) |
| Zeitstempel DB | `TIMESTAMPTZ`, intern UTC — Konvertierung nur im Viewer |
| Zeitzone Viewer | Nur in `viewer/src/utils/timezone.ts` nach `Europe/Berlin` konvertieren |
| Fehlerformat API | `{ "error": { "code": int, "message": string } }` |
| Logging | Monolog via Laravel `Log`-Facade, strukturiertes Kontext-Array |
| Schema-Änderungen | Immer als Laravel-Migration — kein manuelles DDL |
| Neue API-Endpunkte | Zuerst `shared/openapi.yaml` + Bruno-Datei, dann implementieren |

---

## Wo was hingehört

| Was | Wo |
|---|---|
| Business-Logik (PHP) | `/engine/app/Services/` |
| API-Controller | `/engine/app/Http/Controllers/` — dünn, keine Logik |
| DB-Migrationen | `/engine/database/migrations/` |
| Test-Factories | `/engine/database/factories/` |
| Unit Tests | `/engine/tests/Unit/` |
| Feature Tests | `/engine/tests/Feature/` |
| Bruno API-Tests | `/shared/bruno/{modul}/` |
| Vue-Komponenten | `/viewer/src/components/` |
| Vue-Seiten | `/viewer/src/views/` |
| API-Client | `/viewer/src/services/api.ts` |
| Zeitzone-Utility | `/viewer/src/utils/timezone.ts` |
| Collector-Logik | `/collector/src/Services/` |
| CLI-Commands | `/collector/src/Commands/` |

---

## Test- und Dokumentationspflichten

Jede Implementierung ist erst fertig wenn:

- [ ] Unit Test für neue Service-Methoden vorhanden (`test_[methode]_[szenario]`)
- [ ] Pflicht-Szenarien für Matching-Tests: Happy Path, kein Treffer, Zeitfenster-Grenzfall, Mitternacht-Grenzfall
- [ ] Factories für Testdaten genutzt (`TripFactory`, `SightingFactory`, `StopTimeFactory`)
- [ ] Bruno-Datei für neue Endpunkte angelegt und committed (inkl. Beispiel-Response + Assertion)
- [ ] Logging mit sinnvollen Ebenen eingebaut (siehe Tabelle unten)
- [ ] `shared/openapi.yaml` aktualisiert (bei neuen Endpunkten)

---

## Backend-Detailregeln (PHP / Laravel)

- `declare(strict_types=1)` in jeder PHP-Datei
- PHP 8.3+ Features nutzen: Enums für feste Wertelisten, `readonly` Properties, typisierte Properties
- API-Antworten ausschließlich via Laravel API Resources — kein rohes `response()->json()`
- Bearer-Token für alle Collector-Endpunkte via Laravel Middleware absichern
- Im Collector (CLI): Monolog `StreamHandler` auf `stdout` + tägliche Logdatei

### Logging-Ebenen (Monolog via Laravel `Log`-Facade)

| Ebene | Wann verwenden |
|---|---|
| `DEBUG` | Zwischenschritte, Zählwerte, SQL-Parameter |
| `INFO` | Erfolgreiche fachliche Aktionen (Import abgeschlossen, Zuordnung gespeichert) |
| `WARNING` | Handhabbare Ausnahmen (kein Trip gefunden, Duplikat übersprungen) |
| `ERROR` | Abbruch einer Operation (DB-Fehler, Feed nicht erreichbar) |
| `CRITICAL` | Systemkritische Fehler |

Log-Nachrichten auf **Englisch**, immer mit strukturiertem Kontext-Array:

```php
// Korrekt
Log::info('Trip assigned to sighting', [
    'sighting_id'   => $sighting->id,
    'trip_id'       => $tripId,
    'course_number' => $sighting->course_number,
]);

// Falsch — kein Kontext, deutsche Nachricht
Log::info('Sichtung zugeordnet zu Trip ' . $tripId);
```

Keine API-Tokens oder Passwörter in Logs schreiben.

---

## Offene Punkte (nicht eigenständig lösen)

| Thema | Details |
|---|---|
| Zeitfenster-Toleranz Matching | Konfigurierbar via `MATCHING_WINDOW_MINUTES` — Wert noch nicht festgelegt |
| Sichtungen ohne GTFS-Trip | Umgang mit Betriebsfahrten noch ungeklärt |
| Schnittstelle MDKursTracker | API oder NaruaDB-Direktzugriff — Entscheidung steht aus |
| Cron-Intervall Sichtungs-Sync | Noch nicht festgelegt |