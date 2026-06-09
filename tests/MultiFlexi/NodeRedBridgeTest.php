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

use MultiFlexi\NodeRedBridge;
use PHPUnit\Framework\TestCase;

class NodeRedBridgeTest extends TestCase
{
    /**
     * The bridge is disabled when no target URL is configured.
     */
    public function testDisabledWithoutUrl(): void
    {
        putenv('NODERED_WEBHOOK_URL');
        unset($_ENV['NODERED_WEBHOOK_URL'], $_SERVER['NODERED_WEBHOOK_URL']);

        $bridge = new NodeRedBridge();
        $this->assertFalse($bridge->isEnabled());
    }

    /**
     * Sending while disabled is a safe no-op returning false.
     */
    public function testSendWhileDisabledReturnsFalse(): void
    {
        putenv('NODERED_WEBHOOK_URL');
        unset($_ENV['NODERED_WEBHOOK_URL'], $_SERVER['NODERED_WEBHOOK_URL']);

        $bridge = new NodeRedBridge();
        $this->assertFalse($bridge->send(NodeRedBridge::EVENT_CHANGE, ['evidence' => 'banka']));
        $this->assertFalse($bridge->forwardChange(['inversion' => 1], ['id' => 1]));
        $this->assertFalse($bridge->forwardJob(['id' => 1], []));
    }

    /**
     * Event type constants are stable identifiers.
     */
    public function testEventTypeConstants(): void
    {
        $this->assertSame('webhook.change', NodeRedBridge::EVENT_CHANGE);
        $this->assertSame('job.completed', NodeRedBridge::EVENT_JOB_COMPLETED);
    }
}
