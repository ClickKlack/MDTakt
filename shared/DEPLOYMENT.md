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

> **Konvention:** Dieses Dokument beschreibt das Vorgehen, **nicht die konkrete Umgebung.**
> Hostnamen, IP-Adressen, Verzeichnispfade, Anbieter und Anschlussarten gehören nicht ins
> Repository — sie stehen in den `.env`-Dateien (gitignored) und in der Betriebsdokumentation
> außerhalb dieses Repos. Platzhalter wie `<COLLECTOR_VERZEICHNIS>` sind entsprechend zu ersetzen.

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
| `CACHE_STORE` | `file` | **Gleiche Stolperfalle:** keine `cache`-Tabelle im Migrations-Satz. Das Rate-Limit der Collector-Endpunkte liegt im Cache — mit `database` scheitert jeder Import-Request |
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

## 2b. Absicherung der Import-Endpunkte

Die Collector-Endpunkte (`/api/v1/collector/*`) sind durch **einen statischen Bearer-Token**
geschützt — `COLLECTOR_API_TOKEN`, identisch in Engine und Collector. Fehlt der Token in der
Engine-Konfiguration, ist der Endpunkt gesperrt (Fail-Closed), nicht offen.

Der Token ist damit die einzige echte Verteidigung. Erzeuge ihn mit echter Entropie:

```bash
openssl rand -base64 32
```

Zusätzlich greift ein **Rate-Limit von 120 Requests/Minute je IP** vor der Token-Prüfung, damit
eine Flut weder Token-Vergleiche noch gzip-Dekompression auslöst. Ein realer Lauf sendet rund
19 Requests (Start, ~17 stop_times-Chunks bei ~164.000 MVB-Zeilen, Abschluss) und das wöchentlich —
der Puffer ist bewusst groß, weil ein am Limit gescheiterter Lauf eine unwiederbringliche Woche kostet.

**Bewusst nicht umgesetzt:** eine IP-Allowlist. Sie setzt eine feste Absender-IP voraus; ist die
nicht garantiert, sperrt eine veraltete Allowlist genau den Lauf aus, der sich nicht nachholen
lässt. Das Risiko der Maßnahme wäre größer als das Risiko, gegen das sie schützt.

Die Verbindungsrichtung hilft dabei: Der Collector ist **Client** und verbindet ausgehend zur
Engine. Auf der Collector-Seite muss deshalb kein Port erreichbar sein.

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
| `COLLECTOR_LOG_PATH` | Pfad der rotierenden Logdatei; leer = `collector/storage/logs/` |
| `ENGINE_TIMEOUT_SECONDS` | `300` — je Chunk-Request; auf langsamer Leitung höher setzen |

Erster Lauf von Hand, um Token und Erreichbarkeit zu prüfen:

```bash
php bin/collector collector:import-gtfs
```

---

## 4. Cron (auf dem Collector-Host, nicht auf dem Engine-Server)

Der Import wird vom **Collector** ausgelöst; der Cron gehört deshalb auf dessen Rechner. Auf dem
Engine-Server ist dafür kein Cronjob nötig — die Konsolidierung läuft im Anschluss an den Import
innerhalb desselben Requests.

Die Quelle wird **wöchentlich** aktualisiert — häufiger zu laufen bringt nichts, der Collector
überspringt unveränderte Feeds ohnehin per ETag/sha256.

```cron
# GTFS-Import, montags 03:00
0 3 * * 1 cd <COLLECTOR_VERZEICHNIS> && php bin/collector collector:import-gtfs >> <LOGPFAD>/cron.log 2>&1
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

## 7b. Deployment-Skript

`scripts/deploy.sh` überträgt Engine und Admin-Frontend. Umgebungsabhängige Werte liegen in
`scripts/deploy.local.env` (gitignored, Vorlage: `deploy.local.env.example`) — SSH-Ziel,
Serverpfad, PHP-Binary, API-URL.

```bash
cp scripts/deploy.local.env.example scripts/deploy.local.env   # einmalig, Werte eintragen

./scripts/deploy.sh preflight          # nur prüfen: SSH, PHP-Version, Zielverzeichnisse
./scripts/deploy.sh engine --dry-run   # zeigt, was übertragen würde
./scripts/deploy.sh engine             # Laravel-API
./scripts/deploy.sh admin              # Admin-Frontend
./scripts/deploy.sh all                # beides
```

**Was das Skript tut**

| Schritt | Anmerkung |
|---|---|
| `rsync` der Engine | **ohne** `.env`, `vendor/`, `storage/`, `tests/` — die drei erstgenannten bleiben auf dem Server unberührt |
| `composer install --no-dev` **auf dem Server** | statt `vendor/` zu übertragen — so passt es zur dortigen PHP-Plattform |
| `artisan key:generate --force` | nur wenn `APP_KEY` in der Server-`.env` noch leer ist |
| `artisan migrate --force` | |
| `artisan db:seed --class=AdminSeeder --force` | idempotent; die `.env` ist die Quelle der Wahrheit für den Single-Admin. Bei leeren `ADMIN_*`-Werten überspringt der Seeder sich selbst |
| `artisan optimize` | Config-, Route- und View-Cache |
| Admin-Build | `VITE_API_BASE_URL` wird ins Bundle kompiliert, danach `rsync` von `dist/` |
| Abschlussprüfung | `/up` muss 200 liefern, `/.env` darf **nicht** abrufbar sein |

Das Skript bricht ab, wenn der Working Tree nicht sauber ist (`--allow-dirty` überstimmt das),
wenn die PHP-Version älter als 8.3 ist oder wenn auf dem Server keine `.env` liegt.

## 7c. Erstmalige Einrichtung der `.env` auf dem Server

Die `.env` enthält Geheimnisse und wird **nie** vom Skript übertragen. Einmalig von Hand:

1. **Datenbank anlegen** (bei Managed Hosting im Panel), Zugangsdaten notieren.
2. Vorlage aus `engine/.env.example` ableiten und ausfüllen — mindestens `APP_ENV=production`,
   `APP_DEBUG=false`, `APP_URL`, die `DB_*`-Werte, ein zufälliges `COLLECTOR_API_TOKEN`
   (`openssl rand -base64 32`) sowie `ADMIN_EMAIL`/`ADMIN_PASSWORD`.
3. Datei als `<engine-verzeichnis>/.env` hochladen.
4. Fertig — `APP_KEY` und der Admin-Zugang entstehen beim ersten `deploy.sh engine` automatisch.

> Der Seeder legt **keinen** Admin an, wenn `ADMIN_EMAIL` oder `ADMIN_PASSWORD` leer sind — er
> überspringt still mit einer Warnung, statt einen Zugang mit schwachem Passwort zu erzeugen.

Dasselbe `COLLECTOR_API_TOKEN` muss anschließend in die `.env` des Collectors.

## 8. Erster produktiver Lauf — Reihenfolge

1. Datenbank anlegen, `.env` einrichten (§ 7c), `key:generate`.
2. `./scripts/deploy.sh engine` — erzeugt `APP_KEY`, migriert und legt den Admin an.
3. `./scripts/deploy.sh admin`, Login prüfen.
4. Backup-Job einrichten und **testen** (Restore auf eine Wegwerf-DB).
5. Collector konfigurieren (`ENGINE_BASE_URL`, gleiches Token), **einmal von Hand** laufen lassen.
6. Prüfen: Admin „Imports" zeigt `success`, „Versionen" zeigt eine Periode mit Intervallen,
   und im Archivverzeichnis liegt eine ZIP.
7. Cron auf dem Collector-Host aktivieren.
