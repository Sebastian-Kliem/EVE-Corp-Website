# WH-Toolbox 🌌

> **Eve Online Corp Tools** – Eine Sammlung von nützlichen Werkzeugen und Systemen für unsere Eve Online Corporation, basierend auf Symfony und Doctrine, kombiniert mit einer modernen React+TypeScript Insellösung für dynamische UI-Komponenten.

---

## 🎨 Frontend & Design

### CSS-Framework: Bulma
In diesem Projekt verwenden wir **[Bulma](https://bulma.io/)** als CSS-Framework für das grundlegende Styling. 
- **Dateien:** Die Stylesheets liegen unter `assets/styles/bulma.min.css`.
- **Eigene Anpassungen (Dark & Light Mode):** Wir haben ein maßgeschneidertes EVE Online Dark-Theme mit modernen Glassmorphism-Effekten und einem lokalen, GDPR-konformen Theme-Umschalter implementiert. Dies ist in `assets/styles/app.css` definiert und erweitert/überschreibt die Standard-Bulma-Klassen.
- **Theme-Steuerung:** Das Theme wird per JavaScript (im Template `templates/base.html.twig`) über das HTML-Attribut `data-theme` (`dark` oder `light`) gesteuert.

---

## 🛠️ Technologie-Stack

- **Backend:** Symfony 7.3 (PHP 8.2+)
- **Datenbank:** Doctrine ORM (MySQL/MariaDB) + SQLite für EVE SDE
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
  Richtet die Datenbank ein, führt alle Migrationen aus und lädt die neuesten EVE SDE-Daten herunter.
  ```bash
  ddev php bin/console app:install
  ```
* **EVE Online SDE aktualisieren:**
  Prüft auf Aktualisierungen des EVE Online Static Data Exports (SQLite von Fuzzwork) und lädt diesen bei Bedarf herunter.
  ```bash
  ddev php bin/console app:sde:update
  ```
  *Optionen:*
  - `-f` / `--force`: Update erzwingen (ignoriert gecachte Checksummen).
  - `-u <URL>` / `--url=<URL>`: Alternative Download-Quelle für das `sqlite.bz2`-File.

### 2. Benutzer- & Rechteverwaltung
Das Projekt verfügt über ein vierstufiges Rechtesystem:
- **`ROLE_GUEST`**: Gast (Standard nach Registrierung, wartet auf Freischaltung).
- **`ROLE_USER`**: Normales Corp-Mitglied (Zugriff auf internen Bereich: Linksammlung, API).
- **`ROLE_TRUSTED`**: Erweitertes Mitglied (Zugriff auf Orders, darf Gäste freischalten).
- **`ROLE_ADMIN`**: Administrator (darf alle Rollen verwalten).

* **Benutzer erstellen:**
  ```bash
  ddev php bin/console app:create-user <email> <password> [<role>] [<displayName>]
  ```
  *(Erstellt einen Benutzer direkt über die CLI mit optionaler Rolle - Standard ist `ROLE_GUEST`)*
* **Rolle nachträglich zuweisen (Promotion):**
  ```bash
  ddev php bin/console app:promote-user <email> <role>
  ```
* **Anzeigename ändern:**
  ```bash
  ddev php bin/console app:set-displayname <email> "<Anzeigename>"
  ```
* **Passwort zurücksetzen (Notfall-CLI):**
  ```bash
  ddev php bin/console app:reset-password <email> [<new-password>]
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
- **CSS & Fonts:** Alle CSS-Frameworks (Bulma) und Styling-Ressourcen liegen zu 100% lokal im Projekt vor (`assets/styles/`). Es werden keine externen CDNs oder Google-Fonts geladen.
- **EVE Online Asset-Bilder (Image-Proxy):** Um zu verhindern, dass die IP-Adresse der Benutzer an fremde Server (wie den CCP Image Server) übertragen wird, verwenden wir einen lokalen **Image-Proxy** mit On-Demand Caching.
  - **Route:** `/eve/image/{category}/{id}/{action}?size={size}`
  - **Verwendung:** Statt `https://images.evetech.net/types/34/icon` fragt das Frontend `/eve/image/types/34/icon?size=64` an.
  - **Funktionsweise:** Unser Server lädt das Bild im Hintergrund von CCP herunter, speichert es unter `var/eve_image_cache/` und liefert es direkt aus. Nach dem ersten Abruf beträgt die Ladezeit 0ms externe Latenz.

### 🎨 Styling & Layouts
- Verwende für Formulare und Boxen die Bulma-Klassen wie `.box`, `.card`, `.field`, `.control`, `.input`.
- Der Dark Mode passt diese automatisch im EVE Dark-Glassmorphism-Stil an, solange du dich an die Bulma-Struktur hältst!

### 📝 Sprache & Code-Richtlinien
- **Kommunikation im CLI:** Deutsch.
- **Code-Kommentare:** Englisch.
- **Commit-Messages:** Englisch.
- **i-doit Module (falls zutreffend):** Standardpfad unter `src/classes/modules/` mit passenden Präfixen.
