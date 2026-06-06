# WH-Toolbox 🌌

> **Eve Online Corp Tools** – Eine Sammlung von nützlichen Werkzeugen und Systemen für unsere Eve Online Corporation, basierend auf Symfony und Doctrine, kombiniert mit einer modernen React+TypeScript Insellösung für dynamische UI-Komponenten.

---

## 🎨 Frontend & Design

### CSS-Styling (Vanilla CSS)
In diesem Projekt verwenden wir **kein** externes CSS-Framework wie Bootstrap, Bulma oder TailwindCSS. Stattdessen basiert das gesamte Design auf einem maßgeschneiderten, nativen CSS-System.
- **Zentrale Style-Datei:** Das gesamte Styling ist in `assets/styles/app.css` definiert.
- **Design-System (EVE Online Sci-Fi Dark Theme):** In `app.css` werden globale CSS-Variablen (`:root`) für Farben, Hintergründe, Rahmen und Schatten verwendet (z. B. `--theme-bg`, `--theme-primary`, `--theme-card-bg`). Das Styling zeichnet sich durch moderne Glassmorphism-Effekte und subtile Sci-Fi-Elemente aus.
- **Layout-Hilfsklassen:** Es wurden einfache Layout-Hilfsklassen auf Basis von nativem Flexbox und Grid implementiert (z. B. `.container`, `.columns`, `.column`, `.level`), um die UI sauber zu strukturieren.
- **Theme-Steuerung:** Das Theme wird standardmäßig über das HTML-Attribut `data-theme="dark"` (im Template `templates/base.html.twig`) gesteuert.

---

## 🛠️ Technologie-Stack

- **Backend:** Symfony 7.3 (PHP 8.2+)
- **Datenbank:** Doctrine ORM (**zwingend MariaDB**, da die Migrationen diese voraussetzen) + SQLite für EVE SDE
- **Frontend-Pipeline:** Symfony AssetMapper (kein Webpack Encore/Vite im Hauptprojekt nötig!)
- **Dynamische UI-Komponenten:** React & TypeScript als "Insellösung" (Islands Architecture)
- **Kommunikation:** Symfony UX Turbo & Stimulus

---

## 📂 Projektstruktur & Module

- `src/Entity/LinkCollection/`: Verwaltung von nützlichen Corp-Links (kategorisiert).
- `src/Entity/Orders/`: Verwaltung von Einkaufs- (Buy) und Verkaufsaufträgen (Sell).
- `src/Command/`: CLI-Commands für die App-Verwaltung.
- `react/`: Eigenständiger React/TypeScript-Source für interaktive Komponenten.
- `templates/`: Symfony Twig-Templates.

---

## 💻 Wichtige CLI-Befehle (Console Commands)

Hier findest du eine Übersicht aller spezifischen Commands dieser App:

### 1. Installation & SDE-Update
* **App vollständig installieren:**
  Richtet die Datenbank ein, führt alle Migrationen aus, lädt die neuesten EVE SDE-Daten herunter und erstellt interaktiv einen Administrator-Benutzer.
  ```bash
  ddev php bin/console app:install
  ```
  **Ablauf des Installationsprozesses:**
  1. **Datenbank erstellen:** Legt die Hauptdatenbank an (falls sie noch nicht existiert).
  2. **Migrationen ausführen:** Führt alle Doctrine-Migrationen aus (benötigt zwingend eine MariaDB).
  3. **EVE Online SDE initialisieren:** Lädt den neuesten Static Data Export (SQLite) von Fuzzwork herunter und importiert ihn.
  4. **Administrator erstellen (Interaktiv):**
     - Wenn die Konsole im interaktiven Modus läuft, fragt das Skript nacheinander nach einem **Admin-Benutzernamen** (Vorschlag: `admin`) und einem **Admin-Passwort** (verdeckte Eingabe).
     - Bei der Ausführung im nicht-interaktiven Modus (z. B. mit `--no-interaction`) wird dieser Schritt automatisch übersprungen.

* **EVE Online SDE aktualisieren:**
  Prüft auf Aktualisierungen des EVE Online Static Data Exports (SQLite von Fuzzwork) und lädt diesen bei Bedarf herunter.
  ```bash
  ddev php bin/console app:sde:update
  ```
  *Optionen:*
  - `-f` / `--force`: Update erzwingen (ignoriert gecachte Checksummen).
  - `-u <URL>` / `--url=<URL>`: Alternative Download-Quelle für das `sqlite.bz2`-File.

### 2. Benutzer- & Rechteverwaltung
Das Projekt verfügt über ein mehrstufiges Rechtesystem:
- **`ROLE_RECRUIT`**: Rekrut (Standard nach Erstellung).
- **`ROLE_MEMBER`**: Normales Corp-Mitglied (Zugriff auf internen Bereich).
- **`ROLE_OFFICER`**: Offizier.
- **`ROLE_CEO`**: CEO.
- **`ROLE_ADMIN`**: Administrator (darf alle Rollen verwalten).

* **Benutzer erstellen:**
  ```bash
  ddev php bin/console app:create-user <username> <password> [<role>]
  ```
  *(Erstellt einen Benutzer direkt über die CLI mit optionaler Rolle - Standard ist `ROLE_RECRUIT`)*
* **Rolle nachträglich zuweisen (Promotion):**
  ```bash
  ddev php bin/console app:promote-user <username> <role>
  ```
* **Passwort zurücksetzen (Notfall-CLI):**
  ```bash
  ddev php bin/console app:reset-password <username> [<new-password>]
  ```
  *(Setzt das Passwort eines Benutzers zurück. Wenn kein Wunschpasswort angegeben wird, generiert das Command ein zufälliges temporäres Passwort.)*

### 3. React/TypeScript "Islands" Development
Die React-Komponenten liegen unter `react/` und werden via Esbuild direkt in den Symfony AssetMapper kompiliert.
* **Einmalige Einrichtung:**
  ```bash
  cd react && npm install
  ```
* **Entwicklung (Watcher starten – Hot-Rebuild in < 40ms):**
  ```bash
  ddev npm run watch
  ```
* **Produktions-Build (optimiert):**
  ```bash
  ddev npm run build
  ```
* **Komponenten in Twig einbinden:**
  ```twig
  {{ react_component('MyComponent', { 'propName': 'Value' }) }}
  ```

---

## 💡 Entwickler-Tipps & Best Practices

### 🔒 Sicherheit & Authentifizierung
- **Web-Routen:** Normale Web-Seiten nutzen die standardmäßige Cookie-basierte Symfony Session-Security.
- **API-Routen (`/api/*`):** Diese sind statuslos und werden über **JWS/JWT (HS256)** gesichert (Signierung mittels `APP_SECRET` aus der `.env`).
  - *Token abfragen:* `POST /api/login` mit JSON `{"email":"...", "password":"..."}`
  - *Token mitsenden:* Header `Authorization: Bearer <token>` bei Anfragen an `/api/*`

### 🛡️ DSGVO-Konformität & Lokale Ressourcen
- **CSS & Fonts:** Alle CSS- und Styling-Ressourcen liegen zu 100% lokal im Projekt vor (`assets/styles/`). Es werden keine externen CDNs oder Google-Fonts geladen.
- **EVE Online Asset-Bilder (Image-Proxy):** Um zu verhindern, dass die IP-Adresse der Benutzer an fremde Server (wie den CCP Image Server) übertragen wird, verwenden wir einen lokalen **Image-Proxy** mit On-Demand Caching.
  - **Route:** `/eve/image/{category}/{id}/{action}?size={size}`
  - **Verwendung:** Statt `https://images.evetech.net/types/34/icon` fragt das Frontend `/eve/image/types/34/icon?size=64` an.
  - **Funktionsweise:** Unser Server lädt das Bild im Hintergrund von CCP herunter, speichert es unter `var/eve_image_cache/` und liefert es direkt aus. Nach dem ersten Abruf beträgt die Ladezeit 0ms externe Latenz.

### 🎨 Styling & Layouts
- **Keine Inline-Styles:** Schreibe nach Möglichkeit kein Inline-CSS. Nutze stattdessen die in `assets/styles/app.css` bereitgestellten CSS-Variablen und Layout-Klassen.
- **Layouts & Grids:** Verwende `.columns` und `.column` (mit Modifikatoren wie `.is-half` oder `.is-one-third`) für mehrspaltige Layouts und `.level` zur horizontalen Ausrichtung.
- **Formulare & Karten:** Verwende Klassen wie `.card`, `.button`, `.input` und `.textarea`, um Formulare und Container im passenden EVE Sci-Fi-Stil anzuzeigen.

### 📝 Sprache & Code-Richtlinien
- **Kommunikation im CLI:** Deutsch.
- **Code-Kommentare:** Englisch.
- **Commit-Messages:** Englisch.
- **i-doit Module (falls zutreffend):** Standardpfad unter `src/classes/modules/` mit passenden Präfixen.
