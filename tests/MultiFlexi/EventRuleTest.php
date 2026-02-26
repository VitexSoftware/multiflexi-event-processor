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

use MultiFlexi\EventRule;
use PHPUnit\Framework\TestCase;

class EventRuleTest extends TestCase
{
    /**
     * Test that a rule with matching evidence and operation matches.
     */
    public function testMatchesExactEvidenceAndOperation(): void
    {
        $rule = new EventRule();
        $rule->takeData([
            'id' => 1,
            'evidence' => 'faktura-vydana',
            'operation' => 'create',
            'enabled' => true,
            'runtemplate_id' => 10,
        ]);

        $change = [
            'inversion' => 100,
            'recordid' => 42,
            'evidence' => 'faktura-vydana',
            'operation' => 'create',
        ];

        $this->assertTrue($rule->matches($change));
    }

    /**
     * Test that a rule does not match when evidence differs.
     */
    public function testDoesNotMatchWrongEvidence(): void
    {
        $rule = new EventRule();
        $rule->takeData([
            'id' => 1,
            'evidence' => 'faktura-vydana',
            'operation' => 'create',
            'enabled' => true,
            'runtemplate_id' => 10,
        ]);

        $change = [
            'inversion' => 100,
            'recordid' => 42,
            'evidence' => 'banka',
            'operation' => 'create',
        ];

        $this->assertFalse($rule->matches($change));
    }

    /**
     * Test that a rule with operation 'any' matches any operation.
     */
    public function testMatchesAnyOperation(): void
    {
        $rule = new EventRule();
        $rule->takeData([
            'id' => 1,
            'evidence' => 'banka',
            'operation' => EventRule::OPERATION_ANY,
            'enabled' => true,
            'runtemplate_id' => 10,
        ]);

        foreach (['create', 'update', 'delete'] as $op) {
            $change = [
                'inversion' => 100,
                'recordid' => 42,
                'evidence' => 'banka',
                'operation' => $op,
            ];
            $this->assertTrue($rule->matches($change), "Should match operation: {$op}");
        }
    }

    /**
     * Test that a rule with null evidence matches any evidence.
     */
    public function testNullEvidenceMatchesAny(): void
    {
        $rule = new EventRule();
        $rule->takeData([
            'id' => 1,
            'evidence' => null,
            'operation' => 'update',
            'enabled' => true,
            'runtemplate_id' => 10,
        ]);

        $change = [
            'inversion' => 100,
            'recordid' => 42,
            'evidence' => 'some-random-evidence',
            'operation' => 'update',
        ];

        $this->assertTrue($rule->matches($change));
    }

    /**
     * Test that a disabled rule never matches.
     */
    public function testDisabledRuleDoesNotMatch(): void
    {
        $rule = new EventRule();
        $rule->takeData([
            'id' => 1,
            'evidence' => 'banka',
            'operation' => EventRule::OPERATION_ANY,
            'enabled' => false,
            'runtemplate_id' => 10,
        ]);

        $change = [
            'inversion' => 100,
            'recordid' => 42,
            'evidence' => 'banka',
            'operation' => 'create',
        ];

        $this->assertFalse($rule->matches($change));
    }

    /**
     * Test building environment overrides from env_mapping JSON.
     */
    public function testBuildEnvOverrides(): void
    {
        $rule = new EventRule();
        $rule->takeData([
            'id' => 1,
            'env_mapping' => json_encode([
                'RECORD_ID' => 'recordid',
                'EVIDENCE' => 'evidence',
            ]),
            'enabled' => true,
            'runtemplate_id' => 10,
        ]);

        $change = [
            'inversion' => 200,
            'recordid' => 55,
            'evidence' => 'banka',
            'operation' => 'create',
            'externalids' => '',
        ];

        $env = $rule->buildEnvOverrides($change);

        $this->assertSame('55', $env['RECORD_ID']);
        $this->assertSame('banka', $env['EVIDENCE']);
        // Standard metadata should always be present
        $this->assertSame('200', $env['EVENT_INVERSION']);
        $this->assertSame('banka', $env['EVENT_EVIDENCE']);
        $this->assertSame('create', $env['EVENT_OPERATION']);
        $this->assertSame('55', $env['EVENT_RECORD_ID']);
    }

    /**
     * Test that empty env_mapping still provides standard metadata.
     */
    public function testBuildEnvOverridesWithEmptyMapping(): void
    {
        $rule = new EventRule();
        $rule->takeData([
            'id' => 1,
            'env_mapping' => null,
            'enabled' => true,
            'runtemplate_id' => 10,
        ]);

        $change = [
            'inversion' => 300,
            'recordid' => 77,
            'evidence' => 'faktura-vydana',
            'operation' => 'update',
        ];

        $env = $rule->buildEnvOverrides($change);

        $this->assertSame('300', $env['EVENT_INVERSION']);
        $this->assertSame('faktura-vydana', $env['EVENT_EVIDENCE']);
        $this->assertSame('update', $env['EVENT_OPERATION']);
        $this->assertSame('77', $env['EVENT_RECORD_ID']);
    }

    /**
     * Test getRuntemplateId returns correct value.
     */
    public function testGetRuntemplateId(): void
    {
        $rule = new EventRule();
        $rule->takeData([
            'id' => 1,
            'runtemplate_id' => 42,
            'enabled' => true,
        ]);

        $this->assertSame(42, $rule->getRuntemplateId());
    }
}
