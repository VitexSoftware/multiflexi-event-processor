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

use Ease\Shared;

date_default_timezone_set('Europe/Prague');

require_once '../vendor/autoload.php';

Shared::init(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'], '../.env');
$daemonize = (bool) Shared::cfg('MULTIFLEXI_DAEMONIZE', true);
$loggers = ['syslog', '\\MultiFlexi\\LogToSQL'];

if (Shared::cfg('ZABBIX_SERVER') && Shared::cfg('ZABBIX_HOST') && class_exists('\\MultiFlexi\\LogToZabbix')) {
    $loggers[] = '\\MultiFlexi\\LogToZabbix';
}

if (strtolower(Shared::cfg('APP_DEBUG', 'false')) === 'true') {
    $loggers[] = 'console';
}

\define('APP_NAME', 'MultiFlexi Eventor');
\define('EASE_LOGGER', implode('|', $loggers));

new \MultiFlexi\Defaults();
Shared::user(new \MultiFlexi\UnixUser());

/**
 * Check if database error is a permanent failure that should not be retried.
 *
 * @param string $errorMessage Error message from database exception
 *
 * @return bool True if error is permanent and daemon should exit
 */
function isPermanentDatabaseError(string $errorMessage): bool
{
    if (str_contains($errorMessage, 'Access denied')
        || str_contains($errorMessage, '1045')
        || str_contains($errorMessage, 'authentication')) {
        error_log(_('Database authentication failed. Check credentials in configuration. Exiting.'));

        return true;
    }

    if (str_contains($errorMessage, 'Unknown database')
        || str_contains($errorMessage, '1049')) {
        error_log(_('Database does not exist. Check database name in configuration. Exiting.'));

        return true;
    }

    return false;
}

/**
 * Wait for database connection to become available.
 */
function waitForDatabase(): void
{
    $maxRetries = 10;
    $retryCount = 0;

    while (true) {
        try {
            $testEngine = new EventSource();
            $testEngine->listingQuery()->count();
            unset($testEngine);

            break;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            error_log('Database unavailable: '.$errorMessage);

            if (isPermanentDatabaseError($errorMessage)) {
                exit(1);
            }

            ++$retryCount;

            if ($retryCount >= $maxRetries) {
                error_log(_('Maximum database connection retries exceeded. Exiting.'));

                exit(1);
            }

            error_log(sprintf(_('Retrying database connection in 30 seconds (%d/%d)...'), $retryCount, $maxRetries));
            sleep(30);
        }
    }
}

waitForDatabase();
$processor = new EventProcessor();
$processor->logBanner(sprintf(_('MultiFlexi Eventor Daemon %s started'), Shared::appVersion()));

do {
    try {
        $processedCount = $processor->pollAndProcess();

        if ($processedCount > 0) {
            $processor->addStatusMessage(sprintf(_('Processed %d event(s)'), $processedCount), 'success');
        }
    } catch (\PDOException $e) {
        error_log('Database error: '.$e->getMessage());

        if (isPermanentDatabaseError($e->getMessage())) {
            exit(1);
        }

        waitForDatabase();
        $processor = new EventProcessor();
    } catch (\Throwable $e) {
        error_log('Error in event processor: '.$e->getMessage());
        $processor->addStatusMessage('Error: '.$e->getMessage(), 'error');
    }

    if ($daemonize) {
        sleep((int) Shared::cfg('MULTIFLEXI_CYCLE_PAUSE', 10));
    }
} while ($daemonize);

$processor->logBanner('MultiFlexi Eventor Daemon ended');
