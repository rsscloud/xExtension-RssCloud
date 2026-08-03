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
	 * lasts — so this is purely a local timer. The conventional expiry is around 24 hours.
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
			'protocol' => $this->callback->protocol,
			'registerProcedure' => $state['registerProcedure'],
			'url1' => $state['url'],
		];

		// Mark the attempt before making it, so a crashed or timed-out call cannot spin.
		$state['lease_start'] = time();
		$this->registry->save($state['url'], $state);

		$response = FreshRSS_http_Util::httpGet($endpoint->url, null, 'xml', [], [
			CURLOPT_POSTFIELDS => http_build_query($parameters),
			CURLOPT_MAXREDIRS => 10,
		]);

		[$success, $message] = self::parseNotifyResult((string)$response['body'], (int)$response['status']);

		$state['error'] = !$success;
		$state['error_message'] = $success ? '' : $message;
		$this->registry->save($state['url'], $state);

		$log = 'rssCloud pleaseNotify ' . $state['url'] . ' via ' . $endpoint->url
			. ' with callback ' . $this->callback->url()
			. ': ' . $response['status'] . ' ' . $message;
		if ($success) {
			Minz_Log::notice($log, RSSCLOUD_LOG);
		} else {
			Minz_Log::warning($log, RSSCLOUD_LOG);
		}

		return $success;
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
