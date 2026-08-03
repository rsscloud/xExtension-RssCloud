<?php
declare(strict_types=1);

/**
 * On-disk store of rssCloud subscriptions, deliberately mirroring the layout that core uses for
 * WebSub under `PSHB_PATH`:
 *
 * ```
 * data/rssCloud/resources/<sha1(resourceUrl)>/!cloud.json   subscription state
 * data/rssCloud/resources/<sha1(resourceUrl)>/<username>.txt  one marker per interested user
 * ```
 *
 * A subscription is instance-wide (the cloud server knows one callback URL), but the resources
 * behind it are per-user, hence the marker files acting as a reference count. Unlike WebSub there
 * is no per-subscription key, because rssCloud registers a single fixed callback path and
 * identifies the resource by the `url` parameter of each notification.
 *
 * @phpstan-type RssCloudState array{url:string,kind:string,endpoint:string,registerProcedure:string,
 *   lease_start:int,last_notify:int,error:bool,error_message:string}
 */
final class RssCloud_Registry {

	public const KIND_FEED = 'feed';
	public const KIND_OPML = 'opml';

	public function __construct(
		private readonly string $basePath,
	) {
	}

	public function init(): bool {
		return @is_dir($this->basePath . '/resources') || @mkdir($this->basePath . '/resources', 0770, true);
	}

	public function directory(string $resourceUrl): string {
		return $this->basePath . '/resources/' . sha1($resourceUrl);
	}

	/**
	 * @return RssCloudState|null
	 */
	public function load(string $resourceUrl): ?array {
		$json = @file_get_contents($this->directory($resourceUrl) . '/!cloud.json');
		if (!is_string($json) || $json === '') {
			return null;
		}
		$state = json_decode($json, true);
		if (!is_array($state) || !is_string($state['url'] ?? null)) {
			Minz_Log::warning('rssCloud: invalid state JSON for ' . $resourceUrl, RSSCLOUD_LOG);
			return null;
		}
		return self::normalise($state);
	}

	/**
	 * @param array<array-key,mixed> $state as decoded from JSON, so entirely untrusted
	 * @return RssCloudState
	 */
	private static function normalise(array $state): array {
		return [
			'url' => is_string($state['url'] ?? null) ? $state['url'] : '',
			'kind' => is_string($state['kind'] ?? null) ? $state['kind'] : self::KIND_FEED,
			'endpoint' => is_string($state['endpoint'] ?? null) ? $state['endpoint'] : '',
			'registerProcedure' => is_string($state['registerProcedure'] ?? null) ? $state['registerProcedure'] : '',
			'lease_start' => is_numeric($state['lease_start'] ?? null) ? (int)$state['lease_start'] : 0,
			'last_notify' => is_numeric($state['last_notify'] ?? null) ? (int)$state['last_notify'] : 0,
			// Assume broken until a notification actually arrives, like the core WebSub code does.
			'error' => !isset($state['error']) || (bool)$state['error'],
			'error_message' => is_string($state['error_message'] ?? null) ? $state['error_message'] : '',
		];
	}

	/**
	 * @param array<string,mixed> $state
	 */
	public function save(string $resourceUrl, array $state): bool {
		$directory = $this->directory($resourceUrl);
		if (!@is_dir($directory) && !@mkdir($directory, 0770, true)) {
			Minz_Log::error('rssCloud: cannot create ' . $directory, RSSCLOUD_LOG);
			return false;
		}
		$state['url'] = $resourceUrl;
		return file_put_contents($directory . '/!cloud.json', json_encode(self::normalise($state))) !== false;
	}

	/**
	 * Record an endpoint discovered for a resource, preserving any existing lease bookkeeping.
	 *
	 * @param self::KIND_* $kind
	 * @return RssCloudState the stored state
	 */
	public function remember(string $resourceUrl, RssCloud_Endpoint $endpoint, string $kind): array {
		$state = $this->load($resourceUrl) ?? self::normalise(['url' => $resourceUrl]);
		if ($state['endpoint'] !== $endpoint->url) {
			// The publisher moved to a different cloud server: start over.
			$state['lease_start'] = 0;
			$state['error'] = true;
			$state['error_message'] = '';
		}
		$state['kind'] = $kind;
		$state['endpoint'] = $endpoint->url;
		$state['registerProcedure'] = $endpoint->registerProcedure;
		$this->save($resourceUrl, $state);
		return $state;
	}

	public function addSubscriber(string $resourceUrl, string $username): void {
		if (!FreshRSS_user_Controller::checkUsername($username)) {
			return;
		}
		$marker = $this->directory($resourceUrl) . '/' . $username . '.txt';
		if (!file_exists($marker)) {
			@touch($marker);
		}
	}

	public function removeSubscriber(string $resourceUrl, string $username): void {
		if (!FreshRSS_user_Controller::checkUsername($username)) {
			return;
		}
		@unlink($this->directory($resourceUrl) . '/' . $username . '.txt');
	}

	/** @return list<string> usernames known to be interested in this resource */
	public function subscribers(string $resourceUrl): array {
		$markers = @glob($this->directory($resourceUrl) . '/*.txt', GLOB_NOSORT);
		if ($markers === false) {
			return [];
		}
		$usernames = [];
		foreach ($markers as $marker) {
			$username = basename($marker, '.txt');
			if (FreshRSS_user_Controller::checkUsername($username)) {
				$usernames[] = $username;
			}
		}
		return $usernames;
	}

	/** Drop a subscription entirely, e.g. once nobody subscribes to the resource anymore. */
	public function forget(string $resourceUrl): void {
		recursive_unlink($this->directory($resourceUrl));
	}

	/**
	 * Iterate over every known subscription.
	 *
	 * @return iterable<RssCloudState>
	 */
	public function all(): iterable {
		$directories = @glob($this->basePath . '/resources/*', GLOB_ONLYDIR | GLOB_NOSORT);
		foreach ($directories ?: [] as $directory) {
			$json = @file_get_contents($directory . '/!cloud.json');
			if (!is_string($json) || $json === '') {
				continue;
			}
			$state = json_decode($json, true);
			if (is_array($state) && is_string($state['url'] ?? null) && $state['url'] !== '') {
				yield self::normalise($state);
			}
		}
	}
}
