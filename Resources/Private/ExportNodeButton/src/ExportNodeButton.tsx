import React, {PureComponent} from 'react';
import PropTypes from 'prop-types';
// @ts-ignore
import {connect} from 'react-redux';

// @ts-ignore
@connect(state => {
    const nodeContextPath = state.cr.nodes.focused.contextPaths[0];

    return {
        nodeIdentifier: state.cr.nodes.byContextPath[nodeContextPath]?.identifier,
        currentUri: state.ui.contentCanvas.src
    };
})

export default class ExportNodeButton extends PureComponent {
    static propTypes = {
        value: PropTypes.string,
        commit: PropTypes.func.isRequired,
        nodeIdentifier: PropTypes.string,
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
        window.location.href = baseUri + "/api/export-node/" + (this.props.nodeIdentifier ?? '');
    };

    render() {
        return <button
            onClick={this.exportNodeButtonOnClick}
        >Export Node</button>;
    }
}
