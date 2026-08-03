<?php
declare(strict_types=1);

/**
 * The public rssCloud callback, served through `p/api/misc.php/rssCloud/`.
 *
 * It answers the two verbs a subscriber has to implement:
 *
 * * `GET  …?url=<resource>&challenge=<token>` — the registration handshake. Echo the challenge
 *   verbatim with a 2xx status, otherwise the cloud server cancels the subscription.
 * * `POST url=<resource>` — the change notification. Unlike WebSub this carries no payload, so the
 *   resource is simply refreshed through the normal code path.
 *
 * This runs unauthenticated and with no user initialised, so — exactly like `p/api/pshb.php` — it
 * walks the per-resource subscriber markers and initialises each user in turn.
 *
 * @phpstan-import-type RssCloudState from RssCloud_Registry
 */
final class RssCloud_Callback {

	public function __construct(
		private readonly RssCloud_Registry $registry,
		private readonly string $token,
		private readonly int $cooldownSeconds,
	) {
	}

	public function handle(): void {
		header('Content-Type: text/plain; charset=UTF-8');
		header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
		header('X-Content-Type-Options: nosniff');

		if (!$this->checkToken()) {
			Minz_Log::warning('rssCloud: rejected callback with bad token', RSSCLOUD_LOG);
			$this->fail(404, 'Not Found!');
		}

		$url = $this->resourceUrl();
		if ($url === '') {
			$this->fail(422, 'Missing url parameter!');
		}

		$state = $this->registry->load($url);
		if ($state === null) {
			// Never disclose whether the URL merely is not subscribed or does not exist.
			Minz_Log::warning('rssCloud: callback for unknown resource: ' . $url, RSSCLOUD_LOG);
			$this->fail(404, 'Not Found!');
		}

		$challenge = is_string($_GET['challenge'] ?? null) ? $_GET['challenge'] : '';
		if ($challenge !== '') {
			$this->respondToChallenge($state, $challenge);
		}

		$this->respondToNotification($state);
	}

	/**
	 * The registration handshake. The cloud server performs this synchronously while answering our
	 * `pleaseNotify`, so it must not do any real work.
	 *
	 * @param RssCloudState $state
	 */
	private function respondToChallenge(array $state, string $challenge): never {
		Minz_Log::notice('rssCloud: handshake for ' . $state['url'], RSSCLOUD_LOG);
		header('Connection: close');
		exit($challenge);
	}

	/**
	 * @param RssCloudState $state
	 */
	private function respondToNotification(array $state): never {
		$subscribers = $this->registry->subscribers($state['url']);
		if ($subscribers === []) {
			Minz_Log::warning('rssCloud: nobody subscribes to ' . $state['url'] . ' anymore', RSSCLOUD_LOG);
			$this->registry->forget($state['url']);
			$this->fail(410, 'Gone!');
		}

		// A notification is an unauthenticated trigger for real work, so rate-limit it. Still answer
		// 2xx: a non-2xx reply makes some cloud servers drop the subscription.
		if ($state['last_notify'] > time() - $this->cooldownSeconds) {
			Minz_Log::debug('rssCloud: notification within cooldown, ignored: ' . $state['url'], RSSCLOUD_LOG);
			exit("Ignored (cooldown)\n");
		}

		$state['last_notify'] = time();
		$state['error'] = false;
		$state['error_message'] = '';
		$this->registry->save($state['url'], $state);

		$refreshed = 0;
		foreach ($subscribers as $username) {
			try {
				FreshRSS_Context::initUser($username);
				if (!FreshRSS_Context::hasUserConf() || !FreshRSS_Context::userConf()->enabled) {
					Minz_Log::warning('rssCloud: skipping disabled user ' . $username, RSSCLOUD_LOG);
					continue;
				}
				Minz_ExtensionManager::enableByList(FreshRSS_Context::userConf()->extensions_enabled, 'user');
				Minz_Translate::reset(FreshRSS_Context::userConf()->language);

				$done = $state['kind'] === RssCloud_Registry::KIND_OPML
					? $this->refreshOpml($state['url'])
					: $this->refreshFeed($state['url']);

				if ($done) {
					$refreshed++;
				} else {
					Minz_Log::warning("rssCloud: user {$username} no longer subscribes to {$state['url']}", RSSCLOUD_LOG);
					$this->registry->removeSubscriber($state['url'], $username);
				}
			} catch (Throwable $e) {
				Minz_Log::error("rssCloud: {$e->getMessage()} for user {$username} and {$state['url']}", RSSCLOUD_LOG);
			}
		}

		if ($refreshed === 0) {
			$this->registry->forget($state['url']);
			$this->fail(410, 'Gone!');
		}

		Minz_Log::notice("rssCloud: notified {$state['url']}, refreshed for {$refreshed} user(s)", RSSCLOUD_LOG);
		exit("Done: {$refreshed}\n");
	}

	private function refreshFeed(string $url): bool {
		[$nbUpdatedFeeds, ] = FreshRSS_feed_Controller::actualizeFeedsAndCommit(feed_url: $url);
		return $nbUpdatedFeeds > 0;
	}

	/**
	 * Refresh every dynamic-OPML category of the current user that is driven by this OPML URL.
	 *
	 * `FreshRSS_Category::refreshDynamicOpml()` re-imports the list, and mutes feeds that have
	 * disappeared from it — a considerably heavier and more destructive operation than a feed poll.
	 */
	private function refreshOpml(string $url): bool {
		$categoryDAO = FreshRSS_Factory::createCategoryDao();
		$done = false;
		foreach ($categoryDAO->listCategories(prePopulateFeeds: true, details: true) as $category) {
			if ($category->attributeString('opml_url') === $url) {
				$done = $category->refreshDynamicOpml() || $done;
			}
		}
		return $done;
	}

	/**
	 * The resource URL is carried in the query string for the handshake and in the body for a
	 * notification, so accept it from either.
	 */
	private function resourceUrl(): string {
		$url = $_GET['url'] ?? $_POST['url'] ?? '';
		if (!is_string($url)) {
			return '';
		}
		return FreshRSS_http_Util::checkUrl(trim($url)) ?: '';
	}

	/**
	 * Verify the optional shared token that {@see RssCloud_CallbackUrl} appends as a trailing path
	 * segment, e.g. `/api/misc.php/rssCloud/<token>/`.
	 */
	private function checkToken(): bool {
		if ($this->token === '') {
			return true;
		}
		$pathInfo = $_SERVER['PATH_INFO'] ?? $_SERVER['ORIG_PATH_INFO'] ?? '';
		if (!is_string($pathInfo)) {
			return false;
		}
		// Mirror the normalisation p/api/misc.php performs before extracting the extension name.
		$pathInfo = (string)preg_replace('%^(/api)?(/misc\.php)?%', '', rawurldecode($pathInfo));
		$segments = array_values(array_filter(explode('/', $pathInfo), static fn(string $s): bool => $s !== ''));
		$candidate = $segments[1] ?? '';
		return hash_equals($this->token, $candidate);
	}

	private function fail(int $status, string $message): never {
		http_response_code($status);
		exit($message . "\n");
	}
}
