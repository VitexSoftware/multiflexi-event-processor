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
 * EventSource represents a webhook adapter database connection.
 *
 * Records live in the MultiFlexi `event_source` table. Each source points to an
 * external adapter database (e.g. abraflexi-webhook-acceptor) that buffers
 * incoming webhooks in a `changes_cache` table. This class reads unprocessed
 * changes from that external database and tracks the last processed inversion.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class EventSource extends DBEngine
{
    /**
     * Name of the change buffer table inside the adapter database.
     */
    public const CACHE_TABLE = 'changes_cache';

    /**
     * Default maximum number of changes fetched per poll cycle.
     */
    public const DEFAULT_BATCH_LIMIT = 100;

    /**
     * @var null|\PDO Lazily-built connection to the external adapter database
     */
    private ?\PDO $externalPdo = null;

    /**
     * EventSource constructor.
     *
     * @param null|int             $identifier Record ID
     * @param array<string, mixed> $options    Additional options
     */
    public function __construct($identifier = null, $options = [])
    {
        $this->myTable = 'event_source';
        $this->keyColumn = 'id';
        $this->nameColumn = 'name';
        parent::__construct($identifier, $options);
    }

    /**
     * List all enabled event sources from the MultiFlexi database.
     *
     * @return array<int, array<string, mixed>> Event source records
     */
    public function getEnabledSources(): array
    {
        $sources = $this->listingQuery()->where('enabled', 1)->fetchAll();

        return $sources ?: [];
    }

    /**
     * Test whether the external adapter database is reachable.
     *
     * @return bool True when a connection can be established
     */
    public function isReachable(): bool
    {
        try {
            $this->externalConnection()->query('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            $this->externalPdo = null;

            return false;
        }
    }

    /**
     * Read unprocessed changes from the adapter's changes_cache table.
     *
     * Returns rows with an inversion greater than this source's
     * last_processed_id, ordered ascending, limited to a single batch.
     *
     * @return array<int, array<string, mixed>> Change records
     */
    public function getUnprocessedChanges(): array
    {
        $lastProcessed = (int) ($this->getDataValue('last_processed_id') ?? 0);
        $limit = (int) \Ease\Shared::cfg('MULTIFLEXI_EVENT_BATCH', self::DEFAULT_BATCH_LIMIT);

        $sql = sprintf(
            'SELECT * FROM %s WHERE inversion > :last ORDER BY inversion ASC LIMIT %d',
            self::CACHE_TABLE,
            $limit,
        );

        $statement = $this->externalConnection()->prepare($sql);
        $statement->bindValue(':last', $lastProcessed, \PDO::PARAM_INT);
        $statement->execute();

        $changes = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $changes ?: [];
    }

    /**
     * Remove a processed change record from the adapter's cache table.
     *
     * @param int $inversion The change inversion (primary key in changes_cache)
     */
    public function wipeCacheRecord(int $inversion): void
    {
        $statement = $this->externalConnection()->prepare(sprintf('DELETE FROM %s WHERE inversion = :inversion', self::CACHE_TABLE));
        $statement->bindValue(':inversion', $inversion, \PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * Persist the last processed inversion for this source in the MultiFlexi DB.
     *
     * @param int $inversion The last processed change inversion
     */
    public function updateLastProcessed(int $inversion): void
    {
        $sourceId = (int) $this->getMyKey();

        if ($sourceId === 0) {
            return;
        }

        $this->updateToSQL(
            ['last_processed_id' => (string) $inversion],
            [$this->keyColumn => (string) $sourceId],
        );
        $this->setDataValue('last_processed_id', $inversion);
    }

    /**
     * Get (and cache) a PDO connection to the external adapter database.
     *
     * @throws \PDOException When the connection cannot be established
     */
    private function externalConnection(): \PDO
    {
        if ($this->externalPdo instanceof \PDO) {
            return $this->externalPdo;
        }

        $driver = (string) ($this->getDataValue('db_connection') ?: 'mysql');
        $database = (string) $this->getDataValue('db_database');
        $username = $this->getDataValue('db_username');
        $password = $this->getDataValue('db_password');

        switch ($driver) {
            case 'sqlite':
                $dsn = 'sqlite:'.$database;

                break;
            case 'pgsql':
                $dsn = sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    (string) $this->getDataValue('db_host'),
                    (string) $this->getDataValue('db_port'),
                    $database,
                );

                break;
            case 'mysql':
            default:
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    (string) $this->getDataValue('db_host'),
                    (string) $this->getDataValue('db_port'),
                    $database,
                );

                break;
        }

        $this->externalPdo = new \PDO($dsn, (string) $username, (string) $password, [
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        return $this->externalPdo;
    }
}
