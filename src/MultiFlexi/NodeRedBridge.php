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
 * NodeRedBridge forwards MultiFlexi events (webhook changes and finished jobs)
 * to a Node-RED HTTP-in endpoint as normalized JSON.
 *
 * Node-RED acts as the visual routing brain: it receives these events and
 * decides which RunTemplate to schedule (calling back through the MultiFlexi
 * REST API). Forwarding is opt-in: when NODERED_WEBHOOK_URL is empty the bridge
 * is disabled and all methods become no-ops.
 *
 * Configuration keys (Ease\Shared::cfg):
 *  - NODERED_WEBHOOK_URL — target HTTP-in URL for events (empty disables the bridge)
 *  - NODERED_CATALOG_URL — target HTTP-in URL for the config catalog (empty disables it)
 *  - NODERED_TOKEN       — optional shared secret sent as X-MultiFlexi-Token
 *  - NODERED_TIMEOUT     — request timeout in seconds (default 5)
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class NodeRedBridge extends \Ease\Sand
{
    public const EVENT_CHANGE = 'webhook.change';
    public const EVENT_JOB_COMPLETED = 'job.completed';
    public const EVENT_CATALOG = 'catalog.update';

    /**
     * @var string Target Node-RED HTTP-in URL ('' = disabled)
     */
    private string $url;

    /**
     * @var string Target Node-RED catalog HTTP-in URL ('' = disabled)
     */
    private string $catalogUrl;

    /**
     * @var string Optional shared secret header value
     */
    private string $token;

    /**
     * @var int Request timeout in seconds
     */
    private int $timeout;

    /**
     * NodeRedBridge constructor — reads configuration.
     */
    public function __construct()
    {
        $this->setObjectName('NodeRedBridge');
        $this->url = (string) \Ease\Shared::cfg('NODERED_WEBHOOK_URL', '');
        $this->catalogUrl = (string) \Ease\Shared::cfg('NODERED_CATALOG_URL', '');
        $this->token = (string) \Ease\Shared::cfg('NODERED_TOKEN', '');
        $this->timeout = (int) \Ease\Shared::cfg('NODERED_TIMEOUT', 5);
    }

    /**
     * Whether event forwarding is configured/enabled.
     *
     * @return bool True when a target URL is set
     */
    public function isEnabled(): bool
    {
        return $this->url !== '';
    }

    /**
     * Whether the catalog push is configured/enabled.
     *
     * @return bool True when a catalog target URL is set
     */
    public function catalogIsEnabled(): bool
    {
        return $this->catalogUrl !== '';
    }

    /**
     * Push the MultiFlexi configuration catalog to Node-RED.
     *
     * @param array<string, mixed> $catalog Catalog payload from NodeRedCatalog::build()
     *
     * @return bool True on a 2xx response
     */
    public function forwardCatalog(array $catalog): bool
    {
        return $this->postTo($this->catalogUrl, self::EVENT_CATALOG, $catalog);
    }

    /**
     * Forward a webhook change event to Node-RED.
     *
     * @param array<string, mixed> $change     Change record from changes_cache
     * @param array<string, mixed> $sourceData Owning event_source record
     *
     * @return bool True on a 2xx response
     */
    public function forwardChange(array $change, array $sourceData): bool
    {
        return $this->send(self::EVENT_CHANGE, [
            'source_id' => isset($sourceData['id']) ? (int) $sourceData['id'] : null,
            'source_name' => $sourceData['name'] ?? null,
            'adapter_type' => $sourceData['adapter_type'] ?? null,
            'inversion' => isset($change['inversion']) ? (int) $change['inversion'] : null,
            'evidence' => $change['evidence'] ?? null,
            'operation' => $change['operation'] ?? null,
            'recordid' => isset($change['recordid']) ? (int) $change['recordid'] : null,
            'externalids' => $change['externalids'] ?? null,
        ]);
    }

    /**
     * Forward a finished-job event to Node-RED.
     *
     * @param array<string, mixed>             $jobRow    job table row (with app_uuid joined)
     * @param array<int, array<string, mixed>> $artifacts Lightweight artifact metadata
     *
     * @return bool True on a 2xx response
     */
    public function forwardJob(array $jobRow, array $artifacts = []): bool
    {
        return $this->send(self::EVENT_JOB_COMPLETED, [
            'job_id' => isset($jobRow['id']) ? (int) $jobRow['id'] : null,
            'runtemplate_id' => isset($jobRow['runtemplate_id']) ? (int) $jobRow['runtemplate_id'] : null,
            'app_id' => isset($jobRow['app_id']) ? (int) $jobRow['app_id'] : null,
            'app_uuid' => $jobRow['app_uuid'] ?? null,
            'company_id' => isset($jobRow['company_id']) ? (int) $jobRow['company_id'] : null,
            'exitcode' => isset($jobRow['exitcode']) ? (int) $jobRow['exitcode'] : null,
            'schedule_type' => $jobRow['schedule'] ?? null,
            'executor' => $jobRow['executor'] ?? null,
            'begin' => $jobRow['begin'] ?? null,
            'end' => $jobRow['end'] ?? null,
            'artifacts' => array_values($artifacts),
        ]);
    }

    /**
     * Post an event payload to the Node-RED endpoint.
     *
     * @param string               $eventType One of the EVENT_* constants
     * @param array<string, mixed> $data      Event-specific payload fields
     *
     * @return bool True on a 2xx response, false otherwise (including disabled)
     */
    public function send(string $eventType, array $data): bool
    {
        return $this->postTo($this->url, $eventType, $data);
    }

    /**
     * Post an event payload to a specific Node-RED endpoint.
     *
     * @param string               $url       Target HTTP-in URL ('' = disabled, no-op)
     * @param string               $eventType One of the EVENT_* constants
     * @param array<string, mixed> $data      Event-specific payload fields
     *
     * @return bool True on a 2xx response, false otherwise (including disabled)
     */
    private function postTo(string $url, string $eventType, array $data): bool
    {
        if ($url === '') {
            return false;
        }

        $payload = array_merge(['event' => $eventType, 'ts' => (new \DateTime())->format(\DateTime::ATOM)], $data);
        $body = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json'];

        if ($this->token !== '') {
            $headers[] = 'X-MultiFlexi-Token: '.$this->token;
        }

        $ch = curl_init();
        curl_setopt($ch, \CURLOPT_URL, $url);
        curl_setopt($ch, \CURLOPT_POST, true);
        curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->addStatusMessage(
                sprintf(
                    _('Node-RED forward of "%s" failed (HTTP %d): %s'),
                    $eventType,
                    $httpCode,
                    $curlError ?: (\is_string($response) ? $response : ''),
                ),
                'warning',
            );

            return false;
        }

        return true;
    }
}
