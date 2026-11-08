# WordPress with TiDB

A local HTTPS WordPress deployment backed by a **dedicated TiDB database and account**. Only `https://localhost:8443` is published, on `127.0.0.1`. WordPress itself has no published port. Finish installation locally before designing a public deployment.

## Requirements

- Docker Engine **28 or later**, with its normal firewall rules enabled, and Docker Compose v2, installed from [Docker's official instructions](https://docs.docker.com/engine/install/). The isolated gateway mode requires Engine 28+. Run `sh install.sh` to check the installed engine before setup.
- Python 3.9 or later for local configuration generation.
- A TiDB DNS hostname with a certificate trusted by the system CA bundle and an account allowed to create a dedicated database/user. Literal IP addresses and the reserved Compose names `wordpress`, `proxy`, `db-relay`, `database-gateway` are rejected.

`sh install.sh` only checks prerequisites. It does not install packages, fetch signing keys, or run commands as root.

## Configure and start

1. Clone this repository and its pinned submodule:

   ```sh
   git submodule update --init --recursive
   python3 scripts/setup.py
   ```

   Setup generates unique project, database and table-prefix names, eight independent signing keys, and a random database password. It refuses to overwrite existing settings. It never prints secrets.

2. Set `TIDB_HOST` and `TIDB_USER` in `.env`. Keep the generated database name and table prefix. Database names must contain letters/numbers only, avoiding wildcard semantics in database grants. Each installation needs a separate database and account; a prefix alone is not an isolation boundary.

3. In TiDB's administrative console, create the generated database and a dedicated account using the password in `secrets/db_password`. Grant only `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES` on that database's tables. Require SSL for that account where supported. Do not grant global privileges, roles, access to other databases, or `GRANT OPTION`. Restrict allowed source addresses to the deployment's egress addresses. The database account is used for both initial schema creation and subsequent WordPress migrations.

   The runtime rejects `root`/`*.root` identities, shared `test` databases, default `wp_` prefixes, and accounts whose effective direct grants extend beyond the configured schema. Role-based grants deliberately fail closed. Never copy a cluster administrator password into the application.

4. Build and start:

   ```sh
   docker compose config --quiet
   docker compose up --build -d
   docker compose ps
   ```

5. Export the local HTTPS certificate authority's **public certificate**:

   ```sh
   docker compose cp proxy:/data/caddy/pki/authorities/local/root.crt ./localhost-ca.crt
   ```

   Trust this certificate through your operating system/browser's normal certificate-management process. This is a deployment-specific local CA; keep its private keys in the Docker volume. Do not bypass browser certificate errors. For a CLI verification without changing a trust store:

   ```sh
   curl --cacert localhost-ca.crt https://localhost:8443/wp-admin/install.php
   ```

6. Open `https://localhost:8443` and create the WordPress administrator account. Use a unique strong password. Only local processes can reach the installer; on a shared workstation, complete setup before allowing other users access. The initial administrator creation and subsequent authentication/role events are audited.

## Security properties

- HTTPS terminates at Caddy; no HTTP port is published. The internal HTTP hop uses a private Docker network. Caddy overwrites the forwarded protocol header and WordPress forces HTTPS for administration.
- The database drop-in configures the CA bundle and peer/hostname verification before connecting. It requires a negotiated TLS cipher and validates schema-scoped grants before permitting WordPress queries. Initial connections and reconnects use the same policy. Connection failures return a generic error, without credentials or host details.
- All four services run as non-root with dropped capabilities, no-new-privileges, a read-only root filesystem, bounded CPU/memory/PIDs and rotated logs. Health checks check process readiness, not database availability. Relay health checks use private Unix sockets in a bounded temporary filesystem; they do not reserve TCP ports or open database sessions.
- WordPress core, configuration and plugins are built into the image. Uploads and private security state use separate writable volumes; Apache refuses uploaded PHP and symlinks without disabling normal site permalinks. Symlink checks are defense in depth, not protection against an attacker already able to run code as the application user. Dashboard file editing, plugin/theme installation and automatic file modifications are disabled. Updates require rebuilding and replacing the image. Startup requires both the DB drop-in and audit plugin.
- Apache accepts application requests only from the current `proxy` service address, resolved through Docker DNS on each request. The base image's broad `mod_remoteip` trust is disabled so forwarded input cannot replace the peer used for this check. Only the static health check accepts local connections. Caddy overwrites the HTTPS and client-address headers. Do not attach untrusted services with the `proxy` network alias or enable a module that rewrites Apache's peer address; the Docker daemon and network administrators remain trusted. This check does not encrypt the private proxy-to-application hop.
- The compatibility plugin is pinned at Git commit `3c714b9145cf5e05da821266404317e1b0f68eee` and its PHP file is verified against SHA-256 `aa234d6c8fe89d1c9fea7804d9099d03c5ed4dea106015cb24131d04291fe0dc` during the build. A missing or modified submodule fails the build. There is no host source-code bind mount.
- Database passwords and signing keys are read from read-only secret mounts, never environment variables or image layers. Local Compose secrets are files, **not an encrypted secret manager**. The parent `secrets/` directory is owner-only (`0700`); its files are readable inside the non-root containers. Protect the host with disk encryption and restricted Docker access. For production, use an encrypted secret manager and equivalent mounts.
- Image versions and digests are pinned. Review and update them regularly, rebuild, test, then recreate containers. Pinning prevents unexpected changes; it does not replace security updates.

### Database egress

WordPress joins only the internal `backend` network. Its `isolated` gateway mode removes the host bridge address, and Docker provides no external route or recursive external DNS to this container. IPv6 is disabled on the internal networks. It can reach Caddy and a fixed TCP database relay, but cannot directly reach the real database, arbitrary Internet/LAN endpoints, external DNS or cloud metadata services. Do not add other networks, routes, host aliases or untrusted peers to this network; Docker administrators and the host firewall remain trusted.

The connection path is `WordPress → db-relay → database-gateway → TIDB_HOST:TIDB_PORT`. On the app network, the original database hostname resolves to `db-relay`. The second relay is on a separate internal transit network and resolves that hostname using the real external DNS; separating them avoids resolving the database name back to the first relay. Only the gateway joins the outward-facing `database_egress` network. Neither relay mounts secrets, WordPress code or uploads, and neither accepts a destination supplied by a client. Both forward a byte stream to one fixed destination, with bounded connections and timeouts; they are not HTTP/CONNECT or SOCKS proxies.

MySQL TLS remains end-to-end between WordPress and TiDB: the relays do not terminate TLS, and WordPress still verifies the **original database hostname and certificate chain**. The gateway follows DNS changes for the operator-configured hostname; protect that configuration and its DNS. Network restrictions cannot prevent disclosure through legitimate site responses or protect data after a database/application compromise.

General WordPress outbound HTTP requests are intentionally unavailable, including remote embed discovery, download/update checks and plugins that call external APIs. Image updates happen at build time outside this isolated runtime. Any additional outbound integration needs an explicit reviewed destination and transport policy. Do not restore a general-purpose application egress bridge as a workaround. After changing the database endpoint, recreate `database-gateway`, `db-relay` and `wordpress` together and verify database access.

## Logs and operations

`docker compose logs` includes structured `wordpress_security` events for administrator bootstrap, successful/failed login, logout, account creation/deletion, role assignments and role-definition creation/update/deletion, password resets, key security options, and database query/connection validation failures. Database events use the same explicit stderr destination as the plugin, so Apache's general `LogLevel crit` cannot discard them. Events contain numeric actor/target IDs, timestamps and random request IDs, never passwords, tokens, SQL, supplied usernames or option values. Login events add fixed-length HMAC tags for the normalized account and proxy-observed client IP. Tags permit correlation without storing raw values, are different across deployments, and change when signing keys rotate; they are still sensitive audit data. Access logs omit request URLs, query strings, cookies and headers. PHP exception arguments are suppressed.

Each service uses Docker's `local` log driver, limited to five 10 MB files. In addition, administrator bootstrap, successful logins/logout, user/role changes, password resets and security-option changes are retained separately in `/var/lib/wordpress-security/privileged.jsonl`, with four rotated files, each at most 1 MiB. Anonymous failures, access noise and database errors cannot evict these privileged records. This directory uses a persistent project-scoped `security_state` volume, mode `0700`, with `0600` files owned by the application user. Rotation and appends share a bounded process lock. Container recreation preserves both records and login admission state.

Only authorized Docker administrators should read or back up these files. Forward privileged records and stderr to an access-controlled collector for production retention. Local files are not tamper-proof against an application compromise or Docker administrator, writes are not atomic with database changes, and bounded local rotation is not archival storage. Monitor `audit_storage_unavailable`: disk/lock failures are reported to stderr, where the original event is also emitted, but the separate retained copy may then be missing. Startup refuses unavailable or insecure storage. Do not delete the volume to clear a login limit; this also erases audit history. Follow your organization's retention policy.

### Login admission

All HTTP POST actions through `wp-login.php`, including login and account recovery, share a 60-second window: at most 5 admitted requests per normalized submitted identifier, 20 per proxy-observed source address and 60 across the deployment. Requests are counted before authentication, including correct passwords; limits do not disclose whether an account exists. Different usernames/emails for the same user can have different identifier buckets, while source and global budgets still apply. Oversized or non-string identifiers are rejected. Caddy supplies the client address, and spoofed forwarding headers cannot select another source bucket. On Docker Desktop, local clients may share a translated source address; the source limit then applies to that group.

Exhausted budgets return HTTP `429` with `Retry-After` and recover after the window expires; there is no persistent account lockout. Missing, malformed or busy admission storage returns a generic `503` before authentication. A file lock makes admissions atomic across PHP processes, and state/lock waiting are bounded. Only the first denial per window and a later aggregate denial count are added to the audit stream. Denied identifiers cannot grow the state indefinitely. Login-form GETs and trusted CLI operations remain available.

This is admission control for one Compose deployment, not bot protection or a distributed rate-limit service. It does not cover custom plugin authentication or application-password endpoints. XML-RPC is disabled. Before public hosting, add edge limits, review any additional authentication paths and expected traffic, and monitor availability: a sustained attack can consume the shared budget. Do not weaken source attribution or expose the backend to bypass a limit.

XML-RPC and web-triggered cron are disabled. If scheduled WordPress tasks are required, arrange a host scheduler to run this command every five minutes:

```sh
docker compose exec -T wordpress php /opt/wordpress/wp-cron.php
```

Back up the TiDB database, uploads and security-state volumes, `.env`, signing keys and Caddy state using encrypted backups. Test restoration. `docker compose down` preserves named volumes. Do not remove volumes unless you intend to erase deployment data.

### Rotation

To rotate WordPress signing keys and invalidate existing login sessions, complete all three steps in this checkout. The first step only prepares a new host file and does not restart anything:

```sh
python3 scripts/setup.py --rotate-salts
docker compose up -d --force-recreate wordpress
python3 scripts/verify-salts.py
```

Wait for WordPress to become healthy before verification. The verifier checks this checkout's Compose project/service identity and compares the prepared file with the running container's mounted secret without printing keys or fingerprints. A stale mount, stopped/unhealthy service, or unexpected project fails verification. Finally, confirm that a previously logged-in browser must log in again and that a fresh login works. Do not report revocation complete merely because file generation succeeded. Interrupted replacement removes its own temporary file; a process killed before cleanup can still leave a `secrets/salts-*` file. Keep that directory private, investigate any such file, and remove only confirmed unused temporary files.

For database credentials, create a replacement schema-scoped account, securely replace `secrets/db_password` while keeping the parent directory private, update only `TIDB_USER`, recreate WordPress, verify database access, then revoke the old account. Recreate containers after replacing a file-backed secret so Docker remounts its new inode. Investigate and rotate credentials if an older public deployment or writable plugin was compromised; changing this repository cannot revoke previously exposed credentials.

### Existing deployments

This hardening changes the original `http://localhost` setup to `https://localhost:8443`, requires a dedicated schema/account and unique table prefix, and replaces writable code with an image build. Back up first. Migrate existing tables to the dedicated schema and preserve or deliberately migrate table prefixes; changing a prefix without migrating tables makes WordPress appear uninstalled. Migrate uploads into the new project-scoped uploads volume and update WordPress `home`/`siteurl` to the HTTPS URL. Include reviewed custom plugins/themes in the Dockerfile rather than enabling dashboard writes. Validate the migration in a separate project before cutover.

For an existing deployment that predates isolated gateway networks, schedule a short outage, back up, run `docker compose down` **without `--volumes`**, then `docker compose up --build -d`. Docker must recreate the old bridge networks to apply the new gateway mode; merely rebuilding the application does not change an existing network's isolation. Preserve the project name and all data volumes. Confirm HTTPS, database access and the network checks before declaring the deployment updated.

### Public deployments

The shipped configuration is local-only. Public hosting requires a reviewed deployment configuration: a real domain and publicly trusted TLS, an initialized administrator account, authentication/rate limiting at the edge, preserved database egress restrictions, external audit retention, backups and monitoring. This Compose isolation depends on Docker's bridge/firewall implementation; a different deployment platform needs equivalent controls for DNS, IPv6, host/metadata access and the fixed database destination. Validate database connectivity and denied paths on that platform. Do not merely change the host bind to `0.0.0.0`, forward port 8443, or publish the WordPress backend.

The system CA bundle is intentional for TiDB Cloud's supported server authentication; TLS still verifies the hostname and certificate chain. A private-CA deployment needs a separately reviewed CA mount and certificate-rotation procedure. Do not disable verification or require client certificates on a service tier that does not support them. Likewise, file-backed Compose secrets rely on the owner-only parent directory: copying `0444` files elsewhere can expose them. Preserve that directory boundary in encrypted backups, or use a production secret manager with tested ownership mapping rather than changing file modes and preventing the non-root application from starting.

## Verification

`python3 -m unittest discover -s tests` checks setup isolation, secret handling and overwrite/interrupted-rotation behaviour. `python3 tests/integration.py` builds the pinned images in an isolated temporary project and checks HTTPS, database TLS/permissions, empty grant results, case variants of the forbidden prefix, installation, non-root/read-only execution, blocked endpoints, upload execution/symlinks, real Apache database audit output, role-definition events, spoofed source headers, permalinks, and old-session rejection after a verified secret remount. It also exercises concurrent PHP and HTTP admissions, source/global limits, expiry, corrupt/contended state, bounded private audit rotation and persistence across container recreation. Network tests confirm a live canary is accessible from the gateway but denied from WordPress, with direct database/public/metadata/IPv6 paths and external DNS blocked, no host gateway, TLS identity rejection through the relays, and recovery after gateway recreation. Its database and credentials are disposable test fixtures; it never connects to TiDB Cloud. HTTP probes, the canary and state test are added only to the disposable test image. GitHub Actions runs these checks for changes.
