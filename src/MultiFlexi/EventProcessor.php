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
     * EventProcessor constructor.
     *
     * @param int|null $identifier Record ID
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

        if (empty($rules)) {
            $this->addStatusMessage(
                sprintf(_('No enabled rules for source "%s", skipping %d change(s)'), $sourceData['name'] ?? $sourceId, \count($changes)),
                'debug',
            );

            return 0;
        }

        $processedCount = 0;

        foreach ($changes as $change) {
            try {
                $matched = $this->processChange($change, $rules, $source);

                if ($matched) {
                    ++$processedCount;
                }

                // Always update last_processed_id and wipe the cache record
                $inversion = (int) $change['inversion'];
                $source->wipeCacheRecord($inversion);
                $source->updateLastProcessed($inversion);
            } catch (\Throwable $e) {
                $this->addStatusMessage(
                    sprintf(_('Error processing change %d: %s'), $change['inversion'] ?? 0, $e->getMessage()),
                    'error',
                );
            }
        }

        return $processedCount;
    }

    /**
     * Match a single change against rules and schedule jobs for matches.
     *
     * @param array<string, mixed>        $change Change record from changes_cache
     * @param array<int, array>           $rules  Pre-loaded rule records for this source
     * @param EventSource                 $source The event source instance
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
                $this->scheduleJob($rule, $change);
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
     * Schedule a MultiFlexi job via multiflexi-cli for a matched rule.
     *
     * @param EventRule            $rule   The matched event rule
     * @param array<string, mixed> $change The change record data
     *
     * @return bool True if scheduling succeeded
     */
    public function scheduleJob(EventRule $rule, array $change): bool
    {
        $runtemplateId = $rule->getRuntemplateId();
        $envOverrides = $rule->buildEnvOverrides($change);

        $configArgs = [];

        foreach ($envOverrides as $key => $value) {
            $configArgs[] = '--config';
            $configArgs[] = escapeshellarg($key.'='.$value);
        }

        $command = sprintf(
            '%s runtemplate schedule --id %d %s --schedule_time now --executor Native --schedule_type %s -f json',
            escapeshellcmd($this->cliPath),
            $runtemplateId,
            implode(' ', $configArgs),
            self::SCHEDULE_TYPE,
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
