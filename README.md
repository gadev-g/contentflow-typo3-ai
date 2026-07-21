# ContentFlow AI for TYPO3

Die offizielle TYPO3-Integration für [ContentFlow AI](https://contentflow-ai.com). Die Extension übersetzt TYPO3-Seiten, Content-Elemente und Datensätze und erzeugt mit Asset Intelligence zugängliche Bildmetadaten. Alle Änderungen werden vor dem Speichern als Vorschau angezeigt.

## Funktionen

- Übersetzung von `pages`, `tt_content` und frei konfigurierten Textfeldern
- Übersetzung von `sys_file_reference` und `sys_file_metadata`
- Bildanalyse für Titel, Alt-Text, Beschreibung und Keywords
- TYPO3-Datensatz- und Asset-Browser
- Automatische Erkennung der TYPO3-Sprach-ID
- Auswahl der im ContentFlow-Projekt freigegebenen KI-Provider
- Review-first-Workflow: keine ungeprüften Änderungen
- Bereinigtes Debug-Fenster für Request und Response
- Sichere API-Authentifizierung mit projektbezogenen Keys

## Voraussetzungen

- TYPO3 CMS 13.4 LTS
- PHP 8.2 oder neuer
- Composer-basierte TYPO3-Installation
- ContentFlow-Konto, Projekt und API-Key
- Erreichbare ContentFlow API

## Installation

### Installation aus einem Release

Lade das ZIP des gewünschten [GitHub Releases](https://github.com/gadev-g/contentflow-typo3-ai/releases) herunter und entpacke es in ein lokales Composer-Paketverzeichnis, beispielsweise `packages/contentflow_translation`. Binde das Verzeichnis anschließend ein:

```bash
composer config repositories.contentflow path packages/contentflow_translation
composer require contentflow/typo3-translation:@dev
vendor/bin/typo3 cache:flush
```

Mit DDEV:

```bash
ddev composer config repositories.contentflow path packages/contentflow_translation
ddev composer require contentflow/typo3-translation:@dev
ddev typo3 cache:flush
```

### Direkt aus Git installieren

```bash
composer config repositories.contentflow vcs https://github.com/gadev-g/contentflow-typo3-ai.git
composer require contentflow/typo3-translation:dev-main
vendor/bin/typo3 cache:flush
```

Für produktive Installationen sollte ein versioniertes Release statt `dev-main` verwendet werden:

```bash
composer require contentflow/typo3-translation:^0.1
```

Nach erfolgreicher Installation erscheint im TYPO3-Backend unter **Web** das Modul **ContentFlow AI**.

## ContentFlow-Projekt und API-Key

1. Im ContentFlow Control Panel anmelden.
2. Unter **Projects** ein Projekt für die TYPO3-Installation erstellen.
3. Das Projekt auswählen und **API Keys** öffnen.
4. Einen neuen Projekt-Key generieren.
5. Den vollständigen Key sofort sicher kopieren. Er wird nur einmal angezeigt.

Verwende pro Kundeninstallation einen eigenen Projekt-Key. Speichere Keys niemals in Git. Ein veröffentlichter oder verlorener Schlüssel sollte im Control Panel gelöscht und ersetzt werden.

## TYPO3 konfigurieren

Öffne im TYPO3-Backend:

**Admin Tools → Settings → Extension Configuration → contentflow_translation**

| Einstellung | Beschreibung | Beispiel |
|---|---|---|
| `apiUrl` | Basis-URL der ContentFlow API | `https://api.example.com` |
| `apiKey` | Projektbezogener ContentFlow API-Key | `cf_live_…` |
| `debugMode` | Bereinigte Debug-Ausgabe aktivieren | `0` |

### Konfiguration über Umgebungsvariablen

Für Deployments sollten Geheimnisse über Umgebungsvariablen gesetzt und in `config/system/additional.php` übernommen werden:

```php
<?php

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['contentflow_translation'] = [
    'apiUrl' => getenv('CONTENTFLOW_API_URL') ?: '',
    'apiKey' => getenv('CONTENTFLOW_API_KEY') ?: '',
    'debugMode' => getenv('CONTENTFLOW_DEBUG') ?: '0',
];
```

```dotenv
CONTENTFLOW_API_URL=https://api.example.com
CONTENTFLOW_API_KEY=cf_live_REPLACE_WITH_PROJECT_KEY
CONTENTFLOW_DEBUG=0
```

Bei einer lokalen ContentFlow-Installation auf dem Host lautet die URL aus DDEV oder Docker normalerweise:

```dotenv
CONTENTFLOW_API_URL=http://host.docker.internal:8081
```

Bei einer direkt auf dem Host laufenden TYPO3-Installation kann stattdessen `http://localhost:8081` verwendet werden.

## KI-Provider für TYPO3 freigeben

Die Extension zeigt nur Provider an, die im zugehörigen ContentFlow-Projekt aktiviert wurden:

1. Im ContentFlow Control Panel **Providers** öffnen.
2. Das richtige TYPO3-Projekt auswählen.
3. Optional einen eigenen Provider-Key hinterlegen.
4. **Available to TYPO3** aktivieren.
5. Änderungen speichern.

OpenAI und Anthropic unterstützen Übersetzung und Bildanalyse. Ein passend konfigurierter Ollama-Dienst kann lokale Modelle bereitstellen. Für Bildanalyse mit Ollama wird ein Vision-Modell benötigt.

## Inhalte übersetzen

1. **Web → ContentFlow AI** öffnen.
2. **Translation** auswählen.
3. Seite, Content-Element oder unterstützten Datensatz über den TYPO3-Datensatz-Browser wählen.
4. Quell- und Zielsprache prüfen. Die TYPO3-Sprach-ID wird aus der Site-Konfiguration automatisch ermittelt.
5. Optional unter **Advanced AI settings** Provider und Modell auswählen.
6. **Create translation preview** anklicken.
7. Original und Übersetzung feldweise prüfen.
8. Mit **Approve and save translation** freigeben.

Existiert die verbundene Lokalisierung bereits, wird sie aktualisiert. Andernfalls erstellt TYPO3 den lokalisierten Datensatz über den `DataHandler`.

## Asset Intelligence verwenden

1. **Web → ContentFlow AI** öffnen.
2. **Asset Intelligence** auswählen.
3. Ein Bild über den TYPO3-Asset-Browser auswählen.
4. Metadatensprache und optional einen fachlichen Kontext angeben.
5. Provider wählen und **Analyze image** anklicken.
6. Titel, Alt-Text, Beschreibung und Keywords prüfen.
7. Vorschläge freigeben und speichern.

Die Originaldatei wird nicht verändert. ContentFlow schreibt ausschließlich die freigegebenen Werte in `sys_file_metadata`.

## SEO Intelligence verwenden

SEO Intelligence ist im Starter-Tarif und höher verfügbar; der Free-Tarif wird serverseitig gesperrt.

1. **Web → ContentFlow → SEO Intelligence** öffnen.
2. Die gewünschte TYPO3-Seite im Seitenbrowser auswählen.
3. Ausgabesprache und optional Provider sowie Modell festlegen.
4. Mit **Create SEO preview** die Seite einschließlich ihrer Inhaltselemente analysieren.
5. SEO-Titel, Meta-Beschreibung, Fokus-Keywords und Schema.org JSON-LD prüfen.
6. Die Vorschläge mit **Approve and save** übernehmen.

ContentFlow liest dabei auch Textfelder eigener CTypes und Content Blocks. Das geprüfte Schema.org JSON-LD wird auf der Seite gespeichert und automatisch als `application/ld+json` im HTML-Kopf ausgegeben.

## Debug-Modus

Setze `debugMode` vorübergehend auf `1`, um in der Vorschau den bereinigten Request, den HTTP-Status und die API-Antwort zu sehen. API-Keys und vollständige Base64-Bilddaten werden niemals angezeigt.

In Produktion sollte der Debug-Modus deaktiviert bleiben:

```dotenv
CONTENTFLOW_DEBUG=0
```

## Häufige Fehler

### API-Key ist nicht konfiguriert

Prüfe `apiKey` in der Extension Configuration und leere danach den TYPO3-Cache:

```bash
vendor/bin/typo3 cache:flush
```

### ContentFlow ist aus DDEV nicht erreichbar

Verwende `http://host.docker.internal:8081` statt `localhost`. `localhost` innerhalb des TYPO3-Containers bezeichnet den Container selbst.

### Keine Provider werden angezeigt

Aktiviere beim richtigen Projekt mindestens einen vollständig konfigurierten Provider mit **Available to TYPO3**.

### Bildanalyse schlägt mit Ollama fehl

Prüfe, ob der Ollama-Server erreichbar ist und ein Vision-Modell installiert wurde. Ein reines Textmodell kann keine Bilder analysieren.

## Sicherheit

- Projekt-API-Keys werden über den Header `X-API-Key` übertragen.
- TYPO3-Berechtigungen und TCA werden beim Schreiben über den `DataHandler` berücksichtigt.
- Vorschauen liegen höchstens eine Stunde in der authentifizierten Backend-Session.
- API-Keys und vollständige Bilddaten erscheinen nicht im Debug-Panel.
- Produktionssysteme sollten ausschließlich HTTPS verwenden.
- Pro Projekt und Kundeninstallation sollte ein eigener widerrufbarer Key verwendet werden.

## Entwicklung

```bash
composer install
composer validate --strict
```

Lokale Einbindung aus diesem Repository:

```bash
composer config repositories.contentflow path /absolute/path/to/contentflow-typo3-ai
composer require contentflow/typo3-translation:@dev
```

## Releases

Jeder gemergte Pull Request nach `main` erzeugt automatisch ein neues Patch-Release:

1. Die Version in `ext_emconf.php` wird erhöht.
2. Der Versions-Commit wird nach `main` geschrieben.
3. Ein Git-Tag `vX.Y.Z` wird erstellt.
4. GitHub veröffentlicht ein Release mit einem installierbaren `contentflow_translation-X.Y.Z.zip`.

## Lizenz

Proprietäre Software. Alle Rechte vorbehalten, ContentFlow AI.
