# multiflexi-event-processor
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![Packaging: deb](https://img.shields.io/badge/packaging-.deb-red?logo=debian&logoColor=white)

Event-driven job triggering daemon for the MultiFlexi suite.

## Overview

Bridges external webhook adapters (e.g. `abraflexi-webhook-acceptor`) with MultiFlexi's job execution engine:

1. Reads unprocessed changes from adapter databases (`changes_cache` table)
2. Matches changes against configured EventRules **and** stored Node-RED **flows**
3. Schedules MultiFlexi jobs via `multiflexi-cli` / advances `FlowInterpreter` steps

### Flow interpreter

Graphs designed in Node-RED are stored as `flow` / `flow_version` / `flow_node` /
`flow_wire` and executed here without Node-RED running (map, switch, delay,
RunTemplate wait). Legacy pairwise `event_rule` chaining remains available.

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
