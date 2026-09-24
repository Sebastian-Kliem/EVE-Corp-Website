import React from 'react';
import { createRoot, Root } from 'react-dom/client';
import ItemAutocomplete from './components/Form/ItemAutocomplete';
import UserAutocomplete from './components/Form/UserAutocomplete';
import CharacterAssets from './components/Assets/CharacterAssets';
import AssetsOverview from './components/Assets/AssetsOverview';
import CorpAssetsOverview from './components/Assets/CorpAssetsOverview';
import CorpAssetsVisibilityManager from './components/Admin/CorpAssetsVisibilityManager';
import PIOverview from './components/Profile/PIOverview';
import MarketOrdersOverview from './components/Profile/MarketOrdersOverview';
import MiningLedger from './components/Profile/MiningLedger';
import PerformanceLedger from './components/Profile/PerformanceLedger';
import TrackingListManager from './components/Tool/TrackingListManager';
import TrackingViewer from './components/Tool/TrackingViewer';
import ValueHistory from './components/Profile/ValueHistory';
import WalletJournal from './components/Assets/WalletJournal';
import IndustryJobsOverview from './components/Profile/IndustryJobsOverview';
import ReactionDashboard from './components/Tool/ReactionDashboard';
import BlueprintVault from './components/Corp/BlueprintVault';
import CorpStructuresOverview from './components/Corp/CorpStructuresOverview';
import DefenseDoctrine from './components/Corp/DefenseDoctrine';
import JaniceAppraisal from './components/Corp/JaniceAppraisal';
import OrderListManager from './components/Corp/OrderListManager';

// Object to register your React components so they can be selected in Twig
const components: Record<string, React.ComponentType<any>> = {
    ItemAutocomplete,
    UserAutocomplete,
    CharacterAssets,
    AssetsOverview,
    CorpAssetsOverview,
    CorpAssetsVisibilityManager,
    PIOverview,
    MarketOrdersOverview,
    MiningLedger,
    PerformanceLedger,
    TrackingListManager,
    TrackingViewer,
    ValueHistory,
    WalletJournal,
    IndustryJobsOverview,
    ReactionDashboard,
    BlueprintVault,
    CorpStructuresOverview,
    DefenseDoctrine,
    JaniceAppraisal,
    OrderListManager,
};

const mountedRoots = new Map<Element, Root>();

function mountReactComponents() {
    const elements = document.querySelectorAll('[data-react-component]');

    elements.forEach((element) => {
        // Prevent mounting multiple times if already active and tracked
        if (element.getAttribute('data-react-mounted') === 'true' && mountedRoots.has(element)) {
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

        // Clean up previous root if element was re-used or restored
        if (mountedRoots.has(element)) {
            try {
                mountedRoots.get(element)!.unmount();
            } catch (e) {
                // Ignore unmount error
            }
            mountedRoots.delete(element);
        }

        // Render the component
        const root = createRoot(element);
        mountedRoots.set(element, root);
        root.render(<Component {...props} />);

        // Mark as mounted
        element.setAttribute('data-react-mounted', 'true');
    });
}

function unmountReactComponents() {
    mountedRoots.forEach((root, element) => {
        try {
            root.unmount();
        } catch (e) {
            console.error('Error unmounting React root:', e);
        }
        element.removeAttribute('data-react-mounted');
    });
    mountedRoots.clear();
}

// Support standard page load
document.addEventListener('DOMContentLoaded', mountReactComponents);

// Support Symfony UX Turbo dynamic page transitions
document.addEventListener('turbo:load', mountReactComponents);
document.addEventListener('turbo:render', mountReactComponents);
document.addEventListener('turbo:before-cache', unmountReactComponents);
