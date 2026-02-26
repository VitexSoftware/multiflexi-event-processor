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

namespace Test\MultiFlexi;

use MultiFlexi\EventSource;
use PHPUnit\Framework\TestCase;

class EventSourceTest extends TestCase
{
    /**
     * Test that EventSource constructor sets table name correctly.
     */
    public function testConstructorSetsTable(): void
    {
        $source = new EventSource();
        $this->assertSame('event_source', $source->getMyTable());
    }

    /**
     * Test that takeData stores data correctly.
     */
    public function testTakeData(): void
    {
        $source = new EventSource();
        $source->takeData([
            'id' => 1,
            'name' => 'Test AbraFlexi Webhook',
            'adapter_type' => 'abraflexi-webhook',
            'db_connection' => 'mysql',
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_database' => 'webhook_db',
            'db_username' => 'user',
            'db_password' => 'pass',
            'enabled' => true,
            'last_processed_id' => 0,
        ]);

        $this->assertSame('Test AbraFlexi Webhook', $source->getRecordName());
        $this->assertSame('mysql', $source->getDataValue('db_connection'));
        $this->assertSame('webhook_db', $source->getDataValue('db_database'));
    }

    /**
     * Test that isReachable returns false for unreachable databases.
     */
    public function testIsReachableReturnsFalseForBadConnection(): void
    {
        $source = new EventSource();
        $source->takeData([
            'id' => 99,
            'name' => 'Unreachable Source',
            'db_connection' => 'mysql',
            'db_host' => '192.0.2.1',
            'db_port' => '9999',
            'db_database' => 'nonexistent',
            'db_username' => 'nobody',
            'db_password' => 'nothing',
            'enabled' => true,
            'last_processed_id' => 0,
        ]);

        $this->assertFalse($source->isReachable());
    }
}
