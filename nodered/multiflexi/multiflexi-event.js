'use strict';

module.exports = function (RED) {
    var bodyParser = require('body-parser');
    var cookieParser = require('cookie-parser');

    /**
     * MultiFlexi Event (trigger) node.
     *
     * Registers an HTTP POST route on the Node-RED user-facing server. The
     * multiflexi-eventor daemon (NodeRedBridge) posts normalized events here:
     *   { event: "webhook.change" | "job.completed", ... }
     *
     * Incoming events are optionally filtered by event type, evidence,
     * operation and app UUID, then emitted as msg.payload. This is the source
     * of the "arrows" in a flow.
     */
    function MultiFlexiEventNode(config) {
        RED.nodes.createNode(this, config);
        var node = this;

        if (RED.settings.httpNodeRoot === false) {
            node.warn('node-red-contrib-multiflexi: httpNodeRoot is disabled; event node cannot register route');
            return;
        }

        var rawPath = (config.path || '/multiflexi-event').trim();
        if (rawPath[0] !== '/') { rawPath = '/' + rawPath; }
        node.path = rawPath;

        node.eventType = config.eventType || 'any';
        node.evidence = (config.evidence || '').trim();
        node.operation = config.operation || 'any';
        node.appUuid = (config.appUuid || '').trim();
        node.token = (node.credentials && node.credentials.token ? node.credentials.token : (config.token || '')).trim();

        var maxSize = RED.settings.apiMaxLength || '5mb';
        var jsonParser = bodyParser.json({ limit: maxSize });
        var urlencParser = bodyParser.urlencoded({ limit: maxSize, extended: true });

        var httpMiddleware = function (req, res, next) { next(); };
        if (RED.settings.httpNodeMiddleware) {
            if (typeof RED.settings.httpNodeMiddleware === 'function' ||
                Array.isArray(RED.settings.httpNodeMiddleware)) {
                httpMiddleware = RED.settings.httpNodeMiddleware;
            }
        }

        function matches(event) {
            if (node.eventType !== 'any' && event.event !== node.eventType) {
                return false;
            }
            if (node.evidence && event.evidence !== node.evidence) {
                return false;
            }
            if (node.operation !== 'any' && event.operation && event.operation !== node.operation) {
                return false;
            }
            if (node.appUuid && event.app_uuid !== node.appUuid) {
                return false;
            }
            return true;
        }

        function handlePost(req, res) {
            if (node.token) {
                var incoming = (req.headers['x-multiflexi-token'] || '').trim();
                if (incoming !== node.token) {
                    res.sendStatus(401);
                    node.warn('MultiFlexi event: rejected request with invalid X-MultiFlexi-Token from ' + req.ip);
                    return;
                }
            }

            // Respond immediately so the eventor daemon does not block.
            res.sendStatus(200);

            var event = req.body;
            if (!event || typeof event !== 'object') {
                node.status({ fill: 'yellow', shape: 'ring', text: 'empty body' });
                return;
            }

            if (!matches(event)) {
                return;
            }

            node.send({
                event: event.event,
                payload: event,
            });
            node.status({ fill: 'green', shape: 'dot', text: (event.event || 'event') + ' @ ' + new Date().toLocaleTimeString() });
        }

        RED.httpNode.post(node.path, cookieParser(), jsonParser, urlencParser, httpMiddleware, handlePost);

        node.on('close', function (done) {
            // Remove our route from the Express router stack on redeploy.
            var stack = RED.httpNode._router && RED.httpNode._router.stack;
            if (stack) {
                for (var i = stack.length - 1; i >= 0; i--) {
                    var layer = stack[i];
                    if (layer.route && layer.route.path === node.path &&
                        layer.route.methods && layer.route.methods.post) {
                        stack.splice(i, 1);
                    }
                }
            }
            done();
        });
    }

    RED.nodes.registerType('multiflexi-event', MultiFlexiEventNode, {
        credentials: {
            token: { type: 'password' },
        },
    });
};
