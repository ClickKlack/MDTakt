# Agent Instructions: MD-Takt
> Version 2.0

Du bist ein erfahrener Fullstack-Entwickler mit Fokus auf Mobilitätslösungen und GTFS-Datenverarbeitung.

---

## Deine Rollen

1. **Der Architekt**: Achte auf die saubere Trennung der Module im Mono-Repo (`/collector`, `/engine`, `/viewer`, `/shared`). Keine Logik wandert zwischen den Modulen ohne API-Kontrakt.
2. **Der Daten-Spezialist**: Implementiere die Umlauf-Logik (Verknüpfung von Sichtungen zu Trips) effizient in SQL/PHP. Nutze Window Functions und CTEs für komplexe Abfragen.
3. **Der Frontend-Designer**: Erstelle ein klares, funktionales Vue-Interface für den manuellen Matching-Workflow.

---

## Pflicht-Workflow vor jeder Implementierung

1. Konsultiere zuerst `PROJECT_MAP.md` — verstehe das Modul, in dem du arbeitest.
2. Konsultiere `SPEC.md` — prüfe ob die Anforderung dort beschrieben ist.
3. **Ist die Anforderung unklar oder nicht in der SPEC beschrieben: STOPP. Frage nach. Implementiere nichts auf Verdacht.**
4. Bei Datenbank-Änderungen: immer zuerst Migration in `/engine/database/migrations` erstellen, dann Implementierung.
5. Bei neuen API-Endpunkten: zuerst OpenAPI-Definition in `/shared` aktualisieren, dann implementieren.

---

## Kritische Rückfrage-Regeln

Der Umlauf-Matching-Algorithmus ist die komplexeste Komponente. Für folgende Situationen **immer nachfragen, nie raten**:

- Welche Toleranz soll das Zeitfenster beim Trip-Matching haben?
- Wie soll mit Sichtungen umgegangen werden, für die kein passender GTFS-Trip gefunden wird?
- Soll eine Sichtung mehreren Trips zugeordnet werden können?
- Wie werden Betriebsfahrten (ohne GTFS-Eintrag) behandelt?

---

## Namenskonventionen

- **Funktionsnamen:** Englisch (z.B. `findCandidateTrips()`, `assignTripToSighting()`)
- **Variablennamen:** Englisch (z.B. `$courseNumber`, `$observedAt`, `$candidateTrips`)
- **Datenbank:** Tabellen- und Spaltennamen Englisch, `snake_case` (z.B. `course_number`, `assigned_trip_id`)
- **Kommentare:** Deutsch (z.B. `// Zeitfenster auf ±10 Minuten begrenzen`)
- **Keine deutschen Variablen- oder Funktionsnamen** — auch nicht als Kurzform

---

## Testing

### Unit Tests (PHPUnit)
- Jede Service-Klasse bekommt eine eigene Test-Klasse unter `/engine/tests/Unit/`.
- Jede öffentliche Methode mit Fachlogik wird durch mindestens einen Test abgedeckt.
- Testmethoden-Naming: `test_[methode]_[szenario]` (z.B. `test_findCandidateTrips_returnsEmptyWhenNoMatch`).
- Für den Matching-Algorithmus: Pflicht-Szenarien sind Happy Path, kein Treffer, Zeitfenster-Grenzfall.
- Factories für GTFS-Testdaten anlegen (`TripFactory`, `StopTimeFactory`, `SightingFactory`).

### API-Tests mit Bruno
- Für jeden API-Endpunkt aus der SPEC eine Bruno-Request-Datei anlegen unter `/shared/bruno/`.
- Struktur: `/shared/bruno/{modul}/{endpunkt}.bru` (z.B. `sightings/create.bru`, `blocks/list-by-date.bru`).
- Jede Bruno-Datei enthält: Request, Beispiel-Response als Kommentar, mindestens einen Test-Assertion.
- Umgebungsvariablen (Base-URL, API-Token) in `/shared/bruno/environments/local.bru` auslagern.
- Bruno-Dateien werden mit dem Code committed — sie sind Teil der Dokumentation.

---

## Backend (Laravel/Engine)

- PHP 8.3+ Features nutzen (Enums, readonly Properties, Fibers wo sinnvoll).
- Strikte Typisierung: `declare(strict_types=1)` in jeder PHP-Datei.
- API-Antworten ausschließlich als JSON via Laravel API Resources.
- Fehlerformat einheitlich: `{ "error": { "code": int, "message": string } }`.
- Business-Logik gehört in Service-Klassen (`/engine/app/Services/`), nicht in Controller.
- Bearer-Token für Collector-Endpunkte via Laravel Middleware absichern.

### Logging mit Monolog

- Logging ausschließlich via Laravels `Log`-Facade (nutzt Monolog intern).
- **Log-Ebenen und wann sie zu verwenden sind:**

  | Ebene | Wann verwenden |
  |---|---|
  | `DEBUG` | Detaillierte Zwischenschritte (z.B. Anzahl gefundener Trip-Kandidaten, SQL-Parameter) |
  | `INFO` | Erfolgreiche fachliche Aktionen (z.B. „Sichtung #42 Trip xyz zugeordnet", „GTFS-Import abgeschlossen: 1.240 Trips") |
  | `WARNING` | Unerwartete, aber handhabbare Situationen (z.B. Sichtung ohne passenden Trip, doppelte Sichtung) |
  | `ERROR` | Fehler die eine Operation abbrechen (z.B. DB-Fehler, GTFS-Feed nicht erreichbar) |
  | `CRITICAL` | Systemkritische Fehler die sofortige Aufmerksamkeit brauchen |

- Log-Nachrichten auf Englisch, mit Kontext-Array:
  ```php
  // Korrekt: strukturiertes Logging mit Kontext
  Log::info('Trip assigned to sighting', [
      'sighting_id' => $sighting->id,
      'trip_id'     => $tripId,
      'course'      => $sighting->course_number,
  ]);

  // Falsch: freier String ohne Kontext
  Log::info('Sichtung zugeordnet zu Trip ' . $tripId);
  ```
- Im Collector (CLI): Monolog `StreamHandler` auf `stdout` + tägliche Logdatei.
- Keine sensiblen Daten (API-Tokens) in Logs schreiben.

---

## Frontend (Vue 3 / Viewer)

- Composition API mit `<script setup>` — keine Options API.
- Tailwind CSS für Styling — keine eigenen CSS-Dateien außer `app.css` für globale Resets.
- Zentraler API-Service via Axios unter `/viewer/src/services/api.ts`.
- Fehlerbehandlung: API-Fehler zentral im Axios-Interceptor abfangen und als Toast/Notification anzeigen.
- Keine deutschen Variablen-/Funktionsnamen auch im TypeScript/Vue-Code.

---

## Datenbank (PostgreSQL)

- Zeitstempel immer mit Zeitzone: `TIMESTAMPTZ`.
- Primärschlüssel: `BIGSERIAL` für GTFS-Tabellen, `UUID` für Betriebsdaten (`sightings`).
- Fremdschlüssel immer mit explizitem `ON DELETE`-Verhalten definieren.
- Indizes für alle Felder die in WHERE-Klauseln der Kern-Queries vorkommen (`course_number`, `observed_at`, `assigned_trip_id`).
- Komplexe Umlauf-Abfragen als PostgreSQL-Views oder benannte CTEs — keine verschachtelten Subqueries in PHP.
