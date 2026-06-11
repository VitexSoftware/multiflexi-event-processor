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
 * carries the same icon the entity has in MultiFlexi (company logo, the
 * run-template's application image, the credential-type logo) resolved to a
 * data: URI so Node-RED can render it without reaching back to MultiFlexi.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class NodeRedCatalog
{
    /**
     * Directories searched for bare-filename icons (credential-type logos).
     *
     * @var array<int, string>
     */
    private array $imageDirs = [
        '/usr/share/multiflexi/images/',
        '/usr/share/multiflexi-web/images/',
        '/usr/share/multiflexi/',
    ];

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
     * All defined companies with their logo.
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
                'icon' => $this->resolveIcon($row['logo'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * Enabled run-templates with the icon of their application.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runtemplates(): array
    {
        $rows = (new RunTemplate())->listingQuery()->fetchAll();
        $appIcons = $this->applicationIcons();

        $result = [];

        foreach ($rows as $row) {
            if (empty($row['active'])) {
                continue; // only enabled run-templates
            }

            $appId = isset($row['app_id']) ? (int) $row['app_id'] : 0;
            $app = $appIcons[$appId] ?? ['uuid' => null, 'icon' => null];

            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'company_id' => isset($row['company_id']) ? (int) $row['company_id'] : null,
                'app_id' => $appId,
                'app_uuid' => $app['uuid'],
                'executor' => $row['executor'] ?? null,
                'active' => true,
                'icon' => $app['icon'],
            ];
        }

        return $result;
    }

    /**
     * All credentials with the logo of their credential type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function credentials(): array
    {
        $types = $this->credentialTypes();

        $result = [];

        foreach ((new Credential())->listingQuery()->fetchAll() as $row) {
            $typeId = isset($row['credential_type_id']) ? (int) $row['credential_type_id'] : 0;
            $type = $types[$typeId] ?? ['name' => null, 'icon' => null];

            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'company_id' => isset($row['company_id']) ? (int) $row['company_id'] : null,
                'credential_type_id' => $typeId ?: null,
                'type_name' => $type['name'],
                'icon' => $type['icon'],
            ];
        }

        return $result;
    }

    /**
     * Map of app_id => ['uuid' => ?string, 'icon' => ?string] for icon lookup.
     *
     * @return array<int, array{uuid: ?string, icon: ?string}>
     */
    private function applicationIcons(): array
    {
        $map = [];

        foreach ((new Application())->listingQuery()->fetchAll() as $row) {
            $uuid = $row['uuid'] ?? null;
            // Prefer the inline image column; fall back to the <uuid>.svg file
            // MultiFlexi serves from its images dir (appimage.php convention).
            $icon = $this->resolveIcon($row['image'] ?? null)
                ?? ($uuid ? $this->resolveIcon($uuid.'.svg') : null);

            $map[(int) $row['id']] = [
                'uuid' => $uuid,
                'icon' => $icon,
            ];
        }

        return $map;
    }

    /**
     * Map of credential_type_id => ['name' => ?string, 'icon' => ?string].
     *
     * Uses CredentialType::getLogo() so the prototype logo fallback applies.
     *
     * @return array<int, array{name: ?string, icon: ?string}>
     */
    private function credentialTypes(): array
    {
        $map = [];

        foreach ((new CredentialType())->listingQuery()->fetchAll() as $row) {
            $id = (int) $row['id'];
            $type = new CredentialType($id);
            $map[$id] = [
                'name' => $row['name'] ?? null,
                'icon' => $this->resolveIcon($type->getLogo()),
            ];
        }

        return $map;
    }

    /**
     * Normalise an icon reference to something a browser can render.
     *
     * Passes through data: URIs and http(s) URLs unchanged; resolves a bare
     * filename (e.g. "vaultwarden.svg") against the MultiFlexi image dirs and
     * inlines it as a base64 data: URI. Returns null when nothing is found.
     */
    private function resolveIcon(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'data:')
            || str_starts_with($raw, 'http://')
            || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        foreach ($this->imageDirs as $dir) {
            $file = $dir.$raw;

            if (is_file($file)) {
                $mime = str_ends_with(strtolower($raw), '.svg')
                    ? 'image/svg+xml'
                    : (\function_exists('mime_content_type') ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream');

                return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file));
            }
        }

        return null;
    }
}
