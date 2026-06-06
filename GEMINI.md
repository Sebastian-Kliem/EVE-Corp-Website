# WH-Toolbox (Eve Online Corp Tools)

Dieses Projekt ist eine Sammlung von Werkzeugen für eine Eve Online Corp.

## Technologie-Stack
- **Backend:** Symfony 7.3 (PHP 8.2+)
- **Datenbank:** Doctrine ORM
- **Frontend:** 
  - Symfony AssetMapper
  - Symfony UX Turbo & Stimulus
  - CSS: all defined in app.css do not write inline css if is possible
  - React für dynamische Komponenten (React-TypeScript Insellösung)

## Projektstruktur & Module
- `src/Entity/LinkCollection/`: Verwaltung von nützlichen Links, kategorisiert.
- `src/Entity/Orders/`: Verwaltung von Kauf- (Buy) und Verkaufsaufträgen (Sell).

## Konventionen
- **Deutsche Kommunikation** im Gemini CLI, aber englische Code-Kommentare und Commit-Messages.
- **Standard Symfony/Doctrine Patterns** verwenden.
- **Kein Inline-CSS:** Stile dürfen nicht per `style="..."`-Attribut oder `<style>`-Tag inline in Twig-Templates geschrieben werden. Alle Styles müssen zentral und sauber in `assets/styles/app.css` definiert werden.
- **React für dynamische Oberflächen:** Für komplexe, interaktive oder hochdynamische Komponenten (z. B. verschachtelte Bäume mit Filterung, Echtzeit-Kalkulatoren, etc.) sind React-TypeScript Komponenten ("Insellösungen") zu implementieren. Reine Twig-Templates sollten für statisches/semistatisches HTML genutzt und nicht mit komplexem Vanilla-JavaScript überladen werden.
- **Natives CSS (kein Bootstrap/Tailwind/Bulma):** Dieses Projekt nutzt kein Bootstrap, Bulma oder TailwindCSS. Das gesamte Design basiert auf einem maßgeschneiderten, nativen CSS-System in `assets/styles/app.css`. Altlasten mit Bulma-Klassen in Legacy-Templates müssen bei der Bearbeitung durch natives CSS ersetzt werden.
- **Datenschutz & DSGVO:** Externe Ressourcen (Bilder, Schriften, Skripte) dürfen nicht direkt über CDNs oder Drittseiten eingebunden werden. Sie müssen stattdessen heruntergeladen und lokal aus dem Projekt ausgeliefert werden, um DSGVO-Konformität zu gewährleisten (keine IP-Weitergabe an Dritte).

## React-TypeScript Insellösungen (Islands)
Dieses Projekt nutzt eine isolated React-Umgebung im Ordner `react/`. Diese wird über `esbuild` gebündelt und nach `assets/react.js` kompiliert, wo sie durch den Symfony AssetMapper eingebunden wird.

### Bauen & Kompilieren
Für CLI-Befehle im Kontext des DDEV-Containers verwenden:
- **Build (einmalig):** `ddev npm run --prefix react build`
- **Watch (während der Entwicklung):** `ddev npm run --prefix react watch`

### Verwendung in Twig-Templates
Um eine React-Komponente in einem Twig-Template einzubetten, erstelle ein Div mit den Attributen `data-react-component` und optional `data-react-props` (als HTML-escaped JSON-String):
```twig
<div data-react-component="ComponentName" data-react-props="{{ propsArray|json_encode|e('html_attr') }}"></div>
```

### Komponenten-Registrierung
Alle React-Komponenten, die in Twig-Templates nutzbar sein sollen, müssen in [react/src/index.tsx](file:///home/sebastian/develop/WH-Toolbox/react/src/index.tsx) im `components` Mapping registriert sein:
- **MyComponent:** Test- und Demokomponente.
- **ItemAutocomplete:** Autovervollständigung für Gegenstände (unterstützt SDE-Daten).
- **UserAutocomplete:** Autovervollständigung für Benutzer im Adminbereich.
- **CharacterAssets:** Dynamisches Inventar eines einzelnen EVE-Charakters mit Echtzeitsuche.
- **AssetsOverview:** Gesamt-Inventar über alle Charaktere mit Wallet-Saldo, Stationen-Gruppierung, rekursivem Containerschachtelungs-Baum und globaler Live-Filterung.

