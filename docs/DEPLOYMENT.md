# Development deployment (Fedora / Nginx / PHP-FPM)

Owner decision: automatically deploy `main` **after** the `Development baseline` CI workflow succeeds. No production deployment. The workflow deploys the exact verified SHA, skips superseded commits, refuses tracked server edits/diverged history, and preserves `.env`, `APP_KEY`, database, and untracked files. Failures after maintenance begins leave the application in maintenance mode for inspection; database migrations are never automatically reversed.

Observed server: user `ajanci`, existing Git checkout `/home/ajanci/projects/caselantern`, old non-Git copy `/var/www/caselantern/alex`, Nginx and PHP-FPM. Keep both existing directories. The new managed checkout is `/var/www/caselantern/development`; the private Nginx site listens on loopback port 8000. The deploy user needs PHP, Composer, Node/npm, Git, curl and flock in its noninteractive PATH.

## 1. Prepare a new checkout

After this change is merged to main, run:

```bash
sudo install -d -o ajanci -g ajanci /var/www/caselantern/development
git clone https://github.com/skrall3119/HelpDesk.git /var/www/caselantern/development
cd /var/www/caselantern/development
composer install --no-interaction --prefer-dist
npm ci
npm run build
```

To test before merge, fetch and switch to `codex/bearer-auth-dev-deploy` after cloning. Automatic deployment requires returning the managed checkout to `main` after merge.

Copy the **working** environment locally on the server, not through chat. The Git checkout is the preferred source; verify it exists and is the environment you have been using. If only the older copy has a working `.env`, explicitly substitute that path. Do not overwrite an existing destination `.env` and do not generate a new application key:

```bash
test -f /home/ajanci/projects/caselantern/.env
test ! -e .env && install -m 600 /home/ajanci/projects/caselantern/.env .env
```

Edit the copied `.env`: set `TENANT_BASE_DOMAIN=localhost`, `APP_URL=http://localhost:8000`, `APP_DEBUG=false`. Retain its key and working DB credentials; runtime must use `caselantern_app`, with separate migration credentials configured. Confirm these values privately before migration.

Run the one-time lookup-role setup and forward migrations in [AUTHENTICATION.md](AUTHENTICATION.md). If that role already exists, inspect its attributes/membership instead of rerunning the CREATE ROLE script. No database reset is required. Run the documented tests against a disposable test database before using the APIs.

## 2. Configure permissions and the private Nginx site

The observed PHP-FPM workers run as `apache`. These ACLs allow that worker to read the environment and write Laravel runtime directories without making them world-writable:

```bash
sudo dnf install -y acl policycoreutils-python-utils
setfacl -m u:apache:r .env
setfacl -R -m u:apache:rwX,u:ajanci:rwX storage bootstrap/cache
find storage bootstrap/cache -type d -exec setfacl -m d:u:apache:rwx,d:u:ajanci:rwx {} +
sudo semanage fcontext -a -t httpd_sys_content_t '/var/www/caselantern/development(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/caselantern/development/storage(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/caselantern/development/bootstrap/cache(/.*)?'
sudo restorecon -Rv /var/www/caselantern/development
sudo setsebool -P httpd_can_network_connect_db on
sudo cp deploy/nginx-development.conf /etc/nginx/conf.d/caselantern-development.conf
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl reload php-fpm
curl --fail http://127.0.0.1:8000/up
```

The file-context commands are one-time setup; use `semanage fcontext -m` when a matching custom rule already exists. Fedora must permit Nginx to bind port 8000: check `sudo semanage port -l | grep http_port_t`; if 8000 is absent, add it with `sudo semanage port -a -t http_port_t -p tcp 8000`. Do not open port 8000 in the public firewall. The template uses the `php-fpm` upstream already seen in this server's Nginx configuration; `nginx -t` verifies it before reload.

From Windows, keep your existing SSH host/key, with forwarding:

```powershell
ssh -i "$env:USERPROFILE\.ssh\YOUR_SERVER_KEY" -o IdentitiesOnly=yes -N -L 8000:127.0.0.1:8000 ajanci@YOUR_SERVER
```

Open `http://YOUR_TENANT_SLUG.localhost:8000`. For a direct server-side check, use curl's `--resolve YOUR_TENANT_SLUG.localhost:8000:127.0.0.1`. The tenant slug is the one provisioned in your database, not the employee username.

## 3. Give automation narrowly scoped restart access

Verify `command -v systemctl` is `/usr/bin/systemctl`. Use `sudo visudo -f /etc/sudoers.d/caselantern-development` to add:

```text
ajanci ALL=(root) NOPASSWD: /usr/bin/systemctl reload php-fpm
```

Verify with `sudo -n /usr/bin/systemctl reload php-fpm`. This is the only privileged command the deployment workflow needs. Database role bootstrap and Nginx configuration remain one-time administrator steps.

## 4. Configure GitHub's development environment

In repository Settings → Environments, create `development` and restrict deployment branches to `main`. For fully automatic deployment, do not require reviewer approval for this development environment.

Environment variables:

| Name | Value |
| --- | --- |
| `DEV_HOST` | Server's SSH IP or DNS name reachable by GitHub's runner |
| `DEV_USER` | `ajanci` |
| `DEV_PORT` | SSH port (default `22`) |
| `DEV_PATH` | `/var/www/caselantern/development` |
| `DEV_RESTART_COMMAND` | `sudo -n /usr/bin/systemctl reload php-fpm` |

Environment secrets:

| Name | Value |
| --- | --- |
| `DEV_SSH_KEY` | Dedicated deployment SSH private key (never commit or paste into chat) |
| `DEV_KNOWN_HOSTS` | Verified OpenSSH known_hosts entry for the server and SSH port |

Create a separate Ed25519 key for automation, add its public key to `ajanci`'s `~/.ssh/authorized_keys` with `restrict` to disable forwarding/PTY, and store only its private key in GitHub's secret. Verify the host-key fingerprint through your existing trusted SSH connection (`sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`) before saving the matching known_hosts entry. Do not blindly trust an unverified scan. The server still needs inbound SSH connectivity from the runner; do not broaden access without reviewing your current SSH/firewall policy.

The workflow uses SSH host-key checking, no passwords, no secret echoing, and no repository-supplied credentials. Laravel migrations use the server's existing migration configuration. Database credentials and application key stay on the server.

## 5. Normal deployment and failure recovery

Merge the reviewed, passing change to `main`. In Actions, watch **Development baseline**, then **Deploy development**. The server stays on main; no manual pulls are required afterward. The workflow installs locked dependencies, builds assets, migrates forward, caches configuration/routes/views, reloads PHP-FPM, and verifies `/up` on loopback. `/up` verifies HTTP/application boot; authentication correctness is covered by the CI tests and the initial tenant login smoke check.

If setup is incomplete when the first main build passes, deployment fails with the missing variable/secret or SSH error. After fixing setup, rerun the failed **Deploy development** job for the current main SHA. If main has advanced, deploy only after that newer revision passes CI. When application maintenance has started, fix the reported error and rerun; use `php artisan up` manually only after confirming application health. Do not automatically roll back migrations or overwrite server edits.

Implementation was reviewed statically in the authoring workspace; PHP/PostgreSQL tests and real server deployment remain to be executed, per owner preference.
