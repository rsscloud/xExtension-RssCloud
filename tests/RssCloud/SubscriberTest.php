<?php
declare(strict_types=1);

/**
 * Tests for the parts of RssCloud_Subscriber that do not need a cloud server.
 *
 * `protocolCandidates()` decides which `protocol` value a `pleaseNotify` advertises, which is the
 * one thing servers were found to disagree about: some accept `https-post` for a TLS callback,
 * others only the `http-post` the specification lists, with the scheme carried by `port`.
 */
class SubscriberTest extends \PHPUnit\Framework\TestCase {

	public function test_httpsCallbackFallsBackToHttpPost(): void {
		self::assertSame(
			[RssCloud_Endpoint::PROTOCOL_HTTPS, RssCloud_Endpoint::PROTOCOL_HTTP],
			RssCloud_Subscriber::protocolCandidates('', RssCloud_Endpoint::PROTOCOL_HTTPS),
		);
	}

	/** A plain HTTP callback already advertises the universal value, so there is nowhere to fall. */
	public function test_httpCallbackHasNothingToFallBackTo(): void {
		self::assertSame(
			[RssCloud_Endpoint::PROTOCOL_HTTP],
			RssCloud_Subscriber::protocolCandidates('', RssCloud_Endpoint::PROTOCOL_HTTP),
		);
	}

	/** Once a server has accepted http-post, the rejected value is not tried again. */
	public function test_rememberedHttpPostIsUsedAlone(): void {
		self::assertSame(
			[RssCloud_Endpoint::PROTOCOL_HTTP],
			RssCloud_Subscriber::protocolCandidates(RssCloud_Endpoint::PROTOCOL_HTTP, RssCloud_Endpoint::PROTOCOL_HTTPS),
		);
	}

	/** A remembered https-post keeps its fallback, in case the server's behaviour changes back. */
	public function test_rememberedHttpsPostKeepsTheFallback(): void {
		self::assertSame(
			[RssCloud_Endpoint::PROTOCOL_HTTPS, RssCloud_Endpoint::PROTOCOL_HTTP],
			RssCloud_Subscriber::protocolCandidates(RssCloud_Endpoint::PROTOCOL_HTTPS, RssCloud_Endpoint::PROTOCOL_HTTP),
		);
	}

	/** The remembered value wins over the callback's own, since it is evidence rather than a guess. */
	public function test_rememberedValueTakesPrecedence(): void {
		$candidates = RssCloud_Subscriber::protocolCandidates(
			RssCloud_Endpoint::PROTOCOL_HTTP,
			RssCloud_Endpoint::PROTOCOL_HTTPS,
		);

		self::assertSame(RssCloud_Endpoint::PROTOCOL_HTTP, $candidates[0]);
	}

	/**
	 * Most `<cloud>` elements advertise an http endpoint that permanently redirects to https. Without
	 * this option libcurl follows that redirect as a bodyless GET, the server sees no `url1`, and
	 * answers `No feed for url1.` — the failure that made several servers look permanently broken.
	 */
	public function test_notifyKeepsThePostBodyAcrossARedirect(): void {
		$options = RssCloud_Subscriber::notifyCurlOptions('url1=https%3A%2F%2Fexample.com%2Ffeed.xml');

		self::assertSame(CURL_REDIR_POST_ALL, $options[CURLOPT_POSTREDIR] ?? null,
			'a 301 from http to https must not degrade the pleaseNotify POST into a GET');
	}

	/** The body is what carries url1, so it has to survive the option assembly intact. */
	public function test_notifySendsTheBodyItWasGiven(): void {
		$body = 'domain=example.org&port=443&url1=https%3A%2F%2Fexample.com%2Ffeed.xml';

		self::assertSame($body, RssCloud_Subscriber::notifyCurlOptions($body)[CURLOPT_POSTFIELDS] ?? null);
	}

	/** Whatever the inputs, the caller always has at least one value to send. */
	public function test_alwaysYieldsAtLeastOneCandidate(): void {
		foreach (['', RssCloud_Endpoint::PROTOCOL_HTTP, RssCloud_Endpoint::PROTOCOL_HTTPS] as $remembered) {
			foreach ([RssCloud_Endpoint::PROTOCOL_HTTP, RssCloud_Endpoint::PROTOCOL_HTTPS] as $callback) {
				$candidates = RssCloud_Subscriber::protocolCandidates($remembered, $callback);
				self::assertNotSame([], $candidates, "remembered=$remembered callback=$callback");
				self::assertContains(RssCloud_Endpoint::PROTOCOL_HTTP, $candidates,
					"http-post must always remain reachable: remembered=$remembered callback=$callback");
			}
		}
	}
}
