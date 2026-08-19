# DEPLOYMENT.md — MD-Takt in Betrieb nehmen

> **Stand: 19.08.2026.** Checkliste für den Live-Betrieb von **Engine** (Server) und **Collector** (NAS).
> Deckt ROADMAP **I-10** (Deployment-Checkliste) ab. Viewer und Matching sind dafür **nicht** nötig.

---

## Warum jetzt schon live gehen?

Der GTFS-Feed ist ein **rollierendes Zeitfenster** (aktuell 23 Tage) und wird **wöchentlich** aktualisiert.
Der Import **ersetzt** den Bestand. Ein vollständiger Fahrplan mit allen Änderungen — Baustellen,
Ersatzverkehre, Fahrplanwechsel — entsteht deshalb **nur durch Beobachtung über viele Wochen**.

**Jede nicht importierte Woche ist endgültig verloren.** Das ist der einzige Teil des Projekts, der sich
nicht nachholen lässt: Sichtungen, Matching und Viewer können jederzeit später entstehen, Fahrplan-Historie
nicht. Deshalb lohnt der Live-Betrieb, sobald Backup und Archiv stehen — unabhängig vom Rest des Systems.

---

## 1. Voraussetzungen

| | Version / Anmerkung |
|---|---|
| PHP | 8.3+ (Engine und Collector) |
| PostgreSQL | 14+ |
| Composer | 2.x |
| Node | nur für den Admin-Build (`npm run build`), nicht zur Laufzeit |
| Speicherplatz Archiv | ~260 MB je Lauf → **~13 GB/Jahr** bei wöchentlichem Takt |

---

## 2. Engine

```bash
cd engine
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

`.env` anpassen:

| Variable | Wert | Warum |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | sonst Stacktraces nach außen |
| `APP_TIMEZONE` / `DB_TIMEZONE` | `UTC` | Projektregel: intern UTC, Formatierung nur im Frontend |
| `SESSION_DRIVER` | `file` | **Stolperfalle:** Der Migrations-Satz enthält keine `sessions`-Tabelle. Mit `database` schlägt jeder Aufruf von `/` fehl |
| `DB_*` | Zugangsdaten | |
| `COLLECTOR_API_TOKEN` | langes Zufallstoken | muss identisch zum Collector sein |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | Admin-Zugang | wird einmalig geseedet |

```bash
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force
php artisan config:cache && php artisan route:cache
```

Webserver auf `engine/public` zeigen lassen, HTTPS terminieren. Health-Check: `GET /up`.

**Admin-Frontend** (optional, aber empfohlen — sonst ist der Import-Stand nur per API einsehbar):

```bash
cd admin && npm ci && npm run build   # Ergebnis: admin/dist/ statisch ausliefern
```

---

## 3. Collector (NAS)

```bash
cd collector
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

| Variable | Wert |
|---|---|
| `ENGINE_BASE_URL` | `https://…` der Engine |
| `COLLECTOR_API_TOKEN` | identisch zur Engine |
| `GTFS_AGENCY_FILTER` | `Magdeburger Verkehrsbetriebe` |
| `GTFS_ARCHIVE_PATH` | Pfad mit reichlich Platz; leer = `collector/storage/archive` |
| `COLLECTOR_LOG_PATH` | z. B. `/var/log/mdtakt/collector.log` |
| `ENGINE_TIMEOUT_SECONDS` | `300` — je Chunk-Request; auf langsamer Leitung höher setzen |

Erster Lauf von Hand, um Token und Erreichbarkeit zu prüfen:

```bash
php bin/collector collector:import-gtfs
```

---

## 4. Cron

Die Quelle wird **wöchentlich** aktualisiert — häufiger zu laufen bringt nichts, der Collector
überspringt unveränderte Feeds ohnehin per ETag/sha256.

```cron
# GTFS-Import, montags 03:00
0 3 * * 1 cd /volume1/mdtakt/collector && /usr/bin/php bin/collector collector:import-gtfs >> /var/log/mdtakt/cron.log 2>&1
```

Ein ausgefallener Lauf ist **nicht** sofort kritisch: Bei 23 Tagen Fenster und wöchentlichem Takt
überlappen aufeinanderfolgende Läufe um gut zwei Wochen. Zwei verpasste Läufe in Folge reißen eine Lücke.

---

## 5. Backup — der wichtigste Punkt

Der GTFS-Feed ist jederzeit neu ladbar. **Das Konsolidat ist es nicht.** Es ist das einzige
unwiederbringliche Datum im System.

```cron
# Taeglich 04:00, 30 Tage Aufbewahrung
0 4 * * * pg_dump -Fc mdtakt > /backup/mdtakt-$(date +\%F).dump && find /backup -name 'mdtakt-*.dump' -mtime +30 -delete
```

Ebenfalls sichern: **das Feed-Archiv** (`GTFS_ARCHIVE_PATH`). Solange FAHRPLANPERIODEN Phase C fehlt,
speichert die Datenbank nur, *dass* sich ein Fahrplan geändert hat — die Fahrten selbst liegen
ausschließlich in den archivierten ZIPs. Aus ihnen lässt sich das Konsolidat später rückwirkend aufbauen.

> Backup **vor** dem ersten produktiven Import einrichten, nicht danach.

---

## 6. Überwachung

Ein still gescheiterter Cron kostet eine Woche Fahrplan-Historie. Mindestens eines davon einrichten:

- **Manuell:** Admin-Ansicht „Imports" — Status, Zeiten, Counts, Fehlermeldung je Lauf.
- **Automatisch:** `GET /api/v1/admin/imports` (Sanctum) prüfen, ob der letzte Lauf `success` ist und
  jünger als 8 Tage. Bei `failed` oder Überalterung benachrichtigen.
- **Log:** `COLLECTOR_LOG_PATH`, 14 Tage Rotation.

Nach dem Import prüfen lässt sich der Konsolidierungsstand in der Admin-Ansicht „Versionen":
konsolidierter Zeitraum sowie gesicherte gegenüber offenen Grenzen.

> **Auf `running` achten, nicht nur auf `failed`.** Bricht die Verbindung während der
> `stop_times`-Übertragung ab, bleibt der Lauf dauerhaft im Status `running` — die Engine erfährt
> nie, dass der Client weg ist. Die Prüfregel „letzter Lauf ist `success` und jünger als 8 Tage"
> deckt beide Fälle ab; eine reine `failed`-Prüfung nicht.

### Zustand nach einem abgebrochenen Lauf

Getestet am 19.08.2026 (abgebrochene `stop_times`-Übertragung):

| | |
|---|---|
| `line_versions` / Intervalle | **unversehrt** — die Konsolidierung läuft erst im `finish` |
| `trips`, `calendar` | vollständig ersetzt (eigene Transaktion) |
| `stop_times` | **unvollständig** |
| `trip_signatures` | **leer** — mit den alten Trips kaskadiert gelöscht, mangels `finish` nicht neu gebaut |

Die Fahrplan-Historie übersteht einen Abbruch also, der Roh-Bestand nicht. Bis zum nächsten
erfolgreichen Lauf liefert die API unvollständige Fahrten.

### Wiederherstellung aus dem Archiv

Der nächste reguläre Lauf repariert den Zustand — schneller geht es ohne erneuten Download direkt
aus der archivierten ZIP:

```bash
php bin/collector collector:import-gtfs --zip=/pfad/zum/archiv/gtfs-2026-08-19-963faf95a7da.zip
```

Der Import-State bleibt dabei unangetastet, denn die ZIP kann ein älterer Archivstand sein — sonst
hielte der Collector ihn fälschlich für den zuletzt geladenen Feed. Derselbe Weg dient später der
**rückwirkenden Konsolidierung** aus dem Archiv (FAHRPLANPERIODEN Phase C).

---

## 7. Was der Live-Betrieb noch nicht kann

| | Status |
|---|---|
| GTFS-Import, Audit, Konsolidierung (Versionen/Intervalle) | ✅ produktiv nutzbar |
| Admin: Imports, Linien, Kalender, Versionen | ✅ |
| Fahrplan-**Inhalte** historisch (Phase C) | ⬜ — bis dahin rettet nur das ZIP-Archiv |
| Sichtungen, Matching, Umläufe (I-04 – I-06) | ⬜ |
| Öffentlicher Viewer (I-07/I-08) | ⬜ |
| Perioden-CRUD, Periodenwechsel-Vorschlag | ⬜ — der erste Lauf legt automatisch eine `bootstrap`-Periode an |

**Offene Entscheidung:** Subdomain-Zuschnitt (z. B. `api.` und `admin.strassenbahn-magdeburg.de`) — siehe
ROADMAP, offene Punkte.

---

## 8. Erster produktiver Lauf — Reihenfolge

1. Backup-Job einrichten und **testen** (Restore auf eine Wegwerf-DB).
2. Engine deployen, migrieren, seeden, `/up` prüfen.
3. Collector konfigurieren, **einmal von Hand** laufen lassen.
4. Prüfen: Admin „Imports" zeigt `success`, „Versionen" zeigt eine Periode mit Intervallen,
   und im Archivverzeichnis liegt eine ZIP.
5. Cron aktivieren.
