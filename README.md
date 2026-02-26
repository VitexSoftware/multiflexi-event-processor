# multiflexi-event-processor

Event-driven job triggering daemon for the MultiFlexi suite.

## Overview

Bridges external webhook adapters (e.g. `abraflexi-webhook-acceptor`) with MultiFlexi's job execution engine. Follows a **poll → match rules → schedule job** pattern:

1. Reads unprocessed changes from adapter databases (`changes_cache` table)
2. Matches changes against configured EventRules (evidence + operation patterns)
3. Schedules MultiFlexi jobs via `multiflexi-cli runtemplate schedule`

## Installation

```sh
sudo apt install multiflexi-eventor
```

The daemon runs as `multiflexi-eventor.service` under the `multiflexi` user.

## Configuration

Event sources and rules are managed via the MultiFlexi web UI (⚡ Events menu) or CLI:

```sh
# Create an event source
multiflexi-cli eventsource create --name "AbraFlexi Webhooks" --adapter_type abraflexi-webhook-acceptor --db_database webhooks_db

# Create an event rule
multiflexi-cli eventrule create --event_source_id 1 --evidence faktura-vydana --operation create --runtemplate_id 42
```

## Development

```sh
make vendor    # Install dependencies
make tests     # Run PHPUnit tests
make cs        # Fix coding standards
```

## MultiFlexi

multiflexi-event-processor is part of the [MultiFlexi](https://multiflexi.eu) suite.

[![MultiFlexi](https://github.com/VitexSoftware/MultiFlexi/blob/main/doc/multiflexi-app.svg)](https://www.multiflexi.eu/)

## License

MIT
