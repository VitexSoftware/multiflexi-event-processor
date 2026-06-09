module.exports = function (RED) {
    'use strict';

    const http = require('http');
    const https = require('https');
    const { URL } = require('url');

    /**
     * MultiFlexi RunTemplate (action) node.
     *
     * On each input message it schedules a job for the configured RunTemplate
     * via the MultiFlexi REST API (POST /job/). Environment overrides are taken
     * from the static node configuration merged with msg.payload.env (the
     * latter wins), so upstream event data can flow into the job.
     */
    function MultiFlexiRunTemplateNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;
        node.server = RED.nodes.getNode(config.server);
        node.runtemplateId = config.runtemplateId;
        node.executor = config.executor || '';
        node.scheduled = config.scheduled || 'now';

        // Static env overrides: array of { key, value }
        let staticEnv = {};
        try {
            (JSON.parse(config.env || '[]') || []).forEach(function (pair) {
                if (pair && pair.key) {
                    staticEnv[pair.key] = pair.value;
                }
            });
        } catch (e) {
            staticEnv = {};
        }

        function postJob(body) {
            return new Promise((resolve, reject) => {
                const target = new URL(node.server.baseUrl + '/job/');
                const payload = JSON.stringify(body);
                const transport = target.protocol === 'https:' ? https : http;

                const auth = node.server.credentials
                    ? Buffer.from(
                          (node.server.credentials.username || '') + ':' + (node.server.credentials.password || ''),
                      ).toString('base64')
                    : '';

                const options = {
                    method: 'POST',
                    hostname: target.hostname,
                    port: target.port,
                    path: target.pathname + target.search,
                    headers: {
                        'Content-Type': 'application/json',
                        'Content-Length': Buffer.byteLength(payload),
                    },
                };
                if (auth) {
                    options.headers.Authorization = 'Basic ' + auth;
                }

                const req = transport.request(options, (res) => {
                    let data = '';
                    res.on('data', (chunk) => {
                        data += chunk;
                    });
                    res.on('end', () => {
                        let parsed = data;
                        try {
                            parsed = JSON.parse(data);
                        } catch (e) {
                            /* keep raw */
                        }
                        if (res.statusCode >= 200 && res.statusCode < 300) {
                            resolve(parsed);
                        } else {
                            reject(new Error('HTTP ' + res.statusCode + ': ' + JSON.stringify(parsed)));
                        }
                    });
                });
                req.on('error', reject);
                req.write(payload);
                req.end();
            });
        }

        node.on('input', function (msg, send, done) {
            send = send || function () { node.send.apply(node, arguments); };
            done = done || function (err) { if (err) { node.error(err, msg); } };

            if (!node.server || !node.server.baseUrl) {
                done(new Error('No MultiFlexi server configured'));
                return;
            }

            const runtemplateId = parseInt(msg.runtemplate_id || node.runtemplateId, 10);
            if (!runtemplateId) {
                done(new Error('No runtemplate_id set'));
                return;
            }

            const env = Object.assign({}, staticEnv, (msg.payload && msg.payload.env) || {});

            const body = {
                runtemplate_id: runtemplateId,
                scheduled: msg.scheduled || node.scheduled || 'now',
            };
            if (node.executor) {
                body.executor = node.executor;
            }
            if (Object.keys(env).length > 0) {
                body.env = env;
            }

            node.status({ fill: 'blue', shape: 'dot', text: 'scheduling #' + runtemplateId });

            postJob(body)
                .then((result) => {
                    msg.payload = result;
                    node.status({ fill: 'green', shape: 'dot', text: 'job ' + (result.job_id || 'scheduled') });
                    send(msg);
                    done();
                })
                .catch((err) => {
                    node.status({ fill: 'red', shape: 'ring', text: 'failed' });
                    done(err);
                });
        });
    }

    RED.nodes.registerType('multiflexi-runtemplate', MultiFlexiRunTemplateNode);
};
