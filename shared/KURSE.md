# Konzept: Kurse & Umläufe

> **Stand: 2026-09-20.** Konzept für die Umlauf-Ebene: welche Fahrten dasselbe Fahrzeug
> nacheinander fährt und welche Kursnummer dieser Umlauf trägt. Umsetzung als ROADMAP **I-14**.
> Die Sichtungs-API (I-04) liefert Kursnummern später **in dieses Gefüge hinein** — sie baut es nicht.

---

## TL;DR

- **Der Umlauf ist eine Kette verknüpfter Fahrten**, keine Eigenschaft einer einzelnen Fahrt.
  Gespeichert wird der Anschluss „Fahrt A endet an Haltestelle S → Fahrt B beginnt an S".
- **Die Haltestelle ist eine Ebene über dem Halt** (§2 K6). An einer Endstelle liegen Ankunft und
  Abfahrt oft auf verschiedenen Bahnsteigen — netzweit bei 64 von 104 Endstellen.
- **Ketten laufen über Linien hinweg.** Eine 1 wird in Sudenburg zur 13 — das ist der Normalfall,
  kein Sonderfall.
- **Eine Kette endet oder beginnt auch ohne Anschluss** (Ausrücken/Einrücken, Betriebsfahrt —
  besonders morgens). Das ist eine **bewusste Entscheidung**, die gespeichert wird, nicht die
  Abwesenheit einer Pflege. Nur so ist „noch nicht gepflegt" von „hier ist wirklich Schluss"
  unterscheidbar.
- **Die Kursnummer ist ein Etikett an der Kette**, nicht an der Fahrt. Beim Linienwechsel bleibt
  sie erhalten; nur der Linien-Präfix der Anzeige wechselt: `1/03` → `13/03`.
- **Perioden und Versionen gelten durchgehend.** Eine Fahrt hängt an einer `line_version`, diese an
  einer Periode. Verknüpfung und Kurs erben diesen Bezug.
- **GTFS hilft hier nicht.** `trips.block_id` ist im gesamten gtfs.de-Feed `NULL`, `direction_id`
  ebenso. Die Umlauf-Ebene entsteht ausschließlich durch Pflege und später durch Sichtungen.

---

## 1. Begriffe

| Begriff | Bedeutung |
|---|---|
| **Fahrt** | Eine `consolidated_trip` — eine Linienfahrt von A nach B innerhalb einer `line_version` |
| **Anschluss** | Die Aussage „dasselbe Fahrzeug fährt nach Fahrt A die Fahrt B" |
| **Kette / Umlauf** | Die maximale Folge von Fahrten, die über Anschlüsse verbunden sind |
| **Ausrücken** | Die Kette beginnt hier ohne Vorgänger — das Fahrzeug kommt vom Betriebshof |
| **Einrücken** | Die Kette endet hier ohne Nachfolger |
| **Kurs / Kursnummer** | Die am Fahrzeug angeschlagene Bezeichnung des Umlaufs, z. B. `03` |

Das Glossar in `SPEC.md` §2.1 setzte „Umlauf" mit `block_id` gleich. Das trägt nicht: Das Feld ist
leer, und der Umlauf ist hier ein gepflegtes Faktum, kein importiertes.

---

## 2. Entscheidungen (20.09.2026)

### K1 — Beim Linienwechsel bleibt die Nummer

Wechselt ein Fahrzeug in Sudenburg von der Linie 1 auf die Linie 13, behält es seine Kursnummer.
Angezeigt wird sie je Fahrt mit dem Präfix der jeweiligen Linie: `1/03`, dann `13/03`.

**Folge:** Die Kursnummer gehört dem **Umlauf**, nicht der Linie. Gespeichert wird sie einmal an
der Kette, nicht an jeder Fahrt.

### K2 — Die Verkettung führt

Zwei Wege führen zum selben Ziel: Man kann je Fahrt eine Kursnummer eintragen und die Kette daraus
ableiten, oder man verkettet die Fahrten und hängt die Nummer an die Kette. **Entschieden: die
Verkettung führt.**

Begründung: Nur die Verkettung kann den Betriebsfahrt-Fall ausdrücken. „Diese Fahrt beginnt den
Umlauf" ist eine Aussage über einen Übergang, nicht über eine Nummer — und ohne sie ließe sich
eine ungepflegte Fahrt nicht von einer bewusst offen gelassenen unterscheiden. Außerdem hängt die
Kette nicht davon ab, dass überhaupt schon eine Nummer bekannt ist: Der Haltestellen-Editor ist
benutzbar, bevor die erste Kursnummer vergeben wird.

### K3 — Die Kursnummer ist je Linie eindeutig, nicht netzweit

**Geklärt am 21.09.2026 am Realbestand.** Die Kursnummer ist nur je Linie beziehungsweise je
Linienkombination eindeutig: Die „2" der Linie 8 und die „2" der Linie 6 sind zwei verschiedene
Umläufe. Aus K1 folgt lediglich, dass ein Umlauf seine Nummer über einen Linienwechsel hinweg
behält — nicht, dass die Nummer netzweit nur einmal vorkommt.

Anlass war ein Fehlverhalten: Die erste Fassung suchte beim Eintippen einer Nummer netzweit nach
`(period_id, day_type, number)` und hängte damit eine 8er-Kette an den bestehenden Umlauf „2" der
Linie 6. Entstanden war ein Umlauf über zwei Linien, die an keiner Stelle verknüpft sind.

**Entschieden: kein Unique-Index, aber ein linienbezogenes Nachschlagen.** Beim Eintippen einer
Nummer wird ein bestehender Kurs nur dann wiederverwendet, wenn er mindestens **eine Linie mit der
Kette gemeinsam** hat; sonst entsteht ein neuer Kurs. Geprüft wird auf Überschneidung, nicht auf
Gleichheit der Linienmengen — eine Kette wächst: Liegt Kurs `1/03` heute nur auf der 1 und hängt
morgen eine 13er-Fahrt daran, muss das nächste Eintippen auf der 1 weiterhin denselben Umlauf
treffen.

Ein Unique-Index bleibt aus, weil die tragfähige Bedingung („dieselbe Nummer auf einander
berührenden Linien") keine Spaltenkombination ist — die Linienmenge eines Kurses steht in
`course_trips` und ändert sich mit jeder Verknüpfung. Die Prüfung gehört deshalb in den Service.
Als **Dublette** gemeldet wird entsprechend nur, was sich wirklich widerspricht: dieselbe Nummer
auf überschneidenden Linien (oder ein Kurs ohne Fahrten, der an keine Linie gebunden ist und
deshalb mit jedem kollidiert). Zwei Nummern „2" auf 6 und 8 sind der Normalfall und lösen keine
Warnung mehr aus.

Dies **korrigiert die Annahme E2** in `MDKURSTRACKER_REQUIREMENTS.md` („Umlauf =
`(line, course_number, service_date)`"). Ein Umlauf kann mehrere Linien umfassen; das Tripel
identifiziert ihn nicht.

### K4 — Versionswechsel überträgt nichts von selbst

Legt ein Import eine neue `line_version` an, bleibt die Pflege unangetastet. Im Admin gibt es einen
Knopf „Kurse und Anschlüsse aus Version N−1 übernehmen" mit Vorschau.

Begründung: Eine neue Version bedeutet, dass sich Zeiten geändert haben. Ob ein Anschluss das
überlebt, hängt an der Wendezeit — eine um zehn Minuten verschobene Abfahrt kann ihn unmöglich
machen. Eine automatische Übernahme würde solche Fälle stillschweigend fortschreiben. Der Knopf
macht die Übernahme zu einer datierten Entscheidung und zeigt vorher, was sie bewirkt.

Dies präzisiert FAHRPLANPERIODEN §4.6 („betroffene Kurszuordnungen als *stale* markieren"): Sie
werden nicht markiert, sondern gar nicht erst übertragen; die Vorschau übernimmt die Rolle der
Markierung.

### K5 — Der Haltestellen-Editor arbeitet auf Periode + Fahrplantyp

Nicht auf einem Datum. Das hält ihn im Versions-Denken des übrigen Admin-Bereichs.

**Dabei entsteht eine Mehrdeutigkeit, die gelöst werden muss:** Innerhalb einer Periode kann eine
Linie mehrere Versionen mit verschiedenen Gültigkeits-Intervallen haben. An einem Umsteigepunkt
stehen Linie 1 und Linie 13 dann womöglich auf verschiedenen Ständen, und „Periode + Fahrplantyp"
benennt keinen eindeutigen Fahrplan.

**Lösung: der Versionsstand.** Der Perioden-Zeitraum wird an allen Intervallgrenzen der beteiligten
Versionen zerschnitten; aufeinanderfolgende Abschnitte mit identischer Versionsmenge werden wieder
verschmolzen. Übrig bleiben die Abschnitte, in denen sich am beteiligten Fahrplan nichts ändert.
Der Editor bietet sie als dritten Selektor an, beschriftet mit ihrem Datumsbereich.

### K6 — Die Haltestelle ist eine eigene Ebene über dem Halt

**Entschieden 20.09.2026, nachdem die erste Fassung am Realbestand scheiterte.**

Der Editor verlangte ursprünglich, dass die endende und die beginnende Fahrt **denselben** Halt
berühren. Am Netz trägt das nicht:

| Befund | Zahl |
|---|---|
| Halte, an denen überhaupt eine Fahrt beginnt oder endet | 104 von 631 |
| davon **einseitig** — es endet nur oder beginnt nur | **64** |
| Anteil aller Fahrt-Endpunkte an einseitigen Halten | **54,6 %** |
| einseitige Halte mit namensgleichem Gegenstück | 49, in 14–99 m (Median 38 m) |

An „Herrenkrug" enden die Fahrten auf dem einen Bahnsteig und beginnen **72 m weiter** auf dem
anderen. Die Konsolidierung trennt die beiden zu Recht — sie verschmilzt nur bei ≤ 12 m und
gleichem Namen (FAHRPLANPERIODEN §5.1), und diese Schwelle ist am Bestand gemessen. Für die
Halt-*Identität* ist sie richtig; für den *Betrieb* ist sie zu eng.

Deshalb eine zweite Ebene: die **Haltestelle** (`stop_groups`) als Betriebspunkt. Die
Konsolidierung bleibt unangetastet.

- **Automatisch** über den normalisierten Namen. Das deckt den Regelfall ab — am Realbestand
  werden daraus 631 Halte → 315 Haltestellen, und die einseitigen Endstellen fallen von **64 auf 14**.
- **Von Hand** für den Rest. „Rothensee" und „Rothensee (Schleife)" sind dieselbe Haltestelle,
  heißen aber verschieden; ebenso „Buckau (Wasserwerk)" ↔ „… Wendeschl." und „ZOB" ↔ „ZOB Hst. 2".
- Eine Zuordnung von Hand ist **geschützt**: Der Namensabgleich nimmt sie nicht zurück, sonst wäre
  jede Pflege beim nächsten Import verloren.

**Keine reine Abstandsregel.** Sie wäre verlockend — 100 m deckten 55 der 64 Fälle — aber die
verbleibenden Abstände (201 m, 484 m, 612 m, 675 m) gehören zu **echten Betriebsfahrten**:
`Südring` → `Eiskellerplatz`, `Schleswiger Straße` → `Westerhüsen (Betriebshof)`. Die sollen als
Aus- und Einrücken festgehalten und nicht zu einer Haltestelle verschmolzen werden. Der Abstand
dient deshalb nur als **Vorschlag** im Editor (Umkreis 350 m), nie als Automatik.

---

## 3. Datenmodell

```
schedule_periods ─┐
                  ├─ line_versions ── consolidated_trips ─┬─ trip_links (Kette)
                  │                                       └─ course_trips ── courses
                  └─ courses

stop_groups (Haltestelle) ── stop_group_members ── consolidated_stops (Halt)
```

### 3.1 Die Haltestelle über dem Halt

**`stop_groups`** — der Betriebspunkt (K6).

| Spalte | Typ | Anmerkung |
|---|---|---|
| `name` | varchar | Anzeigename, editierbar |
| `name_key` | varchar NULL | Schlüssel der Automatik; `NULL` bei einer von Hand angelegten Gruppe — die sammelt nichts automatisch ein |
| `created_via` | varchar(8) | `auto` \| `manual` |
| `note` | varchar NULL | |

**`stop_group_members`** — `stop_group_id`, `consolidated_stop_id` (**unique**: ein Halt gehört
zu genau einer Haltestelle), `assigned_via` (`auto` \| `manual`).

`assigned_via = manual` ist keine Herkunftsnotiz, sondern eine **Sperre**: Der Namensabgleich
fasst solche Zeilen nicht an.

Die Automatik läuft beim Import-Abschluss (nach der Halt-Konsolidierung) und ist idempotent —
sie ordnet nur zu, was noch keiner Haltestelle angehört.

**`trip_links`** — eine Zeile ist **eine Entscheidung** und entspricht genau einer Zeile im Editor.

| Spalte | Typ | Anmerkung |
|---|---|---|
| `from_trip_id` | bigint NULL → `consolidated_trips` | **unique** |
| `to_trip_id` | bigint NULL → `consolidated_trips` | **unique** |
| `stop_id` | bigint → `consolidated_stops` | wo der Übergang stattfindet |
| `kind` | varchar(8) | `link` \| `start` \| `end` |
| `note` | varchar NULL | z. B. „Ausrücken Betriebshof" |

- `kind=link` — beide gesetzt: A endet, B beginnt.
- `kind=start` — nur `to_trip_id`: Ausrücken.
- `kind=end` — nur `from_trip_id`: Einrücken.

Die beiden Unique-Constraints **sind** die Fachregel: Ein Fahrzeug hat höchstens einen Nachfolger
und höchstens einen Vorgänger. `NULL` gilt in SQL als ungleich zu `NULL` — in PostgreSQL wie in
SQLite —, beliebig viele Fahrten dürfen also „ohne Vorgänger" sein. `kind` ist aus den NULL-Spalten
ableitbar und wird trotzdem gespeichert: Es macht Abfragen und Absicht lesbar.

**`courses`** — `period_id`, `day_type`, `number`, `note`. Index auf
`(period_id, day_type, number)`, bewusst **kein** Unique (K3).

**`course_trips`** — `course_id`, `consolidated_trip_id` (**unique**: eine Fahrt gehört zu
höchstens einem Umlauf). Die Zuweisung erfolgt immer für die **ganze Kette**, nie für eine einzelne
Fahrt.

Vergeben wird über die **Nummer**, nicht über eine ID: Wer sie am Fahrzeug abliest, tippt sie ein,
und ein im Strang noch unbekannter Umlauf entsteht dabei. Dieselbe Nummer zweimal zu setzen hängt
die zweite Kette an den bestehenden Umlauf, statt einen zweiten anzulegen.

**Ein Anschluss überträgt den Kurs von selbst.** Wer zwei Fahrten verknüpft, sagt damit: dasselbe
Fahrzeug. Dann kann es nur eine Kursnummer geben — trägt eine Seite bereits eine, gilt sie ab
diesem Moment für die ganze zusammengewachsene Kette. Das von Hand nachzutragen wäre Arbeit, die
aus der Verknüpfung schon folgt.

Tragen **beide** Seiten einen Kurs, und zwar verschiedene, wird nichts überschrieben: Welcher der
richtige ist, weiß nur der Pflegende. Der Widerspruch erscheint als Warnung `course_conflict`, die
Verknüpfung selbst bleibt bestehen — sie ist eine Aussage über das Fahrzeug, der Kurs nur sein
Etikett.

### Warum an `consolidated_trips.id`

Die naheliegende Alternative wäre die Fahrt-**Signatur**, weil sie Importe überlebt. Sie scheidet
aus: Sie ist je Version **nicht eindeutig** — im Realbestand tragen 494 Signatur/Typ-Paare mehrere
Fahrten (dieselbe Linie fährt dieselbe Zeitsequenz unter verschiedenen Service-Mustern).

Die ID trägt dagegen: `TripConsolidationService::fillVersion()` schreibt eine Version nur, solange
sie unvollständig ist, und steigt sonst aus. Eine fertig konsolidierte Version wird nie neu
geschrieben, ihre Fahrt-IDs bleiben stabil. Der Restfall — eine Version, deren Konsolidierung
abbrach — betrifft nur Versionen, an denen noch niemand gepflegt haben kann; `cascadeOnDelete`
räumt dort korrekt auf.

---

## 4. Regeln für einen Anschluss

**Abgewiesen (422):**
- verschiedene Perioden oder verschiedene Fahrplantypen
- die **Haltestelle** passt nicht: `from.last_stop_id` und `to.first_stop_id` müssen derselben
  `stop_group` angehören. Geprüft wird die Haltestelle, nicht der Halt — sonst wäre an über der
  Hälfte aller Fahrt-Endpunkte kein Anschluss möglich (K6)
- die Gültigkeits-Intervalle beider Versionen überschneiden sich an keinem Tag — der Anschluss
  könnte nie zustande kommen
- **Gattungswechsel**: Ein Fahrzeug wird nie vom Tram zum Bus. Der *Linien*wechsel ist dagegen
  erlaubt und der Normalfall — beides auseinanderzuhalten ist wesentlich, weil dieselbe Linie
  beides sein kann (N2 liegt zeitweise als Tram und als Bus vor, Schienenersatzverkehr)
- Selbstverknüpfung oder Zyklus
- negative Wendezeit

**Abgewiesen (409):** eine der beiden Fahrten trägt bereits eine Entscheidung.

**Nur Hinweis, kein Fehler:**
- kurze Wendezeit (Schwelle `COURSE_MIN_TURNAROUND_MINUTES`, Vorgabe 3 Minuten)
- Linienwechsel — ausdrücklich erlaubt (K1)

**Die Wendezeit wird entlang des Betriebstags gerechnet, nicht nach der Uhr.** Das ist keine
Feinheit: Der gtfs.de-Feed notiert **keine** Zeiten jenseits 24:00, eine Nachtfahrt um Viertel nach
zwölf steht dort als `00:19:00`. Nach der Uhr gerechnet wäre die Wendezeit von `23:20` auf `00:19`
negativ — **jeder** Nachtlinien-Anschluss über Mitternacht wäre abgewiesen worden. Maßgeblich ist
der Sortierschlüssel aus dem `OperatingDayResolver` (FAHRPLANPERIODEN §10); jede Seite trägt dabei
die Grenze ihrer eigenen Linie.

Nie lexikalisch vergleichen: Die GTFS-Spezifikation erlaubt `7:00:00` neben `07:00:00`, und als
String ist `"7:00:00" > "23:50:00"`.

---

## 5. Offene Punkte

- **Nachtlinien-Betriebstag** — FAHRPLANPERIODEN §8: N1 fährt montags anders als Di–Fr, weil die
  Nacht von Sonntag auf Montag eine Sonntagsnacht ist. Der `mo_fr`-Strang fasst für Nachtlinien
  zwei Fahrpläne zusammen; der Editor erbt diese Unschärfe.
- **Zufluss aus Sichtungen** (I-04/I-05) — wie eine beobachtete Kursnummer auf eine gepflegte Kette
  trifft und was bei Widerspruch gilt, ist noch nicht festgelegt.
- **Umläufe über Mitternacht hinaus** — eine Kette, die um 25:30 endet, gehört zum Betriebstag des
  Vortags. Das Modell trägt es (GTFS-Wallclock bleibt erhalten); ob die Anzeige es deutlich genug
  macht, zeigt die Pflege.

---

## 6. Bezug

- ROADMAP **I-14**; schließt I-13 (D) „Kurs je Fahrt anzeigen".
- Versionen und Perioden: [`FAHRPLANPERIODEN.md`](FAHRPLANPERIODEN.md) §4, §5.4, §6.
- Sichtungs-Anbindung: [`INTEGRATION_MDKURSTRACKER.md`](INTEGRATION_MDKURSTRACKER.md) §4,
  [`MDKURSTRACKER_REQUIREMENTS.md`](MDKURSTRACKER_REQUIREMENTS.md) (Annahme E2 durch K3 korrigiert).
