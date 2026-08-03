<?php
declare(strict_types=1);

/**
 * Finds the rssCloud endpoint advertised by a feed or by an OPML subscription list.
 *
 * `<source:cloud>` is preferred over `<cloud>` when both are present, because it carries an
 * absolute URL and therefore does not require guessing a scheme.
 */
final class RssCloud_Discovery {

	/**
	 * Namespace URIs seen in the wild for the `source:` prefix. The canonical form is the first
	 * one; the others are tolerated because namespace URIs are compared verbatim, and publishers
	 * are inconsistent about the scheme and the trailing slash.
	 *
	 * @var list<string>
	 */
	public const SOURCE_NAMESPACES = [
		'https://source.scripting.com/',
		'http://source.scripting.com/',
		'https://source.scripting.com',
		'http://source.scripting.com',
	];

	/**
	 * Discover the cloud endpoint advertised in the `<channel>` of a parsed feed.
	 *
	 * Note that SimplePie has no first-class accessor for either element, so both are read from
	 * the raw channel tags.
	 */
	public static function fromSimplePie(FreshRSS_SimplePieCustom $simplePie): ?RssCloud_Endpoint {
		// 1. `<source:cloud>https://rpc.rsscloud.io/pleaseNotify</source:cloud>`
		foreach (self::SOURCE_NAMESPACES as $namespace) {
			$tags = $simplePie->get_channel_tags($namespace, 'cloud');
			if (is_array($tags) && is_string($tags[0]['data'] ?? null)) {
				$endpoint = RssCloud_Endpoint::fromUrl($tags[0]['data']);
				if ($endpoint !== null) {
					return $endpoint;
				}
			}
		}

		// 2. `<cloud domain="…" port="…" path="…" registerProcedure="" protocol="…"/>`
		// SimplePie::NAMESPACE_RSS_20 is the empty string: RSS 2.0 elements are not namespaced,
		// so their attributes live under the empty-string namespace key too.
		$tags = $simplePie->get_channel_tags(SimplePie\SimplePie::NAMESPACE_RSS_20, 'cloud');
		$namespacedAttributes = $tags[0]['attribs'] ?? null;
		if (is_array($namespacedAttributes)) {
			$attributes = $namespacedAttributes[''] ?? null;
			if (is_array($attributes)) {
				return RssCloud_Endpoint::fromCloudAttributes($attributes);
			}
		}

		return null;
	}

	/**
	 * Discover the cloud endpoint advertised in the `<head>` of an OPML subscription list.
	 *
	 * OPML has no `<cloud>` element of its own, so only `<source:cloud>` is supported here.
	 * This parses the document directly rather than going through LibOpml, which discards
	 * namespaced elements it does not know about.
	 *
	 * @param string $opml the raw OPML document
	 */
	public static function fromOpml(string $opml): ?RssCloud_Endpoint {
		if (trim($opml) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$xml = simplexml_load_string($opml, options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!($xml instanceof SimpleXMLElement)) {
			return null;
		}

		$head = $xml->head ?? null;
		if (!($head instanceof SimpleXMLElement)) {
			return null;
		}

		$declared = $xml->getDocNamespaces(recursive: true) ?: [];
		foreach (self::SOURCE_NAMESPACES as $namespace) {
			if (!in_array($namespace, $declared, true)) {
				continue;
			}
			$children = $head->children($namespace);
			$cloud = $children->cloud ?? null;
			if ($cloud !== null && (string)$cloud !== '') {
				$endpoint = RssCloud_Endpoint::fromUrl((string)$cloud);
				if ($endpoint !== null) {
					return $endpoint;
				}
			}
		}

		return null;
	}
}
