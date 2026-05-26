# Exposing the Maya webhook to the public internet (local dev)

Maya posts payment-status updates to a webhook URL you register in its developer
portal. Because Maya can't reach `localhost` or your DDEV `.ddev.site` hostname,
you need a public HTTPS URL that proxies back to your DDEV WordPress instance.
This guide covers two paths with Cloudflare Tunnel:

- **Quick tunnel** — ephemeral `*.trycloudflare.com` URL, no account, ideal for a
  one-off smoke test.
- **Named tunnel** — stable URL on your own domain, the right choice for any
  ongoing development.

## The webhook URL pattern

The plugin registers its handler on the `woocommerce_api_maya_webhook` action
(see `src/Plugin.php`), so the endpoint customers/Maya reach is:

```
https://<your-public-host>/?wc-api=maya_webhook
```

With pretty permalinks enabled the equivalent form
`https://<your-public-host>/wc-api/maya_webhook/` also works.

## Sanity check the endpoint

From any machine that can resolve the public host:

```bash
curl -i 'https://<your-public-host>/?wc-api=maya_webhook'
```

Expected responses:

| Response                               | Meaning                                                                |
| -------------------------------------- | ---------------------------------------------------------------------- |
| `HTTP/2 400` body `Empty body`         | Reaching `WebhookHandler::process()` — correct.                        |
| `HTTP/2 404` body `404 page not found` | Hit DDEV's Traefik but no project matched — Host-header rewrite wrong. |
| `HTTP/2 404` from WordPress (HTML)     | Permalinks need flushing, or WooCommerce isn't active.                 |
| Cloudflare 502/503/1033 page           | Tunnel can't reach origin, or DNS/tunnel mis-binding.                  |

---

## Path A — Quick tunnel (ephemeral)

Good for a 5-minute "does Maya even hit me?" sanity check. The URL changes on
every restart, so don't paste it anywhere permanent.

### 1. Start the tunnel

```bash
cloudflared tunnel \
  --url https://stork-sage-bedrock.ddev.site \
  --http-host-header stork-sage-bedrock.ddev.site \
  --no-tls-verify
```

Why each flag matters:

- `--url` — the origin. The `.ddev.site` hostname resolves to `127.0.0.1` via
  `/etc/hosts` (DDEV manages this), so cloudflared connects locally to your
  Traefik router.
- `--http-host-header` — without this, cloudflared forwards the public
  `*.trycloudflare.com` Host header to DDEV's Traefik, which doesn't know that
  hostname and returns its default `404 page not found`. The rewrite makes
  Traefik see the DDEV hostname and route to your project.
- `--no-tls-verify` — DDEV's HTTPS cert is mkcert-signed (locally trusted),
  not publicly trusted, so cloudflared would otherwise refuse the connection.

### 2. Grab the URL

cloudflared prints `https://<random-words>.trycloudflare.com` once the four
edge connections register. Paste that into Maya's Webhook URL field as
`https://<random-words>.trycloudflare.com/?wc-api=maya_webhook`.

### 3. Keep it running

The Bash terminal hosting cloudflared must stay open. To survive a closed
terminal, run inside `tmux` or `nohup`:

```bash
tmux new -s cf 'cloudflared tunnel \
  --url https://stork-sage-bedrock.ddev.site \
  --http-host-header stork-sage-bedrock.ddev.site \
  --no-tls-verify'
# Detach: Ctrl-b d ; Reattach: tmux attach -t cf
```

```bash
nohup cloudflared tunnel \
  --url https://stork-sage-bedrock.ddev.site \
  --http-host-header stork-sage-bedrock.ddev.site \
  --no-tls-verify \
  > ~/cf-tunnel.log 2>&1 &
disown
grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' ~/cf-tunnel.log | head -1
```

### Limitations of the quick tunnel

- New subdomain every restart — you'll re-paste it into the Maya dashboard.
- WordPress will still emit redirect URLs pointing at
  `https://stork-sage-bedrock.ddev.site` (because `WP_HOME` is the DDEV
  primary URL), so the customer redirect leg of Maya Checkout won't work
  end-to-end. The webhook itself is unaffected because Maya talks to it
  server-to-server.

---

## Path B — Named tunnel on your own domain

Stable hostname, survives restarts, single Maya-dashboard paste forever. Assumes
the domain is already on Cloudflare. Steps below use `tanikyuun.pw` with the
subdomain `stork.tanikyuun.pw` — adjust freely.

### 1. Authorize cloudflared

```bash
cloudflared tunnel login
```

Browser opens → log into Cloudflare → pick your zone → Authorize. Writes
`~/.cloudflared/cert.pem`.

### 2. Create the named tunnel

```bash
cloudflared tunnel create stork-dev
```

Prints a UUID and writes `~/.cloudflared/<UUID>.json` (the tunnel credentials).
Note the UUID.

### 3. Route a DNS hostname to the tunnel

```bash
cloudflared tunnel route dns stork-dev stork.tanikyuun.pw
```

If you've previously routed this name and the CNAME already exists, either pick
a different subdomain or overwrite:

```bash
cloudflared tunnel route dns --overwrite-dns stork-dev stork.tanikyuun.pw
```

### 4. Write the cloudflared config

`~/.cloudflared/config.yml`:

```yaml
tunnel: stork-dev
credentials-file: /home/<you>/.cloudflared/<UUID>.json

ingress:
  - hostname: stork.tanikyuun.pw
    service: https://stork-sage-bedrock.ddev.site
    originRequest:
      noTLSVerify: true
      httpHostHeader: stork-sage-bedrock.ddev.site
  - service: http_status:404
```

The `originRequest` block plays the same role as `--no-tls-verify` and
`--http-host-header` did for the quick tunnel.

### 5. Teach DDEV about the public hostname

Edit `.ddev/config.yaml` and add:

```yaml
additional_fqdns:
  - stork.tanikyuun.pw
```

This adds a Traefik rule so requests with `Host: stork.tanikyuun.pw` route to
your project (belt-and-suspenders alongside the `httpHostHeader` rewrite).

### 6. Make WordPress emit tunnel URLs (only if you need the redirect flow)

Bedrock's `.env` binds `WP_HOME` to `DDEV_PRIMARY_URL`. For full
Maya Checkout testing — where a customer redirects to Maya and back — WP needs
to emit the tunnel hostname in return URLs. Add overrides via DDEV so they only
apply inside the container:

```yaml
# .ddev/config.yaml
web_environment:
  - WP_HOME=https://stork.tanikyuun.pw
  - WP_SITEURL=https://stork.tanikyuun.pw/wp
```

Then:

```bash
ddev restart
```

Skip this step if you only care about the webhook leg of the integration; Maya
posts to webhooks server-to-server regardless of `WP_HOME`.

### 7. Run the tunnel

```bash
cloudflared tunnel run stork-dev
```

For a detached session:

```bash
tmux new -s cf 'cloudflared tunnel run stork-dev'
# detach: Ctrl-b d
```

### 8. Configure Maya

Webhook URL in the Maya developer portal:

```
https://stork.tanikyuun.pw/?wc-api=maya_webhook
```

Same URL goes in `Application details → Webhook URL` when creating or updating
your Maya application.

---

## Cleanup

### Stop a running tunnel

```bash
pkill -f 'cloudflared tunnel'
```

### Delete a named tunnel from Cloudflare

```bash
cloudflared tunnel list                    # find the UUID
cloudflared tunnel delete <UUID-or-name>   # removes tunnel + credentials JSON
```

### Remove the DNS CNAME

`cloudflared` has no CLI for deleting DNS routes. Remove the CNAME via
Cloudflare dashboard → your zone → DNS → Records → delete the relevant entry.
Leaving the CNAME in place is harmless beyond the visitor seeing a Cloudflare
`1033` error page if they hit the hostname after the tunnel is gone.

### Nuke all local cloudflared state

```bash
rm -rf ~/.cloudflared
```

Removes the account cert, all tunnel credentials, and `config.yml`. Reversible
by re-running `cloudflared tunnel login` and `cloudflared tunnel create`.

---

## Troubleshooting cheatsheet

| Symptom                                                       | Likely cause                                                                               |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `404 page not found` (19-byte plain-text) from the tunnel URL | Cloudflared isn't rewriting the Host header — set `--http-host-header` / `httpHostHeader`. |
| `502 Bad Gateway` / Cloudflare error 1033                     | Tunnel isn't running, or `ingress.service` URL is wrong / unreachable.                     |
| `HTTP/2 400 Empty body` from `curl`                           | Working correctly. That's `WebhookHandler::process()` rejecting an empty POST.             |
| Maya retries the webhook repeatedly                           | The handler isn't returning 2xx — check the WC logs (`wp-content/uploads/wc-logs/`).       |
| Customer redirected to `*.ddev.site` after Maya checkout      | `WP_HOME`/`WP_SITEURL` not overridden to the tunnel hostname (see Path B step 6).          |
| `An A, AAAA, or CNAME record with that host already exists`   | Old CNAME left over — use `--overwrite-dns` or delete via the Cloudflare dashboard.        |
| ICMP/`ping_group_range` warning in cloudflared logs           | Cosmetic. Affects only ICMP forwarding, not HTTP. Safe to ignore.                          |
| `failed to sufficiently increase receive buffer size` warning | Cosmetic. QUIC perf hint only — irrelevant at dev volumes.                                 |
