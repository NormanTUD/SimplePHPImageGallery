<?php

$images_path = "/docker_images/";

if (is_dir($images_path)) {
	chdir($images_path);
}

$filepath = $_GET["path"];

if (preg_match("/\.\./", $filepath)) {
	echo "Nice try";
	exit(1);
}

if(file_exists($filepath)) {
	if(is_file($filepath)) {
		$contents = file_get_contents($filepath);
		$expires = 14 * 60*60*24;

		header("Content-Type: image/jpeg");
		header("Content-Length: " . strlen($contents));
		header("Cache-Control: public", true);
		header("Pragma: public", true);
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT', true);

		echo $contents;
		exit;
	} else {
		echo "File is a folder";
		exit(2);
	}
} else {
	echo "File not found";
	exit(1);
}
?>
