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
 * Builds the MultiFlexi configuration catalog pushed to Node-RED.
 *
 * The catalog enumerates the building blocks a Node-RED flow can reference:
 * all companies, all enabled run-templates and all credentials. Each entry
 * carries the identifiers Node-RED needs to fetch the matching icon from the
 * MultiFlexi web image endpoints (appimage.php / companylogo.php /
 * credentialimage.php) — the icon bytes are NOT embedded, keeping the payload
 * small.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class NodeRedCatalog
{
    /**
     * Build the full catalog payload.
     *
     * @return array{companies: array<int, array<string, mixed>>, runtemplates: array<int, array<string, mixed>>, credentials: array<int, array<string, mixed>>}
     */
    public function build(): array
    {
        return [
            'companies' => $this->companies(),
            'runtemplates' => $this->runtemplates(),
            'credentials' => $this->credentials(),
        ];
    }

    /**
     * All defined companies. Node-RED renders the logo via
     * companylogo.php?id={id}.
     *
     * @return array<int, array<string, mixed>>
     */
    private function companies(): array
    {
        $result = [];

        foreach ((new Company())->listingQuery()->fetchAll() as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'slug' => $row['slug'] ?? null,
                'enabled' => (bool) ($row['enabled'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * Enabled run-templates. Node-RED renders the application icon via
     * appimage.php?uuid={app_uuid}.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runtemplates(): array
    {
        $appUuids = $this->applicationUuids();

        $result = [];

        foreach ((new RunTemplate())->listingQuery()->fetchAll() as $row) {
            if (empty($row['active'])) {
                continue; // only enabled run-templates
            }

            $appId = isset($row['app_id']) ? (int) $row['app_id'] : 0;

            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'company_id' => isset($row['company_id']) ? (int) $row['company_id'] : null,
                'app_id' => $appId,
                'app_uuid' => $appUuids[$appId] ?? null,
                'executor' => $row['executor'] ?? null,
                'active' => true,
            ];
        }

        return $result;
    }

    /**
     * All credentials. Node-RED renders the credential-type logo via
     * credentialimage.php?id={id}.
     *
     * @return array<int, array<string, mixed>>
     */
    private function credentials(): array
    {
        $typeNames = $this->credentialTypeNames();

        $result = [];

        foreach ((new Credential())->listingQuery()->fetchAll() as $row) {
            $typeId = isset($row['credential_type_id']) ? (int) $row['credential_type_id'] : 0;

            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'company_id' => isset($row['company_id']) ? (int) $row['company_id'] : null,
                'credential_type_id' => $typeId ?: null,
                'type_name' => $typeNames[$typeId] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Map of app_id => uuid (for building appimage.php URLs).
     *
     * @return array<int, ?string>
     */
    private function applicationUuids(): array
    {
        $map = [];

        foreach ((new Application())->listingQuery()->fetchAll() as $row) {
            $map[(int) $row['id']] = $row['uuid'] ?? null;
        }

        return $map;
    }

    /**
     * Map of credential_type_id => name.
     *
     * @return array<int, ?string>
     */
    private function credentialTypeNames(): array
    {
        $map = [];

        foreach ((new CredentialType())->listingQuery()->fetchAll() as $row) {
            $map[(int) $row['id']] = $row['name'] ?? null;
        }

        return $map;
    }
}
