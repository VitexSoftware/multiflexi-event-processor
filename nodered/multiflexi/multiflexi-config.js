module.exports = function (RED) {
    'use strict';

    /**
     * Configuration node holding the MultiFlexi REST API connection.
     *
     * baseUrl is the full API base including the version path, e.g.
     *   http://multiflexi.example.com/api/VitexSoftware/MultiFlexi/1.0.0
     */
    function MultiFlexiConfigNode(config) {
        RED.nodes.createNode(this, config);
        this.name = config.name;
        this.baseUrl = (config.baseUrl || '').replace(/\/+$/, '');
        // credentials.username / credentials.password are managed by Node-RED
    }

    RED.nodes.registerType('multiflexi-config', MultiFlexiConfigNode, {
        credentials: {
            username: { type: 'text' },
            password: { type: 'password' },
        },
    });
};
