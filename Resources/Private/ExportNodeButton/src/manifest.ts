import manifest from '@neos-project/neos-ui-extensibility';
import ExportNodeButton from './ExportNodeButton';

// @ts-ignore
manifest('Sandstorm.E2ETestTools:ExportNodeButton', {}, globalRegistry => {
    const editorsRegistry = globalRegistry.get('inspector').get('editors');
    editorsRegistry.set('Sandstorm.E2ETestTools/Inspector/Editors/ExportNodeButton', {
        component: ExportNodeButton
    });
});
