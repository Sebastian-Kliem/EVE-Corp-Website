import React from 'react';
import { createRoot } from 'react-dom/client';
import ItemAutocomplete from './components/Form/ItemAutocomplete';
import UserAutocomplete from './components/Form/UserAutocomplete';
import CharacterAssets from './components/Assets/CharacterAssets';
import AssetsOverview from './components/Assets/AssetsOverview';
import CorpAssetsOverview from './components/Assets/CorpAssetsOverview';
import CorpAssetsVisibilityManager from './components/Admin/CorpAssetsVisibilityManager';
import PIOverview from './components/Profile/PIOverview';
import MiningLedger from './components/Profile/MiningLedger';
import TrackingListManager from './components/Tool/TrackingListManager';
import TrackingViewer from './components/Tool/TrackingViewer';

// Object to register your React components so they can be selected in Twig
const components: Record<string, React.ComponentType<any>> = {
    ItemAutocomplete,
    UserAutocomplete,
    CharacterAssets,
    AssetsOverview,
    CorpAssetsOverview,
    CorpAssetsVisibilityManager,
    PIOverview,
    MiningLedger,
    TrackingListManager,
    TrackingViewer,
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
