# WH-Toolbox

Werkzeuge für die EVE-Online-Corporation *Keepers of Duat* (Wurmloch/J-Space): Corp-Orders, Inventare, Strukturen und Fuel, PI-Planung, Industrie, Bergbau, Performance-Auswertung, Discord- und Wanderer-Integration. Symfony-Backend mit React-Komponenten als Inseln in Twig-Seiten.

---

## Technologie-Stack

- **Backend:** Symfony 7.4 LTS (PHP 8.2+, im DDEV-Container 8.3)
- **Datenbank:** Doctrine ORM auf **MariaDB** (die Migrationen setzen MariaDB voraus). Zweite DBAL-Verbindung `sde` auf SQLite (`var/sde.sqlite`, EVE Static Data Export von Fuzzwork)
- **Frontend:** Symfony AssetMapper und Importmap, Symfony UX Turbo und Stimulus
- **React-Inseln:** React 18 und TypeScript unter `react/`, mit esbuild nach `assets/react.js` gebündelt
- **Styling:** Tailwind CSS v4 (CLI). Quelle ist `react/src/input.css` mit eigenem Theme (`--color-eve-*`), Ausgabe `assets/styles/app.css`
- **EVE-Anbindung:** ESI über `App\Service\Esi\EsiClient` (Cache, Fehlerbudget, Circuit Breaker), EVE SSO nur zum Verknüpfen von Charakteren
- **Lokale Entwicklung:** DDEV

---

## Einrichtung (lokal)

```bash
ddev start
ddev composer install
ddev npm install --prefix react
ddev npm run --prefix react build
ddev php bin/console app:install
```

`app:install` legt die Datenbank an, führt die Migrationen aus, lädt die SDE, fragt interaktiv einen ersten Administrator ab und legt die Tracking-Vorlagen an. Der Befehl ist wiederholbar.

Lokale Secrets (z. B. `ESI_TOKEN_KEY`, EVE-SSO-Zugangsdaten, Discord-Webhooks) gehören in `.env.local`, nicht in `.env`.

---

## Wichtige Befehle

### Backend

| Zweck | Befehl |
| :--- | :--- |
| Installation / Update | `ddev php bin/console app:install` |
| SDE aktualisieren | `ddev php bin/console app:sde:update [--force] [--url=<url>]` |
| Cron-Lauf (alle fälligen Jobs) | `ddev php bin/console app:cron:run` |
| Einzelnen Cron-Job erzwingen | `ddev php bin/console app:cron:run --job=<command>` |
| Benutzer anlegen | `ddev php bin/console app:create-user <username> <password> [<role>]` |
| Rolle setzen | `ddev php bin/console app:promote-user <username> <role>` |
| Passwort zurücksetzen | `ddev php bin/console app:reset-password <username> [<password>]` |
| Discord-Webhook testen | `ddev php bin/console app:discord:test [<channel>]` |
| Tracking-Vorlagen anlegen | `ddev php bin/console app:seed:tracking-templates` |
| Tests | `ddev php bin/phpunit` |

Ohne Passwort generiert `app:reset-password` ein zufälliges temporäres Passwort.

### Frontend

| Zweck | Befehl |
| :--- | :--- |
| Build (CSS und JS) | `ddev npm run --prefix react build` |
| Watcher während der Entwicklung | `ddev npm run --prefix react watch` |
| Typprüfung | `ddev exec --dir /var/www/html/react npx tsc --noEmit` |

`assets/react.js` und `assets/styles/app.css` sind Build-Artefakte und nicht versioniert.

React-Komponenten werden in `react/src/index.tsx` registriert und in Twig eingebunden:

```twig
{{ react_component('MyComponent', { propName: 'Value' }) }}
```

---

## Rollen und Zugriff

Hierarchie (`config/packages/security.yaml`), höhere Rollen erben alle niedrigeren:

`ROLE_USER` < `ROLE_RECRUIT` < `ROLE_MEMBER` < `ROLE_OFFICER` < `ROLE_CEO` < `ROLE_ADMIN`

Neue Konten über `/register` starten als `ROLE_RECRUIT` und sehen nur Startseite und Profil, bis ein Officer sie freischaltet.

| Bereich | Mindestrolle |
| :--- | :--- |
| `/personal/profile` | eingeloggt |
| `/personal`, `/corp`, `/general`, `/auth/eve`, `/online-status` | `ROLE_MEMBER` |
| `/admin` | `ROLE_OFFICER` (Cron-Jobs, Webhooks, Wanderer, Hangar-Sichtbarkeit: `ROLE_CEO`) |

`access_control` setzt die Grundregel je Bereich, Feinheiten regeln `#[IsGranted]`-Attribute an den Controllern.

---

## Authentifizierung

- **Website:** Username und Passwort mit Session (Remember-me), Login-Throttling.
- **API (`/api/*`):** zustandslos per JWT (HS256, signiert mit `APP_SECRET`, 1 h gültig). Token über `POST /api/login` oder in Twig per `jwt_token(app.user)` für React-Props. Ausnahme: `/api/orders/*` läuft über die Session.
- **EVE SSO:** verknüpft Charaktere mit einem Benutzer. Access- und Refresh-Tokens liegen verschlüsselt in der Datenbank (Schlüssel `ESI_TOKEN_KEY`). Geht der Schlüssel verloren, müssen alle Charaktere neu verknüpft werden.

---

## Hintergrund-Jobs

Der Server ruft alle paar Minuten `app:cron:run` auf. Welche Jobs es gibt, wann sie fällig sind und wie der letzte Lauf ausging, steht in der Datenbank und ist unter `/admin/cron` sichtbar (dort lassen sich Jobs auch manuell starten). Das Protokoll liegt in `var/log/cron.log`.

---

## Datenschutz: EVE-Bilder

EVE-Bilder werden über einen lokalen Proxy mit Cache ausgeliefert, damit Browser keine Anfragen an CCP-Server schicken:

- Route: `/eve/image/{category}/{id}/{action}?size={size}`, z. B. `/eve/image/types/34/icon?size=64`
- Cache: `var/eve_image_cache/`

---

## Konventionen

- Kommentare im Code und Commit-Messages auf Englisch
- Styling mit Tailwind-Utility-Klassen, möglichst keine Inline-Styles
- Datenbankänderungen nur über Doctrine-Migrationen (`ddev php bin/console make:migration`)
- Deploy: Merge nach `main` wird nach grüner CI auf dem Produktionsserver ausgerollt
