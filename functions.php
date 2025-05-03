<?php
function dier ($msg) {
	print("<pre>");
	print(var_dump($msg));
	print("</pre>");
	exit(0);
}

function normalize_special_characters($text) {
	$normalized_text = preg_replace_callback('/[^\x20-\x7E]/u', function ($match) {
		$char = $match[0];
		$normalized_char = iconv('UTF-8', 'ASCII//TRANSLIT', $char);
		return $normalized_char !== false ? $normalized_char : '';
	}, $text);

	$normalized_text = mb_strtolower($normalized_text, 'UTF-8');

	return $normalized_text;
}

function isValidPath($path) {
	return strpos($path, '..') === false && strpos($path, '/') !== 0 && strpos($path, '\\') !== 0;
}

function print_file_metadata() {
	$file_info = $_GET['file_info'] ?? '';

	if (strpos($file_info, '..') !== false || realpath($file_info) === false) {
		echo json_encode(["error" => "Invalid file path"]);
		return;
	}

	if (!file_exists($file_info)) {
		echo json_encode(["error" => "File not found"]);
		return;
	}

	$metadata = [];

	$metadata['name'] = basename($file_info);
	$metadata['size'] = filesize($file_info);
	$metadata['last_modified'] = date("Y-m-d H:i:s", filemtime($file_info));
	$metadata['created_at'] = date("Y-m-d H:i:s", filectime($file_info));

	if (exif_imagetype($file_info)) {
		$image_info = getimagesize($file_info);
		$metadata['image_width'] = $image_info[0];
		$metadata['image_height'] = $image_info[1];
		$metadata['image_type'] = image_type_to_mime_type($image_info[2]);

		$exif_data = exif_read_data($file_info, 'IFD0');
		if ($exif_data) {
			// exif als HTML-Tabelle codieren
			$exif_html = '<table style="border-collapse:collapse;">';
			foreach ($exif_data as $k => $v) {
				if (is_array($v)) {
					$v = implode(', ', $v);
				}
				$exif_html .= '<tr><td style="padding:2px 5px;"><b>' . htmlspecialchars($k) . '</b></td><td style="padding:2px 5px;">' . htmlspecialchars((string)$v) . '</td></tr>';
			}
			$exif_html .= '</table>';
			$metadata['exif'] = $exif_html;
		}
	}

	echo json_encode($metadata);
}

