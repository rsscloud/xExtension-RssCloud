<?php
declare(strict_types=1);

/**
 * Where a URL has permanently moved to, cached on disk.
 *
 * FreshRSS identifies a feed by its URL, and rewrites that URL when the feed answers HTTP 301
 * (`FreshRSS_Feed::load()`). A dynamic OPML identifies the same feed by the `xmlUrl` its publisher
 * wrote down, which does not change. Reconciling the two needs an answer to "where does this URL
 * end up?", which only the network can give.
 *
 * The answer is stable almost by definition — that is what makes a redirect permanent — so it is
 * cached, one file per URL:
 *
 * ```
 * data/rssCloud/redirects/<sha1(url)>.json   {"url":…,"target":…,"failed":…,"time":…}
 * ```
 *
 * One file per URL rather than one shared map, so that concurrent notifications cannot lose each
 * other's writes. `target` is null for a URL that resolves to itself, which is cached too: the
 * common case is a URL that has not moved, and it should not be probed again on every refresh.
 * `failed` separates "it did not move" from "we could not tell", so only the latter is retried soon.
 */
final class RssCloud_Redirects {

	/** How long a completed resolution is trusted. Permanent redirects rarely stop being permanent. */
	public const TTL_SECONDS = 30 * 86400;

	/** How long to wait before probing a URL whose resolution failed, e.g. because the host was down. */
	public const TTL_FAILED_SECONDS = 6 * 3600;

	/** Redirect hops to follow before giving up. Matches the default cURL limit core applies. */
	public const MAX_HOPS = 4;

	public function __construct(
		private readonly string $basePath,
	) {
	}

	/**
	 * The URL that `$url` permanently moved to, or `$url` itself if it did not move or could not be
	 * checked. Never throws: a failure to resolve has to leave the caller with the status quo.
	 *
	 * @param array<string,mixed> $attributes the feed attributes, for `curl_params` and `timeout`
	 */
	public function resolve(string $url, array $attributes = []): string {
		$cached = $this->load($url);
		if ($cached !== null) {
			return $cached;
		}

		$target = $this->follow($url, $attributes);
		$this->store($url, $target);
		return $target ?? $url;
	}

	/** The cached target for `$url`, or null if there is no fresh entry. */
	private function load(string $url): ?string {
		$json = @file_get_contents($this->filename($url));
		if (!is_string($json) || $json === '') {
			return null;
		}
		$entry = json_decode($json, true);
		if (!is_array($entry)) {
			return null;
		}
		$time = is_numeric($entry['time'] ?? null) ? (int)$entry['time'] : 0;
		$target = is_string($entry['target'] ?? null) ? $entry['target'] : null;
		// "It did not move" is an answer and is trusted for as long as a move is; "we could not tell"
		// is not, and is retried sooner. Both store a missing target, so they are told apart by flag.
		$ttl = ($entry['failed'] ?? false) === true ? self::TTL_FAILED_SECONDS : self::TTL_SECONDS;
		if ($time < time() - $ttl) {
			return null;
		}
		return $target ?? $url;
	}

	private function store(string $url, ?string $target): void {
		$directory = $this->basePath . '/redirects';
		if (!@is_dir($directory) && !@mkdir($directory, 0770, true)) {
			Minz_Log::error('rssCloud: cannot create ' . $directory, RSSCLOUD_LOG);
			return;
		}
		$entry = [
			'url' => $url,
			'target' => $target === null || $target === $url ? null : $target,
			'failed' => $target === null,
			'time' => time(),
		];
		@file_put_contents($this->filename($url), json_encode($entry));
	}

	private function filename(string $url): string {
		return $this->basePath . '/redirects/' . sha1($url) . '.json';
	}

	/**
	 * Walk the chain of permanent redirects, or null if any hop could not be checked.
	 *
	 * Only 301 and 308 are followed. A temporary redirect deliberately says the resource has *not*
	 * moved, so following it would merge two feeds that the publisher considers distinct — and it is
	 * also what core declines to follow when it rewrites a feed URL, via SimplePie's permanent URL.
	 *
	 * @param array<string,mixed> $attributes
	 */
	private function follow(string $url, array $attributes): ?string {
		$current = $url;
		$seen = [$current => true];

		// One probe more than the hop limit: the URL reached by the last allowed hop still has to be
		// checked, or a chain of exactly MAX_HOPS would be reported as unresolvable despite being
		// within the limit — which is also how core counts, in `FreshRSS_http_Util::httpGet()`.
		for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
			$location = self::permanentLocation($current, $attributes);
			if ($location === false) {
				return null;
			}
			if ($location === null) {
				return $current;
			}
			if ($hop === self::MAX_HOPS) {
				break;
			}
			$absolute = \SimplePie\Misc::absolutize_url($location, $current);
			$next = is_string($absolute) ? (FreshRSS_http_Util::checkUrl($absolute, fixScheme: false) ?: '') : '';
			if ($next === '' || isset($seen[$next])) {
				// Unusable or looping: stop where we are rather than report a move we cannot trust.
				return $current;
			}
			$seen[$next] = true;
			$current = $next;
		}

		Minz_Log::warning('rssCloud: too many permanent redirects from ' .
			\SimplePie\Misc::url_remove_credentials($url), RSSCLOUD_LOG);
		return null;
	}

	/**
	 * Issue one HEAD and report the `Location` of a permanent redirect.
	 *
	 * @param array<string,mixed> $attributes
	 * @return string|false|null the location; null if this is not a permanent redirect; false if the
	 * request could not be made or failed, which is not the same answer and must not be cached as one
	 */
	private static function permanentLocation(string $url, array $attributes): string|false|null {
		if ($url === '') {
			return false;
		}

		// Re-checked at every hop, so a redirect cannot walk into the private network.
		$resolve = FreshRSS_http_Util::getCurlResolveInfo($url);
		if (!is_array($resolve)) {
			// null: the host's IP is not in the allowlist. false: the host did not resolve.
			return false;
		}

		$ch = curl_init();
		if ($ch === false) {
			return false;
		}

		$limits = FreshRSS_Context::systemConf()->limits;
		$timeout = is_numeric($attributes['timeout'] ?? null) && (int)$attributes['timeout'] > 0 ?
			(int)$attributes['timeout'] : (int)($limits['timeout'] ?? 10);

		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			// Only the status line and Location are wanted, so never ask for a body.
			CURLOPT_NOBODY => true,
			// Hops are walked by hand above, so that each one is re-checked against the allowlist.
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT => FRESHRSS_USERAGENT,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT => $timeout,
		]);
		if ($resolve !== []) {
			curl_setopt($ch, CURLOPT_RESOLVE, $resolve);	// Prevent DNS rebinding
		}
		if (defined('CURLOPT_PROTOCOLS_STR') && is_int(CURLOPT_PROTOCOLS_STR)) {
			curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
		} elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
		}

		// Instance-wide options carry the proxy configuration, so they must not be skipped.
		curl_setopt_array($ch, FreshRSS_Context::systemConf()->curl_options);
		if (is_array($attributes['curl_params'] ?? null)) {
			curl_setopt_array($ch, FreshRSS_http_Util::sanitizeCurlParams($attributes['curl_params']));
		}
		// Reassert what the options above are not allowed to undo.
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

		curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
		$error = curl_error($ch);

		if ($error !== '') {
			Minz_Log::debug('rssCloud: cannot check ' . \SimplePie\Misc::url_remove_credentials($url) .
				' for a permanent redirect: ' . $error, RSSCLOUD_LOG);
			return false;
		}

		if (in_array($status, [301, 308], true)) {
			// A permanent redirect with nowhere to go is malformed, and says nothing either way.
			return is_string($location) && $location !== '' ? $location : false;
		}

		// Transient: the same request may well answer differently later, so this must not be recorded
		// as a lasting "did not move" — that would let duplicates resume for the whole cache lifetime.
		if ($status === 0 || $status === 408 || $status === 429 || $status >= 500) {
			return false;
		}

		// Anything else is a stable answer of "this has not permanently moved", including a server
		// that rejects HEAD outright: retrying that in six hours would not produce a different answer.
		return null;
	}
}
