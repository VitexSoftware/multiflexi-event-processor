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

use MultiFlexi\Flow\FlowExecutableCatalog;
use MultiFlexi\Flow\FlowMsg;
use MultiFlexi\Flow\FlowRepository;
use MultiFlexi\Flow\FlowRun;
use MultiFlexi\Flow\FlowRunStep;

/**
 * Executes stored MultiFlexi flow graphs (designed in Node-RED).
 *
 * Advances ready steps, schedules RunTemplates, resumes on job completion,
 * and evaluates switch/delay/map without requiring Node-RED at runtime.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class FlowInterpreter extends DBEngine
{
    private string $cliPath;

    private FlowRepository $repository;

    public function __construct($identifier = null, $options = [])
    {
        $this->myTable = 'flow_run';
        $this->keyColumn = 'id';
        parent::__construct($identifier, $options);
        $this->cliPath = (string) \Ease\Shared::cfg('MULTIFLEXI_CLI_PATH', 'multiflexi-cli');
        $this->repository = new FlowRepository();
    }

    /**
     * Start flow runs for webhook changes that match multiflexi-event triggers.
     *
     * @param array<string, mixed> $change
     * @param array<string, mixed> $sourceData
     *
     * @return int Number of runs started
     */
    public function startFromChange(array $change, array $sourceData): int
    {
        $sourceId = isset($sourceData['id']) ? (int) $sourceData['id'] : null;
        $triggers = $this->repository->findTriggersForChange($change, $sourceId);
        $started = 0;

        foreach ($triggers as $match) {
            $flowId = (int) $match['flow']['id'];
            $versionId = (int) $match['version_id'];
            $triggerNodeId = (string) $match['trigger_node']['nr_node_id'];
            $inversion = (int) ($change['inversion'] ?? 0);
            $idem = sprintf('change:%d:%d:%s', $sourceId ?? 0, $inversion, $triggerNodeId);

            if ($this->runExists($flowId, $idem)) {
                continue;
            }

            $companyId = $match['flow']['company_id'] ?? ($change['company_id'] ?? null);
            $msg = new FlowMsg(
                payload: $change,
                env: [],
                produced: [],
                meta: [
                    'trigger' => 'webhook.change',
                    'event_source_id' => $sourceId,
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                ],
            );

            $runId = $this->createRun($flowId, $versionId, $companyId, $idem, $msg);

            if ($runId === null) {
                continue;
            }

            // Mark trigger as completed and enqueue outbound wires on port 0.
            $this->completeStep($runId, $triggerNodeId, $msg, 0);
            $this->enqueueDownstream($runId, $versionId, $triggerNodeId, 0, $msg);
            ++$started;
            $this->addStatusMessage(
                sprintf(_('Started flow run #%d for flow #%d (change inversion %d)'), $runId, $flowId, $inversion),
                'success',
            );
        }

        return $started;
    }

    /**
     * Advance all ready / due delay steps and resume waiting jobs.
     *
     * @return int Number of steps advanced
     */
    public function tick(): int
    {
        $advanced = 0;
        $advanced += $this->resumeFinishedJobs();
        $advanced += $this->processReadySteps();
        $advanced += $this->processDueDelays();
        $this->finalizeCompletedRuns();

        return $advanced;
    }

    /**
     * Cancel a running flow (best-effort; pending steps skipped).
     */
    public function cancelRun(int $runId): bool
    {
        return $this->repository->cancelRun($runId);
    }

    private function runExists(int $flowId, string $idem): bool
    {
        $stmt = $this->getPdo()->prepare('SELECT id FROM flow_run WHERE flow_id = ? AND idempotency_key = ? LIMIT 1');
        $stmt->execute([$flowId, $idem]);

        return (bool) $stmt->fetchColumn();
    }

    private function createRun(int $flowId, int $versionId, $companyId, string $idem, FlowMsg $msg): ?int
    {
        try {
            $run = new FlowRun();
            $run->setDataValue('flow_id', $flowId);
            $run->setDataValue('flow_version_id', $versionId);
            $run->setDataValue('company_id', $companyId);
            $run->setDataValue('idempotency_key', $idem);
            $run->setDataValue('trigger_json', $msg->toJson());
            $run->setDataValue('status', FlowRun::STATUS_RUNNING);
            $run->setDataValue('started', date('Y-m-d H:i:s'));

            if (!$run->dbsync()) {
                return null;
            }

            return (int) $run->getMyKey();
        } catch (\Throwable $e) {
            // Unique idempotency collision
            $this->addStatusMessage($e->getMessage(), 'warning');

            return null;
        }
    }

    private function enqueueDownstream(int $runId, int $versionId, string $fromNodeId, int $fromPort, FlowMsg $msg): void
    {
        foreach ($this->repository->getWires($versionId) as $wire) {
            if ((string) $wire['from_nr_node_id'] !== $fromNodeId) {
                continue;
            }

            if ((int) $wire['from_port'] !== $fromPort) {
                continue;
            }

            $this->ensureReadyStep($runId, (string) $wire['to_nr_node_id'], $msg);
        }
    }

    private function ensureReadyStep(int $runId, string $nrNodeId, FlowMsg $msg): void
    {
        $pdo = $this->getPdo();
        $check = $pdo->prepare('SELECT id, status FROM flow_run_step WHERE flow_run_id = ? AND nr_node_id = ? LIMIT 1');
        $check->execute([$runId, $nrNodeId]);
        $existing = $check->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            if (\in_array($existing['status'], [FlowRunStep::STATUS_COMPLETED, FlowRunStep::STATUS_WAITING_JOB, FlowRunStep::STATUS_FAILED], true)) {
                return;
            }

            $pdo->prepare('UPDATE flow_run_step SET status = ?, input_json = ? WHERE id = ?')->execute([
                FlowRunStep::STATUS_READY,
                $msg->toJson(),
                $existing['id'],
            ]);

            return;
        }

        $pdo->prepare(
            'INSERT INTO flow_run_step (flow_run_id, nr_node_id, status, input_json) VALUES (?, ?, ?, ?)',
        )->execute([$runId, $nrNodeId, FlowRunStep::STATUS_READY, $msg->toJson()]);
    }

    private function completeStep(int $runId, string $nrNodeId, FlowMsg $out, ?int $outputPort, ?int $jobId = null): void
    {
        $pdo = $this->getPdo();
        $check = $pdo->prepare('SELECT id FROM flow_run_step WHERE flow_run_id = ? AND nr_node_id = ? LIMIT 1');
        $check->execute([$runId, $nrNodeId]);
        $id = $check->fetchColumn();

        if ($id) {
            $pdo->prepare(
                'UPDATE flow_run_step SET status = ?, output_json = ?, output_port = ?, job_id = COALESCE(?, job_id), error = NULL WHERE id = ?',
            )->execute([FlowRunStep::STATUS_COMPLETED, $out->toJson(), $outputPort, $jobId, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO flow_run_step (flow_run_id, nr_node_id, status, output_json, output_port, job_id) VALUES (?, ?, ?, ?, ?, ?)',
            )->execute([$runId, $nrNodeId, FlowRunStep::STATUS_COMPLETED, $out->toJson(), $outputPort, $jobId]);
        }
    }

    private function processReadySteps(): int
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->query(
            "SELECT s.*, r.flow_version_id, r.flow_id, r.company_id, r.status AS run_status
             FROM flow_run_step s
             INNER JOIN flow_run r ON r.id = s.flow_run_id
             WHERE s.status = 'ready' AND r.status = 'running'
             ORDER BY s.id ASC
             LIMIT 50",
        );
        $count = 0;

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $step) {
            try {
                $this->executeStep($step);
                ++$count;
            } catch (\Throwable $e) {
                $pdo->prepare('UPDATE flow_run_step SET status = ?, error = ? WHERE id = ?')->execute([
                    FlowRunStep::STATUS_FAILED,
                    $e->getMessage(),
                    $step['id'],
                ]);
                $pdo->prepare('UPDATE flow_run SET status = ?, finished = ? WHERE id = ?')->execute([
                    FlowRun::STATUS_FAILED,
                    date('Y-m-d H:i:s'),
                    $step['flow_run_id'],
                ]);
                $this->addStatusMessage(
                    sprintf(_('Flow step %s failed: %s'), $step['nr_node_id'], $e->getMessage()),
                    'error',
                );
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function executeStep(array $step): void
    {
        $runId = (int) $step['flow_run_id'];
        $versionId = (int) $step['flow_version_id'];
        $nrNodeId = (string) $step['nr_node_id'];
        $node = $this->findNode($versionId, $nrNodeId);

        if ($node === null) {
            throw new \RuntimeException('Node not found in flow_version: '.$nrNodeId);
        }

        $type = (string) $node['type'];
        $config = \is_array($node['config']) ? $node['config'] : (json_decode((string) $node['config'], true) ?: []);
        $input = FlowMsg::fromJson($step['input_json'] ?? null);

        switch ($type) {
            case 'multiflexi-event':
            case 'multiflexi-company':
            case 'multiflexi-application':
            case 'multiflexi-credential':
            case 'link in':
            case 'link out':
                $this->passThrough($runId, $versionId, $nrNodeId, $input);

                break;

            case 'multiflexi-map':
                $this->executeMap($runId, $versionId, $nrNodeId, $config, $input);

                break;

            case 'multiflexi-artifact':
                $this->passThrough($runId, $versionId, $nrNodeId, $input);

                break;

            case 'multiflexi-runtemplate':
                $this->executeRuntemplate($step, $node, $config, $input);

                break;

            case 'switch':
                $this->executeSwitch($runId, $versionId, $nrNodeId, $config, $input);

                break;

            case 'delay':
                $this->executeDelay((int) $step['id'], $config);

                break;

            case 'catch':
                $this->passThrough($runId, $versionId, $nrNodeId, $input);

                break;

            default:
                throw new \RuntimeException('Unsupported node type at runtime: '.$type);
        }
    }

    private function passThrough(int $runId, int $versionId, string $nrNodeId, FlowMsg $input): void
    {
        $this->completeStep($runId, $nrNodeId, $input, 0);
        $this->enqueueDownstream($runId, $versionId, $nrNodeId, 0, $input);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function executeMap(int $runId, int $versionId, string $nrNodeId, array $config, FlowMsg $input): void
    {
        $mapping = $config['env_mapping'] ?? $config['envMapping'] ?? [];

        if (\is_string($mapping)) {
            $mapping = json_decode($mapping, true) ?: [];
        }

        // Map against produced data (job output), not the webhook payload —
        // EventRule::buildEnvOverrides() would otherwise inject EVENT_* from
        // the merged change record and pollute the downstream job env.
        $source = $input->produced !== [] ? $input->produced : $input->payload;
        $env = [];

        foreach ($mapping as $envKey => $selector) {
            if (!\is_string($selector) || $selector === '') {
                continue;
            }

            $value = $this->resolveMapSelector($selector, $source, $input->produced);

            if ($value !== null) {
                $env[(string) $envKey] = $value;
            }
        }

        $out = $input->withEnv($env);
        $this->completeStep($runId, $nrNodeId, $out, 0);
        $this->enqueueDownstream($runId, $versionId, $nrNodeId, 0, $out);
    }

    /**
     * Resolve a map selector against produced/source data.
     * Arrays are JSON-encoded so list-valued produces (e.g. matched_documents) can pass as DOCID.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $produced
     */
    private function resolveMapSelector(string $selector, array $source, array $produced): ?string
    {
        if (str_starts_with($selector, '@file:')) {
            $rule = new EventRule();
            $rule->setDataValue('env_mapping', json_encode(['_tmp' => $selector]));
            $tmp = $rule->buildEnvOverrides($produced, $produced);

            return $tmp['_tmp'] ?? null;
        }

        $path = ltrim($selector, '$.');
        $current = $source;

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        if ($current === null) {
            return null;
        }

        if (\is_scalar($current)) {
            return (string) $current;
        }

        return (string) json_encode($current, \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $node
     * @param array<string, mixed> $config
     */
    private function executeRuntemplate(array $step, array $node, array $config, FlowMsg $input): void
    {
        $runtemplateId = (int) ($config['runtemplateId'] ?? $config['runtemplate_id'] ?? 0);

        if ($runtemplateId <= 0) {
            throw new \RuntimeException('multiflexi-runtemplate node missing runtemplateId');
        }

        $env = $input->env;
        $staticEnv = $config['env'] ?? [];

        if (\is_string($staticEnv)) {
            $decoded = json_decode($staticEnv, true);

            if (\is_array($decoded)) {
                // Node-RED stores [{key,value}]
                if (isset($decoded[0]['key'])) {
                    $tmp = [];

                    foreach ($decoded as $pair) {
                        if (!empty($pair['key'])) {
                            $tmp[(string) $pair['key']] = (string) ($pair['value'] ?? '');
                        }
                    }

                    $staticEnv = $tmp;
                } else {
                    $staticEnv = $decoded;
                }
            } else {
                $staticEnv = [];
            }
        }

        if (\is_array($staticEnv)) {
            $env = array_merge($staticEnv, $env);
        }

        $jobId = $this->scheduleJob(
            $runtemplateId,
            $env,
            (int) $step['flow_run_id'],
            (int) $step['id'],
            isset($input->meta['source_job_id']) ? (int) $input->meta['source_job_id'] : null,
        );

        if ($jobId === null) {
            throw new \RuntimeException('Failed to schedule RunTemplate #'.$runtemplateId);
        }

        $this->getPdo()->prepare(
            'UPDATE flow_run_step SET status = ?, job_id = ?, input_json = ? WHERE id = ?',
        )->execute([FlowRunStep::STATUS_WAITING_JOB, $jobId, $input->toJson(), $step['id']]);

        $this->addStatusMessage(
            sprintf(_('Flow run #%d: scheduled job #%d for RT #%d'), $step['flow_run_id'], $jobId, $runtemplateId),
            'success',
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function executeSwitch(int $runId, int $versionId, string $nrNodeId, array $config, FlowMsg $input): void
    {
        $property = (string) ($config['property'] ?? 'meta.exitcode');
        $rules = $config['rules'] ?? $config['outputs'] ?? [];

        if (\is_string($rules)) {
            $rules = json_decode($rules, true) ?: [];
        }

        $value = $this->resolveProperty($input, $property);
        $matchedPort = null;

        foreach (array_values($rules) as $port => $rule) {
            if ($this->ruleMatches($rule, $value)) {
                $matchedPort = (int) $port;

                break;
            }
        }

        // Node-RED switch often has an otherwise port as last output.
        if ($matchedPort === null && !empty($config['checkall']) === false && \count($rules) > 0) {
            // fall through: no match → no enqueue (strict)
            $matchedPort = null;
        }

        $out = $input->withMeta(['switch_property' => $property, 'switch_value' => $value, 'switch_port' => $matchedPort]);
        $this->completeStep($runId, $nrNodeId, $out, $matchedPort);

        if ($matchedPort !== null) {
            $this->enqueueDownstream($runId, $versionId, $nrNodeId, $matchedPort, $out);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function executeDelay(int $stepId, array $config): void
    {
        $seconds = (int) ($config['timeout'] ?? $config['delay'] ?? 1);
        $unit = (string) ($config['timeoutUnits'] ?? $config['units'] ?? 'seconds');

        $multiplier = match ($unit) {
            'minutes' => 60,
            'hours' => 3600,
            'days' => 86400,
            default => 1,
        };

        $due = date('Y-m-d H:i:s', time() + max(0, $seconds * $multiplier));
        $this->getPdo()->prepare(
            'UPDATE flow_run_step SET status = ?, due_at = ? WHERE id = ?',
        )->execute([FlowRunStep::STATUS_WAITING_DELAY, $due, $stepId]);
    }

    private function processDueDelays(): int
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->query(
            "SELECT s.*, r.flow_version_id FROM flow_run_step s
             INNER JOIN flow_run r ON r.id = s.flow_run_id
             WHERE s.status = 'waiting_delay' AND s.due_at IS NOT NULL AND s.due_at <= CURRENT_TIMESTAMP
               AND r.status = 'running'
             LIMIT 50",
        );
        $count = 0;

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $step) {
            $msg = FlowMsg::fromJson($step['input_json'] ?? null);
            $this->completeStep((int) $step['flow_run_id'], (string) $step['nr_node_id'], $msg, 0);
            $this->enqueueDownstream((int) $step['flow_run_id'], (int) $step['flow_version_id'], (string) $step['nr_node_id'], 0, $msg);
            ++$count;
        }

        return $count;
    }

    private function resumeFinishedJobs(): int
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->query(
            "SELECT s.*, r.flow_version_id, j.exitcode, j.id AS finished_job_id
             FROM flow_run_step s
             INNER JOIN flow_run r ON r.id = s.flow_run_id
             INNER JOIN job j ON j.id = s.job_id
             WHERE s.status = 'waiting_job' AND j.end IS NOT NULL AND r.status = 'running'
             ORDER BY s.id ASC
             LIMIT 50",
        );
        $count = 0;

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $step) {
            $jobId = (int) $step['finished_job_id'];
            $job = new Job($jobId);
            $produced = $job->getMyKey() ? $job->collectProducedData() : [];
            $input = FlowMsg::fromJson($step['input_json'] ?? null);
            $out = new FlowMsg(
                payload: array_merge($input->payload, ['job_id' => $jobId, 'exitcode' => $step['exitcode']]),
                env: $input->env,
                produced: $produced,
                meta: array_merge($input->meta, [
                    'exitcode' => isset($step['exitcode']) ? (int) $step['exitcode'] : null,
                    'source_job_id' => $jobId,
                    'job_id' => $jobId,
                ]),
            );

            $port = ((int) ($step['exitcode'] ?? 1) === 0) ? 0 : 0; // single output; switch handles branching
            $this->completeStep((int) $step['flow_run_id'], (string) $step['nr_node_id'], $out, $port, $jobId);
            $this->enqueueDownstream((int) $step['flow_run_id'], (int) $step['flow_version_id'], (string) $step['nr_node_id'], $port, $out);
            ++$count;
        }

        return $count;
    }

    private function finalizeCompletedRuns(): void
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->query(
            "SELECT r.id FROM flow_run r
             WHERE r.status = 'running'
               AND NOT EXISTS (
                 SELECT 1 FROM flow_run_step s
                 WHERE s.flow_run_id = r.id
                   AND s.status IN ('ready','pending','waiting_job','waiting_delay')
               )
               AND EXISTS (SELECT 1 FROM flow_run_step s2 WHERE s2.flow_run_id = r.id)",
        );

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $failed = $pdo->prepare("SELECT COUNT(*) FROM flow_run_step WHERE flow_run_id = ? AND status = 'failed'");
            $failed->execute([$row['id']]);
            $status = ((int) $failed->fetchColumn()) > 0 ? FlowRun::STATUS_FAILED : FlowRun::STATUS_COMPLETED;
            $pdo->prepare('UPDATE flow_run SET status = ?, finished = ? WHERE id = ?')->execute([
                $status,
                date('Y-m-d H:i:s'),
                $row['id'],
            ]);
        }
    }

    /**
     * @return null|array<string, mixed>
     */
    private function findNode(int $versionId, string $nrNodeId): ?array
    {
        foreach ($this->repository->getNodes($versionId) as $node) {
            if ((string) $node['nr_node_id'] === $nrNodeId) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $env
     */
    private function scheduleJob(int $runtemplateId, array $env, int $flowRunId, int $flowStepId, ?int $sourceJobId): ?int
    {
        $configArgs = [];

        foreach ($env as $key => $value) {
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
            $this->addStatusMessage(implode("\n", $output), 'error');

            return null;
        }

        $joined = implode("\n", $output);
        $decoded = json_decode($joined, true);
        $jobId = null;

        if (\is_array($decoded)) {
            $jobId = (int) ($decoded['job_id'] ?? $decoded['id'] ?? $decoded['data']['job_id'] ?? $decoded['data']['id'] ?? 0);
        }

        if (!$jobId) {
            // Fallback: latest job for this runtemplate
            $stmt = $this->getPdo()->prepare('SELECT id FROM job WHERE runtemplate_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$runtemplateId]);
            $jobId = (int) $stmt->fetchColumn();
        }

        if ($jobId > 0) {
            $pdo = $this->getPdo();
            $pdo->prepare('UPDATE job SET flow_run_id = ?, flow_step_id = ?, source_job_id = COALESCE(?, source_job_id) WHERE id = ?')
                ->execute([$flowRunId, $flowStepId, $sourceJobId, $jobId]);

            return $jobId;
        }

        return null;
    }

    private function resolveProperty(FlowMsg $msg, string $property): mixed
    {
        $path = ltrim($property, '$.');
        $roots = [
            'payload' => $msg->payload,
            'env' => $msg->env,
            'produced' => $msg->produced,
            'meta' => $msg->meta,
        ];

        if (str_contains($path, '.')) {
            [$root, $rest] = explode('.', $path, 2);

            if (isset($roots[$root])) {
                return $this->dig($roots[$root], $rest);
            }
        }

        if (isset($roots[$path])) {
            return $roots[$path];
        }

        return $this->dig(array_merge($msg->meta, $msg->payload, $msg->produced), $path);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dig(array $data, string $path): mixed
    {
        $cur = $data;

        foreach (explode('.', $path) as $seg) {
            if (!\is_array($cur) || !\array_key_exists($seg, $cur)) {
                return null;
            }

            $cur = $cur[$seg];
        }

        return $cur;
    }

    private function ruleMatches(mixed $rule, mixed $value): bool
    {
        if (\is_string($rule) || \is_numeric($rule)) {
            return (string) $rule === (string) $value;
        }

        if (!\is_array($rule)) {
            return false;
        }

        $t = (string) ($rule['t'] ?? $rule['type'] ?? 'eq');
        $v = $rule['v'] ?? $rule['value'] ?? null;

        return match ($t) {
            'eq', '==' => (string) $v === (string) $value,
            'neq', '!=' => (string) $v !== (string) $value,
            'gt' => is_numeric($value) && is_numeric($v) && (float) $value > (float) $v,
            'lt' => is_numeric($value) && is_numeric($v) && (float) $value < (float) $v,
            'else', 'otherwise' => true,
            default => (string) $v === (string) $value,
        };
    }
}
