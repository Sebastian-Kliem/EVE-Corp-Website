# WH-Toolbox: Dokumentation der neuen Features

Dieses Dokument bietet dir eine vollständige Übersicht über die neu implementierten Features in deiner WH-Toolbox und erklärt, wie du sie verwendest und verwaltest.

---

## 1. Dreistufiges Rechtesystem

Wir haben eine hierarchische Rollenverteilung in `config/packages/security.yaml` eingerichtet:
* **`ROLE_USER`**: Normales Mitglied (Standard)
* **`ROLE_TRUSTED`**: Erweitertes Mitglied (erbt alle Rechte von `ROLE_USER`)
* **`ROLE_ADMIN`**: Administrator (erbt alle Rechte von `ROLE_TRUSTED` und `ROLE_USER`)

### CLI-Befehle zur Rollenverwaltung
* **Benutzer mit Rolle erstellen:**
  ```bash
  ddev php bin/console app:create-user <email> <password> [<role>] [<displayName>]
  ```
* **Rolle nachträglich zuweisen (Promotion):**
  ```bash
  ddev php bin/console app:promote-user <email> <role>
  ```

---

## 2. Benutzer-Registrierung

Benutzer können sich über ein einfaches und sicheres Web-Formular selbst ein Konto erstellen.

### Funktionsweise
* **URL:** `/register`
* **Features:**
  * Abfrage von E-Mail, **Anzeigename** und Passwort.
  * Automatische Validierung (E-Mail-Format, E-Mail-Eindeutigkeit, Passwort-Mindestlänge 6 Zeichen, Übereinstimmung der Passwort-Wiederholung).
  * Passwort-Hashing mit dem modernen Symfony-Hasher.
  * Zuweisung der Standardrolle `ROLE_USER`.
  * Grüne Erfolgsmeldung (Flash Message) und automatische Weiterleitung zum Login-Formular nach erfolgreicher Registrierung.
* **Verlinkung:** In der Navigationsleiste (wenn nicht eingeloggt) und unter dem Login-Formular ("Don't have an account yet? Register here").

---

## 3. JWS/JWT-Authentifizierung (HS256)

Zustandslose Absicherung deiner API-Routen (`/api/*`), damit du später aus den React-Komponenten heraus Daten dynamisch nachladen kannst (Signierung über das `APP_SECRET` aus der `.env`).

### Wichtige APIs
* **Login und Token anfordern:**
  ```bash
  curl -X POST http://127.0.0.1:8000/api/login \
    -H "Content-Type: application/json" \
    -d '{"email":"max@example.com", "password":"geheim"}'
  ```
  *Gibt ein JSON-Objekt mit dem `"token"` und der `"message"` zurück.*
* **Geschützter Endpunkt (Beispiel):**
  ```bash
  curl -X GET http://127.0.0.1:8000/api/me \
    -H "Authorization: Bearer <token>"
  ```
  *Liefert details wie E-Mail, `displayName` und Rollen des Benutzers.*

---

## 4. React + TypeScript "Insellösung" (Islands Architecture)

Ermöglicht es dir, React-Komponenten (in TypeScript/TSX) gezielt in Twig-Templates einzubetten. Alle React-spezifischen Tools (NPM, node_modules, Quellcode) liegen sauber isoliert im Hauptverzeichnis unter `/react/`.

### Entwicklungs-Workflow
1. **Einmalig Dependencies installieren (im Ordner `react/`):**
   ```bash
   cd react && npm install
   ```
2. **Entwicklung (Watcher starten - übersetzt deine Änderungen in unter 40ms):**
   ```bash
   ddev npm run watch
   ```
3. **Produktion (Optimierter Build):**
   ```bash
   ddev npm run build
   ```

### Komponenten in Twig einbetten
```twig
{{ react_component('MyComponent', { 'name': 'Sebastian' }) }}
```

---

## 5. Anzeigename (`displayName`) für Benutzer

Die `User`-Entität wurde um ein optionales `displayName` Feld (inklusive Fallback-Logik) erweitert:
* **Fallback:** Falls kein Name gesetzt ist, gibt `getDisplayName()` automatisch den Teil der E-Mail vor dem `@` zurück (aus `sebastian@example.com` wird `sebastian`).
* **Navbar-Integration:** Wenn eingeloggt, wird der Benutzer in der Navigationsleiste mit einem freundlichen `"Hello, [Anzeigename]"` begrüßt!
* **CLI-Befehl zum Ändern des Anzeigenamens:**
  ```bash
  ddev php bin/console app:set-displayname max@example.com "Maximilian Mustermann"
  ```
