<?php
declare(strict_types=1);

/**
 * Tests for RssCloud_Registry, the on-disk store of subscription state.
 *
 * The emphasis is on what `load()` and `all()` do with state they cannot use. Both once parsed the
 * file themselves, and the copies had diverged: `all()` skipped unusable JSON in silence, which is
 * the wrong behaviour for the method that feeds the status screen.
 */
class RegistryTest extends \PHPUnit\Framework\TestCase {

	private string $basePath = '';

	private RssCloud_Registry $registry;

	#[\Override]
	protected function setUp(): void {
		$this->basePath = sys_get_temp_dir() . '/rsscloud-registry-test-' . getmypid() . '-' . uniqid();
		mkdir($this->basePath . '/resources', 0770, true);
		$this->registry = new RssCloud_Registry($this->basePath);
		@unlink(RSSCLOUD_LOG);
	}

	#[\Override]
	protected function tearDown(): void {
		recursive_unlink($this->basePath);
		@rmdir($this->basePath);
		@unlink(RSSCLOUD_LOG);
	}

	/** Write a state file straight to disk, bypassing save(), so malformed content can be staged. */
	private function writeRaw(string $resourceUrl, string $json): void {
		$directory = $this->registry->directory($resourceUrl);
		mkdir($directory, 0770, true);
		if ($json !== '') {
			file_put_contents($directory . '/!cloud.json', $json);
		}
	}

	private function log(): string {
		return (string)@file_get_contents(RSSCLOUD_LOG);
	}

	/** @param array<string,mixed> $state */
	private function writeState(string $resourceUrl, array $state): void {
		$this->writeRaw($resourceUrl, (string)json_encode($state + ['url' => $resourceUrl]));
	}

	public function test_load_returnsStoredState(): void {
		$this->writeState('https://a.example/feed', [
			'kind' => 'opml',
			'endpoint' => 'https://rpc.example/pleaseNotify',
			'registerProcedure' => '',
			'lease_start' => 1000,
			'last_notify' => 2000,
			'error' => false,
			'error_message' => '',
		]);

		$state = $this->registry->load('https://a.example/feed');

		self::assertIsArray($state);
		self::assertSame('https://a.example/feed', $state['url']);
		self::assertSame('opml', $state['kind']);
		self::assertSame('https://rpc.example/pleaseNotify', $state['endpoint']);
		self::assertSame(1000, $state['lease_start']);
		self::assertSame(2000, $state['last_notify']);
		self::assertFalse($state['error']);
	}

	public function test_load_reportsMalformedJson(): void {
		$this->writeRaw('https://b.example/feed', '{not json');

		self::assertNull($this->registry->load('https://b.example/feed'));
		self::assertStringContainsString('invalid state JSON', $this->log());
	}

	/** An empty url used to be accepted here and rejected in all(); both now reject it. */
	public function test_load_reportsEmptyUrl(): void {
		$this->writeRaw('https://c.example/feed', (string)json_encode(['url' => '']));

		self::assertNull($this->registry->load('https://c.example/feed'));
		self::assertStringContainsString('invalid state JSON', $this->log());
	}

	/**
	 * A directory without a state file is ordinary: save() creates the directory before writing,
	 * and addSubscriber() can create one on its own. It must not be logged as a fault.
	 */
	public function test_load_isSilentWhenFileAbsent(): void {
		$this->writeRaw('https://d.example/feed', '');

		self::assertNull($this->registry->load('https://d.example/feed'));
		self::assertSame('', $this->log());
	}

	public function test_load_constrainsUnknownKindToFeed(): void {
		$this->writeState('https://e.example/feed', ['kind' => 'something-else']);

		$state = $this->registry->load('https://e.example/feed');

		self::assertIsArray($state);
		self::assertSame(RssCloud_Registry::KIND_FEED, $state['kind']);
	}

	/** Absence of the flag must read as broken, not as fine, like core's WebSub code. */
	public function test_load_treatsMissingErrorFlagAsError(): void {
		$this->writeState('https://f.example/feed', []);

		$state = $this->registry->load('https://f.example/feed');

		self::assertIsArray($state);
		self::assertTrue($state['error']);
	}

	public function test_all_yieldsOnlyUsableStateAndReportsTheRest(): void {
		$this->writeState('https://a.example/feed', ['endpoint' => 'https://rpc.example/x']);
		$this->writeRaw('https://b.example/feed', '{not json');
		$this->writeRaw('https://c.example/feed', (string)json_encode(['url' => '']));
		$this->writeRaw('https://d.example/feed', '');

		$urls = [];
		foreach ($this->registry->all() as $state) {
			$urls[] = $state['url'];
		}

		self::assertSame(['https://a.example/feed'], $urls);
		// The two unusable files are reported; the absent one is not.
		self::assertSame(2, substr_count($this->log(), 'invalid state JSON'));
	}

	/** Documented as single-pass, so callers know not to count it without collecting it first. */
	public function test_all_isAGenerator(): void {
		self::assertInstanceOf(Generator::class, $this->registry->all());
	}

	public function test_all_isEmptyWhenNothingIsStored(): void {
		$states = [];
		foreach ($this->registry->all() as $state) {
			$states[] = $state;
		}

		self::assertSame([], $states);
	}

	public function test_subscribers_roundTrip(): void {
		$this->writeState('https://a.example/feed', []);

		$this->registry->addSubscriber('https://a.example/feed', 'alice');
		$this->registry->addSubscriber('https://a.example/feed', 'bob');
		self::assertSame(['alice', 'bob'], $this->sortedSubscribers('https://a.example/feed'));

		$this->registry->removeSubscriber('https://a.example/feed', 'alice');
		self::assertSame(['bob'], $this->sortedSubscribers('https://a.example/feed'));
	}

	/** @return list<string> */
	private function sortedSubscribers(string $resourceUrl): array {
		$subscribers = $this->registry->subscribers($resourceUrl);
		sort($subscribers);
		return $subscribers;
	}

	public function test_saveThenLoad_roundTrips(): void {
		$this->registry->init();
		$this->registry->save('https://g.example/feed', [
			'kind' => RssCloud_Registry::KIND_OPML,
			'endpoint' => 'https://rpc.example/pleaseNotify',
			'lease_start' => 42,
			'error' => false,
		]);

		$state = $this->registry->load('https://g.example/feed');

		self::assertIsArray($state);
		self::assertSame('https://g.example/feed', $state['url']);
		self::assertSame(RssCloud_Registry::KIND_OPML, $state['kind']);
		self::assertSame(42, $state['lease_start']);
		self::assertFalse($state['error']);
	}

	/** Moving to a different cloud server invalidates the lease rather than carrying it over. */
	public function test_remember_resetsLeaseWhenEndpointChanges(): void {
		$this->registry->init();
		$this->registry->save('https://h.example/feed', [
			'endpoint' => 'https://old.example/pleaseNotify',
			'lease_start' => 999,
			'error' => false,
		]);

		$endpoint = RssCloud_Endpoint::fromUrl('https://new.example/pleaseNotify');
		self::assertInstanceOf(RssCloud_Endpoint::class, $endpoint);
		$moved = $this->registry->remember('https://h.example/feed', $endpoint, RssCloud_Registry::KIND_FEED);

		self::assertSame(0, $moved['lease_start']);
		self::assertTrue($moved['error']);
	}

	public function test_remember_keepsLeaseWhenEndpointIsUnchanged(): void {
		$this->registry->init();
		$this->registry->save('https://i.example/feed', [
			'endpoint' => 'https://same.example/pleaseNotify',
			'lease_start' => 999,
			'error' => false,
		]);

		$endpoint = RssCloud_Endpoint::fromUrl('https://same.example/pleaseNotify');
		self::assertInstanceOf(RssCloud_Endpoint::class, $endpoint);
		$kept = $this->registry->remember('https://i.example/feed', $endpoint, RssCloud_Registry::KIND_FEED);

		self::assertSame(999, $kept['lease_start']);
		self::assertFalse($kept['error']);
	}
}
