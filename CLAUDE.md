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

MD-Takt ist eine Plattform zur manuellen Umlauf-Rekonstruktion für den Magdeburger Nahverkehr (MVB — Tram und Bus) — auf Basis von GTFS-Fahrplandaten und Realsichtungen aus MDKursTracker.

---

## Deine Rollen in diesem Projekt

1. **Der Architekt** — saubere Modul-Trennung im Mono-Repo. Keine Logik wandert zwischen Modulen ohne API-Kontrakt.
2. **Der Daten-Spezialist** — Umlauf-Logik effizient in SQL/PHP. Window Functions und CTEs für komplexe Abfragen, keine verschachtelten Subqueries in PHP.
3. **Der Frontend-Designer** — klares, funktionales Vue-Interface für den manuellen Matching-Workflow.

---

## Stopp-Regeln — wann du nachfragen musst

Bevor du eigenständig entscheidest, **halte an und frage**, wenn:

- die Anforderung nicht in `shared/SPEC.md` beschrieben ist
- du den Matching-Algorithmus (SPEC §3.2, `SightingMatcher`) verändern sollst. Festgelegt am 26.09.2026
  (SPEC §3.3): keine Toleranz, exakter Signatur-Match, Folgeversion ≤ 14 Tage, ohne Treffer `waiting` → `no_trip`,
  eine Sichtung gehört zu genau einer Fahrt. Jede Abweichung davon ist eine neue Entscheidung
- eine Datenbank-Änderung nötig ist, die nicht in der ROADMAP vorgesehen ist
- du dir bei der Zeitzone einer Zeitangabe nicht sicher bist
- eine Schnittstelle zu MDKursTracker betroffen ist — Fluss 1 ist festgelegt (`openapi.yaml`,
  `INTEGRATION_MDKURSTRACKER.md` §5.1/§8), jede Vertragsänderung trifft die Tracker-Seite; Fluss 2 ist noch offen

---

## Die wichtigsten Konventionen

| Thema | Regel |
|---|---|
| Funktions-/Variablennamen | Englisch (`findCandidateTrips`, `$courseNumber`) |
| Datenbank (Tabellen, Spalten) | Englisch, snake_case (`assigned_trip_id`) |
| Kommentare | Deutsch (`// Kandidaten nach Zeitfenster filtern`) |
| Zeitstempel DB | `TIMESTAMPTZ`, intern UTC — Formatierung nur in den Frontends |
| Zeitzone Frontends | Anzeige in **lokaler Browser-Zeitzone**, dt. Format („12:43 Uhr"); Umwandlung nur in der jeweiligen `timezone.ts`. Reine Kalenderdaten (z. B. `service_date`, Feed-Gültigkeit) ohne TZ-Verschiebung. Ausnahme prüfen: Fahrplan-/Trip-Zeiten im öffentlichen Viewer ggf. fest `Europe/Berlin` (Netz-Zeit) — bei I-12b/Viewer entscheiden. |
| Fehlerformat API | `{ "error": { "code": int, "message": string } }` |
| Logging | Monolog via Laravel `Log`-Facade, strukturiertes Kontext-Array |
| Schema-Änderungen | Immer als Laravel-Migration — kein manuelles DDL |
| Neue API-Endpunkte | Zuerst `shared/openapi.yaml` + Bruno-Datei, dann implementieren |
| Vergleiche auf `date`-Spalten | Immer `whereDate()`, nie Gleichheit. Tests laufen auf **SQLite**, Produktion auf **PostgreSQL**: Der `date`-Cast schreibt `Y-m-d H:i:s`; PostgreSQL wirft die Uhrzeit in der `date`-Spalte weg, SQLite behält sie als Text. Ein `where('valid_from', '2026-12-13')` findet dort nichts — und Duplikat- oder Unique-Prüfungen laufen still ins Leere |

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
- Laravel 13 (aktuell installiert, bewusst gewählt statt L11)
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
| Kursauskunft für MDKursTracker (Fluss 2) | `GET /course-lookup` — Konzept in INTEGRATION §5.2, `confidence`-Semantik offen |
| Tracker-Seite Fluss 1 | Push + Nachhol-Cron baut MDKursTracker (REQUIREMENTS §2.1); Cron-Intervall dort festlegen (Vorschlag 15 min) |
| Löschungen / Rücknahme | Löschungen im Tracker werden nicht übertragen; eine Entscheidung lässt sich nicht per Knopf zurücknehmen |

Entschieden am 26.09.2026 (vorher hier offen): Zeitfenster-Toleranz (keine), Sichtungen ohne GTFS-Trip und
Betriebsfahrten (`waiting` → `no_trip`, ablehnbar), Schnittstelle (HTTP-API, eigener Token) — siehe SPEC §3.3.