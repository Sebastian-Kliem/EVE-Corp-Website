import React from 'react';
import { createRoot } from 'react-dom/client';
import MyComponent from './components/MyComponent';
import ItemAutocomplete from './components/ItemAutocomplete';
import UserAutocomplete from './components/UserAutocomplete';
import CharacterAssets from './components/CharacterAssets';
import AssetsOverview from './components/AssetsOverview';

// Object to register your React components so they can be selected in Twig
const components: Record<string, React.ComponentType<any>> = {
    MyComponent,
    ItemAutocomplete,
    UserAutocomplete,
    CharacterAssets,
    AssetsOverview,
};

function mountReactComponents() {
    const elements = document.querySelectorAll('[data-react-component]');

    elements.forEach((element) => {
        // Prevent mounting multiple times (especially with Turbo)
        if (element.getAttribute('data-react-mounted') === 'true') {
            return;
        }

        const componentName = element.getAttribute('data-react-component');
        if (!componentName) {
            return;
        }

        const Component = components[componentName];
        if (!Component) {
            console.warn(`React component "${componentName}" is not registered in index.tsx`);
            return;
        }

        const propsJson = element.getAttribute('data-react-props') || '{}';
        let props = {};

        try {
            props = JSON.parse(propsJson);
        } catch (error) {
            console.error(`Failed to parse props for React component "${componentName}":`, error);
        }

        // Render the component
        const root = createRoot(element);
        root.render(<Component {...props} />);

        // Mark as mounted
        element.setAttribute('data-react-mounted', 'true');
    });
}

// Support standard page load
document.addEventListener('DOMContentLoaded', mountReactComponents);

// Support Symfony UX Turbo dynamic page transitions
document.addEventListener('turbo:load', mountReactComponents);
