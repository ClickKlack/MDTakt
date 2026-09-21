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
- **Schwelle festgelegt 21.08.2026: ≥ 33 % der Linien, die an dem Tag überhaupt verkehren.** Anteilig statt
  absolut, damit sie nicht kippt, wenn das Netz wächst oder an einem Sonntag weniger Linien fahren. Konfigurierbar
  über `PERIOD_OFFER_MIN_SHARE` (`config/mdtakt.php`). Der empfindlichere Wert nimmt lieber einen abzulehnenden
  Vorschlag in Kauf, als einen realen Fahrplanwechsel zu übersehen — ein echter Wechsel fasst nahezu alle Linien
  gleichzeitig an, eine einzelne Baustelle drei bis sechs.
- **Gezählt werden nur beobachtete Wechsel:** Eine Version, die an der Feed-Fensterkante beginnt, ist bloß eine
  Untergrenze (§5.4 b) und ergibt keinen Wechseltag. Der erste Import löst deshalb keinen Vorschlag aus.
- **Gezählt wird nur, was bleibt (21.09.2026).** Kehrt hinter einem Abschnitt der **vorherige Fingerprint**
  zurück, war er eine Abweichung und kein Wechsel — ein Fahrplanwechsel ist per Definition dauerhaft. Die Prüfung
  ist rein lokal: Abschnitt *i* zählt nicht, wenn *i−1* und *i+1* denselben Fingerprint tragen. Am Rand des Laufs
  ist die Rückkehr nicht beobachtbar; dort bleibt der Abschnitt ein Wechsel.
  Der Fall, der das zeigte: Der **03.10.2026** (Samstag **und** Feiertag) meldete 10 von 30 Linien — 33,3 %, knapp
  über der Schwelle. Neun davon waren Nachtlinien, deren **Tagteil unverändert** war; abgewichen ist allein ihre
  Nacht, die als Samstagnacht im `so_feiertag`-Strang liegt (siehe §10). Jede dieser Versionen galt genau einen
  Tag, am 04.10. lief der alte Fahrplan weiter. Ohne die Nachtlinien blieb Linie 1 mit einer echten
  Feiertagsabweichung — 3,3 %.
  **Die Abweichung selbst bleibt eine Version** (§5.4): Der 03.10. *hat* einen eigenen Fahrplan. Unterdrückt wird
  nur der Vorschlag.
- **Die Beleglage zählt nur die Linien des Vorschlags (21.09.2026).** `observed_until` lief zuvor über *alle*
  Intervalle des Wechseltags. Der Vorschlag zum 03.10. meldete dadurch „beobachtet bis 11.10." und galt als gut
  belegt, obwohl jede seiner zehn Linien dort eine Eintagsversion hatte — die acht Tage stammten von Linie 9, die
  gar nicht zum Vorschlag gehörte. Der Admin entscheidet an dieser Zahl; sie darf nichts Fremdes mitzählen.

### 4.4 `status` ist abgeleitet, nicht gespeichert (21.09.2026)

`valid_to` hängt allein an der Nachbarperiode und ändert sich nur, wenn ohnehin geschrieben wird — es bleibt
gespeichert. **`status` hängt zusätzlich am heutigen Tag** und wurde als Spalte nur beim Schreiben einer Periode
nachgezogen (`SchedulePeriodService::rebuildChain`).

Der Fehler, der das zeigte: Am 20.09.2026 wurde eine Periode ab dem **21.09.** angelegt. Zu Recht war sie da noch
nicht laufend. Am 21.09. war sie es — aber nichts hatte nachgerechnet. Die abgelaufene Periode trug weiterhin
`current`, die geltende `frozen`. Jede Ansicht, die „die laufende Periode" über die Spalte suchte, zeigte damit den
falschen Fahrplan; die Auswahlmasken in „Kurse" und „Anschlüsse" wählten die Vorperiode vor.

Seitdem: **keine Spalte**, sondern ein Accessor auf `SchedulePeriod` und derselbe Ausdruck als Query-Scope.
`current` ist die Periode, die den heutigen Tag abdeckt; eine erst künftig beginnende bleibt `frozen`, sonst
schlüge der nächste Import seine Versionen einer noch nicht geltenden Periode zu.

### 4.5 Historie über Periodengrenzen (21.09.2026)

Eine neue Periode setzt alle Linien zurück (§4.1) — sie darf die Historie davor aber nicht unsichtbar machen.

- **`GET /admin/line-versions` nimmt `?period=`.** Ohne Angabe die laufende Periode, mit Angabe auch eine
  eingefrorene. Die Admin-Ansichten „Versionen" und „Fahrplan" haben eine Periodenauswahl.
- **Der Versionsvergleich ist über die Periodengrenze erlaubt.** Die frühere Sperre („Fahrpläne, die nie in
  Konkurrenz standen") trug für zwei beliebige Versionen, nicht aber an der Grenze: Die letzte Version der alten
  und Version 1 der neuen Periode folgen unmittelbar aufeinander, und ein Periodenwechsel ist der Moment, in dem
  die Frage *was hat sich geändert?* am dringendsten ist. Gemessen am Wechsel zum 21.09.2026 (Linie 1, `mo_fr`):
  390 Fahrten unverändert, 3 verschoben — ohne die Öffnung nicht abrufbar.
- **Die Kursübernahme (KURSE §2 K4) ebenso.** Dort war die Sperre am teuersten: Ein Periodenwechsel hätte die
  gesamte Kurs- und Anschlusspflege verworfen. Übertragen wird ohnehin nur, wo der Diff eine Partnerfahrt findet.
- Weil `version_no` je Periode wieder bei 1 beginnt, trägt jede Version im Vergleich ihre Periode mit — „v3 gegen
  v1" läse sich sonst rückwärts.

### 4.6 Bezug Matching
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
- **Halt** = ein physischer Punkt; Identität = gerundete **Koordinaten**, Name „latest wins" (Namen ändern sich).
  **Dedup-Regel entschieden 21.08.2026:** verschmelzen genau dann, wenn **Abstand ≤ 12 m *und* normalisierter Name
  gleich**. Normalisierung: Kleinschreibung, Ortspräfix „Magdeburg," entfernt, `Str.`→`Straße`, Umlaute/ß entfaltet,
  Satzzeichen und Leerraum entfernt.

  Gemessen am Live-Bestand (730 Halte, Build 15.08.2026, Haversine): Bis **15 m** verschmelzen **ausschließlich**
  namensgleiche Halte — 149 Paare, **keine** Fehlverschmelzung. Die erste echte Fehlverschmelzung liegt bei
  **17,1 m** (`City Carré` ↔ `City Carré / Ersatzh.`); die weiteren kritischen Fälle bis 50 m tragen alle einen
  unterscheidenden Zusatz (`(Schleife)`, `(Quittenweg)`, `Wendeschl.`, `ZOB Hst. 2`). **12 m** hält Sicherheitsabstand
  nach oben, die Namensbedingung sichert zusätzlich gegen künftige Netzänderungen ab.

  Damit ist auch die alte Sorge ausgeräumt: Die „**338 von 730** bei 20 m" waren falsch gruppiert — 181 der 190 Paare
  heißen **identisch**, 7 weitere sind reine Schreibvarianten (`Listemannstr.` ↔ `Listemannstraße`,
  `Magdeburg, Zoo` ↔ `Zoo`). Deren Verschmelzung ist genau das Gewollte, nicht der Schaden.
- **Steige verschmelzen — bewusst** (entschieden 21.08.2026). **9 Paare (18 Halte) liegen auf exakt identischen
  Koordinaten** und tragen dort die beiden Fahrtrichtungen: `Maybachstraße` etwa als zwei `stop_id`s, beide Linie 59,
  mit 18 bzw. 19 Fahrten. **Keine** Koordinaten-Schwelle trennt die, auch 0 m nicht — und `trips.direction_id` ist im
  **gesamten** Feed `NULL`, die Richtung steckt einzig in der Wahl der `stop_id`. Konsequenz: **ein Konsolidat-Halt je
  physischem Punkt**; die Richtung ergibt sich aus der Position in der Fahrt-Sequenz, nicht aus dem Halt. Preis: an
  diesen 18 Halten ist „Steig A/B" nicht mehr darstellbar. Dasselbe Muster tritt für Nachtlinien auf (`N8` mit eigener
  `stop_id` 0,7 m neben dem Tagesbahnsteig) und für Tram/Bus am selben Punkt (`Bördepark Ost`, 0,0 m) — dort ist das
  Verschmelzen ohnehin erwünscht.
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
| `schedule_periods` | 2 | `id, valid_from, valid_to (nullable), label, created_via (admin/offer), detected_at` — `status` ist **abgeleitet**, siehe §4.4 |
| `line_versions` | 2 | `id, period_id, line (route_short_name), day_type, version_no, fingerprint, first_seen_at, last_seen_at` — Gültigkeit liegt in `line_version_intervals`, nicht hier |
| `consolidated_stops` | 2 | **Global**, eine Zeile je physischem Halt — `id, anchor_lat, anchor_lon, first_seen_at, last_seen_at`. Identität = gerundete Koordinaten (entschieden 18.08.2026) |
| `consolidated_stop_versions` | 2 | Attribut-Historie je Halt — `consolidated_stop_id, name, lat, lon, valid_from, valid_to, from_confirmed, to_confirmed`. Trägt Umbenennungen und Verlegungen, ohne die Identität zu vervielfachen |
| `consolidated_trips` | 2 | `id, line_version_id, signature, first_stop, last_stop` |
| `consolidated_stop_times` | 2 | `consolidated_trip_id, stop_id (→consolidated_stops), stop_sequence, arrival_time, departure_time` |
| `line_version_intervals` | 2 | Beobachtete Gültigkeit je Version (§5.4) — `line_version_id, valid_from, valid_to, from_confirmed, to_confirmed`; mehrere Intervalle je Version (Rückkehr zum alten Fahrplan) |

`FahrplanTyp` als PHP-Enum (`MoFrNormal`, `MoFrFerien`, `Sa`, `SoFeiertag`). Konsolidat-Hierarchie:
**`schedule_periods` → `line_versions` (je Linie & Typ) → `consolidated_trips` → `consolidated_stop_times`**.
Roh-GTFS (Schicht 1) bleibt wie gehabt — nur der aktuellste Lauf.

### 6.1 Phase B — konkrete Tabellen (Entwurf, 18.08.2026)

Alle Entscheidungen aus §5.4 und §5.1 eingearbeitet. **Noch nicht implementiert** — Grundlage für die Migrationen.

**`schedule_periods`** — netzweite, kuratierte Periode (§4.1)

| Spalte | Typ | Anmerkung |
|---|---|---|
| `id` | bigserial | |
| `label` | varchar | z. B. „Jahresfahrplan 2026/27" |
| `valid_from` | date | vom Admin gesetzt |
| `valid_to` | date, null | offen = laufende Periode |
| ~~`status`~~ | — | **keine Spalte** (seit 21.09.2026) — `current`/`frozen` wird beim Lesen aus `valid_from`/`valid_to` gegen den heutigen Tag gerechnet, siehe §4.4 |
| `created_via` | varchar | `admin` \| `offer` — angenommener Systemvorschlag (§4.3) |
| `created_at` | timestamptz | |

**`trip_signatures`** — Brücke von der volatilen `trip_id` zur stabilen Identität (§5.1)

| Spalte | Typ | Anmerkung |
|---|---|---|
| `id` | bigserial | |
| `trip_id` | varchar FK → `trips` | Zeiger, wird bei jedem Import neu aufgelöst |
| `day_type` | varchar | einer der vier Typen |
| `signature` | char(64) | `SHA256(route_short_name │ day_type │ Abfahrts-HH:MM-Sequenz)` — **ohne Halte** |
| | | **unique** `(trip_id, day_type)`, **index** `(signature)` |

> Warum je **(Trip, Typ)** und nicht je Trip: Ein „täglich"-Service gehört zu allen vier Typen. Die Signatur trägt den
> Typ, also braucht ein solcher Trip vier Zeilen. Die Tabelle wird bei jedem Import neu aufgebaut — sie gehört
> logisch zu Schicht 1, ist aber der Ankerpunkt, an dem Zuordnungen (I-05/I-06) dauerhaft hängen.

**`line_versions`** — ein Fahrplanstand einer Linie für einen Betriebstag-Typ (§4.2, §5.4 a)

| Spalte | Typ | Anmerkung |
|---|---|---|
| `id` | bigserial | |
| `period_id` | bigint FK | |
| `line` | varchar | `route_short_name` — **ohne** `route_type` (entschieden) |
| `day_type` | varchar | |
| `version_no` | int | fortlaufend je `(period, line, day_type)`, nur zur Anzeige |
| `fingerprint` | char(64) | SHA über die sortierten `trip_signatures` dieser Linie und dieses Typs |
| `first_seen_at` / `last_seen_at` | timestamptz | |
| | | **unique** `(period_id, line, day_type, fingerprint)` — die Version **ist** ihr Fingerprint |

Keine `valid_from`/`valid_to` — die Gültigkeit liegt vollständig in:

**`line_version_intervals`** — beobachtete Gültigkeit (§5.4 b)

| Spalte | Typ | Anmerkung |
|---|---|---|
| `id` | bigserial | |
| `line_version_id` | bigint FK, cascade | |
| `valid_from` / `valid_to` | date | |
| `from_confirmed` / `to_confirmed` | boolean | `false` = Fensterkante, nur Untergrenze |
| | | **index** `(line_version_id)`, **index** `(valid_from, valid_to)` |

### 6.2 Fortschreibung beim Import-`finish` (Entwurf)

Ersetzt den „repräsentativen Tag" aus §5.3 durch eine **tagesweise** Auswertung — nur so entstehen echte Intervalle
und die Unterscheidung gesichert/offen.

```
1. je (Trip, Typ): Signatur berechnen → trip_signatures neu aufbauen
2. je Tag D im Feed-Fenster, je Linie:
       typ_D = classify(D)                        (§2.1)
       menge  = aktive Trips der Linie an D       (calendar + calendar_dates)
       fp(D)  = SHA über die sortierten Signaturen dieser Menge
3. je (Linie, Typ): aufeinanderfolgende Tage mit gleichem fp zu Intervallen bündeln
4. je Intervall:
       line_version zu (Periode, Linie, Typ, fp) finden oder anlegen
       Intervall anfügen; an bestehende angrenzende Intervalle derselben Version anschließen
       from_confirmed = Intervallbeginn liegt NICHT auf der Fensterkante
       to_confirmed   = Intervallende  liegt NICHT auf der Fensterkante
5. offene Grenzen verdichten: deckt dieser Lauf eine zuvor offene Grenze im Inneren ab,
   wird sie bestätigt
6. betrifft ein neuer Fingerprint viele Linien am selben Datum → Periodenwechsel anbieten (§4.3)
```

**Nicht als Änderung werten:** Deckt das Fenster einen Typ gar nicht ab (der Import vom 17.08.2026 enthielt keinen
Ferien-Werktag), entstehen für diesen Typ schlicht keine Intervalle — die bestehende Version bleibt unangetastet.

**Aufwand:** 23 Tage × 36 Linien ≈ 830 Fingerprints je Lauf. Die Tagesmengen kommen aus einer Bulk-Abfrage
(`ServiceDayResolver::activeServiceIdsForRange`, bereits vorhanden), die Signaturen aus `trip_signatures`.

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
- ~~**Nachtlinien: Betriebstag ≠ Kalendertag.**~~ — **gelöst 20.09.2026**, siehe §10.
- **Schwelle „viele Linien"** für den Periodenwechsel-Vorschlag (absolute Zahl oder Anteil? konfigurierbar?).
- Genaue **Versions-Grenz-Erkennung**: ein einzelner Feed kann schon eine künftige Linien-Version enthalten (Zeitsub-Bereiche) — Algorithmus festzurren.
- ~~`consolidated_stops` global vs. je Periode~~ — entschieden 18.08.2026: **global mit versionierten Attributen** (§6.1).
- ~~Dedup-Schwelle für Haltestellen-Koordinaten~~ — entschieden 21.08.2026: **≤ 12 m + normalisierter Name** (§5.1).
- ~~Steig-Trennung im Konsolidat~~ — entschieden 21.08.2026: **ein Halt je Punkt**, Richtung aus der Fahrt-Sequenz (§5.1).
- **Noch ungemessen: die Drift zwischen zwei Builds.** Der eigentliche Grund für die Koordinaten-Dedup ist, denselben
  Halt über Builds hinweg wiederzuerkennen — bislang existiert aber nur **ein** Build (15.08.2026). Offen bleibt damit,
  ob gtfs.de die `stop_id`s überhaupt neu vergibt und wie weit die Koordinaten wandern. Beides ist mit dem zweiten
  Import zu messen; fällt die Drift größer als 12 m aus, ist die Schwelle nachzuziehen.
- **GTFS-`service_id` → Fahrplantyp:** repräsentativer Tag je Typ; Umgang mit Trips, deren Service mehrere Typen mischt.
- Rollierendes ~2-Wochen-Fenster deckt evtl. nicht alle 4 Typen gleichzeitig ab → Versionen/Konsolidat füllen sich über mehrere Importe.
- Wann **Roh-GTFS löschen** (sofort nach erfolgreicher Konsolidierung im `finish`, oder erst beim nächsten Import).
- Region fix: **Sachsen-Anhalt**.

## 9. Bezug
- ROADMAP **I-12 Bereich (e)**; Admin-Schaltzentrale (SPEC §10).
- Import-Ersetzen (Schicht 1) umgesetzt: `GtfsImportService` (commit `d8afe13`).
- Periodenwechsel → Re-Match, siehe `INTEGRATION_MDKURSTRACKER.md` §4.2.

---

## 10. Betriebstag ≠ Kalendertag (entschieden 20.09.2026)

Beim ersten Konsolidierungslauf (18.08.2026) zeigte N1 im Typ `mo_fr` **montags einen anderen Fahrplan als Di–Fr**.
Grund: Die Nacht von Sonntag auf Montag ist eine Sonntagnacht, GTFS ordnet diese Fahrten aber dem Montag zu. Der
Punkt galt als „nicht dringend" — bis der Haltestellen-Editor (I-14) ihn sichtbar machte: An Herrenkrug entstanden
dadurch **16 Fahrplanstände über vier Wochen**, je einer pro Montag und pro Di–Fr-Block.

### Der Befund

Verkehrsbetriebe drücken den Betriebstag sonst über Zeiten **jenseits 24:00** aus („26:00" für 2 Uhr des folgenden
Kalendertags, aber desselben Betriebstags). Der gtfs.de-Feed tut das **nicht**: Im gesamten Bestand beginnt **keine
einzige Fahrt** jenseits 24:00. Alles hängt am Kalendertag, der Betriebstag muss also rekonstruiert werden.

Gemessen am Realbestand (20.09.2026, 24.319 konsolidierte Fahrten):

| | Taglinien | Nachtlinien |
|---|---|---|
| Frühester Start | **03:47** (Linie 5) | 00:10 |
| Spätester Start | 23:5x | 06:40 (N1) |
| Fahrten 00:00–03:00 | **keine** | rund 790 |
| Fahrten 07:00–21:59 | durchgehend | **keine einzige** |

### Die Entscheidung: zwei Grenzen

Tag- und Nachtnetz **überlappen von 03:45 bis 06:40** — eine gemeinsame Grenze müsste dort zwangsläufig etwas falsch
zuordnen. Die Lücke von 07:00 bis 22:00 macht dagegen jede Grenze dazwischen für Nachtlinien wasserdicht.

| Linienart | Grenze | Begründung |
|---|---|---|
| Taglinien (`1`, `73`, …) | **03:00** | Liegt vor der frühesten Fahrt (03:47), ist heute also wirkungslos — aber vorbereitet, falls eine Taglinie je nach Mitternacht verkehrt |
| Nachtlinien (`N1`–`N9`) | **12:00** | Frei wählbar zwischen 07:00 und 22:00, weil dort keine Nachtlinie fährt |

Konfigurierbar über `OPERATING_DAY_BOUNDARY` und `OPERATING_DAY_NIGHT_BOUNDARY` (`config/mdtakt.php`). Die
Nachtlinie wird am Buchstaben-Präfix erkannt — dieselbe Regel, nach der die Frontends das Nachtsignet wählen.

### Wirkung

Eine Fahrt vor ihrer Grenze gehört zum Betriebstag des **Vortags**. Das betrifft die Zuordnung Fahrt → Fahrplantyp
(`TripSignatureService`), die tagesweise Fingerprint-Auswertung (`ScheduleVersionService`) und das Einsammeln der
Fahrten einer Version (`TripConsolidationService`) — alle drei über `OperatingDayResolver`.

Am Realbestand: **213 Roh-Fahrten** wechseln den Betriebstag, sämtlich auf Nachtlinien. N1 hat im `mo_fr`-Strang
danach eine durchgehende Version statt wöchentlich wechselnder; die Stände an Herrenkrug fielen von 16 auf 3.

### Rückwirkend angewandt

Die Korrektur wirkt zunächst nur so weit, wie der Roh-Feed reicht — beim Einbau am 20.09.2026 also ab dem 19.09.
Deshalb wurde das Konsolidat am selben Tag **aus dem Feed-Archiv neu aufgebaut**: sechs archivierte Feeds
(19.08., 23.08., 30.08., 07.09., 13.09., 20.09.) chronologisch eingespielt, nachdem Linien-Versionen, Fahrten und
Halte geleert waren. Erhalten blieben die kuratierten Fahrplanperioden.

Damit trägt die **gesamte** Historie ab 15.08.2026 das korrigierte Modell. Belege danach: N1, N2, N3 und N9 haben
im `mo_fr`-Strang der Ausgangsperiode je **eine** Version statt vier; die Fahrplanstände an Herrenkrug fielen von
16 auf 2 (Ausgangsperiode) bzw. 3 (Folgeperiode).

Genau dafür ist das Feed-Archiv gebaut (`FeedArchiveService`): *„Aus ihm lässt sich das Konsolidat später
rückwirkend aufbauen."* Dies war sein erster Einsatz — und er hat getragen. **Wer das Archiv nicht sichert, kann
eine solche Korrektur nicht nachholen.**

### Der letzte Fenstertag wird nicht ausgewertet (gelöst 21.09.2026)

**Der Betriebstag-Wechsel ist an der Fensterkante unvollständig beobachtbar.** Der letzte Tag eines Feed-Fensters
sieht nur seine Abendseite; die zugehörige Nacht steht im Feed bereits unter dem Folgetag, der außerhalb liegt.

Das blieb zunächst als Hinweis stehen — bis sich zeigte, dass es nicht bei „auffällig wenigen Fahrten" bleibt: Am
**16.10.2026**, dem letzten Tag seines Fensters, fehlten auf jeder Nachtlinie 13 von 18 Fahrten. Sechzehn Linien
bekamen dadurch einen neuen Fingerprint, und das System bot einen **Periodenwechsel** an, den es nie gegeben hat
(16 von 34 Linien = 47 %). Ein halb beobachteter Tag ist keine Beobachtung.

**Seitdem endet die Auswertung am vorletzten Tag des Fensters** (`ScheduleVersionService::fingerprintsPerDay`).
Die Intervalle enden dort mit `to_confirmed = false` — was sie an der Fensterkante ohnehin taten; die rechte
Grenze war schon immer nur eine Untergrenze (§5.4 b). Der Tag kommt beim nächsten Import als Innentag wieder,
dann vollständig. Ein Fenster, das nach dieser Regel keinen Tag mehr übrig lässt, wird mit einer `WARNING`
übersprungen.

Der Preis: Ein echter Fahrplanwechsel genau am letzten Fenstertag wird einen Import später erkannt. Das ist kein
Verlust, sondern die ehrlichere Aussage — ein einzelner, halbierter Tag trägt keine Periodengrenze.

### Was offen bleibt

**Der Nachtverkehr folgt einem eigenen Rhythmus, nicht dem Fahrplantyp seines Betriebstags.** Ein Betriebstag
trägt genau einen Typ (§2.1) — sein Tagteil und sein Nachtteil können aber verschiedenen Mustern folgen:

- **03.10.2026** (Samstag **und** Feiertag) → `so_feiertag`. Sein Tagteil ist der eines Sonntags, seine Nacht ist
  **zeichengleich mit der Nacht des 10.10.** (normaler Samstag): dieselben Abfahrtsfolgen, 15 bis 16 Fahrten je
  Nachtlinie statt der 11 bis 13 einer Sonntagnacht. Weil die Signatur den `day_type` enthält, hasht dieselbe
  Samstagnacht unter `so_feiertag` anders als unter `sa` — jede Nachtlinie bekommt dort eine Eintagsversion.
- **02.10.2026** (Freitag, Vorabend des Feiertags) → `mo_fr`, mit 16 statt 13 Nachtfahrten auf der N1. Auch hier
  eine Eintagsversion.

Der zweite Fall zeigt, warum eine naheliegende Korrektur zu kurz greift: Den Nachtteil nach dem **Kalender-
Wochentag** zu klassifizieren, löste den 03.10., nicht aber den 02.10. Maßgeblich ist offenbar nicht der Wochentag,
sondern ob der **Folgetag ein Ruhetag** ist — der Nachtrhythmus ist eine eigene Dimension.

Ein Umbau wäre teuer: Die Signatur ist `SHA(line | day_type | Abfahrtsfolge)`; ein geänderter `day_type` macht
sämtliche Nachtlinien-Fingerprints neu und verlangt einen chronologischen Neuaufbau des Konsolidats aus dem
Feed-Archiv. Solange der Effekt nur **Eintagsversionen** erzeugt — die korrekt sind und seit dem 21.09.2026 keinen
Periodenwechsel mehr vorschlagen (§4.3) — ist der Leidensdruck gering. Die Frage gehört in eine eigene Iteration.
