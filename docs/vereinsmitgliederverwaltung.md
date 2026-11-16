# Vereinsmitgliederverwaltung – Entwicklungsentwurf

**Status:** Konzept / noch keine installierbare Erweiterung. Umsetzung ausschließlich im Branch `feature/vereinsmitgliederverwaltung`. Die produktive Nextcloud-Instanz bleibt unverändert.

## Ziel

Eine eigenständige Mitgliederverwaltungsseite innerhalb von `profile_fields` mit einer durchsuchbaren, sortierbaren Tabelle, konfigurierbaren Spalten, Abteilungsfilter anhand von Nextcloud-Gruppen und direkter Einzelzellenbearbeitung. Keine zweite Mitgliederdatenbank und keine neuen Abteilungsprofilfelder.

## Instanzabhängige Konfiguration

| Einstellung | Testcamp (Beispiel) | Datacamp (Beispiel) |
|---|---|---|
| Basis-Mitgliedergruppe | `LHReV` | `LHR` |
| Abteilungsgruppen | `20NY`, `50nY` | Durch Admin zu bestimmen |
| Bearbeitungsgruppe | `Vorstand` | Durch Admin zu bestimmen |
| Administrator | Nextcloud-Administrator | Nextcloud-Administrator |

Gruppen-IDs werden **nicht fest im Code hinterlegt**, sondern über die Administrationsseite eingestellt. Die abteilungsbezogene Auswahl ist eine Schnittmenge aus Basisgruppen- und Abteilungsmitgliedschaft; Mehrfachzugehörigkeiten werden unterstützt.

## Zugriffsmodell

- Alle Endpunkte prüfen serverseitig Login und explizit konfiguriertes Lese-/Schreibrecht; ausgeblendete UI-Elemente sind kein Ersatz.
- Bis zur initialen Einrichtung sind Verwaltungszugriffe nur für Nextcloud-Administratoren zugelassen (fail closed).
- Die Admin-Konfiguration ist ausschließlich für Nextcloud-Administratoren editierbar; die Gruppe `Vorstand` erhält keine globalen Nextcloud-Administratorrechte.
- Eine Mitgliederliste umfasst **ausschließlich** Accounts der konfigurierten Basisgruppe. Die Gruppenmitgliedschaft wird bei **jedem** Lese- und Schreibzugriff erneut geprüft.
- Nur explizit für die Mitgliederverwaltung freigegebene Felddefinitionen und Werte dürfen über Verwaltungsendpunkte gelesen/geändert werden. Bestehende Profil-Sichtbarkeitsregeln dürfen nicht pauschal aufgeweicht werden.
- Die Mitgliederverwaltung ändert die Sichtbarkeit eines bestehenden Feldwerts nicht. Beim Aufruf von `FieldValueService::upsert()` ist dessen bisheriges `currentVisibility` ausdrücklich weiterzureichen; bei neuen Werten wird die Feldvorgabe verwendet.
- Vorhandene Validierung, Änderungs-Events und Änderungsmetadaten von `FieldValueService` werden wiederverwendet. Kein direkter SQL-Schreibzugriff.
- Anti-CSRF-Schutz, Datensparsamkeit sowie Prüfungen auf unzulässige Gruppen- und Feld-IDs sind verpflichtend.

## Oberfläche (MVP)

1. Eigene App-Seite „Mitgliederverwaltung“ (keine Änderung der Nextcloud-Standard-Benutzerverwaltung).
2. Alle Mitglieder der Basisgruppe untereinander; Suche, Sortierung, Pagination.
3. Abteilungsfilter auf Basis von Nextcloud-Gruppenmitgliedschaften.
4. Konfigurierbare Spalten aus freigegebenen Profilfeldern; ausgeblendete Spalten werden nicht unnötig vom Server abgerufen.
5. Inline-Bearbeitung mit feldtypabhängiger Validierung und explizitem Speichern je Zelle; lesbare Fehleranzeige und Rücknahme nicht gespeicherter Änderungen.
6. Administrationsbereich zur Auswahl der Basisgruppe, der Filtergruppen, der Lese-/Schreibgruppen und der einzeln freigegebenen Profilfelder.

## Technische Bausteine

- Separater `MemberAccessService` (gruppen- und feldspezifische serverseitige Rechteprüfung).
- Eigener `MemberDirectoryController` bzw. OCS-Endpunkt für die Tabelle, ohne bestehende Admin-API zu öffnen.
- Eigene Einstellungen für die Vereinsverwaltung, unabhängig von den bestehenden Profilfeld-Definitionen.
- Wiederverwendung von `FieldDefinitionService` und `FieldValueService`; keine zusätzliche Tabelle für Mitgliederdaten.
- Tests: unberechtigter Zugriff, fehlende/falsche Basisgruppe, Mitglieder anderer Gruppen, Mehrfachzugehörigkeit, nicht freigegebene Felder, private Werte, Sichtbarkeit unverändert nach Update, CSRF, Eingabevalidierung und Race Conditions.

## Versionsabgleich vor Deployment

Der Entwicklungsbranch basiert auf dem offiziellen Upstream-Branch **`stable35`** (Stand `df9f85d6b3cc`), dessen `info.xml` Nextcloud 35 unterstützt. Der Fork-Branch `main` ist für Nextcloud 36 vorgesehen. Vor der ersten Testinstallation lokalen Code-/Commitstand mit `stable35` vergleichen und die Erweiterung unter Nextcloud 35 testen. Versionsgrenzen in `info.xml` nicht bloß zur Umgehung einer Kompatibilitätsprüfung ändern.


## Implementierungsstand (15.11.2026)

Die Backend-Bausteine liegen im Entwicklungsbranch, sind **noch nicht getestet, deployed oder für Produktivbetrieb freigegeben**:

- `MemberDirectoryConfig`: standardmäßig deaktivierte Konfiguration, Gruppen- und aktive Feldschlüssel-Whitelist
- `MemberDirectoryAccessService`: Lese- und Schreibgruppen, Mitgliedschaft in der Basisgruppe
- `MemberDirectorySettingsApiController`: Admin-only GET/PUT der Konfiguration
- `MemberDirectoryService`: gruppenbasierte Mitgliederliste, Suche und Pagination; bestehende `FieldValueService`-Validierung; Sichtbarkeitserhalt
- `MemberDirectoryApiController`: lesende und schreibende OCS-Endpunkte mit serverseitigen Prüfungen
- `MemberDirectoryConflictException`: Meldung veralteter Bearbeitungsstände
- `MemberDirectoryApiControllerTest`: erste Unit-Tests, Ausführung noch offen

Geplante OCS-Routen:

| Methode | Route (relativ zur OCS-App-API) | Berechtigung |
|---|---|---|
| GET | `/api/v1/member-directory/settings` | Nextcloud-Admin |
| PUT | `/api/v1/member-directory/settings` | Nextcloud-Admin |
| GET | `/api/v1/member-directory/members` | konfigurierte Lese-/Schreibgruppe oder Admin |
| PUT | `/api/v1/member-directory/members/{userUid}/fields/{fieldDefinitionId}` | konfigurierte Schreibgruppe oder Admin |

Die Übersicht erlaubt bis 100 Einträge pro Seite. `department`, `search`, `limit` und `offset` sind Suchparameter. Jeder Lesezugriff liefert nur explizit für das Verzeichnis freigegebene Profilfelder.

**Vor Aktivierung noch zu erledigen:** tatsächliche PHPUnit-/PHP-Lint-Ausführung, API-/Berechtigungsintegrationstests, Frontend und Navigation, Admin-Einstellungsoberfläche, Paralleländerungs-Absicherung im Schreibpfad (die vorhandene ETag-Prüfung ist noch nicht atomar), Sicherung und Testdeployment auf Testcamp. Keine Mitgliederverwaltung auf Basis des derzeitigen Branches installieren.

## Anzeigename und Abteilungszuordnung korrigieren

- Berechtigte Bearbeiter können den Nextcloud-Anzeigenamen eines Mitglieds ändern, sofern dessen Benutzer-Backend diese Änderung unterstützt. Nextcloud-Administrationskonten sind nur für vollwertige Nextcloud-Administratoren bearbeitbar.
- Abteilungswechsel erfordern zusätzlich eine Nextcloud-Administrationsrolle (Administration oder delegierte Benutzerverwaltung). Die Vereinsbasisgruppe, die Lese- und Schreibgruppen der App sowie `admin` sind für Abteilungswechsel gesperrt, auch wenn sie als Abteilung eingetragen sind. Gruppen müssen das Hinzufügen und Entfernen unterstützen.
- Beim Wechsel wird die gewählte Abteilung zuerst hinzugefügt. Danach werden bisherige Abteilungsmitgliedschaften entfernt. Weitere Gruppenmitgliedschaften bleiben bestehen. Ist eine bestehende Abteilung schreibgeschützt, wird der Wechsel abgelehnt.
- Die Nextcloud-Benutzer-ID wird angezeigt und ist fest mit dem Konto verknüpft. Die öffentliche Nextcloud-35-API bietet dafür keine Umbenennung an.
