<?php

/**
 * @file
 * A Backblaze B2 native API server for the E2E lane, written from the public API documentation.
 *
 * Backblaze ships no emulator, so this is a reading of the B2 documentation and not B2 itself. It
 * models what `strata_b2` depends on: every upload creates a version, a delete removes one version,
 * a download is addressed by bucket and name, a listing is ordered by name and paged by
 * `nextFileName`, and a large file is assembled from parts. A passing run proves Strata against
 * that reading and nothing more.
 *
 * Run with `php -S 0.0.0.0:8080 server.php`. State lives in a single JSON index beside the file
 * bodies under B2_DATA, guarded by a lock, so concurrent requests are safe and a restart keeps
 * everything.
 */

declare(strict_types=1);

$api = '/b2api/v2/';

$data = getenv('B2_DATA') ?: '/data';
$keyId = getenv('B2_KEY_ID') ?: 'fake-key-id';
$appKey = getenv('B2_APPLICATION_KEY') ?: 'fake-application-key';
$bucketName = getenv('B2_BUCKET_NAME') ?: 'strata-b2';
$bucketId = getenv('B2_BUCKET_ID') ?: 'bucket0000000001';
$minimumPart = (int) (getenv('B2_MINIMUM_PART') ?: 1000);
$token = hash('sha256', $keyId . ':' . $appKey);

if (!is_dir($data . '/files')) {
	mkdir($data . '/files', 0777, true);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$origin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

/**
 * Answers and stops.
 *
 * @param int $status
 *   The HTTP status.
 * @param array<mixed>|string $body
 *   An array is sent as JSON.
 * @param array<string, string> $headers
 *   Extra response headers.
 */
function reply(int $status, array|string $body = '', array $headers = []): never
{
	http_response_code($status);

	foreach ($headers as $name => $value) {
		header($name . ': ' . $value);
	}

	if (is_array($body)) {
		header('Content-Type: application/json');
		$body = (string) json_encode($body);
	}

	echo $body;

	exit();
}

/**
 * Answers with a B2-shaped error.
 *
 * @param int $status
 *   The HTTP status.
 * @param string $code
 *   B2's own error code.
 * @param string $message
 *   What went wrong.
 */
function fail(int $status, string $code, string $message): never
{
	reply($status, ['status' => $status, 'code' => $code, 'message' => $message]);
}

/**
 * Runs a callback holding the index lock, so concurrent requests cannot interleave a write.
 *
 * @param string $data
 *   The data directory.
 * @param callable(array<string, mixed>): array<string, mixed> $change
 *   Receives the index and returns the index to store.
 *
 * @return array<string, mixed>
 *   The index after the change.
 */
function locked(string $data, callable $change): array
{
	$handle = fopen($data . '/index.lock', 'c');
	flock($handle, LOCK_EX);

	$file = $data . '/index.json';
	$index = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
	$index = is_array($index)
		? $index
		: ['files' => [], 'large' => [], 'failures' => [], 'counter' => 0];
	$index = $change($index);

	file_put_contents($file, json_encode($index));
	flock($handle, LOCK_UN);
	fclose($handle);

	return $index;
}

/**
 * The latest version of each name, which is what a listing and a download see.
 *
 * @param array<string, mixed> $index
 *   The index.
 *
 * @return array<string, array<string, mixed>>
 *   Name to its newest visible version.
 */
function current_versions(array $index): array
{
	$byName = [];

	foreach ($index['files'] as $file) {
		$name = (string) $file['fileName'];

		if (
			!isset($byName[$name]) ||
			$file['uploadTimestamp'] >= $byName[$name]['uploadTimestamp']
		) {
			$byName[$name] = $file;
		}
	}

	ksort($byName, SORT_STRING);

	return array_filter($byName, static fn(array $file): bool => $file['action'] !== 'hide');
}

/**
 * Takes the next scripted failure, so a test can make the next request fail.
 *
 * @param string $data
 *   The data directory.
 *
 * @return int
 *   The status to answer with, or 0 for none.
 */
function scripted_failure(string $data): int
{
	$status = 0;

	locked($data, static function (array $index) use (&$status): array {
		if ($index['failures'] !== []) {
			$status = (int) array_shift($index['failures']);
		}

		return $index;
	});

	return $status;
}

#region Test controls

if ($path === '/__fake/reset') {
	foreach (glob($data . '/files/*') ?: [] as $body) {
		unlink($body);
	}

	locked(
		$data,
		static fn(array $index): array => [
			'files' => [],
			'large' => [],
			'failures' => [],
			'counter' => $index['counter'],
		],
	);

	reply(200, ['reset' => true]);
}

if ($path === '/__fake/fail') {
	parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);

	$count = max(1, (int) ($query['count'] ?? 1));
	$status = (int) ($query['status'] ?? 503);

	locked($data, static function (array $index) use ($count, $status): array {
		$index['failures'] = array_merge($index['failures'], array_fill(0, $count, $status));

		return $index;
	});

	reply(200, ['queued' => $count, 'status' => $status]);
}

if ($path === '/__fake/versions') {
	reply(200, [
		'files' => array_values(locked($data, static fn(array $index): array => $index)['files']),
	]);
}

#endregion

if ($path === $api . 'b2_authorize_account') {
	$sent = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

	if ($sent !== 'Basic ' . base64_encode($keyId . ':' . $appKey)) {
		fail(401, 'bad_auth_token', 'Invalid authorization token');
	}

	reply(200, [
		'accountId' => 'fakeaccount01',
		'authorizationToken' => $token,
		'apiUrl' => $origin,
		'downloadUrl' => $origin,
		'recommendedPartSize' => max($minimumPart, 100000),
		'absoluteMinimumPartSize' => $minimumPart,
		'allowed' => [
			'capabilities' => [
				'listBuckets',
				'listFiles',
				'readFiles',
				'writeFiles',
				'deleteFiles',
			],
			'bucketId' => $bucketId,
			'bucketName' => $bucketName,
			'namePrefix' => null,
		],
	]);
}

#region Downloads

if (str_starts_with($path, '/file/')) {
	if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== $token) {
		fail(401, 'bad_auth_token', 'Invalid authorization token');
	}

	$status = scripted_failure($data);

	if ($status > 0) {
		fail($status, 'service_unavailable', 'scripted failure');
	}

	$parts = explode('/', substr($path, strlen('/file/')), 2);
	$name = rawurldecode($parts[1] ?? '');
	$versions = current_versions(locked($data, static fn(array $index): array => $index));

	if (rawurldecode($parts[0]) !== $bucketName || !isset($versions[$name])) {
		fail(404, 'not_found', 'File not present: ' . $name);
	}

	$file = $versions[$name];
	$bytes = (string) file_get_contents($data . '/files/' . $file['fileId']);
	$headers = [
		'Content-Type' => (string) $file['contentType'],
		'X-Bz-File-Id' => (string) $file['fileId'],
		'X-Bz-File-Name' => rawurlencode($name),
		'X-Bz-Content-Sha1' => (string) $file['contentSha1'],
		'X-Bz-Upload-Timestamp' => (string) $file['uploadTimestamp'],
	];

	foreach ((array) $file['fileInfo'] as $key => $value) {
		$headers['X-Bz-Info-' . $key] = (string) $value;
	}

	$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

	if ($method === 'GET' && preg_match('/^bytes=(\d+)-(\d+)$/', $range, $match) === 1) {
		$start = (int) $match[1];
		$end = min((int) $match[2], strlen($bytes) - 1);

		if ($start >= strlen($bytes)) {
			fail(416, 'range_not_satisfiable', 'Range not satisfiable');
		}

		$slice = substr($bytes, $start, $end - $start + 1);
		$headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, strlen($bytes));
		$headers['Content-Length'] = (string) strlen($slice);

		reply(206, $slice, $headers);
	}

	$headers['Content-Length'] = (string) strlen($bytes);

	reply(200, $method === 'HEAD' ? '' : $bytes, $headers);
}

#endregion

#region Uploads

if (str_starts_with($path, '/upload/') || str_starts_with($path, '/upload-part/')) {
	$isPart = str_starts_with($path, '/upload-part/');

	if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== $token) {
		fail(401, 'bad_auth_token', 'Invalid upload authorization token');
	}

	$status = scripted_failure($data);

	if ($status > 0) {
		fail($status, 'service_unavailable', 'scripted failure');
	}

	$body = (string) file_get_contents('php://input');
	$sha1 = strtolower((string) ($_SERVER['HTTP_X_BZ_CONTENT_SHA1'] ?? ''));

	if (strlen($body) !== (int) ($_SERVER['CONTENT_LENGTH'] ?? -1)) {
		fail(400, 'bad_request', 'Content-Length does not match the body');
	}
	if ($sha1 !== '' && $sha1 !== 'do_not_verify' && $sha1 !== sha1($body)) {
		fail(400, 'bad_request', 'Sha1 did not match data received');
	}

	if ($isPart) {
		$fileId = substr($path, strlen('/upload-part/'));
		$number = (int) ($_SERVER['HTTP_X_BZ_PART_NUMBER'] ?? 0);

		locked($data, static function (array $index) use ($fileId, $number, $body, $data): array {
			if (!isset($index['large'][$fileId]) || $number < 1) {
				fail(400, 'bad_request', 'Unknown large file or part number');
			}

			file_put_contents($data . '/files/' . $fileId . '.part' . $number, $body);
			$index['large'][$fileId]['parts'][$number] = sha1($body);

			return $index;
		});

		reply(200, [
			'fileId' => $fileId,
			'partNumber' => $number,
			'contentLength' => strlen($body),
			'contentSha1' => sha1($body),
		]);
	}

	$name = rawurldecode((string) ($_SERVER['HTTP_X_BZ_FILE_NAME'] ?? ''));

	if ($name === '') {
		fail(400, 'bad_request', 'Missing X-Bz-File-Name');
	}

	$info = [];

	foreach ($_SERVER as $header => $value) {
		if (str_starts_with($header, 'HTTP_X_BZ_INFO_')) {
			$name = strtolower(str_replace('_', '-', substr($header, strlen('HTTP_X_BZ_INFO_'))));
			$info[$name] = rawurldecode((string) $value);
		}
	}

	$entry = [];

	locked($data, static function (array $index) use (
		&$entry,
		$name,
		$body,
		$info,
		$data,
		$bucketId,
	): array {
		$index['counter']++;
		$fileId = sprintf('4_z%020d', $index['counter']);

		file_put_contents($data . '/files/' . $fileId, $body);

		$entry = [
			'fileId' => $fileId,
			'fileName' => $name,
			'bucketId' => $bucketId,
			'action' => 'upload',
			'contentLength' => strlen($body),
			'contentSha1' => sha1($body),
			'contentType' => (string) ($_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream'),
			'fileInfo' => $info,
			'uploadTimestamp' => (int) round(microtime(true) * 1000) * 1 + $index['counter'],
		];
		$index['files'][$fileId] = $entry;

		return $index;
	});

	reply(200, $entry);
}

#endregion

if ($method !== 'POST' || !str_starts_with($path, $api)) {
	fail(404, 'not_found', 'No such call: ' . $path);
}

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== $token) {
	fail(401, 'bad_auth_token', 'Invalid authorization token');
}

$status = scripted_failure($data);

if ($status > 0) {
	fail($status, 'service_unavailable', 'scripted failure');
}

$call = substr($path, strlen($api));
$payload = json_decode((string) file_get_contents('php://input'), true);
$payload = is_array($payload) ? $payload : [];

#region Calls

if ($call === 'b2_get_upload_url' || $call === 'b2_get_upload_part_url') {
	$isPart = $call === 'b2_get_upload_part_url';
	$target = $isPart ? '/upload-part/' . (string) ($payload['fileId'] ?? '') : '/upload/slot';

	reply(200, [
		$isPart ? 'fileId' : 'bucketId' => $isPart
			? (string) ($payload['fileId'] ?? '')
			: (string) ($payload['bucketId'] ?? $bucketId),
		'uploadUrl' => $origin . $target,
		'authorizationToken' => $token,
	]);
}

if ($call === 'b2_list_file_names') {
	$prefix = (string) ($payload['prefix'] ?? '');
	$start = (string) ($payload['startFileName'] ?? '');
	$limit = max(1, min(10000, (int) ($payload['maxFileCount'] ?? 100)));
	$delimiter = (string) ($payload['delimiter'] ?? '');
	$files = [];
	$next = null;
	$seen = [];

	foreach (
		current_versions(locked($data, static fn(array $index): array => $index))
		as $name => $file
	) {
		if (
			($prefix !== '' && !str_starts_with($name, $prefix)) ||
			($start !== '' && $name < $start)
		) {
			continue;
		}

		if ($delimiter !== '') {
			$rest = substr($name, strlen($prefix));
			$cut = strpos($rest, $delimiter);

			if ($cut !== false) {
				$folder = $prefix . substr($rest, 0, $cut + strlen($delimiter));

				if (isset($seen[$folder])) {
					continue;
				}

				$seen[$folder] = true;
				$file = [
					'fileName' => $folder,
					'action' => 'folder',
					'contentLength' => 0,
					'uploadTimestamp' => 0,
				];
			}
		}

		if (count($files) >= $limit) {
			$next = $name;

			break;
		}

		$files[] = $file;
	}

	reply(200, ['files' => $files, 'nextFileName' => $next]);
}

if ($call === 'b2_delete_file_version') {
	$fileId = (string) ($payload['fileId'] ?? '');
	$found = false;

	locked($data, static function (array $index) use ($fileId, &$found, $data): array {
		if (isset($index['files'][$fileId])) {
			$found = true;
			unset($index['files'][$fileId]);
			@unlink($data . '/files/' . $fileId);
		}

		return $index;
	});

	if (!$found) {
		fail(400, 'file_not_present', 'File not present: ' . $fileId);
	}

	reply(200, ['fileId' => $fileId, 'fileName' => (string) ($payload['fileName'] ?? '')]);
}

if ($call === 'b2_start_large_file') {
	$fileId = '';

	locked($data, static function (array $index) use (&$fileId, $payload): array {
		$index['counter']++;
		$fileId = sprintf('4_zlarge%016d', $index['counter']);
		$index['large'][$fileId] = [
			'fileName' => (string) ($payload['fileName'] ?? ''),
			'contentType' => (string) ($payload['contentType'] ?? 'application/octet-stream'),
			'fileInfo' => (array) ($payload['fileInfo'] ?? []),
			'parts' => [],
		];

		return $index;
	});

	reply(200, ['fileId' => $fileId, 'fileName' => (string) ($payload['fileName'] ?? '')]);
}

if ($call === 'b2_finish_large_file') {
	$fileId = (string) ($payload['fileId'] ?? '');
	$entry = [];

	locked($data, static function (array $index) use (
		$fileId,
		$payload,
		&$entry,
		$data,
		$bucketId,
	): array {
		$large = $index['large'][$fileId] ?? null;
		$sums = array_values((array) ($payload['partSha1Array'] ?? []));

		if ($large === null) {
			fail(400, 'bad_request', 'Unknown large file');
		}

		$body = '';

		foreach ($sums as $position => $sum) {
			$number = $position + 1;

			if (($large['parts'][$number] ?? null) !== $sum) {
				fail(400, 'bad_request', 'Part ' . $number . ' does not match its checksum');
			}

			$body .= (string) file_get_contents($data . '/files/' . $fileId . '.part' . $number);
		}

		foreach (array_keys($large['parts']) as $number) {
			@unlink($data . '/files/' . $fileId . '.part' . $number);
		}

		file_put_contents($data . '/files/' . $fileId, $body);
		unset($index['large'][$fileId]);

		$entry = [
			'fileId' => $fileId,
			'fileName' => $large['fileName'],
			'bucketId' => $bucketId,
			'action' => 'upload',
			'contentLength' => strlen($body),
			'contentSha1' => 'none',
			'contentType' => $large['contentType'],
			'fileInfo' => $large['fileInfo'],
			'uploadTimestamp' => (int) round(microtime(true) * 1000),
		];
		$index['files'][$fileId] = $entry;

		return $index;
	});

	reply(200, $entry);
}

if ($call === 'b2_cancel_large_file') {
	$fileId = (string) ($payload['fileId'] ?? '');

	locked($data, static function (array $index) use ($fileId, $data): array {
		foreach (array_keys($index['large'][$fileId]['parts'] ?? []) as $number) {
			@unlink($data . '/files/' . $fileId . '.part' . $number);
		}

		unset($index['large'][$fileId]);

		return $index;
	});

	reply(200, ['fileId' => $fileId]);
}

if ($call === 'b2_list_buckets') {
	reply(200, [
		'buckets' => [
			['bucketId' => $bucketId, 'bucketName' => $bucketName, 'bucketType' => 'allPrivate'],
		],
	]);
}

#endregion

fail(400, 'bad_request', 'Unsupported call: ' . $call);
