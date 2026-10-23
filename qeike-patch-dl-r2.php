<?php
/**
 * Plugin Name: Qeike Patch dl-r2 trial allowlist
 * Description: One-shot: sync public_html/dl-r2/index.php from the trial-aware proxy copy shipped beside this MU plugin. Safe no-op once SHA matches.
 * Version: 1.1.9
 */
if (!defined('ABSPATH')) {
	exit;
}

add_action('plugins_loaded', static function (): void {
	$flag = WP_CONTENT_DIR . '/.qeike-dl-r2-trial-patched-1.1.9';
	if (is_file($flag)) {
		return;
	}
	$src = __DIR__ . '/qeike-dl-r2-index.php';
	$dst = ABSPATH . 'dl-r2/index.php';
	if (!is_file($src) || !is_dir(dirname($dst))) {
		return;
	}
	$want = hash_file('sha256', $src);
	$have = is_file($dst) ? hash_file('sha256', $dst) : '';
	if ($want === $have) {
		@file_put_contents($flag, $want . "\n");
		return;
	}
	if (@copy($src, $dst)) {
		@file_put_contents($flag, $want . "\n");
	}
}, 1);

add_action('plugins_loaded', static function (): void {
	$flag = WP_CONTENT_DIR . '/.qeike-htaccess-dl-r2b-1.1.9';
	if (is_file($flag)) {
		return;
	}
	$ht = ABSPATH . '.htaccess';
	if (!is_file($ht) || !is_writable($ht)) {
		return;
	}
	$raw = file_get_contents($ht);
	if ($raw === false || strpos($raw, 'dl-r2b/index.php') !== false) {
		@file_put_contents($flag, "already\n");
		return;
	}
	$next = str_replace(
		[
			'RewriteCond %{REQUEST_URI} !^/(dl-r2|update)/',
			'RewriteRule ^(.*)$ /dl-r2/index.php?qk_path=$1 [L,QSA,B]',
		],
		[
			'RewriteCond %{REQUEST_URI} !^/(dl-r2|dl-r2b|update)/',
			'RewriteRule ^(.*)$ /dl-r2b/index.php?qk_path=$1 [L,QSA,B]',
		],
		$raw
	);
	if ($next !== $raw && @file_put_contents($ht, $next) !== false) {
		@file_put_contents($flag, "patched\n");
	}
}, 2);
