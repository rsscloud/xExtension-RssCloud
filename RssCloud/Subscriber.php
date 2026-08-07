<?php
declare(strict_types=1);

/**
 * Sends `pleaseNotify` calls to rssCloud servers and keeps lease bookkeeping up to date.
 *
 * The rssCloud REST call is a form POST carrying the subscriber's callback location, split into
 * `domain` / `port` / `path` / `protocol` (this is the mirror image of the `<cloud>` element), plus
 * one `url1`…`urlN` parameter per resource being subscribed to.
 *
 * Registration is synchronous: before answering, the cloud server calls the advertised callback
 * back with a `challenge` and expects it echoed. {@see RssCloud_Callback} answers that.
 *
 * @phpstan-import-type RssCloudState from RssCloud_Registry
 */
final class RssCloud_Subscriber {

	public function __construct(
		private readonly RssCloud_Registry $registry,
		private readonly RssCloud_CallbackUrl $callback,
		private readonly int $renewAfterSeconds,
	) {
	}

	/**
	 * A subscription needs (re)issuing when it has never succeeded, when the last attempt errored,
	 * or when the lease is old enough that the cloud server has probably expired it.
	 *
	 * rssCloud has no lease negotiation — the server does not tell us how long the subscription
	 * lasts — so this is purely a local timer. Subscriptions expire after 25 hours and are meant to
	 * be renewed every 24 (http://walkthrough.rsscloud.co/).
	 *
	 * @param RssCloudState $state
	 */
	public function needsRenewal(array $state): bool {
		if ($state['endpoint'] === '') {
			return false;
		}
		if ($state['lease_start'] <= 0 || $state['error']) {
			// Do not hammer a cloud server that just rejected us.
			return $state['lease_start'] < time() - 3600;
		}
		return $state['lease_start'] < time() - $this->renewAfterSeconds;
	}

	/**
	 * Whether a cloud server is believed to be actively notifying us about this resource.
	 *
	 * Static, and taking the window explicitly, so that the polling decision can be made without a
	 * usable callback URL — an instance is only constructible when we can actually register.
	 *
	 * @param RssCloudState $state
	 */
	public static function isHealthy(array $state, int $renewAfterSeconds): bool {
		return !$state['error']
			&& $state['lease_start'] > 0
			&& $state['lease_start'] > time() - $renewAfterSeconds;
	}

	/**
	 * The cURL options a `pleaseNotify` POST is sent with.
	 *
	 * `CURLOPT_POSTREDIR` is the load-bearing one, and it is not redundant even though it can look
	 * that way. Most rssCloud endpoints are advertised as `http` in the `<cloud>` element and answer
	 * with a permanent redirect to `https`; libcurl's default on 301/302/303 is to follow it as a
	 * bodyless GET, exactly as `curl -L` does. The server then sees no `url1` and answers
	 * `No feed for url1.`, which is what made several servers look permanently broken.
	 *
	 * On FreshRSS 1.29.x this is the whole ballgame: `httpGet()` sets `CURLOPT_FOLLOWLOCATION` and
	 * lets libcurl do the following, so without this option the body is lost. Later versions follow
	 * redirects by hand and happen to keep the body — which is why removing this line would look
	 * harmless when tested against a development build and still break every released one.
	 *
	 * @return array<int,mixed>
	 */
	public static function notifyCurlOptions(string $body): array {
		return [
			CURLOPT_POSTFIELDS => $body,
			// Keep the POST (and its body) across a 301/302/303 instead of degrading to GET.
			CURLOPT_POSTREDIR => CURL_REDIR_POST_ALL,
			CURLOPT_MAXREDIRS => 10,
		];
	}

	/**
	 * Issue a `pleaseNotify` for one resource.
	 *
	 * @param RssCloudState $state
	 */
	public function subscribe(array $state): bool {
		$endpoint = RssCloud_Endpoint::fromUrl($state['endpoint'], $state['registerProcedure']);
		if ($endpoint === null) {
			return false;
		}

		$parameters = [
			'domain' => $this->callback->domain,
			'port' => (string)$this->callback->port,
			'path' => $this->callback->path,
			'registerProcedure' => $state['registerProcedure'],
			'url1' => $state['url'],
		];

		// Mark the attempt before making it, so a crashed or timed-out call cannot spin.
		$state['lease_start'] = time();
		$this->registry->save($state['url'], $state);

		$candidates = self::protocolCandidates($state['protocol'], $this->callback->protocol);
		$message = '';
		$status = 0;

		foreach ($candidates as $i => $protocol) {
			$response = FreshRSS_http_Util::httpGet($endpoint->url, null, 'xml', [], self::notifyCurlOptions(
				http_build_query($parameters + ['protocol' => $protocol]),
			));
			$status = (int)$response['status'];
			[$success, $message] = self::parseNotifyResult((string)$response['body'], $status);

			$log = 'rssCloud pleaseNotify ' . $state['url'] . ' via ' . $endpoint->url
				. ' with callback ' . $this->callback->url() . ' as ' . $protocol
				. ': ' . $status . ' ' . $message;

			if ($success) {
				// Remembered so that later renewals go straight to the value this server accepts,
				// instead of failing the preferred one every time.
				$state['protocol'] = $protocol;
				$state['error'] = false;
				$state['error_message'] = '';
				$this->registry->save($state['url'], $state);
				Minz_Log::notice($log, RSSCLOUD_LOG);
				return true;
			}

			// A negative status is FreshRSS-internal: the request never reached the server, so it
			// cannot be objecting to the protocol and another value would fail identically.
			$retrying = $status > 0 && isset($candidates[$i + 1]);
			if ($retrying) {
				// Not yet a fault: the fallback may still succeed, and warning here every time
				// would make a working subscription look broken in the log.
				Minz_Log::debug($log, RSSCLOUD_LOG);
			} else {
				Minz_Log::warning($log, RSSCLOUD_LOG);
				break;
			}
		}

		$state['error'] = true;
		$state['error_message'] = $message === '' ? "HTTP {$status}" : $message;
		$this->registry->save($state['url'], $state);

		return false;
	}

	/**
	 * The `protocol` parameter of `pleaseNotify` names the notification method — `http-post` for
	 * REST, as against `xml-rpc` or `soap` — and not the scheme of the callback, which is carried by
	 * `port`. Servers disagree about this: some accept `https-post` as a TLS-flavoured spelling,
	 * while others take only the value the specification lists, so an HTTPS callback cannot simply
	 * assume either one.
	 *
	 * The value this server last accepted is therefore tried first, falling back to plain
	 * `http-post`, which every server understands. Registering over a plain HTTP callback has
	 * nothing to fall back to, and yields a single candidate.
	 *
	 * @return non-empty-list<string>
	 */
	public static function protocolCandidates(string $remembered, string $callbackProtocol): array {
		$preferred = $remembered !== '' ? $remembered : $callbackProtocol;
		if ($preferred === RssCloud_Endpoint::PROTOCOL_HTTP) {
			return [$preferred];
		}
		return [$preferred, RssCloud_Endpoint::PROTOCOL_HTTP];
	}

	/**
	 * Interpret a `pleaseNotify` response, e.g.
	 * `<notifyResult success="true" msg="Thanks for the registration."/>`
	 *
	 * A JSON body is also accepted, since rssCloud servers may honour `Accept: application/json`.
	 *
	 * @return array{0:bool,1:string} success flag and a human-readable message
	 */
	private static function parseNotifyResult(string $body, int $status): array {
		$body = trim($body);
		if ($status < 200 || $status >= 300) {
			// Negative statuses are FreshRSS-internal (e.g. -429 rate-limited, -500 cURL failure).
			return [false, $body === '' ? "HTTP {$status}" : substr($body, 0, 500)];
		}
		if ($body === '') {
			return [false, 'Empty response'];
		}

		if (str_starts_with($body, '{')) {
			$json = json_decode($body, true);
			if (is_array($json)) {
				$message = is_string($json['msg'] ?? null) ? $json['msg'] : '';
				return [!empty($json['success']), $message];
			}
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$xml = simplexml_load_string($body, options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if ($xml instanceof SimpleXMLElement) {
			$success = strtolower(trim((string)($xml['success'] ?? ''))) === 'true';
			return [$success, (string)($xml['msg'] ?? '')];
		}

		return [false, 'Unparsable response: ' . substr($body, 0, 500)];
	}
}
