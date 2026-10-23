<?php
/**
 * download.qeike.com → Cloudflare R2 public bucket (streaming reverse proxy).
 * Supports HTTP Range for large installers. Does not change update.qeike.com.
 */
declare(strict_types=1);

$UPSTREAM = 'https://pub-6f0e74afb20c41fe9767451bdb4a879b.r2.dev';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type, Accept, Origin');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges, Content-Type, ETag, Last-Modified');
header('X-LiteSpeed-Cache-Control: no-cache');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
	http_response_code(204);
	exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
	http_response_code(405);
	header('Content-Type: application/json; charset=utf-8');
	header('Allow: GET, HEAD, OPTIONS');
	echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
	exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (isset($_GET['qk_path'])) {
	$path = '/' . ltrim((string) $_GET['qk_path'], '/');
}
// After internal rewrite, REQUEST_URI may become /dl-r2/index.php — recover original.
if (preg_match('#^/dl-r2/index\.php(/.*)?$#', $path, $m)) {
	$path = isset($m[1]) && $m[1] !== '' ? $m[1] : '/';
}
if (!empty($_SERVER['REDIRECT_URL']) && str_starts_with((string) $_SERVER['HTTP_HOST'], 'download.')) {
	$redir = parse_url((string) $_SERVER['REDIRECT_URL'], PHP_URL_PATH);
	if (is_string($redir) && $redir !== '' && !str_starts_with($redir, '/dl-r2/')) {
		$path = $redir;
	}
}

if ($path === '/' || $path === '/index.php') {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['ok' => true, 'service' => 'qeike-download', 'via' => 'download.qeike.com'], JSON_UNESCAPED_UNICODE);
	exit;
}

$upstreamPath = $path;
$allowed = false;
if (preg_match('#^/version\.json$#', $path)) {
	$allowed = true;
} elseif (preg_match('#^/Qeike-Setup-[0-9]+\.[0-9]+\.[0-9]+\.exe$#', $path)) {
	$allowed = true;
} elseif (preg_match('#^/Qeike-[0-9]+\.[0-9]+\.[0-9]+\.apk$#', $path)) {
	$allowed = true;
} elseif (preg_match('#^/android/(Qeike-[0-9]+\.[0-9]+\.[0-9]+\.apk)$#', $path, $m)) {
	// R2 stores APKs at bucket root; map /android/… → /…
	$allowed = true;
	$upstreamPath = '/' . $m[1];
} elseif (preg_match('#^/trial/Qeike-Setup-[0-9]+\.[0-9]+\.[0-9]+\.exe(\.sha256)?$#', $path)) {
	// Trial-only installer (not live /version.json)
	$allowed = true;
} elseif (preg_match('#^/trial/notes-[0-9]+\.[0-9]+\.[0-9]+\.txt$#', $path)) {
	$allowed = true;
}

if (!$allowed) {
	http_response_code(404);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
	exit;
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
	ob_end_clean();
}
set_time_limit(0);
ignore_user_abort(true);

$url = $UPSTREAM . $upstreamPath;
$reqHeaders = [
	'User-Agent: qeike-download-proxy/1.0',
	'Accept: */*',
];
if (!empty($_SERVER['HTTP_RANGE'])) {
	$reqHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}
if (!empty($_SERVER['HTTP_IF_RANGE'])) {
	$reqHeaders[] = 'If-Range: ' . $_SERVER['HTTP_IF_RANGE'];
}

$headersSent = false;
$headerFn = static function ($ch, string $headerLine) use (&$headersSent): int {
	$len = strlen($headerLine);
	$trim = rtrim($headerLine, "\r\n");
	if ($trim === '') {
		return $len;
	}
	if (preg_match('#^HTTP/\S+\s+(\d+)#i', $trim, $m)) {
		http_response_code((int) $m[1]);
		$headersSent = true;
		return $len;
	}
	$lower = strtolower($trim);
	foreach ([
		'transfer-encoding:',
		'connection:',
		'keep-alive:',
		'proxy-',
		'content-encoding:',
		'set-cookie:',
		'server:',
		'cf-',
		'nel:',
		'report-to:',
		'alt-svc:',
	] as $skip) {
		if (str_starts_with($lower, $skip)) {
			return $len;
		}
	}
	// Pass through length/range/type/cache validators
	header($trim, false);
	return $len;
};

$writeFn = static function ($ch, string $data): int {
	echo $data;
	if (function_exists('flush')) {
		flush();
	}
	return strlen($data);
};

$ch = curl_init($url);
if ($ch === false) {
	http_response_code(502);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['ok' => false, 'error' => 'proxy_init_failed'], JSON_UNESCAPED_UNICODE);
	exit;
}

$opts = [
	CURLOPT_HTTPHEADER => $reqHeaders,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_MAXREDIRS => 5,
	CURLOPT_CONNECTTIMEOUT => 20,
	CURLOPT_TIMEOUT => 0,
	CURLOPT_HEADERFUNCTION => $headerFn,
	CURLOPT_WRITEFUNCTION => $writeFn,
	CURLOPT_BUFFERSIZE => 262144,
	CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
];
if ($method === 'HEAD') {
	$opts[CURLOPT_NOBODY] = true;
	$opts[CURLOPT_CUSTOMREQUEST] = 'HEAD';
} else {
	$opts[CURLOPT_HTTPGET] = true;
}
curl_setopt_array($ch, $opts);

$ok = curl_exec($ch);
$errno = curl_errno($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($ok === false) {
	if (!$headersSent) {
		http_response_code(502);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['ok' => false, 'error' => 'proxy_error'], JSON_UNESCAPED_UNICODE);
	}
	exit;
}

// Ensure JSON type for version.json if upstream omitted it
if ($upstreamPath === '/version.json' && !$headersSent && $status >= 200 && $status < 300) {
	header('Content-Type: application/json; charset=utf-8');
}
