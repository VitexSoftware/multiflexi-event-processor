# AGENTS.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

**Repository**: multiflexi-event-processor (part of the MultiFlexi suite)
**Type**: PHP Project/Debian Package — Systemd Service
**Service**: `multiflexi-eventor.service` (runs as daemon under the `multiflexi` user)
**Purpose**: Event-driven job triggering — bridges external webhook adapters with MultiFlexi's job execution engine
**License**: MIT

This is a member of the MultiFlexi suite alongside:
- `multiflexi-executor` — job execution daemon (`multiflexi-executor.service`)
- `multiflexi-scheduler` — cron-based job scheduling daemon (`multiflexi-scheduler.service`)
- `php-vitexsoftware-multiflexi-core` — shared domain classes (Job, RunTemplate, Scheduler, Application, etc.)
- `multiflexi-cli` — command-line management tool
- `multiflexi-database` — schema migrations (located at `~/Projects/Multi/multiflexi-database/db/migrations`)

All suite components share the `vitexsoftware/multiflexi-core` Composer dependency, the same MultiFlexi database, and follow the same project conventions. The eventor connects to the same DB as executor and scheduler (configured via `DB_*` env vars in `/etc/multiflexi/multiflexi.env`), and additionally reads from external adapter databases (e.g. `abraflexi-webhook-acceptor`).

## Application Purpose & Data Flow

The event processor is a bridge between external webhook adapters and MultiFlexi's job execution engine. It follows a **poll → match rules → schedule job** pattern:

1. **Input (webhook adapters)**: External adapters (e.g. `abraflexi-webhook-acceptor`) receive webhooks from business systems and store them in a `changes_cache` database table. Each cached change contains: `inversion` (version ID), `recordid`, `evidence` (entity type), `operation` (create/update/delete), `externalids`, `source` (system ID), and `target`.
2. **Processing (this component)**: The event processor reads unprocessed events from the adapter's cache database(s). Based on internally defined rules, it decides which MultiFlexi jobs to trigger.
3. **Output (MultiFlexi integration)**: When a rule matches, the processor schedules a job in MultiFlexi — either via `multiflexi-cli runtemplate schedule` CLI command or through the MultiFlexi REST API (`POST /job/`). Input data is passed to the job as environment variables or an input JSON file.

### Example Flow
AbraFlexi records a received payment → `abraflexi-webhook-acceptor` catches the webhook and writes a change record to `changes_cache` → this event processor discovers the new record, matches a rule like "payment received → send payment confirmation" → schedules the corresponding MultiFlexi job with the payment details as env vars.

### Integration Interfaces

**MultiFlexi CLI** (`multiflexi-cli` v2.2.0+):
- Schedule a job: `multiflexi-cli runtemplate schedule --id <RT_ID> --config KEY1=VAL1 --config KEY2=VAL2 --schedule_time now --executor Native -f json`
- Create a RunTemplate: `multiflexi-cli runtemplate create --name <name> --app_id <id> --company_id <id> --config KEY=VAL`
- Query jobs: `multiflexi-cli job list -f json`

**MultiFlexi REST API** (OpenAPI schema at `~/Projects/Multi/multiflexi-api/openapi-schema.yaml`):
- `POST /job/` — create/schedule a job
- `POST /runtemplate` — create/update a RunTemplate
- `GET /jobs.json` — list jobs
- Auth: Basic HTTP authentication required

### Input Side Configuration
The processor must be configured with connection details for each webhook adapter database it reads from. The first supported adapter is `abraflexi-webhook-acceptor` (`~/Projects/VitexSoftware/abraflexi-webhook-acceptor`), which uses a `changes_cache` table (Phinx migrations, FluentPDO/Ease SQL engine). Input sources (webhook acceptor database connections) will be configurable via the MultiFlexi web UI.

### Output Side Configuration
Rules mapping events to MultiFlexi RunTemplate IDs will be configurable via the MultiFlexi web UI. Each rule defines which evidence type + operation combination triggers which RunTemplate, and how event data is transformed into job input (environment variables or JSON file path).

### changes_cache Table Schema (Input)
The `changes_cache` table (primary key: `inversion`) stores webhook data from adapters:
- `inversion` (int) — AbraFlexi change version ID
- `recordid` (int) — record ID in the source system
- `evidence` (string, 60) — entity/evidence type (e.g. `faktura-vydana`, `banka`)
- `operation` (enum: create/update/delete) — what happened
- `externalids` (string, 300) — serialized external IDs
- `created` (timestamp) — when the change was cached
- `source` (int) — foreign key to `changesapi` table (identifies the AbraFlexi server)
- `target` (string, 30) — target system identifier

## Build & Development Commands

Dependencies are managed via Composer. The standard Makefile targets (consistent across the suite) are:

- `make vendor` — install Composer dependencies (`composer install`)
- `make tests` — run PHPUnit test suite (`vendor/bin/phpunit tests`)
- `make static-code-analysis` — run PHPStan (`vendor/bin/phpstan analyse --configuration=phpstan-default.neon.dist`)
- `make static-code-analysis-baseline` — regenerate PHPStan baseline
- `make cs` — fix coding standards via php-cs-fixer (`vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --diff --verbose`)
- `make autoload` — update Composer autoload (`composer update`)

Run a single test file: `vendor/bin/phpunit path/to/TestFile.php`
Run tests by pattern: `vendor/bin/phpunit --filter "TestNameOrRegex"`

After every PHP file edit, run `php -l <file>` to lint before proceeding.

## Coding Standards

- PHP 8.4+, PSR-12 coding standard, PSR-4 autoloading under `MultiFlexi\` namespace in `src/MultiFlexi/`
- Use `_()` for all user-facing strings (i18n via gettext)
- All classes and functions must have docblocks with typed parameters and return types
- Define constants instead of magic numbers/strings
- When creating or updating a class, always create or update its PHPUnit test

## Configuration & Environment

- Place a `.env` file at the repository root; the app reads settings via `Ease\Shared`
- Standard env keys: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `APP_DEBUG`, `EASE_LOGGER`
- Optional monitoring keys: `ZABBIX_SERVER`, `ZABBIX_HOST`, `OTEL_ENABLED`, `OTEL_EXPORTER_OTLP_ENDPOINT`

## Related Projects & Extensions

### Predecessor: abraflexi-changes-processor
The project `~/Projects/VitexSoftware/abraflexi-changes-processor` is the AbraFlexi-only predecessor. Its key patterns to generalize:
- **Evidence-based plugin dispatch**: Maps AbraFlexi evidence types (e.g. `banka`, `faktura-vydana`) to PHP plugin classes that handle create/update/delete operations
- **MetaState detection**: Plugins derive higher-level business states from raw change data (e.g. `FakturaVydana` detects `settled`, `storno`, `remind1-3`, `penalised` from update operations)
- **Cache processing loop**: Reads from `changes_cache`, processes each change, wipes processed records, tracks last processed version per source
- **Locking mechanism**: File-based lock to prevent concurrent processing

The new event processor generalizes this from AbraFlexi-only hardcoded plugins to a configurable rule-based system within MultiFlexi.

### Database Extensions (multiflexi-database)
New tables will be added to `~/Projects/Multi/multiflexi-database/db/migrations` to support:
- **Event sources**: webhook acceptor database connection definitions (which external adapter DBs to poll)
- **Event rules**: mappings from (evidence + operation + optional conditions) to (RunTemplate ID + env var mappings)

### Web UI Extensions (MultiFlexi)
The MultiFlexi web application (`~/Projects/Multi/MultiFlexi`) will be extended with:
- **Event source configuration forms**: define input sources (webhook acceptor database connections)
- **Event rule configuration forms**: define relations between incoming events and triggered actions (which events trigger which RunTemplates with what data)

## Suite Architecture Context

MultiFlexi is a task scheduling and automation framework. The core library (`multiflexi-core`) provides domain models persisted via FluentPDO:
- **Application** — external app definition (executable, env vars, metadata). Stored in `application` table, defined via `.app.json` files.
- **Company** — a tenant/organization. Provides multi-tenant data isolation for credentials, jobs, and config.
- **RunTemplate** — binds an Application to a Company with scheduling config. Key fields: `app_id`, `company_id`, `interv` (scheduling interval: `i`=minutely, `h`=hourly, `d`=daily, `w`=weekly, `m`=monthly, `y`=yearly, `c`=custom cron, `n`=none), `active`, `executor` (Native/Docker/Podman/Azure/Kubernetes).
- **Job** — a single execution instance of a RunTemplate. Created via `Job::prepareJob()`. Fields: `runtemplate_id`, `company_id`, `app_id`, `executor`, `env` (serialized ConfigFields), `exitcode`, `stdout`, `stderr`, `begin`, `end`.
- **Scheduler** — queries due jobs from the `schedule` table (entries with `after < NOW()`).
- **Credential / CredentialType / CredentialPrototype** — three-tier credential management (JSON templates → company instances → actual values used by jobs).

### Job Scheduling Flow (how this processor triggers jobs)
When the event processor decides to schedule a job:
1. It calls `multiflexi-cli runtemplate schedule --id <RT_ID> --config KEY=VAL --schedule_time now --executor Native -f json`
2. This internally calls `Job::prepareJob(runtemplateId, configOverrides, scheduleTime, executor, scheduleType)` which:
   - Creates a `job` record with merged environment (app defaults + company credentials + RunTemplate overrides + adhoc config)
   - Inserts into the `schedule` table with the `after` timestamp
3. The `multiflexi-executor` daemon picks up the scheduled job and executes it

### Schedule Types (job.schedule_type column)
MultiFlexi tracks how each job was triggered via `schedule_type`:
- `adhoc` — manual trigger from web UI, CLI, or API
- `cli` — future-scheduled from CLI
- `chained` — triggered by ChainRuntemplate action after another job completes
- `reschedule` — triggered by Reschedule action (retry/delay)
- `event` — **new type** triggered by this event processor based on incoming webhook data

The `event` schedule type will be added to `multiflexi-core` so the platform can distinguish event-driven jobs from other triggers. The `schedule_type` value affects behavior: only non-`adhoc` jobs update the RunTemplate's `next_schedule`/`last_schedule` timestamps.

### Configuration Inheritance (priority order, highest wins)
1. Application defaults (from `.app.json`)
2. Company credentials (from assigned CredentialType values)
3. RunTemplate overrides (custom values)
4. Adhoc overrides (passed via `--config` at schedule time) ← the event processor uses this to inject event data

Suite daemons (executor, scheduler, and this event processor) run as systemd services under the `multiflexi` user, packaged as Debian `.deb` packages via `debian/` directory with `dpkg-buildpackage -b -uc`.

## Debian Packaging

The `debian/` directory follows standard Debhelper conventions:
- `debian/control` — package metadata and dependencies
- `debian/rules` — build rules
- `debian/dirs` — directory creation
- `debian/*.install` — file installation mappings
- `debian/*.service` — systemd unit file (if this is a daemon)

Build: `dpkg-buildpackage -b -uc`
