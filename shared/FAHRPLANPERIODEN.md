# Konzept: Fahrplanperioden & Fahrplantypen

> **Stand: 2026-06-26.** Konzept für die automatische Erkennung von Fahrplanperioden aus dem
> GTFS-Datenbestand und die Klassifikation der Fahrplantypen. Wiedereinstiegspunkt für den
> ROADMAP-I-12-Bereich „Fahrplanperioden-Erkennung". Gehört zur Admin-Schaltzentrale.

---

## TL;DR

- **Fahrplanperiode** = netzweiter, zusammenhängender Zeitraum mit stabilem Fahrplan-Inhalt. Eine neue
  Periode beginnt, wenn sich der Inhalt ändert (erkannt über einen **Timetable-Fingerprint**, nicht über calendar-Fenster).
- **Vier Fahrplantypen** innerhalb einer Periode: **Mo-Fr normal**, **Mo-Fr Ferien**, **Sa**, **So + Feiertage**.
- **Feiertage**: nicht persistiert — aus einer **Code-Regel (Sachsen-Anhalt)** je Jahr berechnet.
- **Ferienzeiten**: persistiert, vom Admin gepflegt (CRUD).
- **Granularität: netzweit** eine Periode. **Historie**: MVP nur Metadaten, Datenmodell aber history-fähig.
- **Viewer** (später): zeigt zunächst nur die aktuelle Periode; Umschalten kommt später.

---

## 1. Befund (warum das nötig ist)

Der gtfs.de-„latest"-Feed (`nv_free`) ist ein **rollierendes ~2-Wochen-Fenster**:
- `calendar`-Enddaten sind **Feed-Horizonte**, keine echten Periodengrenzen.
- `feed_info`-Gültigkeit ist leer; Wochenmuster fein zersplittert.
- Jeder Import **ersetzt** die GTFS-Tabellen (Upsert) → kein historischer Datenbestand.

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

## 4. Perioden-Erkennung (netzweit, beim Import)

### 4.1 Timetable-Fingerprint
- Pro **Fahrplantyp** einen Fingerprint: Hash über die Menge der **period-freien Trip-Signaturen**
  (`route_short_name` + geordnete `HH:MM`-Sequenz) der an einem **repräsentativen Tag** dieses Typs aktiven Trips.
  (Aktive Trips je Datum: `calendar`-Wochenmuster + `calendar_dates`-Ausnahmen, vgl. `TripFilterService`.)
- **Perioden-Fingerprint** = Kombination der bis zu vier Typ-Fingerprints.
- Deckt das aktuelle Datenfenster einen Typ nicht ab (z. B. kein Ferientag im Fenster), bleibt dessen
  Fingerprint vorerst `null` und wird ergänzt, sobald ein Import ihn abdeckt.

### 4.2 Ablauf
Nach jedem erfolgreichen Import:
1. Perioden-Fingerprint berechnen.
2. Mit der aktuell **offenen** Periode vergleichen:
   - **gleich** → Periode verlängern (`valid_to` auf den neuen Feed-Horizont).
   - **anders** → aktuelle Periode schließen, neue öffnen (`valid_from` = Beginn der Änderung).
3. Bei Periodenwechsel: Signal an die Matching-Schicht — betroffene Kurszuordnungen als *stale / neu zu bestätigen*
   markieren (siehe `INTEGRATION_MDKURSTRACKER.md` §4.2).

---

## 5. Datenmodell

| Tabelle | Zweck | Persistenz |
|---|---|---|
| *(keine)* `holidays` | Feiertage | **nein** — `HolidayService` berechnet sie (Region Sachsen-Anhalt) |
| `school_holidays` | Ferienzeiten | `id, name, start_date, end_date` — Admin-CRUD |
| `schedule_periods` | erkannte Perioden | `id, valid_from, valid_to (nullable=offen), fingerprint, type_fingerprints (jsonb), label, detected_at, first_import_run_id, last_import_run_id` |

`FahrplanTyp` als PHP-Enum (`MoFrNormal`, `MoFrFerien`, `Sa`, `SoFeiertag`).

**History-fähig:** Geschlossene Perioden bleiben als Metadaten erhalten. Snapshots der Trip-Daten je Periode
(für die Anzeige *vergangener* Perioden) sind eine spätere Ausbaustufe — nicht im MVP.

---

## 6. MVP-Bauplan

1. **Config + Klassifikation:** `school_holidays`-Migration + Model + Admin-CRUD; `HolidayService`
   (Sachsen-Anhalt); `FahrplanTyp`-Enum + `FahrplanTypClassifier`; Tests (feste + oster-relative Feiertage,
   Ferien-Grenzfall, Sa/So). Admin read-only-Ansicht „Feiertage".
2. **Perioden-Erkennung:** `schedule_periods`-Migration + Model; `SchedulePeriodService`
   (Fingerprint je Typ, Vergleich, Periode öffnen/verlängern/schließen); Einklinken in den Import-Lebenszyklus
   (`finish`); Tests.
3. **Admin-Ansichten:** „Fahrplanperioden" (aktuelle Periode + Historie, je Typ); „Ferienzeiten" (CRUD).
4. **Später:** Snapshots für Historien-Anzeige; Viewer-Scoping auf Periode.

---

## 7. Offene Punkte / Caveats
- Rollierendes ~2-Wochen-Fenster deckt evtl. nicht alle 4 Typen gleichzeitig ab → Typ-Fingerprints füllen sich
  über mehrere Imports.
- Repräsentativer Tag je Typ: Auswahl-Strategie (erster passender Tag im Fenster) festzurren bei Implementierung.
- Region fix: **Sachsen-Anhalt** (Feiertagsregel). Andere Regionen erst bei Bedarf.

## 8. Bezug
- ROADMAP **I-12 Bereich (e)** „Fahrplanperioden-Erkennung"; Admin-Schaltzentrale (SPEC §10).
- Periodenwechsel-Signal → Re-Match, siehe `INTEGRATION_MDKURSTRACKER.md` §4.2.
