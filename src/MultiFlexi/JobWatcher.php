<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi;

/**
 * JobWatcher polls the MultiFlexi `job` table for newly finished jobs and
 * forwards a `job.completed` event (with artifact metadata) to Node-RED.
 *
 * This powers the "RunTemplate produces output → next RunTemplate" chain:
 * Node-RED receives each completion, inspects the produced artifacts, and may
 * schedule a downstream data-consumer RunTemplate.
 *
 * A persistent high-water mark (last forwarded job id) is kept in a state file
 * so restarts do not re-forward already-seen jobs. On first run the mark is
 * initialised to the current maximum job id, so only jobs that finish after the
 * daemon starts are forwarded.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class JobWatcher extends DBEngine
{
    /**
     * Default maximum number of finished jobs forwarded per cycle.
     */
    public const DEFAULT_BATCH_LIMIT = 100;

    /**
     * @var NodeRedBridge Outbound forwarder
     */
    private NodeRedBridge $bridge;

    /**
     * @var string Path to the high-water-mark state file
     */
    private string $stateFile;

    /**
     * @var null|int Cached last forwarded job id
     */
    private ?int $lastForwarded = null;

    /**
     * JobWatcher constructor.
     *
     * @param NodeRedBridge        $bridge     The Node-RED forwarder to use
     * @param null|int             $identifier Record ID
     * @param array<string, mixed> $options    Additional options
     */
    public function __construct(NodeRedBridge $bridge, $identifier = null, $options = [])
    {
        $this->myTable = 'job';
        $this->keyColumn = 'id';
        parent::__construct($identifier, $options);
        $this->bridge = $bridge;
        $this->stateFile = (string) \Ease\Shared::cfg(
            'NODERED_JOB_STATE_FILE',
            '/var/lib/multiflexi-eventor/last_forwarded_job',
        );
    }

    /**
     * Forward all newly finished jobs to Node-RED.
     *
     * @return int Number of jobs forwarded
     */
    public function forwardFinishedJobs(): int
    {
        if (!$this->bridge->isEnabled()) {
            return 0;
        }

        $sinceId = $this->getLastForwarded();
        $limit = (int) \Ease\Shared::cfg('MULTIFLEXI_JOB_BATCH', self::DEFAULT_BATCH_LIMIT);

        $jobs = $this->listingQuery()
            ->select('apps.uuid AS app_uuid')
            ->leftJoin('apps ON apps.id = job.app_id')
            ->where('job.end IS NOT NULL')
            ->where('job.id > ?', $sinceId)
            ->orderBy('job.id ASC')
            ->limit($limit)
            ->fetchAll();

        if (empty($jobs)) {
            return 0;
        }

        $forwarded = 0;
        $maxId = $sinceId;

        foreach ($jobs as $jobRow) {
            $jobId = (int) $jobRow['id'];
            $artifacts = $this->getArtifactsForJob($jobId);

            if ($this->bridge->forwardJob($jobRow, $artifacts)) {
                ++$forwarded;
            }

            // Advance the mark regardless so a single unforwardable job does
            // not block the queue forever.
            $maxId = max($maxId, $jobId);
        }

        $this->setLastForwarded($maxId);

        return $forwarded;
    }

    /**
     * Fetch lightweight artifact metadata for a job (without the content blob).
     *
     * @param int $jobId job.id
     *
     * @return array<int, array<string, mixed>> Artifact descriptors
     */
    public function getArtifactsForJob(int $jobId): array
    {
        $rows = $this->getFluentPDO(true)
            ->from('artifacts')
            ->select(null)
            ->select('id, filename, content_type, note, created_at')
            ->where('job_id', $jobId)
            ->fetchAll();

        return $rows ?: [];
    }

    /**
     * Read the persisted last-forwarded job id, initialising on first run.
     */
    private function getLastForwarded(): int
    {
        if ($this->lastForwarded !== null) {
            return $this->lastForwarded;
        }

        if (is_file($this->stateFile)) {
            $this->lastForwarded = (int) trim((string) file_get_contents($this->stateFile));

            return $this->lastForwarded;
        }

        // First run: start from the current maximum so history is not replayed.
        $maxRow = $this->listingQuery()->select(null)->select('MAX(id) AS max_id')->fetch();
        $this->lastForwarded = (int) ($maxRow['max_id'] ?? 0);
        $this->setLastForwarded($this->lastForwarded);

        return $this->lastForwarded;
    }

    /**
     * Persist the last-forwarded job id to the state file.
     */
    private function setLastForwarded(int $jobId): void
    {
        $this->lastForwarded = $jobId;
        $dir = \dirname($this->stateFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        if (@file_put_contents($this->stateFile, (string) $jobId) === false) {
            $this->addStatusMessage(
                sprintf(_('Unable to persist Node-RED job high-water mark to %s'), $this->stateFile),
                'warning',
            );
        }
    }
}
