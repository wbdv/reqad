# Reqad DNS Agent

Small Python HTTPS service that runs on the cPanel server and manages DNS zones
on behalf of Reqad VPS servers. Reqad (PowerDNS hidden-master mode) calls it
after each local DNS write; the agent applies the change to cPanel via `whmapi1`.

## Architecture

```
Reqad VPS  →  HTTPS POST /dns  →  cPanel agent  →  sudo whmapi1 adddns
              (token + IP auth)     port 2089          (runs as root)
```

Security: each Reqad VPS has its own token; connections are validated by
both token and source IP. The agent enforces a domain allowlist per server.

## Setup (cPanel server, run as root)

1. Copy this folder to the cPanel server:
   ```
   scp -r scripts/deploy-cpanel/ root@your-cpanel-server.example:/root/reqad-agent-setup/
   ```

2. Run the setup script:
   ```
   chmod +x /root/reqad-agent-setup/setup-agent.sh
   /root/reqad-agent-setup/setup-agent.sh
   ```
   This creates the `reqad-agent` system user, deploys `agent.py`, configures
   sudoers, opens port 2089 in CSF, and starts the systemd service.

3. Register the Reqad VPS (generate a random token):
   ```
   TOKEN=$(openssl rand -hex 20)
   /home/reqad-agent/add-server.sh v182 85.9.27.182 $TOKEN
   ```
   This adds the server to the database and adds a CSF IP rule for port 2089.

4. In Reqad UI → Settings → DNS Settings → PowerDNS → Hidden master mode:
   - Agent URL: `https://your-cpanel-server.example:2089`
   - Agent token: (the token from step 3)

## Files

| File | Purpose |
|---|---|
| `agent.py` | Python HTTPS agent (deployed to `/home/reqad-agent/`) |
| `agent.conf` | Runtime config: cert paths, port (created by setup-agent.sh) |
| `agent.db` | SQLite: registered servers + domain allowlist |
| `agent.log` | Request log |
| `setup-agent.sh` | One-time setup (creates user, sudoers, CSF, systemd) |
| `add-server.sh` | Register a new Reqad VPS (run after setup) |

## Management

```bash
systemctl status reqad-agent          # check status
journalctl -u reqad-agent -f          # live logs
tail -f /home/reqad-agent/agent.log   # agent request log

# Add another Reqad server
/home/reqad-agent/add-server.sh vps2 1.2.3.4 $(openssl rand -hex 20)
```

## Cert renewal note

The agent uses the cPanel wildcard certificate. After cert renewal, restart:
```
systemctl restart reqad-agent
```
If the cert path changes (new filename), update `/home/reqad-agent/agent.conf`
and restart.

## Actions

POST `/dns` with a JSON body `{ "action": "...", ... }` (Bearer token + source-IP
auth). Implemented actions:

| Action | Body | Direction | Effect |
|---|---|---|---|
| `add_zone` | `domain`, `ip` | push | `whmapi1 adddns`, add domain to this server's allowlist |
| `delete_zone` | `domain` | push | `whmapi1 killdns`, remove domain from allowlist |
| `sync_zone` | `domain`, `records[]` | push | full replace of non-SOA/NS records via `mass_edit_dns_zone` |
| `list_zones` | — | pull | reads the `domains` allowlist; returns this server's `zones[]` (no whmapi1) |
| `dump_zone` | `domain` | pull | `whmapi1 parse_dns_zone`; returns `records[]` (PowerDNS content) + `serial` |

`list_zones` + `dump_zone` back the **"Sync to local"** button on Reqad's DNS
page (PowerDNS hidden-master): they pull the cPanel copy of this server's zones
into local PowerDNS. **Scope is the allowlist only** — the agent never lists or
dumps a zone that is not already registered to the requesting server (i.e. a
zone created through Reqad's `add_zone`). It never auto-claims a zone, so it
cannot expose or touch domains that belong to another server / WHM account.

## Upgrading the live agent

`agent.py` changed when `list_zones`/`dump_zone` were added; the sudoers
allowlist is unchanged (`dump_zone` reuses the already-allowed `parse_dns_zone`).
To update an already-deployed agent, on the cPanel server as root:
```
/bin/cp /root/reqad-agent-setup/agent.py /home/reqad-agent/agent.py
chown reqad-agent:reqad-agent /home/reqad-agent/agent.py
systemctl restart reqad-agent
```
