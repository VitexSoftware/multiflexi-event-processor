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

use MultiFlexi\EventProcessor;
use MultiFlexi\EventRule;
use MultiFlexi\EventSource;
use PHPUnit\Framework\TestCase;

class EventProcessorTest extends TestCase
{
    /**
     * Test that processChange returns true when a rule matches.
     */
    public function testProcessChangeMatchesRule(): void
    {
        $processor = new EventProcessor();

        $change = [
            'inversion' => 100,
            'recordid' => 42,
            'evidence' => 'banka',
            'operation' => 'create',
        ];

        $rules = [
            [
                'id' => 1,
                'event_source_id' => 1,
                'evidence' => 'banka',
                'operation' => 'create',
                'runtemplate_id' => 10,
                'env_mapping' => null,
                'enabled' => true,
                'priority' => 0,
            ],
        ];

        $source = new EventSource();
        $source->takeData([
            'id' => 1,
            'name' => 'Test Source',
            'enabled' => true,
            'last_processed_id' => 0,
        ]);

        // processChange returns true if at least one rule matched
        // Note: scheduleJob will fail (no CLI available) but the match itself succeeds
        $result = $processor->processChange($change, $rules, $source);
        $this->assertTrue($result);
    }

    /**
     * Test that processChange returns false when no rule matches.
     */
    public function testProcessChangeNoMatch(): void
    {
        $processor = new EventProcessor();

        $change = [
            'inversion' => 100,
            'recordid' => 42,
            'evidence' => 'banka',
            'operation' => 'create',
        ];

        $rules = [
            [
                'id' => 1,
                'event_source_id' => 1,
                'evidence' => 'faktura-vydana',
                'operation' => 'delete',
                'runtemplate_id' => 10,
                'env_mapping' => null,
                'enabled' => true,
                'priority' => 0,
            ],
        ];

        $source = new EventSource();
        $source->takeData([
            'id' => 1,
            'name' => 'Test Source',
            'enabled' => true,
            'last_processed_id' => 0,
        ]);

        $result = $processor->processChange($change, $rules, $source);
        $this->assertFalse($result);
    }

    /**
     * Test the SCHEDULE_TYPE constant is 'event'.
     */
    public function testScheduleTypeConstant(): void
    {
        $this->assertSame('event', EventProcessor::SCHEDULE_TYPE);
    }
}
