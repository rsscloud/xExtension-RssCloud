<?php

return array(
	'rsscloud' => array(
		'base_url' => 'Public base URL override',
		'base_url.help' => 'Leave empty to use the instance base URL. Set this when a reverse proxy makes FreshRSS reachable under a different scheme, host or port than it sees internally.',
		'callback' => 'Notification callback',
		'callback.help' => 'This is the address advertised to rssCloud servers. It must be reachable from the public internet, otherwise the registration handshake fails and subscriptions are cancelled.',
		'callback.invalid' => 'No usable callback URL could be derived. Check the base URL below and the instance base_url setting.',
		'callback.private' => 'This address does not look publicly reachable, so rssCloud registration will fail.',
		'cooldown' => 'Notification cooldown (seconds)',
		'cooldown.help' => 'Minimum delay between two honoured notifications for the same resource. The callback is unauthenticated, so this bounds how often a stranger can make this server do work.',
		'feeds_enabled' => 'Subscribe to feeds',
		'feeds_enabled.help' => 'Use rssCloud for feeds advertising a <cloud> or <source:cloud> element.',
		'opml_enabled' => 'Subscribe to dynamic OPML',
		'opml_enabled.help' => 'Use rssCloud for dynamic OPML subscription lists advertising <source:cloud> in their <head>.',
		'regenerate_token' => 'Regenerate callback token',
		'regenerate_token.help' => 'Changes the secret path segment of the callback. Existing subscriptions stop being delivered until they are renewed.',
		'renew_hours' => 'Renew subscriptions after (hours)',
		'renew_hours.help' => 'rssCloud does not negotiate a lease duration. Subscriptions expire after 25 hours and are meant to be renewed every 24, so the default of 23 leaves a margin. Anything above 24 would simply lapse, and is capped.',
		'skip_polling' => 'Skip polling covered resources',
		'skip_polling.help' => 'Stop polling a resource on a timer while its cloud subscription is healthy. It is still polled if it goes stale, or when refreshed individually.',
	),
);
