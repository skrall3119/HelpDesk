# Separate development environments on one Fedora server

Use one Nginx installation and one PostgreSQL installation, with independent code,
configuration, databases and Laravel storage. Only the integration checkout is
updated automatically from main after CI passes.

## Proposed layout

These are defaults to configure, not claims about existing Nginx bindings. Check
ports with `sudo ss -ltnp` before using them. Keep working developer Nginx sites.

| Environment | Checkout | Database | Suggested loopback port |
| --- | --- | --- | --- |
| Alex | /home/ajanci/projects/caselantern (existing) | caselantern_alex | 8000 |
| Friend | Their existing checkout (choose with them) | caselantern_friend | 8001 |
| Integration | /var/www/caselantern/development (new) | caselantern_integration | 8002 |

Each developer controls their branch and migrations in their own database.
Integration tracks main. Never point automation at either developer checkout.
Tests use additional disposable databases ending in `_test`, not any of these
three databases. Separate databases isolate accidental schema/data changes;
the existing shared PostgreSQL login roles do not provide security isolation
between developers who possess those credentials. Per-developer database roles
and PHP-FPM users are later hardening if mutually untrusted access is a concern.

## 1. Create databases without changing the current database

Keep the existing database and its data intact. Do not rename it, reset it, or
restore over it. These commands create NEW empty databases and fail if a name
already exists. Run only for missing names:

```bash
sudo -u postgres createdb -O caselantern_migrator caselantern_alex
sudo -u postgres createdb -O caselantern_migrator caselantern_friend
sudo -u postgres createdb -O caselantern_migrator caselantern_integration
```

The existing `caselantern_migrator` and `caselantern_app` roles are cluster-wide;
do not rerun `development-roles.sql`. The restricted authentication lookup role
is also cluster-wide: run `database/setup/authentication-role.sql` once only if
not already created. If pg_hba.conf restricts database names, add the three new
names for the existing runtime/migration roles using the existing SCRAM policy,
then reload PostgreSQL configuration. Do not change authentication to trust.

This starts each environment empty. If existing tenant data is needed, plan a
backup/restore into the NEW databases separately; do not silently move or copy
credentials/data. Otherwise use the existing provisioning command after migration
(see TENANT_PROVISIONING.md).

## 2. Configure each checkout independently

In each developer checkout, preserve the current `.env` and APP_KEY, back up `.env`
privately, then set its own DB_DATABASE from the table above. Set:

```dotenv
DEPLOYMENT_ENVIRONMENT=developer
DB_CONNECTION=pgsql
DB_URL=
TENANT_BASE_DOMAIN=localhost
```

Set APP_URL to that checkout's Nginx URL. Use its own storage directory, cache,
sessions, logs and assets; do not symlink these to the other checkout. File cache
and sessions already default to checkout-local paths. If using Redis instead,
configure separate connection databases/prefixes. Browser development on different
localhost ports shares hostname storage, so use separate browser profiles when
switching environments with saved bearer tokens.

Retain separate runtime and migration role credentials. After checking the database
name privately, apply migrations in THAT checkout:

```bash
php artisan config:clear
php artisan migrate --database=pgsql_migrator --force
```

Apply runtime grants once in each new database (from any up-to-date checkout):

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d caselantern_alex < database/setup/runtime-grants.sql
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d caselantern_friend < database/setup/runtime-grants.sql
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d caselantern_integration < database/setup/runtime-grants.sql
```

Only run a grant command after that database has been migrated. Future main
deployments migrate integration only. Developer databases follow their respective
branches. Once a migration is applied to a persistent environment, use forward
migrations rather than rewriting history.

## 3. Bootstrap the integration checkout once

After the changes are merged, create a NEW checkout; do not overwrite existing
files or either developer checkout:

```bash
sudo install -d -o ajanci -g ajanci /var/www/caselantern/development
git clone https://github.com/skrall3119/HelpDesk.git /var/www/caselantern/development
cd /var/www/caselantern/development
composer install --no-interaction --prefer-dist
npm ci
npm run build
test ! -e .env && install -m 600 .env.example .env
```

Configure this NEW `.env` privately with existing role passwords and:

```dotenv
APP_ENV=local
APP_DEBUG=false
APP_URL=http://localhost:8002
TENANT_BASE_DOMAIN=localhost
DEPLOYMENT_ENVIRONMENT=integration
DB_CONNECTION=pgsql
DB_DATABASE=caselantern_integration
DB_URL=
```

Set DB_USERNAME/DB_PASSWORD to the restricted runtime role and
DB_MIGRATION_USERNAME/DB_MIGRATION_PASSWORD to the migration role. Generate an
APP_KEY only for this new, unused installation (`php artisan key:generate`);
never rotate the keys in existing environments. Then:

```bash
php artisan config:clear
php artisan caselantern:check-deployment caselantern_integration
php artisan migrate --database=pgsql_migrator --force
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d caselantern_integration < database/setup/runtime-grants.sql
php artisan config:cache
```

The deployment check connects as both roles and verifies the actual database
name before maintenance or migrations. It refuses developer environments,
production, and database names not ending in `_integration`. Install this command
and configure integration manually before enabling automation; an older checkout
without the command fails closed.

## 4. Add only the integration Nginx site

Keep existing developer sites. `deploy/nginx-development.conf` is a template for
the NEW integration checkout on 127.0.0.1:8002. Confirm the port is free and that
the existing `php-fpm` upstream is defined before installation. If selecting a
different port, update the template, APP_URL and GitHub DEV_HEALTH_URL together.

The observed FPM worker user is `apache`. On the new checkout:

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
```

For existing custom file-context rules use `-m` rather than `-a`. Check
`sudo semanage port -l | grep http_port_t`; if 8002 is unassigned, add it with
`sudo semanage port -a -t http_port_t -p tcp 8002`. If assigned to another SELinux
service type, choose a free permitted port instead of reassigning it blindly.
Then install the new site only if its destination does not already exist:

```bash
test ! -e /etc/nginx/conf.d/caselantern-development.conf && sudo cp deploy/nginx-development.conf /etc/nginx/conf.d/caselantern-development.conf
sudo nginx -t && sudo systemctl reload nginx
curl --fail http://127.0.0.1:8002/up
```

Use SSH forwarding, e.g. `ssh -N -L 8002:127.0.0.1:8002 ajanci@YOUR_SERVER`,
then open `http://YOUR_TENANT_SLUG.localhost:8002`. Do not expose these loopback
development ports publicly. Use the provisioned tenant slug, not a developer name.

## 5. Enable automatic deployment for integration only

Create GitHub environment `development`, restricted to main. Configure:

| Variable | Value |
| --- | --- |
| DEV_HOST | SSH address reachable from the runner |
| DEV_USER | ajanci (or a dedicated integration deployment user) |
| DEV_PORT | SSH port, normally 22 |
| DEV_PATH | /var/www/caselantern/development |
| DEV_DATABASE | caselantern_integration |
| DEV_HEALTH_URL | http://127.0.0.1:8002/up |
| DEV_RESTART_COMMAND | See PHP-FPM note below |

Secrets: DEV_SSH_KEY (dedicated deployment private key) and DEV_KNOWN_HOSTS
(verified OpenSSH known_hosts entry). Keep secrets out of chat and Git. Add the
public deployment key to the server user's authorized_keys with `restrict`.
Verify the host fingerprint through a trusted SSH connection before storing it.

PHP-FPM: reloading the shared service affects every pool. Prefer timestamp-based
OPcache validation for development and set DEV_RESTART_COMMAND to `true` only
after confirming `opcache.validate_timestamps=1` in the FPM configuration and
its revalidation interval is acceptable. Otherwise configure a separate integration
FPM service/socket and use its narrowly scoped reload command. Do not assume that
creating a pool inside the same service gives independent service reloads.
If choosing a service reload, grant only that exact command through sudoers.
The workflow never changes existing Nginx sites or developer checkout files.

Main CI uses disposable PostgreSQL databases, then the deployment workflow sends
the verified commit to integration. A checkout lock serializes integration deploys;
run heavy developer builds/tests at different times on the 1-vCPU/2-GB server.
The health check verifies application boot, not database authentication; run an
initial login/me/logout smoke check in each newly provisioned environment.

A failed deployment remains in integration maintenance mode. Fix and rerun for
the current passing main commit. Never automatically roll back databases. Neither
developer database is migrated by this workflow. Actual server provisioning and
Nginx configuration must be performed by the owner using the commands above.
