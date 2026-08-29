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
 * JobChainProcessor watches for finished jobs and, for each enabled
 * `event_rule` whose runtemplate_source_id matches the just-finished job's
 * RunTemplate, schedules the rule's target RunTemplate — the job-to-job
 * "produces → consumes" chaining path described in
 * passing_data_between_jobs.md.
 *
 * Unlike JobWatcher (which only forwards job.completed to Node-RED for
 * display/manual wiring), this class is the actual chaining engine: it reads
 * the finished job's produced data (Job::collectProducedData()), resolves
 * each rule's env_mapping selector against it, and schedules the target job
 * via multiflexi-cli — the same mechanism EventProcessor uses for
 * webhook-triggered rules. This is what the node-red-contrib-multiflexi
 * `multiflexi-map` node's own comments describe as "eventrules.php" — that
 * consumer did not previously exist anywhere in the codebase.
 *
 * When a selector resolves to a list of scalars (e.g. several invoices
 * matched by one payment-matching run), one target job is scheduled per
 * element rather than only the first — anything else would silently drop
 * confirmations/side effects for all but one match.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class JobChainProcessor extends DBEngine
{
    /**
     * Default maximum number of finished jobs processed per cycle.
     */
    public const DEFAULT_BATCH_LIMIT = 100;

    /**
     * @var string Path to the multiflexi-cli binary
     */
    private string $cliPath;

    /**
     * @var string Path to the high-water-mark state file
     */
    private string $stateFile;

    /**
     * @var null|int Cached last processed job id
     */
    private ?int $lastProcessed = null;

    /**
     * JobChainProcessor constructor.
     *
     * @param null|int             $identifier Record ID
     * @param array<string, mixed> $options    Additional options
     */
    public function __construct($identifier = null, $options = [])
    {
        $this->myTable = 'job';
        $this->keyColumn = 'id';
        parent::__construct($identifier, $options);
        $this->cliPath = (string) \Ease\Shared::cfg('MULTIFLEXI_CLI_PATH', 'multiflexi-cli');
        $this->stateFile = (string) \Ease\Shared::cfg(
            'JOBCHAIN_STATE_FILE',
            '/var/lib/multiflexi-eventor/last_chained_job',
        );
    }

    /**
     * Process all newly finished jobs against job-chaining event_rules.
     *
     * @return int Number of downstream jobs scheduled
     */
    public function processFinishedJobs(): int
    {
        $sinceId = $this->getLastProcessed();
        $limit = (int) \Ease\Shared::cfg('MULTIFLEXI_JOB_BATCH', self::DEFAULT_BATCH_LIMIT);

        $jobs = $this->listingQuery()
            ->where('job.end IS NOT NULL')
            ->where('job.id > ?', $sinceId)
            ->orderBy('job.id ASC')
            ->limit($limit)
            ->fetchAll();

        if (empty($jobs)) {
            return 0;
        }

        $scheduled = 0;
        $maxId = $sinceId;

        foreach ($jobs as $jobRow) {
            $jobId = (int) $jobRow['id'];
            $runtemplateId = (int) ($jobRow['runtemplate_id'] ?? 0);

            if ($runtemplateId > 0) {
                foreach (EventRule::getRulesForRunTemplate($runtemplateId) as $ruleData) {
                    $rule = new EventRule();
                    $rule->takeData($ruleData);
                    $rule->setMyKey($ruleData['id']);

                    try {
                        $scheduled += $this->scheduleChainedJobs($rule, $jobId);
                    } catch (\Throwable $e) {
                        $this->addStatusMessage(
                            sprintf(_('Job chain rule #%d failed for job #%d: %s'), $rule->getMyKey(), $jobId, $e->getMessage()),
                            'error',
                        );
                    }
                }
            }

            $maxId = max($maxId, $jobId);
        }

        $this->setLastProcessed($maxId);

        return $scheduled;
    }

    /**
     * Resolve a rule's produced data and schedule one job per fanned-out value.
     */
    private function scheduleChainedJobs(EventRule $rule, int $sourceJobId): int
    {
        $sourceJob = new Job($sourceJobId);

        if (!$sourceJob->getMyKey()) {
            return 0;
        }

        $produced = $sourceJob->collectProducedData();

        if (empty($produced)) {
            return 0;
        }

        $mapping = $rule->getEnvMapping();
        $fanKey = null;
        $fanValues = null;

        // Detect at most one mapping target whose selector resolves to a
        // list of scalars; that key drives the fan-out (one target job per
        // element). Other keys resolve as normal single values via
        // EventRule::buildEnvOverrides() below.
        foreach ($mapping as $envKey => $selector) {
            $raw = $this->resolveRaw((string) $selector, $produced);

            if (\is_array($raw) && $raw === array_values($raw)) {
                if ($fanKey !== null) {
                    // A second fan-out key would require cross-product
                    // semantics not needed today; keep the first only and
                    // warn instead of guessing.
                    $this->addStatusMessage(
                        sprintf(_('Job chain rule #%d: ignoring extra list-valued selector for %s (only one fan-out key supported)'), $rule->getMyKey(), $envKey),
                        'warning',
                    );

                    continue;
                }

                $fanKey = (string) $envKey;
                $fanValues = array_values(array_filter($raw, static fn ($v) => \is_scalar($v)));
            }
        }

        $baseOverrides = $rule->buildEnvOverrides($produced);
        $targetRuntemplateId = $rule->getRuntemplateId();
        $count = 0;

        // @file: selectors materialise a temp file whose path lives in
        // $baseOverrides; scheduleJob() below runs the target job to
        // completion synchronously (Native executor, exec() blocks), so it's
        // safe to remove those files once every job scheduled from this rule
        // has finished reading them.
        $tmpFiles = [];

        foreach ($rule->getEnvMapping() as $envKey => $selector) {
            if (str_starts_with((string) $selector, '@file:') && isset($baseOverrides[$envKey])) {
                $tmpFiles[] = $baseOverrides[$envKey];
            }
        }

        try {
            if ($fanKey !== null && !empty($fanValues)) {
                foreach ($fanValues as $value) {
                    $overrides = $baseOverrides;
                    $overrides[$fanKey] = (string) $value;

                    if ($this->scheduleJob($targetRuntemplateId, $overrides, $rule->getMyKey(), $sourceJobId)) {
                        ++$count;
                    }
                }
            } elseif ($this->scheduleJob($targetRuntemplateId, $baseOverrides, $rule->getMyKey(), $sourceJobId)) {
                ++$count;
            }
        } finally {
            foreach ($tmpFiles as $tmpFile) {
                if (is_string($tmpFile) && is_file($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        }

        return $count;
    }

    /**
     * Walk a dot-path selector against produced data without collapsing
     * arrays to null, so scheduleChainedJobs() can detect fan-out targets.
     * Mirrors EventRule's own path-walking but returns the raw value
     * (scalar, array, or null) instead of forcing it to a string.
     *
     * @param array<string, mixed> $source
     */
    private function resolveRaw(string $selector, array $source): mixed
    {
        if (str_starts_with($selector, '@file:')) {
            return null; // file selectors are handled by EventRule::buildEnvOverrides() itself
        }

        $path = ltrim($selector, '$.');
        $segments = explode('.', $path);
        $current = $source;

        foreach ($segments as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Schedule the target RunTemplate via multiflexi-cli (same mechanism as
     * EventProcessor::scheduleJob for webhook-triggered rules).
     *
     * @param array<string, string> $envOverrides
     */
    private function scheduleJob(int $runtemplateId, array $envOverrides, int $ruleId, int $sourceJobId): bool
    {
        $configArgs = [];

        foreach ($envOverrides as $key => $value) {
            $configArgs[] = '--config';
            $configArgs[] = escapeshellarg($key.'='.$value);
        }

        $command = sprintf(
            '%s run-template:schedule --id %d %s --schedule_time now --executor Native -f json',
            escapeshellcmd($this->cliPath),
            $runtemplateId,
            implode(' ', $configArgs),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->addStatusMessage(
                sprintf(
                    _('Job chain rule #%d (source job #%d): failed to schedule RunTemplate #%d (exit %d): %s'),
                    $ruleId,
                    $sourceJobId,
                    $runtemplateId,
                    $exitCode,
                    implode("\n", $output),
                ),
                'error',
            );

            return false;
        }

        $this->addStatusMessage(
            sprintf(_('Job chain rule #%d: source job #%d -> scheduled RunTemplate #%d'), $ruleId, $sourceJobId, $runtemplateId),
            'success',
        );

        return true;
    }

    /**
     * Read the persisted last-processed job id, initialising on first run.
     */
    private function getLastProcessed(): int
    {
        if ($this->lastProcessed !== null) {
            return $this->lastProcessed;
        }

        if (is_file($this->stateFile)) {
            $this->lastProcessed = (int) trim((string) file_get_contents($this->stateFile));

            return $this->lastProcessed;
        }

        // First run: start from the current maximum so history is not replayed.
        $maxRow = $this->listingQuery()->select(null)->select('MAX(id) AS max_id')->fetch();
        $this->lastProcessed = (int) ($maxRow['max_id'] ?? 0);
        $this->setLastProcessed($this->lastProcessed);

        return $this->lastProcessed;
    }

    /**
     * Persist the last-processed job id to the state file.
     */
    private function setLastProcessed(int $jobId): void
    {
        $this->lastProcessed = $jobId;
        $dir = \dirname($this->stateFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        if (@file_put_contents($this->stateFile, (string) $jobId) === false) {
            $this->addStatusMessage(
                sprintf(_('Unable to persist job-chain high-water mark to %s'), $this->stateFile),
                'warning',
            );
        }
    }
}
