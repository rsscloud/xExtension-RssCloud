<?php
declare(strict_types=1);

defined('RSSCLOUD_PATH') or define('RSSCLOUD_PATH', DATA_PATH . '/rssCloud');
defined('RSSCLOUD_LOG') or define('RSSCLOUD_LOG', USERS_PATH . '/_/log_rsscloud.txt');

/**
 * Real-time updates over rssCloud.
 *
 * Where WebSub pushes the changed document to the subscriber, an rssCloud notification carries
 * nothing but `url=<resource>`. That makes the protocol content-agnostic, so this extension covers
 * both of the things FreshRSS polls on a timer:
 *
 * * **feeds**, advertising either the RSS 2.0 `<cloud>` element or `<source:cloud>`;
 * * **dynamic OPML** subscription lists, advertising `<source:cloud>` in their `<head>`.
 *
 * Core's WebSub support is the model for the moving parts here — see `p/api/pshb.php` and
 * `FreshRSS_Feed::pubSubHubbub*()` — but nothing is shared with it, and the two can run together.
 *
 * @phpstan-import-type RssCloudState from RssCloud_Registry
 */
final class RssCloudExtension extends Minz_Extension {

	/** Must match the `name` in metadata.json: it is the path segment used by `p/api/misc.php`. */
	public const NAME = 'rssCloud';

	/**
	 * rssCloud has no lease negotiation, and the protocol documentation does not state an expiry.
	 * The convention among implementations is around 24 hours, so renew slightly early — the same
	 * policy core applies to WebSub leases in `FreshRSS_Feed::pubSubHubbubPrepare()`.
	 */
	public const DEFAULT_RENEW_HOURS = 23;

	/** How stale a cloud-covered resource may get before we poll it anyway, as a safety net. */
	public const DEFAULT_MAX_STALENESS_HOURS = 24;

	/** Minimum delay between two honoured notifications for the same resource. */
	public const DEFAULT_COOLDOWN_SECONDS = 60;

	/** Upper bound on `pleaseNotify` calls issued in a single refresh cycle, so cron cannot stall. */
	public const MAX_SUBSCRIPTIONS_PER_RUN = 10;

	/** Feed attribute holding the resource URL this feed is (or should be) subscribed under. */
	private const FEED_ATTRIBUTE = 'rssCloud';

	private ?RssCloud_Registry $registry = null;
	private ?RssCloud_Redirects $redirects = null;
	private ?RssCloud_Subscriber $subscriber = null;

	/** Whether {@see self::subscriber()} has run, so a failure is diagnosed and logged only once. */
	private bool $subscriberResolved = false;

	/**
	 * Number of feeds in the batch currently being actualised. Used to tell a whole-instance refresh
	 * apart from a targeted one, which must never be skipped.
	 */
	private int $batchSize = 0;

	/** Registered by Minz_ExtensionManager before {@see self::init()} runs. */
	public function autoload(string $class): void {
		if (str_starts_with($class, 'RssCloud_')) {
			$file = $this->getPath() . '/RssCloud/' . substr($class, strlen('RssCloud_')) . '.php';
			if (is_file($file)) {
				include_once $file;
			}
		}
	}

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerTranslates();

		// The public callback. Always registered: without it, subscriptions cannot be verified.
		$this->registerHook(Minz_HookType::ApiMisc, [$this, 'handleApiMisc']);

		if ($this->isEnabledForFeeds()) {
			$this->registerHook(Minz_HookType::SimplepieAfterInit, [$this, 'onSimplepieAfterInit']);
			$this->registerHook(Minz_HookType::FeedsListBeforeActualize, [$this, 'onFeedsListBeforeActualize']);
			$this->registerHook(Minz_HookType::FeedBeforeActualize, [$this, 'onFeedBeforeActualize']);
		}
		if ($this->isEnabledForOpml()) {
			$this->registerHook(Minz_HookType::FreshrssUserMaintenance, [$this, 'onUserMaintenance']);
			$this->registerHook(Minz_HookType::FeedBeforeInsert, [$this, 'onFeedBeforeInsert']);
		}
	}

	#[\Override]
	public function install() {
		// Note: install() runs *before* Minz_ExtensionManager enables the extension, so the
		// autoloader registered above is not active yet. Nothing here may touch an RssCloud_* class.
		$resources = RSSCLOUD_PATH . '/resources';
		$redirects = RSSCLOUD_PATH . '/redirects';
		foreach ([$resources, $redirects] as $directory) {
			if (!@is_dir($directory) && !@mkdir($directory, 0770, true)) {
				return 'Cannot create ' . $directory;
			}
		}
		// `data/.htaccess` denies all, but add the same directory-listing guards core ships under
		// `data/PubSubHubbub/` for servers that do not read .htaccess.
		foreach ([RSSCLOUD_PATH, $resources, $redirects] as $directory) {
			if (!file_exists($directory . '/index.html')) {
				@file_put_contents($directory . '/index.html', '');
			}
		}
		if ($this->getSystemConfigurationString('token') === null) {
			$this->setSystemConfigurationValue('token', bin2hex(random_bytes(16)));
		}
		return true;
	}

	#[\Override]
	public function uninstall() {
		// Subscriptions are left on disk: rssCloud has no unsubscribe verb, so they simply expire.
		// Re-enabling the extension within the lease window then resumes without re-registering.
		return true;
	}

	// <configuration>

	public function isEnabledForFeeds(): bool {
		return $this->getSystemConfigurationBool('feeds_enabled') ?? true;
	}

	public function isEnabledForOpml(): bool {
		return $this->getSystemConfigurationBool('opml_enabled') ?? true;
	}

	public function isPollingSkipped(): bool {
		return $this->getSystemConfigurationBool('skip_polling') ?? true;
	}

	public function renewSeconds(): int {
		return max(1, $this->getSystemConfigurationInt('renew_hours') ?? self::DEFAULT_RENEW_HOURS) * 3600;
	}

	public function maxStalenessSeconds(): int {
		return max(1, $this->getSystemConfigurationInt('max_staleness_hours') ?? self::DEFAULT_MAX_STALENESS_HOURS) * 3600;
	}

	public function cooldownSeconds(): int {
		return max(0, $this->getSystemConfigurationInt('cooldown_seconds') ?? self::DEFAULT_COOLDOWN_SECONDS);
	}

	public function token(): string {
		return $this->getSystemConfigurationString('token') ?? '';
	}

	/** Public base URL to advertise, defaulting to the instance one. Useful behind a reverse proxy. */
	public function baseUrl(): string {
		$override = $this->getSystemConfigurationString('base_url') ?? '';
		return $override !== '' ? $override : Minz_Request::getBaseUrl();
	}

	public function callbackUrl(): ?RssCloud_CallbackUrl {
		return RssCloud_CallbackUrl::fromBaseUrl($this->baseUrl(), $this->token());
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->registerTranslates();

		if (!Minz_Request::isPost()) {
			return;
		}
		$this->setSystemConfigurationValue('feeds_enabled', Minz_Request::paramBoolean('feeds_enabled'));
		$this->setSystemConfigurationValue('opml_enabled', Minz_Request::paramBoolean('opml_enabled'));
		$this->setSystemConfigurationValue('skip_polling', Minz_Request::paramBoolean('skip_polling'));
		$this->setSystemConfigurationValue('renew_hours', Minz_Request::paramInt('renew_hours') ?: self::DEFAULT_RENEW_HOURS);
		$this->setSystemConfigurationValue('cooldown_seconds', Minz_Request::paramInt('cooldown_seconds'));
		$this->setSystemConfigurationValue('base_url', Minz_Request::paramString('base_url', plaintext: true));
		if (Minz_Request::paramBoolean('regenerate_token')) {
			$this->setSystemConfigurationValue('token', bin2hex(random_bytes(16)));
		}
	}

	// </configuration>

	private function registry(): RssCloud_Registry {
		return $this->registry ??= new RssCloud_Registry(RSSCLOUD_PATH);
	}

	private function redirects(): RssCloud_Redirects {
		return $this->redirects ??= new RssCloud_Redirects(RSSCLOUD_PATH);
	}

	private function subscriber(): ?RssCloud_Subscriber {
		if ($this->subscriberResolved) {
			return $this->subscriber;
		}
		$this->subscriberResolved = true;

		$callback = $this->callbackUrl();
		if ($callback === null) {
			Minz_Log::warning('rssCloud: cannot derive a callback URL from ' . $this->baseUrl(), RSSCLOUD_LOG);
			return null;
		}
		// A cloud server cannot reach a callback on a private network, and would cancel the
		// subscription during the handshake. Fail fast and loudly instead.
		if (!Minz_Request::serverIsPublic($callback->url())) {
			Minz_Log::warning('rssCloud: callback ' . $callback->url() . ' does not look publicly reachable', RSSCLOUD_LOG);
			return null;
		}
		return $this->subscriber = new RssCloud_Subscriber($this->registry(), $callback, $this->renewSeconds());
	}

	// <hooks>

	/** Entry point for `p/api/misc.php/rssCloud/…`. */
	public function handleApiMisc(): void {
		$callback = new RssCloud_Callback($this->registry(), $this->token(), $this->cooldownSeconds());
		$callback->handle();
	}

	/**
	 * Discover the cloud endpoint of a feed that has just been parsed, and subscribe if needed.
	 *
	 * @param bool $result whether SimplePie parsed the feed successfully
	 */
	public function onSimplepieAfterInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed, bool $result): void {
		if (!$result) {
			return;
		}
		$endpoint = RssCloud_Discovery::fromSimplePie($simplePie);
		$resource = $feed->selfUrl() ?: $feed->url();

		if ($endpoint === null) {
			$feed->_attribute(self::FEED_ATTRIBUTE, null);
			return;
		}

		// Persisted with the feed by FreshRSS_feed_Controller::actualizeFeeds(), so that the polling
		// decision below can be made before the feed is fetched on later cycles.
		$feed->_attribute(self::FEED_ATTRIBUTE, ['resource' => $resource]);

		$this->ensureSubscribed($resource, $endpoint, RssCloud_Registry::KIND_FEED);
	}

	/**
	 * Renew subscriptions for the feeds in this batch.
	 *
	 * This has to happen at the list level rather than per feed, because a feed whose polling is
	 * skipped never reaches {@see self::onSimplepieAfterInit()} and would otherwise never renew.
	 *
	 * @param array<FreshRSS_Feed> $feeds
	 * @return array<FreshRSS_Feed> the list, unmodified
	 */
	public function onFeedsListBeforeActualize(array $feeds): array {
		$this->batchSize = count($feeds);

		$subscriber = $this->subscriber();
		if ($subscriber === null) {
			return $feeds;
		}

		$issued = 0;
		foreach ($feeds as $feed) {
			if ($issued >= self::MAX_SUBSCRIPTIONS_PER_RUN) {
				break;
			}
			$resource = $this->resourceUrlOf($feed);
			if ($resource === null) {
				continue;
			}
			$state = $this->registry()->load($resource);
			if ($state !== null && $subscriber->needsRenewal($state)) {
				$subscriber->subscribe($state);
				$issued++;
			}
		}

		return $feeds;
	}

	/**
	 * Skip polling a feed that a cloud server is actively notifying us about.
	 *
	 * Returning `null` drops the feed from this cycle entirely, so this is deliberately
	 * conservative: it only applies to whole-instance batches, and never lets a feed go unpolled
	 * for longer than the configured staleness bound.
	 *
	 * Known limitation: the hook cannot see whether the caller targeted a single feed, so batch size
	 * is used as a proxy. A one-feed instance therefore always polls.
	 */
	public function onFeedBeforeActualize(FreshRSS_Feed $feed): ?FreshRSS_Feed {
		if (!$this->isPollingSkipped() || $this->batchSize <= 1) {
			return $feed;
		}
		$resource = $this->resourceUrlOf($feed);
		if ($resource === null) {
			return $feed;
		}
		$state = $this->registry()->load($resource);
		if ($state === null || !RssCloud_Subscriber::isHealthy($state, $this->renewSeconds())) {
			return $feed;
		}
		if ($feed->lastUpdate() <= time() - $this->maxStalenessSeconds()) {
			return $feed;
		}
		return null;
	}

	/**
	 * Discover and renew cloud endpoints for the current user's dynamic OPML categories.
	 *
	 * There is no hook inside `FreshRSS_Category::refreshDynamicOpml()`, so the OPML is read from
	 * the cache file that the refresh itself writes, rather than being fetched a second time.
	 */
	public function onUserMaintenance(): void {
		$subscriber = $this->subscriber();
		if ($subscriber === null) {
			return;
		}

		$categoryDAO = FreshRSS_Factory::createCategoryDao();
		foreach ($categoryDAO->listCategories(prePopulateFeeds: false, details: false) as $category) {
			$opmlUrl = $category->attributeString('opml_url');
			if ($opmlUrl === null || $opmlUrl === '') {
				continue;
			}

			$cached = @file_get_contents($category->cacheFilename($opmlUrl));
			if (!is_string($cached) || $cached === '') {
				// Not fetched yet; the next refresh will populate the cache and we will pick it up.
				continue;
			}

			$endpoint = RssCloud_Discovery::fromOpml($cached);
			if ($endpoint === null) {
				continue;
			}
			$this->ensureSubscribed($opmlUrl, $endpoint, RssCloud_Registry::KIND_OPML);
		}
	}

	/**
	 * Address a feed being imported by the URL it has permanently moved to, when that is a feed we
	 * already hold. Otherwise leave it exactly as the list gives it.
	 *
	 * This is what stops a dynamic OPML category accumulating duplicates. The two sides disagree
	 * about what identifies a feed:
	 *
	 * * `FreshRSS_Feed::load()` rewrites a feed's stored URL to wherever it moved on HTTP 301.
	 * * `FreshRSS_Category::refreshDynamicOpml()` matches what it holds against the OPML by exact URL.
	 *
	 * So one 301 is enough to make every later refresh read the entry as new and insert it again,
	 * then mute the drifted copy for having vanished from the list. Nothing catches the collision:
	 * `_feed.url` carries no unique index, and `FeedDAO::updateFeed()` does not check for one. The
	 * copies therefore accumulate at one per refresh — unbounded under rssCloud, where refreshes
	 * follow notifications rather than a timer.
	 *
	 * Resolving the redirect here settles the disagreement in core's favour, at the import step and
	 * before core does its matching: the feed is recognised as one we already hold, so it is neither
	 * inserted nor muted, and `FeedDAO::addFeedObject()` unmutes it if an earlier refresh muted it.
	 *
	 * The hook fires on every import path, so this also covers refreshes driven by cron or the CLI
	 * rather than by a notification, and keeps a manual subscription from duplicating a feed already
	 * held under its post-redirect URL.
	 *
	 * The feed is always returned: the hook can cancel an import by returning null, but a URL this
	 * extension failed to reconcile is not a reason to drop a subscription the list asked for.
	 */
	public function onFeedBeforeInsert(FreshRSS_Feed $feed): FreshRSS_Feed {
		$url = $feed->url();
		$feedDAO = FreshRSS_Factory::createFeedDao();
		if ($url === '' || $feedDAO->searchByUrl($url) !== null) {
			// Already held under this exact URL, so there is nothing to reconcile — and no reason to
			// spend a request finding that out.
			return $feed;
		}

		$target = $this->redirects()->resolve($url, $feed->attributes());
		if ($target === $url) {
			return $feed;
		}
		if ($feedDAO->searchByUrl($target) === null) {
			// It moved, but not onto anything we hold. Genuinely new, so let core add it under the
			// URL the list gives and rewrite that itself on the first fetch.
			return $feed;
		}

		try {
			$feed->_url($target);
		} catch (FreshRSS_BadUrl_Exception $e) {
			Minz_Log::warning('rssCloud: ' . $e->getMessage(), RSSCLOUD_LOG);
			return $feed;
		}
		Minz_Log::notice('rssCloud: ' . \SimplePie\Misc::url_remove_credentials($url) .
			' permanently moved to a feed already subscribed as ' . $target . ', reusing it', RSSCLOUD_LOG);
		return $feed;
	}

	// </hooks>

	/**
	 * Record interest in a resource and issue a `pleaseNotify` if the subscription is new, expiring
	 * or in error.
	 *
	 * @param RssCloud_Registry::KIND_* $kind
	 */
	private function ensureSubscribed(string $resource, RssCloud_Endpoint $endpoint, string $kind): void {
		$username = Minz_User::name() ?? '';
		if (!FreshRSS_user_Controller::checkUsername($username)) {
			return;
		}

		$this->registry()->init();
		$state = $this->registry()->remember($resource, $endpoint, $kind);
		$this->registry()->addSubscriber($resource, $username);

		$subscriber = $this->subscriber();
		if ($subscriber !== null && $subscriber->needsRenewal($state)) {
			$subscriber->subscribe($state);
		}
	}

	/** The URL a feed's subscription is keyed under, or null if it advertises no cloud. */
	private function resourceUrlOf(FreshRSS_Feed $feed): ?string {
		$attribute = $feed->attributeArray(self::FEED_ATTRIBUTE);
		$resource = is_string($attribute['resource'] ?? null) ? $attribute['resource'] : '';
		return $resource !== '' ? $resource : null;
	}
}
