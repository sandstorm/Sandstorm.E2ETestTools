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
    currentUri: state.ui.contentCanvas.src
}))

export default class ExportNodeButton extends PureComponent {
    static propTypes = {
        value: PropTypes.string,
        commit: PropTypes.func.isRequired,
        nodeAddress: PropTypes.string,
        currentUri: PropTypes.string,
    };

    exportNodeButtonOnClick = () => {
        // @ts-ignore
        const parts = this.props.currentUri.split('/');
        const neosIndex = parts.indexOf('neos');
        const baseUri = parts
            .slice(0, neosIndex === -1 ? parts.length : neosIndex)
            .join('/');

        // @ts-ignore
        window.location.href = baseUri + "/api/export-node?node=" + encodeURIComponent(this.props.nodeAddress ?? '');
    };

    render() {
        return <button
            className={"neos-button-primary"}
            onClick={this.exportNodeButtonOnClick}
        >Export Node</button>;
    }
}
