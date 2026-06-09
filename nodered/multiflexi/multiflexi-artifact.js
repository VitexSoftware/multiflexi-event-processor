module.exports = function (RED) {
    'use strict';

    /**
     * MultiFlexi Artifact (filter) node.
     *
     * Takes a `job.completed` event (typically from a multiflexi-event node)
     * and emits one message per produced artifact, optionally filtered by the
     * producing RunTemplate ID and a filename pattern. This lets a producing
     * RunTemplate's output drive a downstream data-consumer RunTemplate,
     * forming a processing chain.
     */
    function MultiFlexiArtifactNode(config) {
        RED.nodes.createNode(this, config);
        const node = this;

        node.runtemplateId = config.runtemplateId ? parseInt(config.runtemplateId, 10) : null;
        node.filenamePattern = (config.filenamePattern || '').trim();
        let filenameRe = null;
        if (node.filenamePattern) {
            try {
                filenameRe = new RegExp(node.filenamePattern);
            } catch (e) {
                node.warn('Invalid filename pattern: ' + node.filenamePattern);
            }
        }

        node.on('input', function (msg, send, done) {
            send = send || function () { node.send.apply(node, arguments); };
            done = done || function () {};

            const event = msg.payload || {};

            if (event.event && event.event !== 'job.completed') {
                done();
                return;
            }

            if (node.runtemplateId && parseInt(event.runtemplate_id, 10) !== node.runtemplateId) {
                done();
                return;
            }

            const artifacts = Array.isArray(event.artifacts) ? event.artifacts : [];
            let emitted = 0;

            artifacts.forEach(function (artifact) {
                if (filenameRe && !filenameRe.test(artifact.filename || '')) {
                    return;
                }
                emitted++;
                send({
                    event: 'artifact',
                    job_id: event.job_id,
                    runtemplate_id: event.runtemplate_id,
                    app_uuid: event.app_uuid,
                    artifact: artifact,
                    payload: artifact,
                });
            });

            node.status({ fill: emitted ? 'green' : 'grey', shape: 'dot', text: emitted + ' artifact(s)' });
            done();
        });
    }

    RED.nodes.registerType('multiflexi-artifact', MultiFlexiArtifactNode);
};
