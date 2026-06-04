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
- Deutsche Kommunikation im Gemini CLI, aber englische Code-Kommentare und Commit-Messages.
- Standard Symfony/Doctrine Patterns.
- Externe Ressourcen (Bilder, Schriften, Skripte) dürfen nicht direkt über CDNs oder Drittseiten eingebunden werden. Sie müssen stattdessen heruntergeladen und lokal aus dem Projekt ausgeliefert werden, um DSGVO-Konformität zu gewährleisten (keine IP-Weitergabe an Dritte).
