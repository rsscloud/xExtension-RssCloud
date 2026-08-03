<?php
declare(strict_types=1);

/**
 * The location this FreshRSS instance asks to be notified at, decomposed the way a `pleaseNotify`
 * call wants it.
 *
 * The callback rides on `p/api/misc.php`, the entry point core provides so that extensions can own
 * a public unauthenticated endpoint. The `PATH_INFO` form is used rather than `?ext=rssCloud`,
 * because rssCloud appends its own `?url=…&challenge=…` and a path that already carries a query
 * string breaks that concatenation on some servers.
 *
 * An optional shared token is appended as a further path segment. rssCloud has no equivalent of
 * WebSub's `hub.secret`, so without it the endpoint is an unauthenticated "go and fetch this URL"
 * trigger for anyone who can guess a subscribed URL.
 */
final class RssCloud_CallbackUrl {

	public const ROUTE = '/api/misc.php/' . RssCloudExtension::NAME;

	private function __construct(
		public readonly string $domain,
		public readonly int $port,
		public readonly string $path,
		public readonly string $protocol,
		public readonly bool $secure,
	) {
	}

	/**
	 * @param string $baseUrl the public base URL of this instance, e.g. `https://rss.example.net/p`
	 * @param string $token optional shared secret path segment, `''` to disable
	 */
	public static function fromBaseUrl(string $baseUrl, string $token = ''): ?self {
		$parts = parse_url(rtrim($baseUrl, '/'));
		if (!is_array($parts) || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
			return null;
		}

		$scheme = strtolower((string)($parts['scheme'] ?? 'http'));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}
		$secure = $scheme === 'https';

		$port = is_numeric($parts['port'] ?? null) ? (int)$parts['port'] : ($secure ? 443 : 80);

		$path = rtrim((string)($parts['path'] ?? ''), '/') . self::ROUTE;
		if ($token !== '') {
			$path .= '/' . rawurlencode($token);
		}
		// A trailing slash keeps the segment count stable once the cloud server appends its query.
		$path .= '/';

		return new self(
			domain: $parts['host'],
			port: $port,
			path: $path,
			protocol: $secure ? RssCloud_Endpoint::PROTOCOL_HTTPS : RssCloud_Endpoint::PROTOCOL_HTTP,
			secure: $secure,
		);
	}

	/** The same location as a single absolute URL, for logging and for the configuration screen. */
	public function url(): string {
		$scheme = $this->secure ? 'https' : 'http';
		$defaultPort = $this->secure ? 443 : 80;
		$authority = $this->port === $defaultPort ? $this->domain : "{$this->domain}:{$this->port}";
		return "{$scheme}://{$authority}{$this->path}";
	}
}
