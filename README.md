# Calmfox Watch for Neos CMS

**English** · [Polski](README.pl.md)

Inside-the-site monitoring for sites built on Neos (Flow), reporting to the
[watch.calmfox.net](https://watch.calmfox.net) panel. The package implements the
same contract with Calmfox Watch as the WordPress plugin and the Magento and
Sylius packages (same response shape, same signature, same pairing flow): it
exposes a secret health endpoint that Calmfox Watch polls in a pull model.

Three things it gives you:

- **service status**: database, disk space, connection to the mail server,
  Flow cache, resource publishing, content repository, and optionally job
  queues and the search engine;
- **basic security hygiene**: application context, leaking error details, file
  and directory permissions, administrator accounts, development packages in
  production, pending updates;
- **package version change history**: so that you can say "the outage started
  an hour after package X was updated".

What the package does **not** do, and will not promise: it does not scan for
malicious code, does not compute file checksums, does not make backups and does
not ship event logs.

---

## Requirements and version range

```json
"require": {
    "php": ">=8.1",
    "neos/flow": "^8.3 || ^9.0",
    "neos/neos": "^8.3 || ^9.0"
}
```

The constraint `^8.3 || ^9.0` means "8.3 and newer within the 8 line, or any
9.x". We deliberately do NOT write `>=8.3`, because that would promise
compatibility with every future major version nobody has seen yet. And we
deliberately do not write just `^8.3`, because Neos 9 is the version deployments
are moving to right now.

To be honest about the two major versions: Neos 9 rewrote the content repository
API from scratch. The code sticks to what is stable in both (`SiteRepository`,
`CacheManager`, Flow Security, Doctrine), and where the APIs diverge, the check
**degrades to less information instead of falling over**: on Neos 9 the
`content_repository` check reports the number of active sites without the node
count, rather than throwing an exception. The package was built and run against
the Neos 8.3 API; for 9.0 the compatibility paths are written, but we have not
exercised them on a live installation.

## Installation

The package is available in the public Composer package index (Packagist) as
`calmfox/watch-neos`. Where a site cannot use Packagist, you install it from the
`calmfox-watch-neos.zip` archive provided by the Calmfox Watch panel (Integrations,
the "Download for Neos CMS" button). The archive contains a single directory:
`Calmfox.Watch/`. All routes below lead to the same result.

### Route 1: Composer (recommended)

```bash
composer require calmfox/watch-neos
FLOW_CONTEXT=Production ./flow flow:cache:flush --force
```

Updating: `composer update calmfox/watch-neos`.

### Route 2: unpack the archive next to the project and hook it up with Composer

The order is: first unpack the archive into a directory inside the project, then
point Composer at that directory as a `path` repository, and finally run
`composer require`. Composer then runs the Neos installer, rebuilds the
autoloader and publishes resources, which is exactly what happens with a package
downloaded from Packagist:

```bash
mkdir -p CalmfoxPackages && unzip calmfox-watch-neos.zip -d CalmfoxPackages
composer config repositories.calmfox-watch '{"type":"path","url":"./CalmfoxPackages/Calmfox.Watch","options":{"symlink":false}}'
composer require calmfox/watch-neos:@dev
FLOW_CONTEXT=Production ./flow flow:cache:flush --force
```

Three places where it is easy to trip up:

- **`"symlink": false`** tells Composer to copy the files. Without it, the
  package in `Packages/Application` is merely a symlink to `CalmfoxPackages` and
  will disappear together with that directory.
- **The unpacked directory stays in the project** (and in the repository, if you
  deploy from git). Composer reads it on every `composer install`, so deleting
  it will break the next deployment.
- **The `@dev` after the package name is required.** The archive's
  `composer.json` deliberately has no `version` field (Composer derives the
  version from the repository tag, and the archive has no tag), so the `path`
  repository reports it as `dev-main`.

Updating from the archive: unpack the newer one into the same place and run
`composer update calmfox/watch-neos`.

### Route 3: copying the files manually

**Neos 9 only.** On Neos 8.3 (Flow 8.3) this route did not work for us: Flow saw
the package, but its classes were missing from the Composer autoloader and every
`./flow` command ended with an exception. On 8.3, use route 1 or 2.

```bash
unzip calmfox-watch-neos.zip -d Packages/Application
FLOW_CONTEXT=Production ./flow flow:cache:flush --force
FLOW_CONTEXT=Production ./flow resource:publish
```

Flow discovers packages by scanning the `Packages` directory for `composer.json`
files, and it adds the namespaces from the package's `autoload` section to its
own class loader, so the package also works without an entry in the project's
`composer.json`. With this route both commands after unpacking are mandatory:
the scan result lives in the cache (without a flush Flow will see neither the
package nor its commands), and there is no Composer here to publish the public
resources with a post-install script.

One warning for deployments from git: the Neos base distribution keeps the whole
`Packages/` directory out of the repository (its `.gitignore` has a `/Packages/`
entry), so a manually copied package either has to be excluded from that entry
or copied again after every deployment. Routes 1 and 2 do not have this problem,
because the package comes back with `composer install`.

### Hooking up the health endpoint route

Neos has two routing mechanisms, and which one you use decides whether you need
to do anything at all.

**A distribution without `Configuration/Routes.yaml` in the project** (this is
what the Neos 9 base distribution looks like): routes are built solely from the
`Neos.Flow.mvc.routes` setting, and the package registers itself there, the same
way `Neos.Media` does. You do not need to change anything in the project.
Verified on a live Neos 9.1.5 installation.

**A distribution with `Configuration/Routes.yaml` in the project**: add the
subroute **before** the `Neos.Neos` routes, because those catch every remaining
URL as a page:

```yaml
-
  name: 'Calmfox Watch'
  uriPattern: '<CalmfoxWatchSubroutes>'
  subRoutes:
    CalmfoxWatchSubroutes:
      package: 'Calmfox.Watch'

# ...below, the routes you already have, including Neos:
-
  name: 'Neos'
  uriPattern: '<NeosSubroutes>'
  subRoutes:
    NeosSubroutes:
      package: 'Neos.Neos'
```

The self-registration from settings then sits idle and **does not get in the
way**: Flow appends routes from settings AFTER the routes from the file
(`RoutesLoader`: "Routes from settings will always be appended to existing route
definitions"), so they end up behind the Neos catch-all and never match. The two
mechanisms are not mutually exclusive; an earlier release of this package
wrongly warned that they were.

If you want a path other than `/calmfox-watch/health`, change `uriPattern` in
the package's `Configuration/Routes.yaml` and set the same value in the
`Calmfox.Watch.healthPath` setting, because that is what we build the URL
reported to Calmfox Watch from.

### Running ./flow and flushing the cache

Verified on shared hosting (cyber-Folks, LiteSpeed, Neos 9.1.5):

- **Pass the context explicitly**: `FLOW_CONTEXT=Production ./flow ...`. Without
  it Flow starts in the Development context and crashes on the first command.
- **Use the PHP binary Flow is configured with.** When the `php` in PATH is a
  different one (for us the CLI gave 8.2 while the application runs on 8.4),
  Flow aborts the command and prints the right path, e.g.
  `FLOW_CONTEXT=Production /opt/alt/php84/usr/bin/php ./flow calmfoxwatch:status`.
- **After installation you have to flush the cache**, otherwise Flow sees
  neither the new commands nor the route: `FLOW_CONTEXT=Production ./flow flow:cache:flush --force`.
- **After replacing the package files, publish the resources**: `FLOW_CONTEXT=Production ./flow resource:publish`.
  The module's stylesheet lives in `Resources/Public`, and Flow serves such files from a copy
  in `Web/_Resources`. Without publishing, the module screen comes up without colours and the
  stylesheet returns 404 (verified: right after unpacking the archive the stylesheet URL returned
  404, after `resource:publish` it returned 200). `composer require` does this for you, replacing
  the files by hand does not.
- **Expect a short window of HTTP 503 right after the flush.** Flow rebuilds its
  proxy classes and reflection data at that point; on a medium-sized site it
  took a dozen or so seconds, and requests during that time got a 503. Do it
  off-peak, and right after the flush run
  `FLOW_CONTEXT=Production ./flow flow:cache:warmup` or simply open the site, so
  that you pay for the first request, not a visitor.

Once the route is hooked up, check:

```bash
./flow calmfoxwatch:status
```

The command prints the health endpoint URL and the result of the self-check (a
request from the server to its own URL). If the self-check reports a problem,
pairing will fail too: the most common causes are a route that is not hooked up
and a firewall blocking the unusual path.

## The module in the Neos backend

The package creates its OWN module group in the backend menu, above
"Management", instead of hiding among its submodules: monitoring you have to
click through two levels to reach is only ever looked at by someone searching
for it. The group and its "Site health" item lead to the same screen, just like
in Neos's own groups.

The dashboard tile that WordPress, Sylius and Magento get does not exist in
Neos, and that is not an oversight: the Neos backend has no dashboard to hang it
on (after signing in you land straight in the content module). Instead, the menu
item is one click away from every backend screen.

The module screen shows the site health: a 0-100 ring made of five areas, three
check counters and a legend of the areas. The score is computed by Calmfox Watch
(it takes into account uptime, page reviews and performance measurements, which
this installation knows nothing about); the package only draws it - with the
same drawing as the Calmfox Watch panel and the mobile app. Where there is no
score, that is below the Start plan or before Calmfox Watch has computed it, its
place is taken by an unfilled track: it shows the shape of what a higher plan
brings and deliberately gives NO number about the state of the site.

One thing sets this ring apart from the other packages: the area colours use the
DARK variant (the same one the Calmfox Watch panel shows in its dark theme),
because the Neos backend is dark, while the WordPress, Magento and Sylius
backends are white and take the light variant. The hues sit at the same spot on
the colour wheel, so the legend matches between screens.

## Connecting to the Calmfox Watch panel

Three routes, in the same order as in the WordPress plugin, starting with the
simplest.

**1. Via the Calmfox Watch panel (recommended).** In the module you click
"Connect via Calmfox Watch". You go to the panel, sign in or create an account,
pick an organisation, and the panel sends you back to this screen and the
package pairs itself. You do not copy any keys.

How this route is secured: before you leave, the package stores a one-time token
and sends it in the URL, and on return it compares it in constant time and
deletes it REGARDLESS of the result. The panel, for its part, only returns to a
URL on the domain of the site being connected whose path contains `/neos/`.
Without both of these gates it would be enough to slip an administrator a link
with someone else's key in order to attach the site to a foreign account.

We take the return URL from the current request, not from the module name, so it
also works when the project has changed the Neos backend prefix. If the URL
cannot be determined, the button simply does not appear and the two routes below
remain.

**2. With an installation key.** Copy the `fxp_live_…` key from the Integrations
screen in the Calmfox Watch panel and paste it into the module or pass it to the
console command.

**3. From the console, without clicking** (deployments from a repository):

```bash
# a new account on the Free plan
FLOW_CONTEXT=Production ./flow calmfoxwatch:register owner@example.com

# or attach to a site that already exists in the Calmfox Watch panel
FLOW_CONTEXT=Production ./flow calmfoxwatch:pair fxp_live_0123456789abcdef
```

## Console commands

| Command | What for |
|---|---|
| `./flow calmfoxwatch:status` | connection status, health endpoint URL, self-check |
| `./flow calmfoxwatch:register <email>` | activates the Free plan for the given address |
| `./flow calmfoxwatch:pair <token>` | connects to a site that already exists in the Calmfox Watch panel |
| `./flow calmfoxwatch:disconnect` | ends the inside-the-site monitoring |
| `./flow calmfoxwatch:updates` | recalculates pending updates (for scheduled jobs) |
| `./flow calmfoxwatch:health [--section security]` | prints the payload locally |
| `./flow calmfoxwatch:rotate` | rotates the secret and re-points the monitoring |

### Scheduled job for updates

`composer outdated` goes out to the network and can take a dozen or so seconds,
so the health endpoint does NOT run it. The numbers are produced by the command
and land in the state file:

```cron
17 4 * * * cd /var/www/site && ./flow calmfoxwatch:updates >/dev/null 2>&1
```

Until somebody runs it, the package says plainly "not checked" and **omits the
`updates` field from the payload entirely**. This is not an unfinished corner:
zero means "checked, nothing to update", while a missing field means "we do not
know", and that is how the Calmfox Watch panel describes it.

## Settings

The project's `Configuration/Settings.yaml`, section `Calmfox.Watch`:

| Setting | Default | What for |
|---|---|---|
| `apiUrl` | `https://watch.calmfox.net` | API URL; the `CALMFOX_WATCH_API_URL` environment variable takes precedence |
| `healthPath` | `/calmfox-watch/health` | path of the health endpoint; must match the `uriPattern` from the routes |
| `statePath` | `%FLOW_PATH_DATA%Persistent/CalmfoxWatch/state.json` | state file |
| `smtp.host`, `smtp.port`, `smtp.encryption` | empty | explicit override of the mail configuration |
| `composerBinary` | empty | path to Composer for the `:updates` command |

### Why the state lives in a file, not in the database

Because the whole value of this monitoring shows up exactly when the database is
down. With a dead database the health endpoint has to answer `db: fail` with a
503, not go silent. If the secret were kept in a table, the site would stop
answering at the one moment it really earns its keep.

We write the file atomically (write to a temporary file, then swap), with 600
permissions. With several application instances behind a load balancer, point
`statePath` at a shared volume: they must all have the same secret, otherwise
Calmfox Watch will hit one instance, then another, and get a 403.

## Health endpoint

```
GET https://domain/calmfox-watch/health?key=<32 hex characters>
GET https://domain/calmfox-watch/health?key=…&section=security
GET https://domain/calmfox-watch/health?key=…&nonce=<one-time token>
```

- wrong or missing key: `403` and a bare `{"error":"forbidden"}`,
- `200` for `ok` and `warn`, `503` for `fail`, nothing else,
- the headers `Cache-Control: no-store, max-age=0` and `X-Robots-Tag: noindex, nofollow`,
- with `nonce`, the response is signed with the `X-Calmfox-Proof`
  and `X-Calmfox-Generated-At` headers.

The signature travels in a header, not in the body, because we compute it over
**exactly the bytes** that go out on the wire. The controller deliberately
assembles the JSON itself and bypasses the view layer, so that no renderer
stands between `json_encode` and the wire.

The limit of this protection, stated plainly: whoever has the secret from the
server can sign a lie. The signature cuts off cheap attacks (a planted static
file, a cached response, a replay from before the site was taken over); it does
not replace recovering the server.

Secret rotation: the new one works immediately, the previous one for another
15 minutes, so that a failed re-pointing on the Calmfox Watch side does not
break the monitoring.

## Checks

### The `health` section (the probe polls every 60 s, the result lives for 60 s)

| Id | What it checks |
|---|---|
| `db` | `SELECT 1` through Doctrine, with timing. No database is a `fail`, not an exception |
| `disk` | writability of `Data/Persistent` and `Web/_Resources`, and usage against the quota |
| `smtp` | the CONNECTION to the mail server (TCP, 220 greeting, EHLO). The result lives for 15 minutes |
| `flow_cache` | writing and reading a control key through `CacheManager` |
| `resources` | writability of the resource publishing directory |
| `content_repository` | whether there is an active site and a root node in the live workspace |
| `queue` | backlog in the `Flowpack.JobQueue` queues (optional) |
| `elasticsearch` | cluster status via `/_cluster/health` (optional, the result lives for 5 minutes) |

Optional checks are **skipped entirely** when the site does not have the
relevant package. We do not send an "ok" about a service that is not there.

Two things we state plainly, because anything else would be making things up:

- **`smtp` tests the connection, not delivery.** We do not send test messages.
  When mail goes out through a provider's API (SES, SendGrid, Mailgun,
  Postmark), we say so in the detail and do not pretend to have tested SMTP.
- **`disk` on shared hosting does not know the account quota.**
  `disk_free_space()` reports the whole server volume there, so we do not show
  such a number. Enter the quota in the module (or in the settings) and we will
  start watching the usage.

### The `security` section (Calmfox Watch asks once a day, the result lives for 10 minutes)

`admin_count`, `admin_login`, `flow_context`, `debug_display`, `https`,
`php_version`, `config_perms`, `dir_perms`, `encryption_key`, `dev_packages`,
`pending_updates`.

We read administrator accounts through the Flow Security repositories, not with
an SQL query, because the administrator role is sometimes **inherited** by a
customer's own role. A query by role name would miss such an account, which is
exactly what an attacker is after.

Logins never leave the site. What goes out is the number of accounts, a one-way
fingerprint of their set (an HMAC keyed with the installation secret) and the
date of the newest account. Calmfox Watch detects a CHANGE in the set, not
identities. When the accounts cannot be read (database down), the `signals`
field disappears from the payload entirely: an empty set would look like all
accounts being replaced at once and would open a site-takeover incident at a
moment when only the database went down.

## Version change history

Neos has no update hook: Composer swaps packages outside the application. So we
take a snapshot of versions from `vendor/composer/installed.php` (plus the PHP
version) and compare it every time the `security` section is built, that is at
most every 10 minutes, and when `./flow calmfoxwatch:updates` runs.

Three consequences you need to know about:

1. **`at` is the time the difference was DETECTED, not the time of the
   deployment.** A deployment at 2:00 and the first check at 7:30 produce an
   entry stamped 7:30. For the sentence "the outage started an hour after
   package X was updated" that is enough, and pretending to know a more precise
   time would be making things up.
2. **The history starts when the package is installed.** The first
   reconciliation only stores a snapshot, with no entries. Earlier changes
   cannot be reconstructed.
3. **`mode` is always `manual`, `by` is always empty.** Composer does not tell
   us who ran the deployment or with what, so we do not guess the author.

`neos/neos` and PHP (the platform) get `kind: core`, everything else gets
`kind: plugin`. Buffer: 200 entries. Package names WITH VERSIONS travel **only**
in the history, because a list of "what, and in which version" is a ready-made
map of holes for an attacker; in the `health` section, next to the numbers,
only the SET of installed Flow and Neos packages travels
(`signals.activePlugins`, without versions and without libraries). Without the
names, an event about a package disappearing would read "something changed",
and you cannot react to that. We do not send the `signals.autoUpdates` field at
all: Neos does not update itself, and a value in that field would mean
"checked".

## Custom checks

The counterpart of the `calmfox_watch_health_checks` filter from the WordPress
plugin. In Flow the natural way is to collect implementations of an interface
through `ReflectionService` (this happens at compile time, so it costs nothing
in production):

```php
<?php
namespace Your\Package\Monitoring;

use Calmfox\Watch\Health\HealthCheckInterface;
use Neos\Flow\Annotations as Flow;

#[Flow\Scope('singleton')]
class BrokerCheck implements HealthCheckInterface
{
    public function run(): ?array
    {
        $socket = @fsockopen('127.0.0.1', 5672, $number, $text, 2);
        if (!is_resource($socket)) {
            return ['id' => 'rabbitmq', 'status' => 'fail', 'label' => 'RabbitMQ queue',
                    'detail' => 'The broker is not accepting connections.'];
        }
        fclose($socket);

        return ['id' => 'rabbitmq', 'status' => 'ok', 'label' => 'RabbitMQ queue'];
    }
}
```

After adding the class: `./flow flow:cache:flush`. Our checks run first in a
fixed order, third-party ones follow alphabetically by class name.

The class must not be `final`: Flow wraps injected objects in a proxy class
through inheritance, and a final class cannot be extended.

Two rules for custom checks:

1. **Return `null` when the service does not exist on this installation.** We do
   not send an "ok" about something that is not there.
2. **Keep a short, hard timeout.** The result goes into the response of an
   endpoint polled every minute.

Ids outside the contract's catalogue are shown by the Calmfox Watch panel with
the label from the payload and the note "service added by a custom extension".

## Privacy

What goes to Calmfox: the site domain, the e-mail address given when the account
was created, and the diagnostic data described above (service statuses,
versions, numbers of pending updates, version change history, the number of
administrator accounts and the fingerprint of their set, the set of installed
Flow and Neos packages together with its fingerprint). What does not: content,
user data, logins, passwords. Without the key the health endpoint answers 403.

## Disconnecting

`./flow calmfoxwatch:disconnect` (or the button in the module) tells Calmfox
Watch outright that we are done. We do this deliberately instead of leaving
Calmfox Watch with a dead URL: it treats the package's silence as a signal and
opens an incident after three failed polls. Silencing the monitoring is often
the first step after a backend takeover, so the customer should know about it.

Disconnecting requires the secret from the health endpoint URL, not just the
installation key: the key is not secret.

## Payload samples

`Documentation/sample-health.json` and `Documentation/sample-security.json` are
the output of our builder (`Documentation/generate-samples.php`), not
hand-written files. Calmfox Watch bases a contract test on them, and a file
written from memory would go stale with the first change to the payload shape.

After changing the payload: `php Documentation/generate-samples.php`.

## Tests

The package core (`Classes/Core`) deliberately has no dependency on Flow at all:
normalisation and aggregation of checks, response signing, secret rotation,
version snapshot diffing, the account fingerprint, payload building. Thanks to
that the tests run without bootstrapping the framework, without a database and
without the network:

The tests ship their own autoloader (`tests/bootstrap.php`), so any PHPUnit 10 or
later will do, with no `composer install`:

```bash
phpunit -c phpunit.xml.dist
```

Checks that depend on Flow (`Classes/Health`, `Classes/Security`) are tested on
a live installation with `./flow calmfoxwatch:health`.

## Package version

A single source of truth: the `Calmfox\Watch\Core\Version::NUMBER` constant.
`composer.json` deliberately has NO `version` field (Composer derives it from
repository tags, and a hand-written field always drifts sooner or later). The
script that builds the `calmfox-watch-neos.zip` archive reads the same constant.

## Licence

GPL-3.0-or-later, see [LICENSE](LICENSE).
