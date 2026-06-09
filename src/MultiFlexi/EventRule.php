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
 * EventRule maps an incoming change (evidence + operation) to a MultiFlexi
 * RunTemplate, optionally translating change fields into job environment
 * variables.
 *
 * Rules live in the `event_rule` table (see multiflexi-database migrations).
 * A rule matches a change when:
 *  - the rule is enabled, AND
 *  - the rule evidence is null (wildcard) or equals the change evidence, AND
 *  - the rule operation is OPERATION_ANY or equals the change operation.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class EventRule extends DBEngine
{
    /**
     * Operation wildcard — a rule with this operation matches any operation.
     */
    public const OPERATION_ANY = 'any';

    /**
     * EventRule constructor.
     *
     * @param null|int             $identifier Record ID
     * @param array<string, mixed> $options    Additional options
     */
    public function __construct($identifier = null, $options = [])
    {
        $this->myTable = 'event_rule';
        $this->keyColumn = 'id';
        $this->nameColumn = 'id';
        parent::__construct($identifier, $options);
    }

    /**
     * Load enabled rules for an event source, highest priority first.
     *
     * @param int $eventSourceId event_source.id
     *
     * @return array<int, array<string, mixed>> Rule records
     */
    public function getRulesForSource(int $eventSourceId): array
    {
        $rules = $this->listingQuery()
            ->where('event_source_id', $eventSourceId)
            ->where('enabled', 1)
            ->orderBy('priority DESC, id ASC')
            ->fetchAll();

        return $rules ?: [];
    }

    /**
     * Check whether this rule matches a change record.
     *
     * @param array<string, mixed> $change Change record from changes_cache
     *
     * @return bool True if the rule applies to the change
     */
    public function matches(array $change): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $ruleEvidence = $this->getDataValue('evidence');

        // null/empty evidence is a wildcard matching any evidence
        if (null !== $ruleEvidence && '' !== $ruleEvidence) {
            if (($change['evidence'] ?? null) !== $ruleEvidence) {
                return false;
            }
        }

        $ruleOperation = $this->getDataValue('operation');

        if (self::OPERATION_ANY !== $ruleOperation && null !== $ruleOperation) {
            if (($change['operation'] ?? null) !== $ruleOperation) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the environment variable overrides to inject into the triggered job.
     *
     * Standard metadata (EVENT_INVERSION, EVENT_EVIDENCE, EVENT_OPERATION,
     * EVENT_RECORD_ID) is always present. Custom mappings come from the
     * `env_mapping` JSON column ({"ENV_VAR": "change_field"}).
     *
     * @param array<string, mixed> $change Change record from changes_cache
     *
     * @return array<string, string> Environment variable name => value
     */
    public function buildEnvOverrides(array $change): array
    {
        $env = [
            'EVENT_INVERSION' => (string) ($change['inversion'] ?? ''),
            'EVENT_EVIDENCE' => (string) ($change['evidence'] ?? ''),
            'EVENT_OPERATION' => (string) ($change['operation'] ?? ''),
            'EVENT_RECORD_ID' => (string) ($change['recordid'] ?? ''),
        ];

        $mappingRaw = $this->getDataValue('env_mapping');

        if (!empty($mappingRaw)) {
            $mapping = json_decode((string) $mappingRaw, true);

            if (\is_array($mapping)) {
                foreach ($mapping as $envName => $changeField) {
                    if (\array_key_exists($changeField, $change)) {
                        $env[(string) $envName] = (string) $change[$changeField];
                    }
                }
            }
        }

        return $env;
    }

    /**
     * Get the RunTemplate ID this rule triggers.
     *
     * @return int runtemplate.id
     */
    public function getRuntemplateId(): int
    {
        return (int) $this->getDataValue('runtemplate_id');
    }

    /**
     * Whether this rule is currently enabled.
     */
    public function isEnabled(): bool
    {
        $enabled = $this->getDataValue('enabled');

        // Accept bool, int and string representations from different drivers
        return $enabled === true || $enabled === 1 || $enabled === '1';
    }
}
