# Konzept: Fahrplanperioden & Fahrplantypen

> **Stand: 2026-08-18.** Konzept für die Erkennung von Fahrplanperioden, die Klassifikation der
> Fahrplantypen und die **Konsolidierung** der GTFS-Läufe je Periode. Phase A ist umgesetzt (ROADMAP I-12 e);
> Phase B/C laufen als eigene Iteration **I-13** und sind seit 18.08.2026 das **primäre Ziel** — siehe TL;DR.

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
- **Auch kurze Änderungen sind Versionen (§5.4)** — Ersatzverkehr über wenige Tage bekommt einen eigenen Eintrag.
  Dafür ist eine Version über ihren **Fingerprint** identifiziert und trägt **mehrere Gültigkeits-Intervalle**
  (Rückkehr zum alten Fahrplan = weiteres Intervall, keine neue Version).
- **Gültigkeit ist beobachtet, nicht behauptet (§5.4):** Der Feed schneidet an beiden Rändern ab — Grenzen gelten erst
  als **gesichert**, wenn der Wechsel *innerhalb* eines Fensters gesehen wurde, sonst als **offen**.
- **Viewer** (später): zeigt zunächst nur die aktuelle Periode; Umschalten kommt später.

> **Priorität (entschieden 18.08.2026):** Der **vollständige Fahrplan-Bestand ist das primäre Ziel** — vor der
> MDKursTracker-Anbindung und vor dem Matching. Begründung: Ein Import liefert nur ein Zeitfenster (aktuell 23 Tage,
> 15.08.–06.09.) und **ersetzt** den Bestand. Ein vollständiger Fahrplan mit allen Änderungen — Baustellen,
> Ersatzverkehre, Fahrplanwechsel — entsteht **nur durch Beobachtung über viele Importe**. Was nicht konsolidiert
> wird, während es im Fenster liegt, ist unwiederbringlich weg. Jeder Tag ohne Konsolidierung kostet Fahrplan-Historie.
> Rückwirkende Sichtungs-Zuordnung ist ausdrücklich **kein** Ziel — es geht um den Fahrplan selbst.

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
- **Halt** = gerundete **Koordinaten** (Dedup per Nearest-Neighbor); Name „latest wins" (Namen können sich ändern).
  **Schwelle offen:** die in INTEGRATION §4.2 genannten „~≤ 20 m" sind für das MVB-Netz zu grob — **338 von 730**
  Halten haben einen eigenständigen anderen Halt näher als 20 m (10 m: 188, 5 m: 66). Zu weit verschmilzt Steige
  (Hasselbachplatz: 9 Steige, ein Name), zu eng erkennt denselben Halt zwischen zwei Builds nicht wieder.
- **Fahrt** = **Signatur** = SHA(`route_short_name` + `day_type` + geordnete `(Halt, HH:MM)`-Sequenz)
- **Version** = identifiziert durch den **(Linie, Fahrplantyp)-Fingerprint** innerhalb einer Periode

**`day_type` in der Signatur: die vier Fahrplantypen aus §2** (entschieden 18.08.2026) — `mo_fr`, `mo_fr_ferien`,
`sa`, `so_feiertag`, identisch zum PHP-Enum `FahrplanTyp`. Nicht die drei Werte, die MDKursTracker liefert
(`MO-FR|SA|SO`, INTEGRATION §5.1). Das ist unkritisch, weil jede Sichtung ein `service_date` mitbringt: Die Engine
klassifiziert das Datum selbst (§2.1) und weiß damit, ob `mo_fr` oder `mo_fr_ferien` gemeint ist. Der gröbere
Tracker-Wert wird nicht gebraucht.

> ⚠️ **Widerspruch zu INTEGRATION §4.2, noch zu klären:** Dort ist die Signatur ohne Halte definiert
> (`route_short_name` + `day_type` + Abfahrts-`HH:MM`-Sequenz), hier **mit** `(Halt, HH:MM)`-Paaren.
> Das ist keine Feinheit: Nur die **haltfreie** Variante kann MDKursTracker selbst berechnen — die Gegenseite hat
> HAFAS-Haltestellen-IDs, keine GTFS-Koordinaten, und §4.1 hat den Match bewusst **ohne** Stop-ID-Crosswalk
> validiert. Mit Halten in der Signatur wäre die Kurs↔Signatur-Zuordnung über Systemgrenzen nicht mehr vergleichbar.
> **Empfehlung: haltfrei** (nur Zeiten) — Kollisionen sind unwahrscheinlich, weil abweichende Laufwege auf derselben
> Linie fast immer eine abweichende Zeitsequenz haben. Koordinaten bleiben davon unberührt: Sie sind der Schlüssel
> der **Halt-Dedup**, nicht der Fahrt-Identität.

### 5.2 Betriebstags-Logik (ohne rohen calendar)
Der `day_type` ist **Teil des Schlüssels** (eigener Versions-Strang je Typ), nicht der GTFS-`calendar`. Aktive Fahrten
an einem Datum D in Periode P:
```
aktive Fahrten(D, P) = consolidated_trips der Version von (Linie, classify(D)),
                       deren Gültigkeits-Intervalle D enthalten (§5.4)
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

### 5.4 Kurze Änderungen sind Versionen — und Gültigkeit ist beobachtet, nicht behauptet

**Entschieden (18.08.2026):** Auch **kurze** Fahrplanänderungen (Ersatzverkehr über wenige Tage, Veranstaltung)
erzeugen eine **eigene Linien-Version**. Sie bilden zwar keinen Fahrplanschnitt, sollen aber getrennt geführt werden —
ein eigener Ausnahme-Mechanismus neben den Versionen entfällt damit.

Daraus folgen zwei Dinge, ohne die das Modell nicht trägt:

**(a) Version = Fingerprint, Gültigkeit = Menge von Intervallen.** Kehrt eine Linie nach der Baustelle zum alten
Fahrplan zurück, entsteht **keine dritte Version**, sondern die frühere Version bekommt ein **weiteres Gültigkeits-
Intervall**. Sonst wüchse die Historie bei jeder Rückkehr zum Normalzustand um eine inhaltlich identische Version,
und „diese Linie ändert sich oft" wäre nicht mehr ablesbar. Die Version ist über ihren Fingerprint identifiziert,
nicht über ihre Laufzeit.

**(b) Gültigkeitsgrenzen sind Beobachtungen.** Der Feed schneidet an beiden Rändern ab — im Import vom 17.08.2026
beginnen **19 von 58** Wochenmustern exakt am Fensteranfang und **24 von 58** enden exakt am Fensterende. Eine aus
einem Lauf gelesene Gültigkeit ist deshalb nur eine **Untergrenze**. Das Konsolidat muss zwei Fälle unterscheiden:

| Fall | Bedeutung |
|---|---|
| **gesichert** | Der Wechsel wurde **innerhalb** eines Fensters beobachtet — Tag D trug Fingerprint A, Tag D+1 trug B. Die Grenze ist echt. |
| **offen** | Die Version lag am Fensterrand an. Wahre Grenze unbekannt, nur „mindestens seit / mindestens bis". |

Ein späterer Import, dessen Fenster weiter zurück- oder vorausreicht, kann eine offene Grenze zu einer gesicherten
verdichten. Die Anzeige muss den Unterschied zeigen, statt eine Fensterkante als Fahrplanwechsel auszugeben — das war
der Fehlschluss, der am 16.08.2026 nahelag: der Tag sieht wie ein Sondertag aus, ist aber der letzte sichtbare Tag
der Sommerferien- und Baustellenphase, deren Beginn vor dem Fenster liegt.

---

## 6. Datenmodell

| Tabelle | Schicht | Zweck / Felder |
|---|---|---|
| *(keine)* `holidays` | Config | Feiertage — **nicht persistiert**, `HolidayService` berechnet sie (Sachsen-Anhalt) |
| `school_holidays` | Config | Ferienzeiten — `id, name, start_date, end_date` (Admin-CRUD) |
| `schedule_periods` | 2 | `id, valid_from, valid_to (nullable), label, status (current/frozen), created_via (admin/offer), detected_at` |
| `line_versions` | 2 | `id, period_id, line (route_short_name), day_type, version_no, fingerprint, first_seen_at, last_seen_at` — Gültigkeit liegt in `line_version_intervals`, nicht hier |
| `consolidated_stops` | 2 | `id, lat, lon, name` — per Koordinaten dedupliziert *(offen: global vs. je Periode)* |
| `consolidated_trips` | 2 | `id, line_version_id, signature, first_stop, last_stop` |
| `consolidated_stop_times` | 2 | `consolidated_trip_id, stop_id (→consolidated_stops), stop_sequence, arrival_time, departure_time` |
| `line_version_intervals` | 2 | Beobachtete Gültigkeit je Version (§5.4) — `line_version_id, valid_from, valid_to, from_confirmed, to_confirmed`; mehrere Intervalle je Version (Rückkehr zum alten Fahrplan) |

`FahrplanTyp` als PHP-Enum (`MoFrNormal`, `MoFrFerien`, `Sa`, `SoFeiertag`). Konsolidat-Hierarchie:
**`schedule_periods` → `line_versions` (je Linie & Typ) → `consolidated_trips` → `consolidated_stop_times`**.
Roh-GTFS (Schicht 1) bleibt wie gehabt — nur der aktuellste Lauf.

---

## 7. Bauplan (Phasen)

- **A — Config & Fahrplantyp** ✅ *(umgesetzt 17.08.2026):* `school_holidays` (Migration/Model/Admin-CRUD);
  `HolidayService` (SA, berechnet); `FahrplanTyp`-Enum + Classifier; Tests; Admin-View „Kalender" mit
  Ferienzeiten (CRUD) + Feiertagen (read-only). Zusätzlich nutzbar gemacht: `GET /lines/{line}/trips?day_type=`
  filtert auf einen Betriebstag-Typ, aufgelöst über einen Stichtag im Feed-Fenster (`FahrplanTypDayResolver`).
> **Reihenfolge (18.08.2026):** B und C sind **vorgezogen** — vor Sichtungs-API, Matching und
> MDKursTracker-Anbindung. Sie sind das primäre Ziel, weil nur sie Fahrplan-Historie aufbauen; alles andere
> lässt sich später nachholen, verlorene Fahrpläne nicht. Siehe ROADMAP **I-13**.

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
- **Kurze Änderungen = eigene Version** (§5.4): entschieden 18.08.2026. Offen bleibt die Umsetzung von
  Intervall-Gültigkeit und der Unterscheidung gesichert/offen — beides ist Voraussetzung dafür, dass Fensterkanten
  nicht als Fahrplanwechsel erscheinen.
- **Import-Takt:** Das Konsolidat kann nur sammeln, was im Fenster liegt. Bei 23 Tagen Fenster genügt ein Import
  je Woche für lückenlose Abdeckung; **täglich** ist die sichere Wahl (Ausfälle, Feed-Störungen). Cron festlegen —
  hängt mit dem offenen Punkt „Cron-Intervall" in der ROADMAP zusammen.
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
