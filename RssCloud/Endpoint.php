<?php
declare(strict_types=1);

/**
 * A resolved rssCloud endpoint: the absolute URL we send `pleaseNotify` calls to.
 *
 * Two advertisement forms are supported, and both reduce to this same value object:
 *
 * * The RSS 2.0 `<cloud>` element, whose location is spread across `domain`, `port` and `path`
 *   attributes and whose scheme has to be inferred (see {@see self::fromCloudAttributes()}).
 * * The `<source:cloud>` element, whose text content is already an absolute URL.
 */
final class RssCloud_Endpoint {

	/** Notification protocols this extension can act as a subscriber for. */
	public const PROTOCOL_HTTP = 'http-post';
	public const PROTOCOL_HTTPS = 'https-post';

	/**
	 * @param non-empty-string $url
	 */
	private function __construct(
		public readonly string $url,
		public readonly string $registerProcedure,
	) {
	}

	/**
	 * Build an endpoint from the attributes of an RSS 2.0 `<cloud>` element, e.g.
	 * `<cloud domain="rpc.rsscloud.io" port="443" path="/pleaseNotify" registerProcedure="" protocol="https-post"/>`
	 *
	 * The `<cloud>` element predates ubiquitous TLS and has no scheme attribute, so the scheme is
	 * inferred: `protocol="https-post"` or `port="443"` means HTTPS, anything else means HTTP.
	 *
	 * @param array<array-key,mixed> $attributes the element attributes as the XML parser produced
	 *   them, so values are untyped and keys may be in any case
	 */
	public static function fromCloudAttributes(array $attributes): ?self {
		$attributes = array_change_key_case($attributes, CASE_LOWER);

		$domain = self::attribute($attributes, 'domain');
		$path = self::attribute($attributes, 'path');
		$port = self::attribute($attributes, 'port');
		$protocol = strtolower(self::attribute($attributes, 'protocol'));

		if ($domain === '' || $path === '') {
			return null;
		}
		// `xml-rpc` and `soap` clouds are out of scope: this extension only speaks REST.
		if ($protocol !== '' && $protocol !== self::PROTOCOL_HTTP && $protocol !== self::PROTOCOL_HTTPS) {
			Minz_Log::debug("rssCloud: ignoring <cloud> with unsupported protocol “{$protocol}”", RSSCLOUD_LOG);
			return null;
		}

		$isHttps = $protocol === self::PROTOCOL_HTTPS || $port === '443';
		$scheme = $isHttps ? 'https' : 'http';
		$defaultPort = $isHttps ? '443' : '80';

		$authority = $domain;
		if ($port !== '' && $port !== $defaultPort) {
			$authority .= ':' . $port;
		}
		if (!str_starts_with($path, '/')) {
			$path = '/' . $path;
		}

		return self::fromUrl("{$scheme}://{$authority}{$path}", self::attribute($attributes, 'registerprocedure'));
	}

	/**
	 * Read one XML attribute as a trimmed string, tolerating the untyped values a parser yields.
	 *
	 * @param array<array-key,mixed> $attributes
	 */
	private static function attribute(array $attributes, string $key): string {
		$value = $attributes[$key] ?? null;
		return is_string($value) ? trim($value) : '';
	}

	/**
	 * Build an endpoint from an absolute URL, as advertised by `<source:cloud>`, e.g.
	 * `<source:cloud>https://rpc.rsscloud.io/pleaseNotify</source:cloud>`
	 */
	public static function fromUrl(string $url, string $registerProcedure = ''): ?self {
		$url = FreshRSS_http_Util::checkUrl(trim($url));
		if (!is_string($url) || $url === '') {
			return null;
		}
		$scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}
		return new self($url, trim($registerProcedure));
	}

	public function isSecure(): bool {
		return str_starts_with(strtolower($this->url), 'https://');
	}
}
