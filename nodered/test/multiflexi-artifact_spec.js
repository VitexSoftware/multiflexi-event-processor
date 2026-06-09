'use strict';

var helper = require('node-red-node-test-helper');
var artifactNode = require('../multiflexi/multiflexi-artifact.js');

helper.init(require.resolve('node-red'));

describe('multiflexi-artifact node', function () {
    beforeEach(function (done) { helper.startServer(done); });
    afterEach(function (done) { helper.unload().then(function () { helper.stopServer(done); }); });

    it('should be loaded', function (done) {
        var flow = [{ id: 'n1', type: 'multiflexi-artifact', name: 'art' }];
        helper.load(artifactNode, flow, function () {
            var n1 = helper.getNode('n1');
            n1.should.have.property('name', 'art');
            done();
        });
    });

    it('emits one message per artifact of a job.completed event', function (done) {
        var flow = [
            { id: 'n1', type: 'multiflexi-artifact', name: 'art', wires: [['n2']] },
            { id: 'n2', type: 'helper' },
        ];
        helper.load(artifactNode, flow, function () {
            var n2 = helper.getNode('n2');
            var n1 = helper.getNode('n1');
            var seen = [];
            n2.on('input', function (msg) {
                seen.push(msg.payload.filename);
                if (seen.length === 2) {
                    seen.should.containEql('a.json');
                    seen.should.containEql('b.json');
                    done();
                }
            });
            n1.receive({
                payload: {
                    event: 'job.completed',
                    job_id: 1,
                    runtemplate_id: 15,
                    artifacts: [
                        { filename: 'a.json' },
                        { filename: 'b.json' },
                    ],
                },
            });
        });
    });

    it('filters artifacts by filename pattern', function (done) {
        var flow = [
            { id: 'n1', type: 'multiflexi-artifact', name: 'art', filenamePattern: '\\.json$', wires: [['n2']] },
            { id: 'n2', type: 'helper' },
        ];
        helper.load(artifactNode, flow, function () {
            var n2 = helper.getNode('n2');
            var n1 = helper.getNode('n1');
            n2.on('input', function (msg) {
                msg.payload.filename.should.equal('keep.json');
                done();
            });
            n1.receive({
                payload: {
                    event: 'job.completed',
                    artifacts: [
                        { filename: 'skip.txt' },
                        { filename: 'keep.json' },
                    ],
                },
            });
        });
    });
});
