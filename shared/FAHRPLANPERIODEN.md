# Konzept: Fahrplanperioden & Fahrplantypen

> **Stand: 2026-06-29.** Konzept für die Erkennung von Fahrplanperioden, die Klassifikation der
> Fahrplantypen und die **Konsolidierung** der GTFS-Läufe je Periode. Wiedereinstiegspunkt für den
> ROADMAP-I-12-Bereich „Fahrplanperioden-Erkennung". Gehört zur Admin-Schaltzentrale.

---

## TL;DR

- **Zwei Versionierungs-Ebenen (§4):**
  - **Fahrplanperiode** = netzweit, **kuratiert** — orientiert an den veröffentlichten MVB-Fahrplänen, vom Admin
    angelegt, manuell deklarierbar (Großbaustelle). Eine neue Periode **setzt alle Linien neu**. Nicht automatisch.
  - **Linien-Version** = automatisch, je **(Linie, Fahrplantyp)** — bei jeder Fahrplanänderung einer Linie (Fingerprint),
    transparent + persistiert. Bei Änderungen über **viele Linien** bietet das System einen **Periodenwechsel** an.
- **Vier Fahrplantypen** (Betriebstag): **Mo-Fr normal**, **Mo-Fr Ferien**, **Sa**, **So + Feiertage** — je Typ ein eigener Versions-Strang.
- **Feiertage**: nicht persistiert — aus einer **Code-Regel (Sachsen-Anhalt)** je Jahr berechnet.
- **Ferienzeiten**: persistiert, vom Admin gepflegt (CRUD).
- **Granularität: netzweit** eine Periode.
- **Zwei Schichten (§5):** Roh-GTFS je Lauf = transient (nur aktuellster Lauf); **Konsolidat je Periode** = persistent,
  stabile Schlüssel, aus den Läufen gemerged (neuer Lauf gewinnt). Historische Perioden bleiben **eingefroren** erhalten;
  die App liest das Konsolidat. **Mit Betriebstags-Logik** (Fahrplantyp je Fahrt + Periodenbereich).
- **Viewer** (später): zeigt zunächst nur die aktuelle Periode; Umschalten kommt später.

---

## 1. Befund (warum das nötig ist)

Der gtfs.de-„latest"-Feed (`nv_free`) ist ein **rollierendes ~2-Wochen-Fenster**:
- `calendar`-Enddaten sind **Feed-Horizonte**, keine echten Periodengrenzen.
- `feed_info`-Gültigkeit ist leer; Wochenmuster fein zersplittert.
- gtfs.de vergibt je Build **neue Surrogat-IDs** → der Import **ersetzt** den Roh-Bestand vollständig (umgesetzt),
  hält also **keinen historischen Roh-Datenbestand**. Historie entsteht erst im **Konsolidat je Periode** (§5).

→ Aus einem einzelnen Import lässt sich keine Periode ableiten. Perioden entstehen durch **Beobachtung über mehrere
Imports**: ändert sich der Fahrplan-Inhalt, hat eine neue Periode begonnen.

---

## 2. Fahrplantypen

| Typ | gilt an | abhängig von Config |
|---|---|---|
| **Mo-Fr normal** | Werktag (Mo-Fr) außerhalb der Schulferien | Ferienzeiten |
| **Mo-Fr Ferien** | Werktag (Mo-Fr) innerhalb der Schulferien | Ferienzeiten |
| **Sa** | Samstag | — |
| **So + Feiertage** | Sonntag **und** jeder Feiertag | Feiertage |

GTFS liefert weiterhin, **welche** Trips an einem konkreten Datum fahren (`calendar` + `calendar_dates`).
Die Config liefert das **Label** (welcher der 4 Typen ein Datum ist). Eine GTFS-`calendar_dates`-Ausnahme rührt
oft genau daher (Feiertag → So-Fahrplan; Ferien → Ferienfahrplan).

### 2.1 Klassifikation (reine Funktion `classify(date)`)
```
ist Feiertag?                 → So + Feiertage
Sonntag?                      → So + Feiertage
Samstag?                      → Sa
Mo-Fr & in Ferienzeitraum?    → Mo-Fr Ferien
sonst                         → Mo-Fr normal
```

---

## 3. Konfigurierbare Kalender

### 3.1 Feiertage — berechnet, nicht persistiert
Sachsen-Anhalt-Feiertage sind vollständig aus einer **fixen Regel** berechenbar; die konkreten Daten werden
**je Jahr on-the-fly** ermittelt und **nicht gespeichert**.

- **Feste Tage:** Neujahr (01.01.), Heilige Drei Könige (06.01.), Tag der Arbeit (01.05.),
  Tag der Deutschen Einheit (03.10.), **Reformationstag (31.10.)**, 1.+2. Weihnachtstag (25./26.12.)
- **Oster-relativ** (Computus / Gauß): Karfreitag, Ostermontag, Christi Himmelfahrt, Pfingstmontag

→ `HolidayService::forYear(int $year): list<CarbonImmutable>`. Die Regel (Region Sachsen-Anhalt) lebt im Code.
Der Admin bekommt eine **read-only**-Ansicht „Feiertage <Jahr>" zur Kontrolle. (Optionale Override-Tabelle für
Sonderfälle erst bei Bedarf — nicht im MVP.)

### 3.2 Ferienzeiten — persistiert, Admin-CRUD
Schulferien sind nicht berechenbar (amtlich, jährlich anders) → Tabelle, vom Admin gepflegt.

---

## 4. Versionierung: Perioden & Linien-Versionen

Zwei Ebenen: **Perioden** (kuratiert, netzweit) und **Linien-Versionen** (automatisch, je **Linie & Fahrplantyp**).

### 4.1 Fahrplanperiode (netzweit, kuratiert)
- Orientiert an den **veröffentlichten MVB-Fahrplänen**. Der **Admin legt sie im Frontend an** (Datumsbereich + Label, z. B. „Jahresfahrplan 2026/27").
- **Manuell deklarierbar** für große Änderungen (z. B. Großbaustelle).
- **Nicht** automatisch bei kleinen Änderungen.
- Eine neue Periode **setzt alle Linien zurück** — jede (Linie, Fahrplantyp) startet mit Version 1 im neuen Fahrplan.

### 4.2 Linien-Version (je Linie & Fahrplantyp, automatisch)
- Ein **Fingerprint je (Linie, Fahrplantyp)** = Signatur der Trips/Zeiten dieser Linie an diesem Betriebstag-Typ.
  Die Linie sieht sonntags anders aus als werktags → **eigener Versions-Strang je Typ**.
- Ändert sich der Fingerprint zwischen Importen → **neue Version** (alte einfrieren, neue mit `valid_from`).
  **Änderung ist Änderung** — auch kurze (Veranstaltung, Ein-Tages-Anpassung) erzeugen eine Version.
- **Transparent + persistent:** je (Linie, Typ) eine Versions-Historie; „Linie mit häufigen Änderungen" wird sichtbar.

### 4.3 Periodenwechsel anbieten (statt Automatik)
- Erstreckt sich eine Änderung über **viele Linien gleichzeitig** (Indiz für echten Fahrplanwechsel/Großbaustelle),
  **bietet das System einen Periodenwechsel an** (Hinweis im Admin) — legt ihn aber **nicht** selbst an.
- **Nimmt der Admin an:** die soeben angelegten Linien-Versionen werden **zurückgenommen** und durch den
  **Periodenwechsel ersetzt** (neue Periode, alle Linien Version 1). So bleibt die alte Periode frei von Versions-Wildwuchs.
- **Lehnt er ab / wenige Linien betroffen:** die Änderungen bleiben als Linien-Versionen in der laufenden Periode.
- Schwelle „viele Linien" = Parameter (TBD).

### 4.4 Bezug Matching
Versions- und Periodenwechsel → betroffene Kurszuordnungen als *stale / neu zu bestätigen* markieren
(siehe `INTEGRATION_MDKURSTRACKER.md` §4.2).

---

## 5. Zwei-Schichten-Modell & Konsolidierung

**Schicht 1 — Roh-GTFS je Lauf (transient).** Der Feed eines Imports mit volatilen gtfs.de-IDs. Es wird **nur der
aktuellste Lauf** gehalten (Import ersetzt vollständig — `GtfsImportService`). Quelle für die Konsolidierung; danach verzichtbar.

**Schicht 2 — Konsolidat (persistent).** Kanonischer Fahrplan, geschlüsselt auf
**(Periode → Linie → Fahrplantyp → Version)**, aus den Läufen zusammengeführt. Historische Perioden und Versionen
bleiben **eingefroren** erhalten. **Die App (Linien, Fahrplan, Umläufe, Matching) liest diese Schicht**, nicht das Roh-GTFS.

### 5.1 Stabile Schlüssel
- **Linie** = `route_short_name`
- **Halt** = gerundete **Koordinaten** (Dedup per Nearest-Neighbor); Name „latest wins" (Namen können sich ändern)
- **Fahrt** = **Signatur** = SHA(`route_short_name` + `day_type` + geordnete `(Halt, HH:MM)`-Sequenz)
- **Version** = identifiziert durch den **(Linie, Fahrplantyp)-Fingerprint** innerhalb einer Periode

### 5.2 Betriebstags-Logik (ohne rohen calendar)
Der `day_type` ist **Teil des Schlüssels** (eigener Versions-Strang je Typ), nicht der GTFS-`calendar`. Aktive Fahrten
an einem Datum D in Periode P:
```
aktive Fahrten(D, P) = consolidated_trips der aktiven Version von (Linie, classify(D)) in P
```
`classify` (§2.1) nutzt die berechneten Feiertage + die Ferien-Config — keine volatilen calendar-Zeilen im Konsolidat.

### 5.3 Konsolidierungs-Ablauf (beim Import-`finish`)
Innerhalb der aktuellen Periode, **je (Linie, Fahrplantyp)**:
1. Fingerprint aus dem Roh-GTFS berechnen (repräsentativer Tag des Typs).
2. Vergleich mit der aktiven Version:
   - **gleich** → Konsolidat **mergen** und `valid_to` verlängern (**neuer Lauf gewinnt** bei Überlappung; ältere Läufe füllen nur Ränder).
   - **anders** → aktive Version **einfrieren**, neue Version anlegen (`valid_from`).
3. Halte per Koordinaten deduplizieren.
4. Sind **viele (Linie, Typ) gleichzeitig** betroffen → **Periodenwechsel anbieten** (§4.3).

---

## 6. Datenmodell

| Tabelle | Schicht | Zweck / Felder |
|---|---|---|
| *(keine)* `holidays` | Config | Feiertage — **nicht persistiert**, `HolidayService` berechnet sie (Sachsen-Anhalt) |
| `school_holidays` | Config | Ferienzeiten — `id, name, start_date, end_date` (Admin-CRUD) |
| `schedule_periods` | 2 | `id, valid_from, valid_to (nullable), label, status (current/frozen), created_via (admin/offer), detected_at` |
| `line_versions` | 2 | `id, period_id, line (route_short_name), day_type, version_no, valid_from, valid_to (nullable), fingerprint, detected_at` |
| `consolidated_stops` | 2 | `id, lat, lon, name` — per Koordinaten dedupliziert *(offen: global vs. je Periode)* |
| `consolidated_trips` | 2 | `id, line_version_id, signature, first_stop, last_stop` |
| `consolidated_stop_times` | 2 | `consolidated_trip_id, stop_id (→consolidated_stops), stop_sequence, arrival_time, departure_time` |

`FahrplanTyp` als PHP-Enum (`MoFrNormal`, `MoFrFerien`, `Sa`, `SoFeiertag`). Konsolidat-Hierarchie:
**`schedule_periods` → `line_versions` (je Linie & Typ) → `consolidated_trips` → `consolidated_stop_times`**.
Roh-GTFS (Schicht 1) bleibt wie gehabt — nur der aktuellste Lauf.

---

## 7. Bauplan (Phasen)

- **A — Config & Fahrplantyp** ✅ *(umgesetzt 17.08.2026):* `school_holidays` (Migration/Model/Admin-CRUD);
  `HolidayService` (SA, berechnet); `FahrplanTyp`-Enum + Classifier; Tests; Admin-View „Kalender" mit
  Ferienzeiten (CRUD) + Feiertagen (read-only). Zusätzlich nutzbar gemacht: `GET /lines/{line}/trips?day_type=`
  filtert auf einen Betriebstag-Typ, aufgelöst über einen Stichtag im Feed-Fenster (`FahrplanTypDayResolver`).
- **B — Versionierung (Metadaten):** `schedule_periods` (Admin-CRUD im Frontend: anlegen/aktiv) + `line_versions`
  (automatisch je (Linie, Fahrplantyp) beim Import, Fingerprint-Vergleich → neue Version/verlängern/einfrieren) +
  **Periodenwechsel-Vorschlag** bei vielen betroffenen Linien (Hinweis → Admin nimmt an: Versionen zurücknehmen,
  neue Periode); Admin „Fahrplanperioden" + „Linien-Versionen" (Historie je Linie/Typ).
- **C — Konsolidat-Datenbestand (der große Umbau):** `consolidated_*` an `line_versions` + Merge je (Linie, Typ) (§5.3) +
  Einfrieren; App-Endpunkte (Linien/Fahrplan/Umläufe/Matching) auf das Konsolidat einer Periode umstellen
  (Default: aktuelle). Berührt I-03/I-05/I-06.
- **Später:** Viewer-Scoping/Umschalten zwischen Perioden; Roh-GTFS nach erfolgreicher Konsolidierung löschen.

---

## 8. Offene Punkte / Caveats
- **Linien-Schlüssel:** §5.1 setzt `route_short_name` als stabile Linie. Der Import vom 17.08.2026 zeigt, dass das
  nicht eindeutig ist — **N2 liegt als Tram-Route (18099) und als Bus-Route (17551)** vor, weil ein
  Schienenersatzverkehr dieselbe Nummer führte (Bus 15.–17.08., Tram ab 18.08.). Fürs Konsolidat vermutlich
  **(`route_short_name`, `route_type`)**, sonst wirkt das Ende eines Ersatzverkehrs wie eine Fahrplanänderung.
  *(Die Anzeige fasst bewusst zusammen — dort ist eine Linie eine Linie.)*
- **Fehlender Typ ≠ Änderung:** Der Import vom 17.08.2026 enthielt **keinen einzigen Ferien-Werktag** (Ferienende
  16.08., Fensterbeginn 15.08.), also nur 3 der 4 Typen. Der Fingerprint-Vergleich in Phase B darf einen im Lauf
  **fehlenden** Typ nicht als Änderung werten — sonst friert er den `MoFrFerien`-Strang jedes Mal fälschlich ein.
- **Schwelle „viele Linien"** für den Periodenwechsel-Vorschlag (absolute Zahl oder Anteil? konfigurierbar?).
- Genaue **Versions-Grenz-Erkennung**: ein einzelner Feed kann schon eine künftige Linien-Version enthalten (Zeitsub-Bereiche) — Algorithmus festzurren.
- `consolidated_stops` **global** (über alle Perioden dedupliziert, einfacher) **vs. je Periode** (historientreu, falls Halte sich ändern).
- **GTFS-`service_id` → Fahrplantyp:** repräsentativer Tag je Typ; Umgang mit Trips, deren Service mehrere Typen mischt.
- Rollierendes ~2-Wochen-Fenster deckt evtl. nicht alle 4 Typen gleichzeitig ab → Versionen/Konsolidat füllen sich über mehrere Importe.
- Wann **Roh-GTFS löschen** (sofort nach erfolgreicher Konsolidierung im `finish`, oder erst beim nächsten Import).
- Region fix: **Sachsen-Anhalt**.

## 9. Bezug
- ROADMAP **I-12 Bereich (e)**; Admin-Schaltzentrale (SPEC §10).
- Import-Ersetzen (Schicht 1) umgesetzt: `GtfsImportService` (commit `d8afe13`).
- Periodenwechsel → Re-Match, siehe `INTEGRATION_MDKURSTRACKER.md` §4.2.
