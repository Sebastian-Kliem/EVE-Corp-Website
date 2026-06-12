# WH-Toolbox (Eve Online Corp Tools)

Dieses Projekt ist eine Sammlung von Werkzeugen für eine Eve Online Corp.

## Technologie-Stack
- **Backend:** Symfony 7.3 (PHP 8.2+)
- **Datenbank:** Doctrine ORM (MySQL/MariaDB im DDEV-Container)
- **Frontend:** 
  - Symfony AssetMapper (kein Webpack Encore)
  - Symfony UX Turbo & Stimulus
  - CSS: Natives CSS, alles in `assets/styles/app.css` (keine Inline-Styles)
  - React für dynamische Komponenten (React-TypeScript Insellösung)

---

## DDEV-Umgebung & CLI-Befehle
Dieses Projekt wird in einer **DDEV-Umgebung** ausgeführt. Alle Entwicklungs- und Verwaltungsbefehle müssen im DDEV-Kontext ausgeführt werden:

### Backend-Befehle (PHP & Symfony)
- **Cache leeren:** `ddev php bin/console cache:clear`
- **Datenbank-Migrationen erstellen:** `ddev php bin/console make:migration`
- **Datenbank-Migrationen ausführen:** `ddev php bin/console doctrine:migrations:migrate`
- **Benutzer erstellen:** `ddev php bin/console app:create-user <email> <password> [<role>] [<displayName>]`
- **Benutzer-Rolle ändern:** `ddev php bin/console app:promote-user <email> <role>`

### Frontend-Befehle (React & Bundling)
Das React-Projekt liegt isoliert unter `/react` und wird über `esbuild` nach `assets/react.js` kompiliert.
- **Build (einmalig für Produktion/Test):** `ddev npm run --prefix react build`
- **Watch (während der Entwicklung):** `ddev npm run --prefix react watch`

---

## Projektstruktur & Module

### 1. Controller-Struktur (`src/Controller/`)
Die Controller sind thematisch in Unterordner sortiert, um das Hauptverzeichnis sauber zu halten:
- **`Admin/`**: Administrationswerkzeuge
  - `AdminCorpAssetsController.php`: Sichtbarkeitseinstellungen für Corp-Assets.
  - `AdminCronController.php`: Verwaltung und manuelles Triggeren von Cronjobs.
  - `UserAdminController.php`: Benutzerverwaltung und Rollenzuweisung durch CEOs.
- **`Api/`**: JSON-Schnittstellen
  - `ApiController.php`: Authentifizierung (JWT) und SDE-Datenbank-Suchen.
  - `EveImageProxyController.php`: DSGVO-konformer Proxy für Bilder von EVE-Servern.
- **`Auth/`**: Authentifizierung & SSO
  - `EveSsoController.php`: OAuth2/SSO-Login mit EVE Online.
  - `RegistrationController.php`: Benutzerregistrierung über E-Mail und Passwort.
  - `SecurityController.php`: Login/Logout für Standard-Zugänge.
- **`Profile/`**: Benutzerkonten & Profile
  - `EveAccountController.php`: Verwaltung verknüpfter EVE-Accounts und Charaktere.
  - `ProfileController.php`: Persönliches Profil und Passwortänderung.
  - `ProfilePiController.php`: Datenlieferung und Steuerung für das Planetary Industry Dashboard.
- **`Tool/`**: Corp-Module
  - `LinkCollectionController.php`: Strukturierte Lesezeichen für die Corp.
  - `OrderListController.php`: Einkauf- (Buy) und Verkauf- (Sell) Aufträge mit Jita-Preisen.
  - `SuggestionController.php`: Vorschlagsbox für Corp-Mitglieder.
- **Wurzelverzeichnis:**
  - `HomeController.php`: Haupteinstiegspunkt (`/`).

### 2. React-Komponenten (`react/src/components/`)
Ebenfalls in thematische Ordner strukturiert:
- **`Admin/`**: `CorpAssetsVisibilityManager.tsx` (Einstellen, welche Gruppen welche Corp-Assets sehen dürfen).
- **`Assets/`**:
  - `AssetsOverview.tsx`: Gesamt-Assets über alle Charaktere mit Wallet-Saldo, Container-Schachtelung und Live-Filtern.
  - `CharacterAssets.tsx`: Inventar eines einzelnen Charakters mit Suchfunktion.
  - `CorpAssetsOverview.tsx`: Übersicht der Corp-Assets an verschiedenen Stationen.
- **`Form/`**: Autovervollständigungen
  - `ItemAutocomplete.tsx`: Suche in der Static Data Export (SDE) Datenbank für EVE-Gegenstände.
  - `UserAutocomplete.tsx`: Benutzer-Auswahlfelder für Zuweisungen.
- **`Profile/`**:
  - `PIOverview.tsx`: Interaktives Planetary Industry (PI) Dashboard.

---

## Konventionen
- **Sprache:** Deutsche Kommunikation im Gemini CLI, aber englische Code-Kommentare und Commit-Messages.
- **Standard Symfony/Doctrine Patterns** verwenden.
- **Kein Inline-CSS:** Stile dürfen nicht per `style="..."`-Attribut oder `<style>`-Tag inline in Twig-Templates geschrieben werden. Alle Styles müssen zentral und sauber in `assets/styles/app.css` definiert werden.
- **React für dynamische Oberflächen:** Für komplexe, interaktive oder hochdynamische Komponenten (z. B. verschachtelte Bäume mit Filterung, Echtzeit-Kalkulatoren, etc.) sind React-TypeScript Komponenten ("Insellösungen") zu implementieren. Reine Twig-Templates sollten für statisches/semistatisches HTML genutzt und nicht mit komplexem Vanilla-JavaScript überladen werden.
- **Natives CSS (kein Bootstrap/Tailwind/Bulma):** Dieses Projekt nutzt kein Bootstrap, Bulma oder TailwindCSS. Das gesamte Design basiert auf einem maßgeschneiderten, nativen CSS-System in `assets/styles/app.css`. Altlasten mit Bulma-Klassen in Legacy-Templates müssen bei der Bearbeitung durch natives CSS ersetzt werden.
- **Datenschutz & DSGVO:** Externe Ressourcen (Bilder, Schriften, Skripte) dürfen nicht direkt über CDNs oder Drittseiten eingebunden werden. Sie müssen stattdessen heruntergeladen und lokal aus dem Projekt ausgeliefert werden, um DSGVO-Konformität zu gewährleisten (keine IP-Weitergabe an Dritte).

---

## React-TypeScript Insellösungen (Islands)

### Verwendung in Twig-Templates
Um eine React-Komponente in einem Twig-Template einzubetten, erstelle ein Div mit den Attributen `data-react-component` und optional `data-react-props` (als HTML-escaped JSON-String):
```twig
<div data-react-component="ComponentName" data-react-props="{{ propsArray|json_encode|e('html_attr') }}"></div>
```

### Komponenten-Registrierung
Alle React-Komponenten, die in Twig-Templates nutzbar sein sollen, müssen in [react/src/index.tsx](file:///home/sebastian/develop/WH-Toolbox/react/src/index.tsx) im `components` Mapping registriert sein:
- **ItemAutocomplete:** Autovervollständigung für Gegenstände (unterstützt SDE-Daten).
- **UserAutocomplete:** Autovervollständigung für Benutzer im Adminbereich.
- **CharacterAssets:** Dynamisches Inventar eines EVE-Charakters.
- **AssetsOverview:** Gesamt-Inventar aller Charaktere mit Wallet-Saldo, Stationen-Gruppierung und Live-Filterung.
- **CorpAssetsOverview:** Übersicht aller Corporation-Assets.
- **CorpAssetsVisibilityManager:** Verwaltung der Asset-Sichtbarkeiten.
- **PIOverview:** Planetary Industry Dashboard zur Überwachung von Produktionsketten und Planetendetails.

---

## Zuletzt implementierte Features & aktueller Stand

### Planetary Industry (PI) Dashboard
- **Backend:** [ProfilePiController.php](file:///home/sebastian/develop/WH-Toolbox/src/Controller/Profile/ProfilePiController.php) importiert ESI-Daten über Planeten, Pins und Extraktoren. Über `/profile/pi/data` werden diese als JSON geliefert.
- **Frontend:** Die Komponente [PIOverview.tsx](file:///home/sebastian/develop/WH-Toolbox/react/src/components/Profile/PIOverview.tsx) zeigt:
  - Eine konsolidierte Übersicht der Charaktere, Planeten und der installierten Fabriken/Lager.
  - Warnungen, wenn Extraktorköpfe inaktiv sind (Idle-Warnungen) oder die Route/Produktion unterbrochen ist.
  - Verbleibende Zyklenzeiten der Extraktionsprogramme.
