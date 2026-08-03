# rssCloud for FreshRSS

Real-time updates over [rssCloud](https://rpc.rsscloud.io/docs), for both **feeds** and **dynamic
OPML subscription lists**.

Where WebSub pushes the changed document to the subscriber, an rssCloud notification carries nothing
but `url=<resource>`. That makes the protocol content-agnostic, which is what lets this extension
cover dynamic OPML — something core's WebSub support cannot do, because `p/api/pshb.php` parses every
push as a feed.

This runs alongside core's WebSub support and shares nothing with it. A feed advertising both a hub
and a cloud will be subscribed through both.

## Discovery

| Resource | Advertisement | Read from |
| --- | --- | --- |
| Feed | `<source:cloud>https://rpc.rsscloud.io/pleaseNotify</source:cloud>` | `<channel>`, namespace `https://source.scripting.com/` |
| Feed | `<cloud domain="…" port="…" path="…" registerProcedure="" protocol="…"/>` | `<channel>`, RSS 2.0 (no namespace) |
| Dynamic OPML | `<source:cloud>…</source:cloud>` | `<head>` |

`<source:cloud>` wins when both are present, since it carries an absolute URL and needs no guessing.

The RSS 2.0 `<cloud>` element predates ubiquitous TLS and has no scheme attribute, so the scheme is
inferred: **`protocol="https-post"` or `port="443"` means HTTPS**, anything else means HTTP.

SimplePie has no accessor for either element, so both are read via `get_channel_tags()`. OPML is
parsed with SimpleXML rather than LibOpml, which discards namespaced elements in `<head>`.

## Callback

The public endpoint rides on `p/api/misc.php`, the entry point core provides so extensions can own
an unauthenticated URL:

```text
https://rss.example.net/p/api/misc.php/rssCloud/<token>/
```

The `PATH_INFO` form is used rather than `?ext=rssCloud`, because rssCloud appends its own
`?url=…&challenge=…` and a path that already carries a query string breaks that concatenation on
some servers.

It answers both protocol verbs:

* `GET …?url=<resource>&challenge=<token>` — the registration handshake, performed synchronously by
  the cloud server while answering `pleaseNotify`. The challenge is echoed verbatim.
* `POST url=<resource>` — the change notification. Feeds go through
  `FreshRSS_feed_Controller::actualizeFeedsAndCommit()`; OPML lists go through
  `FreshRSS_Category::refreshDynamicOpml()`.

Because `misc.php` initialises no user, the callback walks per-resource subscriber markers and
initialises each user in turn — exactly like `p/api/pshb.php` does.

### Security

rssCloud has no equivalent of WebSub's `hub.secret`, so the callback is an unauthenticated "go and
fetch this URL" trigger. Three things bound that:

1. Only URLs already present in the registry are accepted.
2. A shared token in the path (generated at install, regenerable from the configuration screen).
3. A per-resource cooldown, default 60s.

The cooldown matters more than it does for WebSub: `refreshDynamicOpml()` re-imports the whole list
and **mutes feeds that have disappeared from it**, which is far heavier and more destructive than a
feed poll.

## Storage

Deliberately mirrors core's WebSub layout under `PSHB_PATH`:

```text
data/rssCloud/resources/<sha1(resourceUrl)>/!cloud.json     subscription state
data/rssCloud/resources/<sha1(resourceUrl)>/<username>.txt  one marker per interested user
data/rssCloud/redirects/<sha1(url)>.json                    where a URL permanently moved to
```

Subscriptions are instance-wide (the cloud server knows one callback), but resources are per-user,
so the markers act as a reference count. There is no per-subscription key, because rssCloud
registers one fixed path and identifies the resource by the `url` parameter.

Feeds additionally carry an `rssCloud` attribute holding the resource URL they are keyed under. This
is what makes the polling decision possible *before* a feed is fetched — `FreshRSS_Feed::selfUrl()`
is only populated during the SimplePie parse and is not persisted.

Redirect resolutions are cached one file per URL, so that concurrent notifications cannot lose each
other's writes. An answer is trusted for 30 days; a resolution that *failed* — as opposed to one that
found no move — is retried after 6 hours.

Logs go to `data/users/_/log_rsscloud.txt`.

## Hooks used

| Hook | Purpose |
| --- | --- |
| `ApiMisc` | serve the callback |
| `SimplepieAfterInit` | discover a feed's cloud, subscribe, persist the resource attribute; pin dynamic OPML feed URLs |
| `FeedsListBeforeActualize` | renew feed subscriptions (capped per cycle) |
| `FeedBeforeActualize` | skip polling a feed with a healthy subscription |
| `FreshrssUserMaintenance` | discover and renew dynamic OPML subscriptions |
| `FeedBeforeInsert` | reconcile a redirected feed against one already subscribed |

Renewal has to happen in `FeedsListBeforeActualize` rather than at discovery time, because a feed
whose polling is skipped never reaches `SimplepieAfterInit` and would otherwise never renew.

### Redirected feeds in dynamic OPML categories

FreshRSS and OPML disagree about what identifies a feed:

* `FreshRSS_Feed::load()` treats a feed as the document it resolves to, and rewrites the stored URL
  when the feed answers HTTP 301.
* `FreshRSS_Category::refreshDynamicOpml()` treats a feed as the exact `xmlUrl` string in the list,
  which does not change.

One 301 is enough to make them disagree forever. Every later refresh then reads the entry as new and
inserts it again, and mutes the drifted copy for having disappeared from the list. Nothing catches
the collision: `_feed.url` has no unique index, and `FeedDAO::updateFeed()` does not check for one.
Copies accumulate at one per refresh — unbounded here, because rssCloud refreshes on notification
rather than on a timer.

`FeedBeforeInsert` settles it at the import step, before core does its matching. A feed whose URL is
not already subscribed is resolved to wherever it permanently moved; if *that* is a feed we hold, the
import is addressed to it instead. Core then recognises it as existing, so it is neither inserted nor
muted, and `FeedDAO::addFeedObject()` unmutes it if an earlier refresh muted it.

Only 301 and 308 are followed. A temporary redirect deliberately says the resource has *not* moved,
so following it would merge two feeds the publisher considers distinct. A feed that moved onto
nothing we hold is left exactly as the list gives it, and core canonicalises it on first fetch as
usual.

The hook fires on every import path, so this covers refreshes driven by cron or the CLI as well as by
a notification, and keeps a manual subscription from duplicating a feed already held under its
post-redirect URL.

## Known gaps

These are deliberate scaffold-level limitations, not oversights:

* **Lease duration is guessed.** The protocol does not negotiate one and the documentation does not
  state one. Default is to renew after 23h, matching the policy core applies to WebSub leases. Adjust
  in the configuration screen if your cloud server expires sooner.
* **Targeted-refresh detection is a heuristic.** `FeedBeforeActualize` cannot see whether the caller
  asked for one feed or the whole instance (core's own WebSub check reads `$feed_id` directly at
  `feedController.php:505`, which the hook has no access to), so batch size is used as a proxy.
  Consequence: a one-feed instance always polls. The staleness bound is the backstop.
* **No unsubscribe.** rssCloud REST has no unsubscribe verb; subscriptions expire by not being
  renewed. Deleting a feed leaves a stale marker until the next notification finds no subscriber and
  self-heals.
* **REST only.** `xml-rpc` and `soap` clouds are discovered and ignored.
* **OPML discovery is one cycle behind.** It reads the OPML from the cache file that
  `refreshDynamicOpml()` writes rather than fetching again, so a newly added list is subscribed on
  the following maintenance pass.
* **`registerProcedure` is passed through but unused**, as it is meaningless for `http-post`.

## Setup

1. Copy this directory into `extensions/`.
2. Enable it in *Administration → Extensions*. It is a **system** extension, so it applies to all
   users, and `p/api/misc.php` will only route to it if it is enabled system-wide.
3. Ensure the API is enabled (`api_enabled`) — `misc.php` returns 503 otherwise.
4. Check the callback URL shown on the configuration screen is publicly reachable.

## Testing

```sh
# The handshake, as a cloud server would perform it
curl -i 'https://rss.example.net/p/api/misc.php/rssCloud/<token>/?url=<feed>&challenge=abc123'
# expect: 200, body exactly `abc123`

# A notification
curl -i -d 'url=<feed>' 'https://rss.example.net/p/api/misc.php/rssCloud/<token>/'
# expect: 200, body `Done: N`

tail -f data/users/_/log_rsscloud.txt
```

## Development

This extension has no dependencies of its own. Its checks run against FreshRSS core, because every
class it touches lives there — so clone it into a FreshRSS checkout at
`FreshRSS/extensions/xExtension-RssCloud`, then, from the FreshRSS root:

```sh
composer install

# phpstan, using core's ruleset scoped to this extension
( cd extensions/xExtension-RssCloud && ../../vendor/bin/phpstan analyse -c phpstan.neon )

# phpcs — core's ruleset excludes extensions/, so drop that one line
sed '/(?-i:extensions)/d' phpcs.xml > phpcs-extensions.xml
vendor/bin/phpcs --standard=phpcs-extensions.xml extensions/xExtension-RssCloud -s
```

CI runs exactly these against FreshRSS `edge` on every push and pull request.

### Commits

Releases are automated with [Release Please](https://github.com/googleapis/release-please), which
derives the version and CHANGELOG from
[Conventional Commit](https://www.conventionalcommits.org/) subjects on `main`. Merging its release
PR bumps both `version.txt` and the `version` field in `metadata.json`, tags, and attaches an
installable zip to the GitHub release.

A subject that does not conform is not an error anywhere — it is just silently dropped from the next
release. Enable the hook that catches it, once per clone:

```sh
git config core.hooksPath .githooks
```

## License

[AGPL-3.0](LICENSE), matching FreshRSS itself.
