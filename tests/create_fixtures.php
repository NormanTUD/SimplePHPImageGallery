<?php
/**
 * Creates an isolated gallery document root with deterministic example data.
 *
 * Usage: php tests/create_fixtures.php /tmp/gallery-docroot
 */

$source = realpath(__DIR__ . '/..');
$docroot = $argv[1] ?? '';

if ($source === false) {
	fwrite(STDERR, "Could not locate project root\n");
	exit(1);
}

if ($docroot === '') {
	fwrite(STDERR, "Usage: php tests/create_fixtures.php <docroot>\n");
	exit(1);
}

$runtime_files = [
	'index.php',
	'functions.php',
	'get_search_index.php',
	'image.php',
	'functions.js',
	'main.js',
	'style.css',
	'jquery-3.7.1.min.js',
	'leaflet.js',
	'leaflet.markercluster.js',
	'MarkerCluster.Default.css',
	'loading.gif',
	'error.svg',
	'search.svg',
	'save.svg',
];

function ensure_dir(string $path): void {
	if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
		throw new RuntimeException("Could not create directory: $path");
	}
}

function make_image(string $path, int $width, int $height, array $rgb, string $label = ''): void {
	ensure_dir(dirname($path));

	$image = imagecreatetruecolor($width, $height);
	$background = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
	imagefill($image, 0, 0, $background);

	if ($label !== '') {
		$white = imagecolorallocate($image, 255, 255, 255);
		imagestring($image, 4, 8, intdiv($height, 2) - 8, $label, $white);
	}

	$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	if ($extension === 'png') {
		imagepng($image, $path);
	} elseif ($extension === 'gif') {
		imagegif($image, $path);
	} else {
		imagejpeg($image, $path, 85);
	}

	imagedestroy($image);
}

function gps_jpeg_base64(): string {
	return <<<'B64'
/9j/4AAQSkZJRgABAQAAAQABAAD/4QECRXhpZgAATU0AKgAAAAgABwEPAAIAAAAWAAAAYgEQAAIAAAADQ0kAAAEaAAUAAAABAAAAeAEbAAUAAAABAAAAgAEoAAMAAAABAAEAAAITAAMAAAABAAEAAIglAAQAAAABAAAAiAAAAABTaW1wbGVQSFBJbWFnZUdhbGxlcnkAAAAAAQAAAAEAAAABAAAAAQAFAAAAAQAAAAQCAwAAAAEAAgAAAAJOAAAAAAIABQAAAAMAAADKAAMAAgAAAAJFAAAAAAQABQAAAAMAAADiAAAAAAAAADMAAAABAAAAAwAAAAEAAAAkAAAAGQAAAA0AAAABAAAALAAAAAEAAAFlAAAAGf/bAEMADQkKCwoIDQsKCw4ODQ8TIBUTEhITJxweFyAuKTEwLiktLDM6Sj4zNkY3LC1AV0FGTE5SU1IyPlphWlBgSlFST//bAEMBDg4OExETJhUVJk81LTVPT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT//AABEIAHgAoAMBIgACEQEDEQH/xAAZAAEBAAMBAAAAAAAAAAAAAAAAAwECBQT/xAAsEAACAwACAQIEBAcAAAAAAAAAEgECAwQREyExBSJBUWFxgZEUIyRyobHR/8QAGAEBAAMBAAAAAAAAAAAAAAAAAAECAwX/xAAaEQEBAQEAAwAAAAAAAAAAAAAAERIBAkFh/9oADAMBAAIRAxEAPwDxqFKqFMK5MSUKVUKKRJQpVQopElClVCikSUKVUKKRJQpVQopElClVCikSUKVUKKRJQpVQopElClVCikVUKVUKUrSJKFKqFFIkoUqoUUiShSqhRSJKFKqFFIkoUqoUUiShSqhRSJKFKqFFIkoUqoUUiShSqhRSKqFKqFKVpElClVCikSUKVUKKRJQpVQopElClVCikSUKVUKKRJQpVQopElClVCikSUKVUKKRJQpVQopFFClVClK0iShSqhRSJKZrSbWiIj1meoKKb41/nU/uj/ZNTFLfCuZXuPFEzHvFb1mf2iTyTXqepg9/OavxPa1JmLRpMxMffs9nIrlTf4hpOVLzWaTEWj0iZ9/8AJec9Jzxw1CnYpTPPjZaf0sX17tby1mfr11ERHpArTiU5HIjOcu5XxTrWZpH3j/nZGTDjqb+G/h8q/JFl77+vudWca012304+cJlFq1rPdLTM9N+X4Gc4ry+FlSc6Zvyq1skdRPpP0GTDjKFO1pTjWrtS08WK1rKeOtniY9u569fx7MNnnpw8o4+No0pR5tTuZ79P0GfphxlCnp3zinI0pX2reYj9yalURJQpVQpFRElClVCikVUKVUKVrWJKFKqFFIkpmvdbRaPeJ7KKFFIvbnXnSdI4/HjSZ7eKzM9/f1khGt01pPU+WYm0z7+k9hQpPfLvSNsuTfPOM7Z56VrPdYvXtfyM15esW0m8U0jWe7ReO4mTRQo10U/i9fLGkRSIiqIvyr9ujGnK0vlGda0zrW0XrFI66k0UKN9FL8vS9bwmdbaR1e9a9TYnba9tMrzFe8oiK/oFCjXSNNJnTS15iO7TMz0aqVUKRSJKFKqFFIkoUqoUUiqhSqhSlaRJQpVQopElClVCikSUKVUKKRJQpVQopElClVCikSUKVUKKRJQpVQopElClVCikSUKVUKKRRQpVQpStIkoUqoUUiShSqhRSJKFKqFFIkoUqoUUiShSqhRSJKFKqFFIkoUqoUUiShSqhRSJKFKqFFIqoUAq0FCgAFCgAFCgAFCgAFCgAFCgAFCgAFCgAFCgAf//Z
B64;
}

try {
	ensure_dir($docroot);
	ensure_dir($docroot . '/thumbnails_cache');

	foreach ($runtime_files as $file) {
		$source_file = $source . '/' . $file;
		if (!is_file($source_file)) {
			throw new RuntimeException("Missing runtime file: $file");
		}
		if (!copy($source_file, $docroot . '/' . $file)) {
			throw new RuntimeException("Could not copy: $file");
		}
	}

	file_put_contents($docroot . '/links.txt', "https://example.com/, Example Link\n");

	make_image($docroot . '/urlaub/strand/sonne.jpg', 320, 240, [220, 160, 40], 'Sonne');
	make_image($docroot . '/urlaub/strand/meer.png', 300, 200, [40, 120, 220], 'Meer');
	make_image($docroot . '/urlaub/berge/gipfel.jpg', 280, 210, [100, 100, 100], 'Gipfel');
	make_image($docroot . '/urlaub/berge/wanderung.gif', 260, 180, [60, 140, 80], 'Wanderung');
	make_image($docroot . '/stadt/strasse.jpg', 240, 180, [80, 80, 80], 'Strasse');
	make_image($docroot . '/stadt/platz.jpg', 240, 160, [160, 80, 80], 'Platz');
	make_image($docroot . '/special folder/it\'s a test.jpg', 200, 150, [30, 130, 90], 'Special');

	file_put_contents($docroot . '/urlaub/strand/sonne.txt', "urlaub sommer strand sonne");
	file_put_contents($docroot . '/urlaub/berge/gipfel.txt', "urlaub berg gipfel wandern aussicht");
	file_put_contents($docroot . '/stadt/strasse.txt', "stadt strasse auto verkehr");
	file_put_contents($docroot . '/special folder/it\'s a test.txt', "sonderzeichen motiv");

	ensure_dir($docroot . '/gps');
	file_put_contents($docroot . '/gps/dresden.jpg', base64_decode(gps_jpeg_base64()));

	ensure_dir($docroot . '/leer');

	for ($i = 1; $i <= 60; $i++) {
		$number = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
		$color = [40 + ($i * 3) % 200, 60 + ($i * 7) % 180, 80 + ($i * 11) % 160];
		make_image($docroot . '/viele/bild_' . $number . '.jpg', 40, 30, $color);
	}

	$has_ffmpeg = false;
	exec('command -v ffmpeg 2>/dev/null', $ffmpeg_output, $ffmpeg_status);
	if ($ffmpeg_status === 0) {
		ensure_dir($docroot . '/videos');
		$video_path = $docroot . '/videos/test.mp4';
		$command = 'ffmpeg -y -loglevel error -f lavfi -i testsrc=size=160x120:rate=10 -t 1 -pix_fmt yuv420p '
			. escapeshellarg($video_path) . ' 2>&1';
		exec($command, $video_output, $video_status);
		$has_ffmpeg = $video_status === 0 && is_file($video_path) && filesize($video_path) > 0;
	}

	$file_count = 0;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file) {
		if ($file->isFile()) {
			$file_count++;
		}
	}

	echo json_encode([
		'docroot' => $docroot,
		'ffmpeg' => $has_ffmpeg,
		'files' => $file_count,
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
	fwrite(STDERR, "Fixture setup failed: " . $error->getMessage() . PHP_EOL);
	exit(1);
}
