# node-red-contrib-multiflexi

Node-RED nodes for visually orchestrating [MultiFlexi](https://multiflexi.eu/)
processes. Receive webhook and job events from the MultiFlexi event processor
(`multiflexi-eventor`) and schedule RunTemplates — the "arrows" in your flow
become the relationships between MultiFlexi processes.

## Nodes

| Node | Role | Direction |
|------|------|-----------|
| **multiflexi-config** | MultiFlexi REST API connection (base URL + Basic auth) | — |
| **multiflexi-event** | Receives forwarded events over HTTP; the *source* of arrows | MultiFlexi → Node-RED |
| **multiflexi-runtemplate** | Schedules a RunTemplate via `POST /job/`; the *target* of arrows | Node-RED → MultiFlexi |
| **multiflexi-artifact** | Splits a `job.completed` event into per-artifact messages for chaining | filter |

## Data flow

```
abraflexi-webhook-acceptor → changes_cache
            │ (poll)
   multiflexi-eventor daemon ──HTTP POST──▶ [multiflexi-event] ──▶ [multiflexi-runtemplate] ──HTTP──▶ MultiFlexi API
            │ (poll finished jobs)
            └─────────────────HTTP POST──▶ [multiflexi-event:job.completed] ─▶ [multiflexi-artifact] ─▶ [multiflexi-runtemplate]
```

## Setup

Install one of two ways:

**Debian package (recommended)** — installs to `/usr/share/nodejs/node-red-contrib-multiflexi`:

```sh
sudo apt install node-red-contrib-multiflexi
sudo systemctl restart node-red
```

**From source** into your Node-RED user directory (`~/.node-red`):

```sh
cd ~/.node-red
npm install /path/to/multiflexi-event-processor/nodered
node-red-restart   # or restart Node-RED
```

Then configure the `multiflexi-eventor` daemon to forward events
(`/etc/multiflexi/multiflexi.env`):

```ini
NODERED_WEBHOOK_URL=http://YOUR-NODE-RED:1880/multiflexi-event
NODERED_FORWARD_CHANGES=true
# NODERED_TOKEN=optional-shared-secret
```

Finally, import `examples/payment-confirmation.flow.json`, set the **Server** base
URL and credentials on the config node, and fill in the RunTemplate IDs.

## Example

`examples/payment-confirmation.flow.json` wires:

- **Payment received** (`webhook.change`, evidence `banka`/create) →
  **Payment Receipt Confirmation** RunTemplate.
- **Job completed** → **JSON output** artifact filter → **Data consumer**
  RunTemplate (output chaining).

## License

MIT
