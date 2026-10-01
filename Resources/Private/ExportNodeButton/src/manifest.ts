import React from 'react';
import manifest from '@neos-project/neos-ui-extensibility';
import ExportNodeButton from './ExportNodeButton';

// @ts-ignore
manifest('Sandstorm.E2ETestTools:ExportNodeButton', {}, (globalRegistry, {frontendConfiguration}) => {
    // computed per request from Policy.yaml (Security.hasAccess, see Settings.yaml) - the endpoint checks it again
    const enabled = frontendConfiguration?.['Sandstorm.E2ETestTools:ExportNodeButton']?.enabled === true;

    const editorsRegistry = globalRegistry.get('inspector').get('editors');
    editorsRegistry.set('Sandstorm.E2ETestTools/Inspector/Editors/ExportNodeButton', {
        component: (props: object) => React.createElement(ExportNodeButton, {...props, enabled})
    });
});
