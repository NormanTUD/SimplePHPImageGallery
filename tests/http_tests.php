<?php
/**
 * HTTP integration tests for SimplePHPImageGallery.
 *
 * Usage: php tests/http_tests.php <base-url> <docroot>
 */

$base_url = rtrim($argv[1] ?? 'http://127.0.0.1:8765', '/');
$docroot = rtrim($argv[2] ?? '', '/');

if ($docroot === '' || !is_dir($docroot)) {
	fwrite(STDERR, "Usage: php tests/http_tests.php <base-url> <docroot>\n");
	exit(2);
}

$tests = [];

function test(string $name, callable $callback): void {
	global $tests;
	$tests[] = [$name, $callback];
}

function fail_test(string $message): void {
	throw new RuntimeException($message);
}

function assert_true($condition, string $message): void {
	if (!$condition) {
		fail_test($message);
	}
}

function assert_false($condition, string $message): void {
	assert_true(!$condition, $message);
}

function assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		fail_test($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
	}
}

function assert_contains(string $needle, string $haystack, string $message): void {
	if (strpos($haystack, $needle) === false) {
		fail_test($message . " (missing: $needle)");
	}
}

function assert_not_contains(string $needle, string $haystack, string $message): void {
	if (strpos($haystack, $needle) !== false) {
		fail_test($message . " (unexpected: $needle)");
	}
}

function assert_http_status(array $response, int $expected): void {
	if ($response['status'] !== $expected) {
		fail_test('HTTP status ' . $response['status'] . ', expected ' . $expected);
	}
}

function assert_header_contains(array $response, string $header, string $needle): void {
	$header = strtolower($header);
	assert_true(isset($response['headers'][$header]), "Missing response header: $header");
	assert_contains(strtolower($needle), strtolower($response['headers'][$header]), "Unexpected $header header");
}

function http_request(string $path, array $options = []): array {
	global $base_url;

	$url = preg_match('#^https?://#', $path) ? $path : $base_url . $path;
	$ch = curl_init($url);

	$curl_options = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 120,
	];

	if (isset($options['method'])) {
		$curl_options[CURLOPT_CUSTOMREQUEST] = $options['method'];
	}
	if (isset($options['headers'])) {
		$curl_options[CURLOPT_HTTPHEADER] = $options['headers'];
	}
	if (isset($options['post'])) {
		$curl_options[CURLOPT_POST] = true;
		$curl_options[CURLOPT_POSTFIELDS] = $options['post'];
	}

	curl_setopt_array($ch, $curl_options);

	$raw = curl_exec($ch);
	if ($raw === false) {
		$error = curl_error($ch);
		curl_close($ch);
		fail_test("Request failed for $url: $error");
	}

	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);

	$raw_headers = substr($raw, 0, $header_size);
	$body = substr($raw, $header_size);
	$headers = [];

	foreach (preg_split("/\r\n|\n/", trim($raw_headers)) as $line) {
		if (strpos($line, ':') === false) {
			continue;
		}
		[$name, $value] = explode(':', $line, 2);
		$headers[strtolower(trim($name))] = trim($value);
	}

	return [
		'status' => $status,
		'headers' => $headers,
		'body' => $body,
	];
}

function http_json(string $path): array {
	$response = http_request($path);
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'application/json');

	$data = json_decode($response['body'], true);
	assert_true(is_array($data), 'Response is not valid JSON: ' . substr($response['body'], 0, 200));

	return $data;
}

function fixture_path(string $relative): string {
	global $docroot;
	return $docroot . '/' . ltrim($relative, '/');
}

function search_path(string $term, bool $fuzzy = false): string {
	return '/index.php?' . http_build_query([
		'search' => $term,
		'allowFuzzy' => $fuzzy ? 'true' : 'false',
	]);
}

function result_paths(array $search_data): array {
	return array_map(static fn(array $result): string => $result['path'] ?? '', $search_data['files'] ?? []);
}

function find_result(array $results, callable $callback): mixed {
	foreach ($results as $result) {
		if ($callback($result)) {
			return $result;
		}
	}
	return null;
}

test('home page renders the gallery shell and links.txt', function (): void {
	$response = http_request('/index.php');
	assert_http_status($response, 200);
	assert_contains('id="searchInput"', $response['body'], 'Search input missing');
	assert_contains('id="gallery"', $response['body'], 'Gallery container missing');
	assert_contains('Example Link', $response['body'], 'links.txt content missing');
});

test('folder gallery lists subfolders with metadata and valid image markup', function (): void {
	$response = http_request('/index.php?' . http_build_query(['gallery' => './urlaub']));
	assert_http_status($response, 200);
	assert_contains('data-path="./urlaub/berge"', $response['body'], 'berge folder missing');
	assert_contains('data-path="./urlaub/strand"', $response['body'], 'strand folder missing');
	assert_contains('title="2 images"', $response['body'], 'folder image count missing');
	assert_contains('data-original-url="index.php?preview=', $response['body'], 'lazy image URL missing');
	assert_not_contains('<img width:', $response['body'], 'Invalid image style markup');
});

test('helper assets are not rendered as gallery items', function (): void {
	$response = http_request('/index.php?' . http_build_query(['gallery' => './']));
	assert_http_status($response, 200);
	assert_not_contains('preview=.%2Floading.gif', $response['body'], 'loading.gif was rendered as an image');
	assert_not_contains('data-path="index.php?preview=', $response['body'], 'Preview URL was rendered as a folder');
});

test('file gallery lists jpg, png and gif assets with safe data-path attributes', function (): void {
	$response = http_request('/index.php?' . http_build_query(['gallery' => './urlaub/strand']));
	assert_http_status($response, 200);
	assert_contains('data-path="./urlaub/strand/sonne.jpg"', $response['body'], 'JPG missing');
	assert_contains('data-path="./urlaub/strand/meer.png"', $response['body'], 'PNG missing');
	assert_contains('style="width:', $response['body'], 'Image dimensions missing');
	assert_not_contains('data-onclick', $response['body'], 'Unsafe inline click handler still rendered');
});

test('gallery pagination slices large folders', function (): void {
	$first = http_request('/index.php?' . http_build_query(['gallery' => './sehrviele', 'offset' => 0, 'limit' => 50]));
	assert_http_status($first, 200);
	assert_header_contains($first, 'x-gallery-total', '150');
	assert_header_contains($first, 'x-gallery-next-offset', '50');
	assert_header_contains($first, 'x-gallery-has-more', '1');
	assert_same(50, substr_count($first['body'], 'class="thumbnail"'), 'First page has the wrong item count');

	$last = http_request('/index.php?' . http_build_query(['gallery' => './sehrviele', 'offset' => 100, 'limit' => 50]));
	assert_http_status($last, 200);
	assert_header_contains($last, 'x-gallery-next-offset', '150');
	assert_header_contains($last, 'x-gallery-has-more', '0');
	assert_same(50, substr_count($last['body'], 'class="thumbnail"'), 'Last page has the wrong item count');
});

test('paths with spaces and apostrophes are escaped and clickable', function (): void {
	$response = http_request('/index.php?' . http_build_query(['gallery' => './special folder']));
	assert_http_status($response, 200);
	assert_contains('data-path="./special folder/it&#039;s a test.jpg"', $response['body'], 'Escaped special path missing');
});

test('list_all returns image files and excludes helper assets', function (): void {
	$data = http_json('/index.php?list_all=1');
	assert_true(is_array($data), 'list_all did not return an array');
	$matches = array_values(array_filter($data, static fn(string $path): bool => str_ends_with($path, 'sonne.jpg')));
	assert_true(count($matches) === 1, 'Expected exactly one sonne.jpg in list_all');
	assert_false(in_array('./loading.gif', $data, true), 'loading.gif must not be listed');
});

test('filename search returns deduplicated results', function (): void {
	$data = http_json(search_path('sonne'));
	$files = $data['files'] ?? [];
	assert_same(1, count($files), 'Expected one deduplicated result for sonne');
	assert_contains('sonne.jpg', $files[0]['path'], 'Wrong search result path');
});

test('text sidecar search finds the related image', function (): void {
	$data = http_json(search_path('wandern'));
	assert_contains('gipfel.jpg', implode(',', result_paths($data)), 'TXT keyword search did not find gipfel.jpg');
});

test('fuzzy search can be enabled and disabled', function (): void {
	$fuzzy = http_json(search_path('sone', true));
	assert_true(
		find_result($fuzzy['files'] ?? [], static fn(array $r): bool => str_ends_with($r['path'] ?? '', 'sonne.jpg')) !== null,
		'Fuzzy search did not find sonne.jpg'
	);

	$exact = http_json(search_path('sone', false));
	assert_true(
		find_result($exact['files'] ?? [], static fn(array $r): bool => str_ends_with($r['path'] ?? '', 'sonne.jpg')) === null,
		'Non-fuzzy search should not find sonne.jpg'
	);
});

test('folder search returns folders and their files', function (): void {
	$data = http_json(search_path('urlaub'));
	$folder = find_result($data['files'] ?? [], static fn(array $r): bool => ($r['type'] ?? '') === 'folder' && ($r['path'] ?? '') === './urlaub');
	assert_true($folder !== null, 'urlaub folder result missing');
	assert_true(!empty($folder['thumbnail']), 'Folder result has no thumbnail');

	$file = find_result($data['files'] ?? [], static fn(array $r): bool => str_ends_with($r['path'] ?? '', '.jpg'));
	assert_true($file !== null, 'urlaub file results missing');
});

test('search results include GPS coordinates', function (): void {
	$data = http_json(search_path('dresden'));
	$result = find_result($data['files'] ?? [], static fn(array $r): bool => str_ends_with($r['path'] ?? '', 'dresden.jpg'));
	assert_true($result !== null, 'dresden.jpg missing from search');
	assert_true(isset($result['latitude'], $result['longitude']), 'GPS fields missing from search result');
	assert_true(abs($result['latitude'] - 51.0504) < 0.01, 'Latitude looks wrong');
	assert_true(abs($result['longitude'] - 13.7373) < 0.01, 'Longitude looks wrong');
});

test('search can be filtered by result type', function (): void {
	$files = http_json(search_path('urlaub') . '&type=file');
	assert_true(($files['files'] ?? []) !== [], 'File filter returned no results');
	foreach ($files['files'] as $result) {
		assert_same('file', $result['type'] ?? null, 'File filter returned a folder');
	}

	$folders = http_json(search_path('urlaub') . '&type=folder');
	assert_true(($folders['files'] ?? []) !== [], 'Folder filter returned no results');
	foreach ($folders['files'] as $result) {
		assert_same('folder', $result['type'] ?? null, 'Folder filter returned a file');
	}
});

test('search with special characters returns the escaped-path asset', function (): void {
	$data = http_json(search_path('sonderzeichen'));
	assert_contains("it's a test.jpg", implode(',', result_paths($data)), 'Special path search failed');
});

test('empty search result is valid JSON', function (): void {
	$data = http_json(search_path('zzzzzzzzzz'));
	assert_same([], $data['files'] ?? null, 'Expected an empty result list');
});

test('preview generates, caches and reuses a JPEG thumbnail', function (): void {
	global $docroot;
	$relative = './urlaub/strand/sonne.jpg';
	$query = '/index.php?' . http_build_query(['preview' => $relative]);

	$first = http_request($query);
	assert_http_status($first, 200);
	assert_header_contains($first, 'content-type', 'image/jpeg');
	assert_header_contains($first, 'cache-control', 'immutable');
	assert_same("\xFF\xD8", substr($first['body'], 0, 2), 'Preview is not a JPEG');

	$cache_file = $docroot . '/thumbnails_cache/' . md5(preg_replace('/\/+/', '/', $relative)) . '_640_2.jpg';
	assert_true(is_file($cache_file), 'Preview cache file was not created');

	$uncached = http_json('/index.php?list_all=1');
	assert_false(in_array('./urlaub/strand/sonne.jpg', $uncached, true), 'Cached image is still reported as uncached');

	$second = http_request($query);
	assert_http_status($second, 200);
	assert_header_contains($second, 'cache-control', 'immutable');
	assert_same($first['body'], $second['body'], 'Cached preview differs from generated preview');
});

test('responsive thumbnail sizes are generated', function (): void {
	$path = '/index.php?' . http_build_query(['preview' => './hochaufloesend/gross.jpg', 'size' => 320]);
	$response = http_request($path);
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'image/jpeg');

	$size = getimagesizefromstring($response['body']);
	assert_true($size !== false, 'Thumbnail is not a readable image');
	assert_same(320, max($size[0], $size[1]), '320px thumbnail has the wrong size');

	$large = http_request('/index.php?' . http_build_query(['preview' => './hochaufloesend/gross.jpg', 'size' => 1280]));
	assert_http_status($large, 200);
	$large_size = getimagesizefromstring($large['body']);
	assert_true($large_size !== false, 'Large thumbnail is not readable');
	assert_same(1200, max($large_size[0], $large_size[1]), '1280px thumbnail should not upscale beyond the source');
});

test('browsers can receive WebP thumbnails', function (): void {
	$response = http_request(
		'/index.php?' . http_build_query(['preview' => './hochaufloesend/gross.jpg', 'size' => 640]),
		['headers' => ['Accept: image/webp,image/*']]
	);
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'image/webp');
	assert_header_contains($response, 'vary', 'accept');
	assert_same('RIFF', substr($response['body'], 0, 4), 'WebP thumbnail has no RIFF header');
});

test('gallery images expose responsive srcset candidates', function (): void {
	$response = http_request('/index.php?' . http_build_query(['gallery' => './urlaub/strand']));
	assert_http_status($response, 200);
	assert_contains('data-srcset="', $response['body'], 'Responsive srcset attribute missing');
	assert_contains('size=320', $response['body'], '320px srcset candidate missing');
	assert_contains('size=640', $response['body'], '640px srcset candidate missing');
	assert_contains('size=1280', $response['body'], '1280px srcset candidate missing');
});

test('PNG preview is converted to JPEG', function (): void {
	$response = http_request('/index.php?' . http_build_query(['preview' => './urlaub/strand/meer.png']));
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'image/jpeg');
	assert_same("\xFF\xD8", substr($response['body'], 0, 2), 'PNG preview is not a JPEG');
});

test('preview supports spaces and apostrophes in paths', function (): void {
	$response = http_request('/index.php?' . http_build_query(['preview' => "./special folder/it's a test.jpg"]));
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'image/jpeg');
	assert_same("\xFF\xD8", substr($response['body'], 0, 2), 'Special-path preview is not a JPEG');
});

test('video preview is generated when ffmpeg is available', function (): void {
	if (!is_file(fixture_path('videos/test.mp4'))) {
		echo "  (skipped: ffmpeg/video fixture unavailable)\n";
		return;
	}

	$response = http_request('/index.php?' . http_build_query(['preview' => './videos/test.mp4']));
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'image/gif');
	assert_same('GIF', substr($response['body'], 0, 3), 'Video preview is not a GIF');
});

test('file_info returns dimensions and metadata', function (): void {
	$relative = './urlaub/strand/sonne.jpg';
	$data = http_json('/index.php?' . http_build_query(['file_info' => $relative]));
	assert_same('sonne.jpg', $data['name'] ?? null, 'Wrong file name');
	assert_same(filesize(fixture_path('urlaub/strand/sonne.jpg')), $data['size'] ?? null, 'Wrong file size');
	assert_same(320, $data['image_width'] ?? null, 'Wrong image width');
	assert_same(240, $data['image_height'] ?? null, 'Wrong image height');
	assert_same('image/jpeg', $data['image_type'] ?? null, 'Wrong image type');
	assert_true(!empty($data['last_modified']), 'Missing last_modified');
});

test('geolist exposes GPS images for the map', function (): void {
	$data = http_json('/index.php?geolist=./');
	$entry = find_result($data, static fn(array $item): bool => str_ends_with($item['url'] ?? '', 'dresden.jpg'));
	assert_true($entry !== null, 'GPS image missing from geolist');
	assert_true(abs($entry['latitude'] - 51.0504) < 0.01, 'Geolist latitude looks wrong');
	assert_true(abs($entry['longitude'] - 13.7373) < 0.01, 'Geolist longitude looks wrong');

	$dot_response = http_request('/index.php?' . http_build_query(['geolist' => '.']));
	assert_http_status($dot_response, 200);
	assert_header_contains($dot_response, 'content-type', 'application/json');
});

test('single image ZIP download is valid', function (): void {
	$relative = './urlaub/strand/sonne.jpg';
	$response = http_request('/index.php?zip=1&' . http_build_query(['img' => [$relative]]));
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'application/zip');
	assert_same('PK', substr($response['body'], 0, 2), 'Download is not a ZIP');

	$zip_file = tempnam(sys_get_temp_dir(), 'gallery-zip-');
	file_put_contents($zip_file, $response['body']);
	$zip = new ZipArchive();
	assert_true($zip->open($zip_file) === true, 'Could not open downloaded ZIP');
	assert_same(1, $zip->numFiles, 'Unexpected file count in ZIP');
	assert_same(
		file_get_contents(fixture_path('urlaub/strand/sonne.jpg')),
		$zip->getFromName('sonne.jpg'),
		'ZIP content differs from source image'
	);
	$zip->close();
	unlink($zip_file);
});

test('folder ZIP download contains nested images', function (): void {
	$response = http_request('/index.php?zip=1&' . http_build_query(['folder' => ['./urlaub']]));
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'application/zip');

	$zip_file = tempnam(sys_get_temp_dir(), 'gallery-zip-');
	file_put_contents($zip_file, $response['body']);
	$zip = new ZipArchive();
	assert_true($zip->open($zip_file) === true, 'Could not open folder ZIP');

	$names = [];
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$names[] = $zip->getNameIndex($i);
	}
	$zip->close();
	unlink($zip_file);

	assert_true(
		find_result($names, static fn(string $name): bool => str_ends_with($name, 'sonne.jpg')) !== null,
		'Folder ZIP does not contain sonne.jpg'
	);
});

test('invalid ZIP paths are rejected', function (): void {
	$response = http_request('/index.php?zip=1&' . http_build_query(['img' => ['../etc/passwd']]));
	assert_contains('Invalid image', $response['body'], 'Path traversal in ZIP was not rejected');
});

test('image.php serves image bytes with caching headers', function (): void {
	$relative = './urlaub/strand/sonne.jpg';
	$response = http_request('/image.php?' . http_build_query(['path' => $relative]));
	assert_http_status($response, 200);
	assert_header_contains($response, 'content-type', 'image/jpeg');
	assert_header_contains($response, 'cache-control', 'public');
	assert_same(file_get_contents(fixture_path('urlaub/strand/sonne.jpg')), $response['body'], 'image.php returned wrong bytes');
});

test('absolute paths cannot read server files', function (): void {
	$image_response = http_request('/image.php?' . http_build_query(['path' => '/etc/passwd']));
	assert_http_status($image_response, 404);
	assert_not_contains('root:', $image_response['body'], 'image.php leaked /etc/passwd');

	$preview_response = http_request('/index.php?' . http_build_query(['preview' => '/etc/passwd']));
	assert_contains('File not found.', $preview_response['body'], 'Preview accepted an absolute path');

	$metadata_response = http_json('/index.php?' . http_build_query(['file_info' => '/etc/passwd']));
	assert_true(isset($metadata_response['error']), 'file_info accepted an absolute path');

	$gallery_response = http_request('/index.php?' . http_build_query(['gallery' => '/etc']));
	assert_contains('Invalid folder', $gallery_response['body'], 'Gallery accepted an absolute directory');
});

test('security headers are present', function (): void {
	$response = http_request('/index.php');
	assert_http_status($response, 200);
	assert_header_contains($response, 'x-content-type-options', 'nosniff');
	assert_header_contains($response, 'referrer-policy', 'no-referrer');
	assert_header_contains($response, 'x-frame-options', 'SAMEORIGIN');
	assert_header_contains($response, 'content-security-policy', "default-src 'self'");
});

test('folder names are HTML escaped', function (): void {
	$response = http_request('/index.php?' . http_build_query(['gallery' => './']));
	assert_http_status($response, 200);
	assert_contains('&lt;img src=x onerror=alert(1)&gt;', $response['body'], 'Escaped folder name missing');
	assert_not_contains('<img src=x onerror=alert(1)>', $response['body'], 'Folder name was rendered as raw HTML');
});

test('path traversal is rejected for previews and galleries', function (): void {
	$preview = http_request('/index.php?' . http_build_query(['preview' => '../index.php']));
	assert_contains('File not found.', $preview['body'], 'Traversal preview was not rejected');

	$gallery = http_request('/index.php?' . http_build_query(['gallery' => '../']));
	assert_contains('Invalid folder', $gallery['body'], 'Traversal gallery was not rejected');
});

test('PWA manifest, icons and service worker are available', function (): void {
	$manifest_response = http_request('/manifest.webmanifest');
	assert_http_status($manifest_response, 200);
	$manifest = json_decode($manifest_response['body'], true);
	assert_true(is_array($manifest), 'Manifest is not valid JSON');
	assert_same('./', $manifest['start_url'] ?? null, 'Manifest start_url is wrong');
	assert_true(!empty($manifest['icons']), 'Manifest has no icons');

	$icon_192 = http_request('/icon-192.png');
	assert_http_status($icon_192, 200);
	assert_same("\x89PNG", substr($icon_192['body'], 0, 4), '192px icon is not a PNG');

	$icon_512 = http_request('/icon-512.png');
	assert_http_status($icon_512, 200);
	assert_same("\x89PNG", substr($icon_512['body'], 0, 4), '512px icon is not a PNG');

	$sw = http_request('/sw.js');
	assert_http_status($sw, 200);
	assert_contains('SHELL_CACHE', $sw['body'], 'Service worker content missing');
});

test('search index endpoint returns JSON and caches the index', function (): void {
	global $docroot;
	$data = http_json('/get_search_index.php');
	assert_true(is_array($data), 'Search index is not an array');
	$entry = find_result($data, static fn(array $item): bool => str_ends_with($item['p'] ?? '', 'sonne.jpg'));
	assert_true($entry !== null, 'sonne.jpg missing from search index');
	assert_true(is_file($docroot . '/thumbnails_cache/search_index.json'), 'Search index cache file missing');
});

$passed = 0;
$failed = 0;

foreach ($tests as [$name, $callback]) {
	try {
		$callback();
		$passed++;
		echo "PASS $name\n";
	} catch (Throwable $error) {
		$failed++;
		echo "FAIL $name\n";
		echo '     ' . $error->getMessage() . "\n";
	}
}

echo "\nHTTP tests: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
