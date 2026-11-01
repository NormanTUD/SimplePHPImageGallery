<?php

$images_path = "/docker_images/";

if (is_dir($images_path)) {
	chdir($images_path);
}

require_once __DIR__ . '/functions.php';

$filepath = $_GET["path"] ?? "";

if (!is_path_within_gallery($filepath) || !is_file($filepath)) {
	http_response_code(404);
	echo "File not found";
	exit(1);
}

$realpath = realpath($filepath);
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
$mime = $finfo ? finfo_file($finfo, $realpath) : mime_content_type($realpath);
if ($finfo) {
	finfo_close($finfo);
}
$allowed = [
	'image/jpeg' => 'image/jpeg',
	'image/png' => 'image/png',
	'image/gif' => 'image/gif',
	'image/webp' => 'image/webp',
	'video/mp4' => 'video/mp4',
	'video/quicktime' => 'video/quicktime',
	'video/webm' => 'video/webm',
];

if (!isset($allowed[$mime])) {
	http_response_code(403);
	echo "Unsupported file type";
	exit(1);
}

$contents = file_get_contents($realpath);
$expires = 14 * 60 * 60 * 24;

header("Content-Type: " . $allowed[$mime]);
header("Content-Length: " . strlen($contents));
header("Cache-Control: public, max-age=1209600");
header("Pragma: public");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + $expires) . " GMT");
header("X-Content-Type-Options: nosniff");
$safe_filename = addcslashes(basename($realpath), "\"\\\r\n");
header("Content-Disposition: inline; filename=\"" . $safe_filename . "\"");

echo $contents;
