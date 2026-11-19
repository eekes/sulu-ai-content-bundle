// The components of sulu-admin-bundle the panel uses, as plain elements.
const React = require('react');

module.exports = {
    Button: ({children, onClick, icon}) => React.createElement('button', {onClick, type: 'button', 'data-icon': icon}, children),
    Loader: () => React.createElement('span', {'data-loader': true}),
    Icon: ({name}) => React.createElement('span', {'data-icon': name}),
};
