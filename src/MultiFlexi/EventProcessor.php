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
 * EventProcessor is the main engine that polls event sources,
 * matches changes against rules, and schedules MultiFlexi jobs.
 *
 * Processing loop:
 * 1. Iterate all enabled EventSources
 * 2. For each source, read unprocessed changes from its adapter DB
 * 3. For each change, find matching EventRules
 * 4. For each matching rule, schedule a job via multiflexi-cli
 * 5. Mark the change as processed (wipe from cache, update last_processed_id)
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class EventProcessor extends DBEngine
{
    /**
     * Schedule type identifier for event-triggered jobs.
     */
    public const SCHEDULE_TYPE = 'event';

    /**
     * @var string Path to the multiflexi-cli binary
     */
    private string $cliPath;

    /**
     * @var EventSource Event source helper instance
     */
    private EventSource $eventSourceHelper;

    /**
     * @var EventRule Event rule helper instance
     */
    private EventRule $eventRuleHelper;

    /**
     * @var NodeRedBridge Outbound forwarder to Node-RED
     */
    private NodeRedBridge $nodeRed;

    /**
     * EventProcessor constructor.
     *
     * @param null|int $identifier Record ID
     * @param array    $options    Additional options
     */
    public function __construct($identifier = null, $options = [])
    {
        $this->myTable = 'event_source';
        $this->keyColumn = 'id';
        parent::__construct($identifier, $options);
        $this->cliPath = \Ease\Shared::cfg('MULTIFLEXI_CLI_PATH', 'multiflexi-cli');
        $this->eventSourceHelper = new EventSource();
        $this->eventRuleHelper = new EventRule();
        $this->nodeRed = new NodeRedBridge();
    }

    /**
     * Whether changes should be forwarded to Node-RED.
     *
     * Controlled by NODERED_FORWARD_CHANGES (default true when a Node-RED URL
     * is configured).
     */
    public function forwardsChangesToNodeRed(): bool
    {
        if (!$this->nodeRed->isEnabled()) {
            return false;
        }

        return (bool) \Ease\Shared::cfg('NODERED_FORWARD_CHANGES', true);
    }

    /**
     * Poll all enabled event sources and process their changes.
     *
     * @return int Total number of changes processed
     */
    public function pollAndProcess(): int
    {
        $totalProcessed = 0;
        $sources = $this->eventSourceHelper->getEnabledSources();

        foreach ($sources as $sourceData) {
            try {
                $totalProcessed += $this->processSource($sourceData);
            } catch (\Throwable $e) {
                $this->addStatusMessage(
                    sprintf(_('Error processing source "%s": %s'), $sourceData['name'] ?? $sourceData['id'], $e->getMessage()),
                    'error',
                );
            }
        }

        return $totalProcessed;
    }

    /**
     * Process all unprocessed changes from a single event source.
     *
     * @param array<string, mixed> $sourceData Event source record data
     *
     * @return int Number of changes processed from this source
     */
    public function processSource(array $sourceData): int
    {
        $source = new EventSource();
        $source->takeData($sourceData);
        $source->setMyKey($sourceData['id']);

        if (!$source->isReachable()) {
            return 0;
        }

        $changes = $source->getUnprocessedChanges();

        if (empty($changes)) {
            return 0;
        }

        $sourceId = (int) $sourceData['id'];
        $rules = $this->eventRuleHelper->getRulesForSource($sourceId);
        $forwardToNodeRed = $this->forwardsChangesToNodeRed();
        $flowInterpreter = new FlowInterpreter();

        $processedCount = 0;

        foreach ($changes as $change) {
            try {
                if ($forwardToNodeRed) {
                    $this->nodeRed->forwardChange($change, $sourceData);
                }

                $matched = $this->processChange($change, $rules, $source);
                $flowStarted = $flowInterpreter->startFromChange($change, $sourceData);

                if ($matched || $flowStarted > 0) {
                    ++$processedCount;
                }

                // Tasks exist now: advance the cursor and wipe the cache record
                $inversion = (int) $change['inversion'];
                $source->wipeCacheRecord($inversion);
                $source->updateLastProcessed($inversion);
            } catch (\Throwable $e) {
                $this->addStatusMessage(
                    sprintf(_('Error processing change %d: %s'), $change['inversion'] ?? 0, $e->getMessage()),
                    'error',
                );

                // Keep order and do not skip the failed change: retry on the next poll
                break;
            }
        }

        return $processedCount;
    }

    /**
     * Match a single change against rules and schedule jobs for matches.
     *
     * @param array<string, mixed> $change Change record from changes_cache
     * @param array<int, array>    $rules  Pre-loaded rule records for this source
     * @param EventSource          $source The event source instance
     *
     * @return bool True if at least one rule matched
     */
    public function processChange(array $change, array $rules, EventSource $source): bool
    {
        $matched = false;

        foreach ($rules as $ruleData) {
            $rule = new EventRule();
            $rule->takeData($ruleData);
            $rule->setMyKey($ruleData['id']);

            if ($rule->matches($change)) {
                $this->expandToTasks($rule, $change);
                $matched = true;

                $this->addStatusMessage(
                    sprintf(
                        _('Change %d (%s/%s) matched rule #%d → RunTemplate #%d'),
                        $change['inversion'] ?? 0,
                        $change['evidence'] ?? '?',
                        $change['operation'] ?? '?',
                        $rule->getMyKey(),
                        $rule->getRuntemplateId(),
                    ),
                    'success',
                );
            }
        }

        return $matched;
    }

    /**
     * Turn one matched change into Tasks (idempotent).
     *
     * @param array<string, mixed> $change
     *
     * @return int Number of Tasks the change maps to
     */
    public function expandToTasks(EventRule $rule, array $change): int
    {
        $rt = new RunTemplate($rule->getRuntemplateId());
        $count = 0;

        if ($rule->isItemFanout()) {
            foreach ($rule->matchingItems($change) as $item) {
                $env = $rule->buildItemEnvOverrides($change, $item);
                Task::forEvent($rt, $env['EVENT_IDEMPOTENCY_KEY'], $env);
                ++$count;
            }

            return $count;
        }

        $env = $rule->buildEnvOverrides($change);
        $key = EventRule::idempotencyKey($change, (int) $rule->getMyKey());
        $env['EVENT_IDEMPOTENCY_KEY'] = $key;
        Task::forEvent($rt, $key, $env);

        return 1;
    }

    /**
     * Phase 2 (execute): start a job for every open event Task that is due.
     * The job is linked to the Task; Job::updateTaskState() fulfils it or
     * schedules the retry, and a Task that runs out of attempts becomes dead.
     *
     * @return int Number of jobs scheduled
     */
    public function executeDueTasks(): int
    {
        $started = 0;

        foreach (Task::dueEventTasks() as $row) {
            $task = new Task();
            $task->takeData($row);
            $task->setMyKey((int) $row['id']);

            $ok = $this->scheduleJob(
                $task->getContext(),
                (int) $row['runtemplate_id'],
                (int) $row['id'],
            );

            if ($ok) {
                $task->markDispatched();
                ++$started;
            } else {
                $task->recordAttemptFailure(_('Scheduling the job failed'));
            }
        }

        return $started;
    }

    /**
     * Schedule a MultiFlexi job via multiflexi-cli for a matched rule.
     *
     * @param array<string, string> $envOverrides env overrides of the event
     * @param int                   $runtemplateId RunTemplate to run
     * @param int                   $taskId        Task the job belongs to (0 = none)
     *
     * @return bool True if scheduling succeeded
     */
    public function scheduleJob(array $envOverrides, int $runtemplateId, int $taskId = 0): bool
    {

        $configArgs = [];

        foreach ($envOverrides as $key => $value) {
            $configArgs[] = '--config';
            $configArgs[] = escapeshellarg($key.'='.$value);
        }

        // Note: run-template:schedule derives schedule_type (adhoc/cli) from the
        // schedule time itself; it has no --schedule_type option. SCHEDULE_TYPE
        // is retained for event metadata forwarded to Node-RED.
        $command = sprintf(
            '%s run-template:schedule --id %d %s%s --schedule_time now --executor Native -f json',
            escapeshellcmd($this->cliPath),
            $runtemplateId,
            implode(' ', $configArgs),
            $taskId > 0 ? ' --task_id '.$taskId : '',
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->addStatusMessage(
                sprintf(
                    _('Failed to schedule job for RunTemplate #%d (exit code: %d): %s'),
                    $runtemplateId,
                    $exitCode,
                    implode("\n", $output),
                ),
                'error',
            );

            return false;
        }

        $this->addStatusMessage(
            sprintf(_('Scheduled job for RunTemplate #%d via event trigger'), $runtemplateId),
            'success',
        );

        return true;
    }
}
