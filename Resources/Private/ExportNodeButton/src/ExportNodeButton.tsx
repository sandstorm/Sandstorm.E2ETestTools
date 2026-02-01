import React, {PureComponent} from 'react';
import PropTypes from 'prop-types';

export default class ExportNodeButton extends PureComponent {
    static propTypes = {
        value: PropTypes.string,
        commit: PropTypes.func.isRequired,
    };
    render() {
        return <button>Export Node</button>;
    }
}
