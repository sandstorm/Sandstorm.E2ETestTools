import React, {PureComponent} from 'react';
import PropTypes from 'prop-types';
// @ts-ignore
import {connect} from 'react-redux';

// @ts-ignore
@connect(state => ({
    // Neos 9: the contextPath is the serialized NodeAddress (content repository, workspace, dimension, node id) -
    // the export needs all of it, the node id alone isn't unique anymore.
    // Focused content node if there is one, else the current document (selected in the document tree).
    nodeAddress: state.cr.nodes.focused.contextPaths[0] ?? state.cr.nodes.documentNode,
}))

export default class ExportNodeButton extends PureComponent {
    static propTypes = {
        nodeAddress: PropTypes.string,
        enabled: PropTypes.bool,
    };

    exportNodeButtonOnClick = () => {
        // the backend and the export route share the host - a download, so no fetch()
        // @ts-ignore
        window.location.href = '/api/export-node?node=' + encodeURIComponent(this.props.nodeAddress ?? '');
    };

    render() {
        // @ts-ignore
        const enabled = this.props.enabled === true;
        return <button
            className={"neos-button-primary"}
            onClick={this.exportNodeButtonOnClick}
            disabled={!enabled}
            title={enabled ? undefined : 'Only administrators can export nodes as test fixtures'}
        >Export Node</button>;
    }
}
