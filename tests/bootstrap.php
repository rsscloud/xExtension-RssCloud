<?php
declare(strict_types=1);

/**
 * The extension has no dependencies of its own. It is tested the same way it is analysed: from
 * inside a FreshRSS checkout, borrowing core's autoloader and its dev dependencies, so that there
 * is no second composer.json to keep in step. See .github/workflows/ci.yml.
 *
 * This deliberately requires core's constants and autoloader directly rather than core's own
 * tests/bootstrap.php: the two lines are the same, and constants.php is far less likely to move
 * than the layout of core's test directory.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

const COPY_LOG_TO_SYSLOG = false;

require dirname(__DIR__, 3) . '/constants.php';
require LIB_PATH . '/lib_rss.php';	// includes the class autoloader

// Written to by Minz_Log, and asserted on: the point of several of these tests is that unusable
// state is reported rather than silently skipped.
define('RSSCLOUD_LOG', sys_get_temp_dir() . '/rsscloud-test-' . getmypid() . '.log');

// Extension classes are loaded by Minz_ExtensionManager in a running FreshRSS, which is not
// involved here, so the classes under test are required explicitly.
require dirname(__DIR__) . '/RssCloud/Endpoint.php';
require dirname(__DIR__) . '/RssCloud/Registry.php';
