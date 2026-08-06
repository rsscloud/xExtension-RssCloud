<?php

/**
 * Minz_Translate splits a key on every dot and walks the array one level per segment, so a literal
 * 'callback.help' key is unreachable: the lookup descends into 'callback' and then looks for a
 * 'help' child of it. Nested arrays are therefore required, with '_' holding the value of a key
 * that is itself also a parent. This mirrors core's own i18n files.
 */

return array(
	'rsscloud' => array(
		'base_url' => array(
			'_' => 'Public base URL override',
			'help' => 'Leave empty to use the instance base URL. Set this when a reverse proxy makes FreshRSS reachable under a different scheme, host or port than it sees internally.',
		),
		'callback' => array(
			'_' => 'Notification callback',
			'help' => 'This is the address advertised to rssCloud servers. It must be reachable from the public internet, otherwise the registration handshake fails and subscriptions are cancelled.',
			'invalid' => 'No usable callback URL could be derived. Check the base URL below and the instance base_url setting.',
			'private' => 'This address does not look publicly reachable, so rssCloud registration will fail.',
		),
		'cooldown' => array(
			'_' => 'Notification cooldown (seconds)',
			'help' => 'Minimum delay between two honoured notifications for the same resource. The callback is unauthenticated, so this bounds how often a stranger can make this server do work.',
		),
		'feeds_enabled' => array(
			'_' => 'Subscribe to feeds',
			'help' => 'Use rssCloud for feeds advertising a <cloud> or <source:cloud> element.',
		),
		'opml_enabled' => array(
			'_' => 'Subscribe to dynamic OPML',
			'help' => 'Use rssCloud for dynamic OPML subscription lists advertising <source:cloud> in their <head>.',
		),
		'regenerate_token' => array(
			'_' => 'Regenerate callback token',
			'help' => 'Changes the secret path segment of the callback. Existing subscriptions stop being delivered until they are renewed.',
		),
		'renew_hours' => array(
			'_' => 'Renew subscriptions after (hours)',
			'help' => 'rssCloud does not negotiate a lease duration. Subscriptions expire after 25 hours and are meant to be renewed every 24, so the default of 23 leaves a margin. Anything above 24 would simply lapse, and is capped.',
		),
		'skip_polling' => array(
			'_' => 'Skip polling covered resources',
			'help' => 'Stop polling a resource on a timer while its cloud subscription is healthy. It is still polled if it goes stale, or when refreshed individually.',
		),
		'status' => array(
			'_' => 'Subscriptions',
			'active' => 'Active',
			'endpoint' => 'Cloud server',
			'error' => 'Failed',
			'help' => 'One row per resource this instance has discovered a cloud server for. "Renewal attempted" is when registration was last tried, not when it last succeeded; the state column says whether it did.',
			'kind' => array(
				'_' => 'Type',
				'feed' => 'Feed',
				'opml' => 'Dynamic OPML',
			),
			'never' => 'Never',
			'none' => 'No cloud servers have been discovered yet. Resources are registered as they are refreshed, so this fills in once feeds advertising a cloud have been fetched at least once.',
			'notified' => 'Last notified',
			'pending' => 'Pending',
			'renewed' => 'Renewal attempted',
			'resource' => 'Resource',
			'stale' => 'Stale',
			'state' => 'State',
			'subscribers' => 'Users',
		),
	),
);
