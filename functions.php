<?php
	if (!defined("THUMBNAIL_MAX_SIZE")) {
		define("THUMBNAIL_MAX_SIZE", 640);
	}
	if (!defined("VIDEO_THUMBNAIL_MAX_SIZE")) {
		define("VIDEO_THUMBNAIL_MAX_SIZE", 480);
	}
	if (!defined("THUMBNAIL_CACHE_VERSION")) {
		define("THUMBNAIL_CACHE_VERSION", "2");
	}
	if (!defined("SEARCH_INDEX_TTL")) {
		define("SEARCH_INDEX_TTL", 300);
	}
	if (!defined("SEARCH_INDEX_STAMP_TTL")) {
		define("SEARCH_INDEX_STAMP_TTL", 5);
	}

	function gallery_base_path() {
		return realpath('.');
	}

	function is_helper_asset($path) {
		$name = strtolower(basename($path));
		return in_array($name, [
			'loading.gif',
			'error.svg',
			'search.svg',
			'save.svg',
			'icon.svg',
			'icon-192.png',
			'icon-512.png',
		], true);
	}

	function is_path_within_gallery($path) {
		if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) {
			return false;
		}

		$base = gallery_base_path();
		$real = realpath($path);

		if ($base === false || $real === false) {
			return false;
		}

		return $real === $base || strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
	}

	function thumbnail_size_from_request($size) {
		$allowed = [320, 640, 1280];
		$size = (int) $size;
		return in_array($size, $allowed, true) ? $size : THUMBNAIL_MAX_SIZE;
	}

	function thumbnail_format_from_request($isVideo = false) {
		if ($isVideo) {
			return "gif";
		}

		$accept = $_SERVER["HTTP_ACCEPT"] ?? "";

		if (function_exists("imageavif") && stripos($accept, "image/avif") !== false) {
			return "avif";
		}

		if (function_exists("imagewebp") && stripos($accept, "image/webp") !== false) {
			return "webp";
		}

		return "jpg";
	}

	function thumbnail_content_type($format) {
		$types = [
			"jpg" => "image/jpeg",
			"webp" => "image/webp",
			"avif" => "image/avif",
			"gif" => "image/gif",
		];
		return $types[$format] ?? "application/octet-stream";
	}

	function thumbnail_cache_file($md5, $size, $format) {
		$cacheFolder = './thumbnails_cache/';
		if (is_dir("/docker_tmp/")) {
			$cacheFolder = "/docker_tmp/";
		}
		return $cacheFolder . $md5 . "_" . (int) $size . "_" . THUMBNAIL_CACHE_VERSION . "." . $format;
	}

	function send_thumbnail_file($path, $format) {
		header('Cache-Control: public, max-age=31536000, immutable');
		header('Vary: Accept');
		header('X-Content-Type-Options: nosniff');
		header('Content-Type: ' . thumbnail_content_type($format));
		readfile($path);
		exit;
	}

	function encode_thumbnail_image($image, $format) {
		if ($format === "avif" && function_exists("imageavif")) {
			ob_start();
			$ok = @imageavif($image, null, 55);
			$data = ob_get_clean();
			if ($ok && $data !== false && $data !== "") {
				return [$data, "avif"];
			}
		}

		if ($format === "webp" && function_exists("imagewebp")) {
			ob_start();
			$ok = @imagewebp($image, null, 86);
			$data = ob_get_clean();
			if ($ok && $data !== false && $data !== "") {
				return [$data, "webp"];
			}
		}

		ob_start();
		imagejpeg($image, null, 88);
		return [ob_get_clean(), "jpg"];
	}
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
		header('Content-Type: application/json; charset=utf-8');

		$file_info = $_GET['file_info'] ?? '';

		if (strpos($file_info, '..') !== false || !is_path_within_gallery($file_info)) {
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

			if (in_array($image_info[2], [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], true)) {
				$exif_data = @exif_read_data($file_info, 'IFD0');
				if ($exif_data) {
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
		}

		echo json_encode($metadata);
	}

	function getImagesInDirectory($directory) {
		$images = [];

		assert(is_dir($directory), "The directory does not exist or is not readable: $directory");

		try {
			$files = scandir($directory);
		} catch (Exception $e) {
			warn("Error while reading the folder $directory: " . $e->getMessage());
			return $images;
		}

		foreach ($files as $file) {
			if ($file !== '.' && $file !== '..') {
				$filePath = $directory . '/' . $file;
				if (is_dir($filePath)) {
					foreach (getImagesInDirectory($filePath) as $nestedImage) {
						$images[] = $nestedImage;
					}
				} else {
					$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
					if (in_array($extension, $GLOBALS["FILETYPES"]) && !is_helper_asset($filePath)) {
						$images[] = $filePath;
					}
				}
			}
		}

		return $images;
	}

	function removeFileExtensionFromString ($string) {
		$string = preg_replace("/\.[a-z0-9_]*$/i", "", $string);
		return $string;
	}

	function searchImageFileByTXT($txtFilePath) {
		$pathWithoutExtension = removeFileExtensionFromString($txtFilePath);
		$fp = dirname($txtFilePath);

		$files = scandir($fp);

		foreach ($files as $file) {
			$fullFilePath = $fp . DIRECTORY_SEPARATOR . $file;

			if (is_file($fullFilePath)) {
				$fileExtension = strtolower(pathinfo($fullFilePath, PATHINFO_EXTENSION));

				if (
					$fileExtension !== 'txt' &&
					$pathWithoutExtension == removeFileExtensionFromString($fullFilePath) &&
					preg_match($GLOBALS["valid_file_ending_regex"], $fullFilePath)
				) {
					return $fullFilePath;
				}
			}
		}

		return null;
	}

	function sortAndCleanString($inputString) {
		$cleanedString = trim(preg_replace('/\s+/', ' ', $inputString));

		$wordsArray = explode(' ', $cleanedString);
		sort($wordsArray);

		$sortedString = implode(' ', $wordsArray);

		return $sortedString;
	}

	function file_or_folder_matches($file_or_folder, $searchTermLower, $normalized, $allowFuzzy, $maxDistance = 3) {
		$searchWords = preg_split('/\s+/', $searchTermLower, -1, PREG_SPLIT_NO_EMPTY);
		$normalizedName = normalize_special_characters($file_or_folder);

		$originalWords = preg_split('/\s+/', strtolower($file_or_folder), -1, PREG_SPLIT_NO_EMPTY);
		$normalizedWords = preg_split('/\s+/', strtolower($normalizedName), -1, PREG_SPLIT_NO_EMPTY);

		foreach ($searchWords as $word) {
			$foundInOriginal = stripos($file_or_folder, $word) !== false;
			$foundInNormalized = stripos($normalizedName, $word) !== false;

			if (!($foundInOriginal || $foundInNormalized)) {
				if ($allowFuzzy) {
					$matchFound = false;

					foreach ($originalWords as $ow) {
						if (levenshtein($word, $ow) <= $maxDistance) {
							$matchFound = true;
							break;
						}
					}

					if (!$matchFound) {
						foreach ($normalizedWords as $nw) {
							if (levenshtein($word, $nw) <= $maxDistance) {
								$matchFound = true;
								break;
							}
						}
					}

					if (!$matchFound) {
						return false;
					}
				} else {
					return false;
				}
			}
		}

		return true;
	}

	function searchFiles($fp, $searchTerm, $allowFuzzy) {
		if (!is_dir($fp)) return [];

		$files = @scandir($fp);
		if (!is_array($files)) return [];

		$results = [];

		$searchTermLower = strtolower(sortAndCleanString($searchTerm));
		$normalized = normalize_special_characters($searchTerm);

		// Build a lookup of image/video files by their name without extension.
		// This avoids scanning the same directory again for every .txt file.
		$imageFileByBase = [];
		foreach ($files as $candidate) {
			if (in_array($candidate, ['.', '..', '.git', 'thumbnails_cache'])) continue;

			$candidatePath = $fp . '/' . $candidate;
			if (!is_file($candidatePath)) continue;

			$candidateExtension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
			if (in_array($candidateExtension, $GLOBALS["FILETYPES"]) && !is_helper_asset($candidatePath)) {
				$base = removeFileExtensionFromString($candidate);
				if (!isset($imageFileByBase[$base])) {
					$imageFileByBase[$base] = $candidatePath;
				}
			}
		}

		foreach ($files as $file) {
			if (in_array($file, ['.', '..', '.git', 'thumbnails_cache'])) continue;

			$filePath = $fp . '/' . $file;
			$fileNameBase = preg_replace("/\.(jpe?g|png|gif)$/i", "", $file);
			$fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

			if (is_dir($filePath)) {
				if (file_or_folder_matches($fileNameBase, $searchTermLower, $normalized, $allowFuzzy)) {
					$randomImage = getRandomImageFromSubfolders($filePath);
					$results[] = [
						'path' => $filePath,
						'type' => 'folder',
						'thumbnail' => $randomImage['path'] ?? ''
					];
				}
				foreach (searchFiles($filePath, $searchTerm, $allowFuzzy) as $nestedResult) {
					$results[] = $nestedResult;
				}
				continue;
			}

			if ($fileExtension === 'txt') {
				$content = strtolower(file_get_contents($filePath));
				$cleanContent = sortAndCleanString($content);
				if (file_or_folder_matches($cleanContent, $searchTermLower, $normalized, $allowFuzzy)) {
					$imageFilePath = $imageFileByBase[removeFileExtensionFromString($file)] ?? null;
					if ($imageFilePath) {
						$results[] = [
							'path' => $imageFilePath,
							'type' => 'file'
						];
					}
				}
				continue;
			}

			if (in_array($fileExtension, $GLOBALS["FILETYPES"]) && !is_helper_asset($filePath)) {
				if (file_or_folder_matches($fileNameBase, $searchTermLower, $normalized, $allowFuzzy)) {
					$results[] = [
						'path' => $filePath,
						'type' => 'file'
					];
				}
			}
		}

		return $results;
	}

	function getCoord( $expr ) {
		$expr_p = explode( '/', $expr );

		if (count($expr_p) == 2) {
			if ($expr_p[1]) {
				return $expr_p[0] / $expr_p[1];
			}
		}

		return null;
	}

	function convertLatLonToDecimal($degrees, $minutes, $seconds, $direction) {
		$decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

		if ($direction == 'S' || $direction == 'W') {
			$decimal *= -1;
		}

		return $decimal;
	}

	function get_image_gps($img) {
		$cacheFolder = './thumbnails_cache/';

		if (is_dir("/docker_tmp/")) {
			$cacheFolder = "/docker_tmp/";
		}

		$cache_file = "$cacheFolder/".md5($img).".json";

		if (file_exists($cache_file)) {
			return json_decode(file_get_contents($cache_file), true);
		}

		$save_null = function () use ($cache_file) {
			@file_put_contents($cache_file, 'null');
			return null;
		};

		$exif = @exif_read_data($img, 0, true);

		if (empty($exif["GPS"])) {
			return $save_null();
		}

		$latitude = array();
		$longitude = array();

		if (empty($exif['GPS']['GPSLatitude'])) {
			return $save_null();
		}

		$coordinates = ['latitude' => 'GPSLatitude', 'longitude' => 'GPSLongitude'];
		$direction = ['latitude' => 'GPSLatitudeRef', 'longitude' => 'GPSLongitudeRef'];

		foreach ($coordinates as $coord => $coord_key) {
			for ($i = 0; $i < 3; $i++) {
				$value = getCoord($exif['GPS'][$coord_key][$i]);
				if (is_null($value)) {
					return $save_null();
				}
				$$coord[$i] = $value;
			}
			${$coord . '_direction'} = $exif['GPS'][$direction[$coord]];
		}

		$res = array(
			"latitude" => convertLatLonToDecimal($latitude['degrees'] ?? $latitude[0], $latitude['minutes'] ?? $latitude[1], $latitude['seconds'] ?? $latitude[2], $latitude_direction),
			"longitude" => convertLatLonToDecimal($longitude['degrees'] ?? $longitude[0], $longitude['minutes'] ?? $longitude[1], $longitude['seconds'] ?? $longitude[2], $longitude_direction)
		);

		if (is_nan($res["latitude"]) || is_nan($res["longitude"])) {
			return $save_null();
		}

		$json_data = json_encode($res);

		file_put_contents($cache_file, $json_data);

		return $res;
	}

	function is_valid_image_or_video_file($filepath) {
		if (!is_file($filepath) || !is_readable($filepath)) {
			return false;
		}

		$ext_map = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'mp4'  => 'video/mp4',
			'webm' => 'video/webm',
			'mov'  => 'video/quicktime',
			'mkv'  => 'video/x-matroska',
			'avi'  => 'video/x-msvideo'
		];

		$dot = strrpos($filepath, '.');

		$ext = $dot === false ? '' : strtolower(substr($filepath, $dot + 1));

		if (isset($ext_map[$ext])) {
			$type = $ext_map[$ext];
		} else {
			if (!isset($GLOBALS["finfo"])) {
				$GLOBALS['finfo'] = finfo_open(FILEINFO_MIME_TYPE);
			}
			$type = finfo_file($GLOBALS['finfo'], $filepath);
		}

		return isset($type) && in_array($type, $GLOBALS["allowed_content_types"]);
	}

	function display_gallery($fp) {
		if (preg_match("/\.\./", $fp) || !is_path_within_gallery($fp)) {
			print("Invalid folder");
			return [];
		}

		if (!is_dir($fp)) {
			print("Folder not found");
			return [];
		}

		// Avoid paths like ".//folder" from a root path of "./".
		$fp = rtrim($fp, '/');
		if ($fp === '') {
			$fp = '.';
		}

		$files = scandir($fp);
		$thumbnails = [];
		$images = [];

		foreach ($files as $file) {
			if ($file === '.' || $file === '..' || preg_match("/^\./", $file) || $file === "thumbnails_cache") {
				continue;
			}

			$filePath = $fp . '/' . $file;

			if (is_dir($filePath)) {
				$folderImages = getImagesInFolder($filePath);

				if (empty($folderImages)) {
					$randomImage = getRandomImageFromSubfolders($filePath);
					$thumbnailPath = $randomImage ? $randomImage['path'] : '';
				} else {
					$randomImage = $folderImages[array_rand($folderImages)];
					$thumbnailPath = $randomImage['path'];
				}

				$thumbnails[] = [
					'name' => $file,
					'thumbnail' => $thumbnailPath,
					'path' => $filePath,
					"counted_thumbs" => count($folderImages)
				];
			} else {
				$fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

				if(file_exists("$fp/$file") && is_readable("$fp/$file") && filesize("$fp/$file")) {
					if (in_array($fileExtension, $GLOBALS["FILETYPES"])) {
						$images[] = [
							'name' => $file,
							'path' => $filePath
						];
					}
				}
			}
		}

		function sortByName(array &$array): void {
			usort($array, function ($a, $b) {
				return strcmp($a['name'], $b['name']);
			});
		}

		sortByName($thumbnails);
		sortByName($images);

		$items = [];

		foreach ($thumbnails as $thumbnail) {
			if (preg_match($GLOBALS["valid_file_ending_regex"], $thumbnail["thumbnail"])) {
				$items[] = ["type" => "folder", "item" => $thumbnail];
			}
		}

		foreach ($images as $image) {
			$is_valid_file = is_valid_image_or_video_file($image["path"]);
			$normalized_image_path = preg_replace("#/+#", "/", $image["path"]);
			if (is_file($image["path"]) && $is_valid_file && !is_helper_asset($image["path"])) {
				$items[] = ["type" => "file", "item" => $image];
			}
		}

		$total_items = count($items);
		$offset = max(0, (int) ($_GET["offset"] ?? 0));
		$limit = isset($_GET["limit"]) ? max(0, min(500, (int) $_GET["limit"])) : 0;

		if ($limit > 0) {
			$items = array_slice($items, $offset, $limit);
		}

		$next_offset = $offset + count($items);
		$has_more = $limit > 0 && $next_offset < $total_items;

		header('X-Gallery-Total: ' . $total_items);
		header('X-Gallery-Offset: ' . $offset);
		header('X-Gallery-Next-Offset: ' . $next_offset);
		header('X-Gallery-Has-More: ' . ($has_more ? '1' : '0'));

		foreach ($items as $entry) {
			foreach (create_thumbnail_html($entry["item"], $entry["type"] === "folder") as $html_part) {
				echo $html_part;
			}
		}
	}

	function create_thumbnail_html($item, $is_folder = false) {
		$path = $item["path"];
		$thumb = $is_folder ? $item["thumbnail"] : $path;
		$file_hash = get_hash_from_file($thumb);
		$cacheDir = __DIR__ . '/thumbnails_cache';
		$cache_key = $is_folder ? 'folder:' . $path : $path;
		$html_cache_file = $cacheDir . '/html_v2_' . sha1($cache_key) . '.html';

		// Falls HTML-Cache existiert, direkt laden
		if (file_exists($html_cache_file) && !$is_folder) {
			$cached = @file_get_contents($html_cache_file);
			// Alte Caches ohne gültiges style-Attribut einmalig neu erzeugen.
			if ($cached !== false && strpos($cached, '<img width:') === false) {
				return [$cached];  // einzeilig oder mehrzeilig, kompatibel
			}
		}

		// Sonst HTML dynamisch generieren
		if (file_exists("thumbnails_cache/{$file_hash}_" . THUMBNAIL_MAX_SIZE . "_" . THUMBNAIL_CACHE_VERSION . ".jpg")) {
			list($width, $height) = getImageSizeWithRotation("thumbnails_cache/{$file_hash}_" . THUMBNAIL_MAX_SIZE . "_" . THUMBNAIL_CACHE_VERSION . ".jpg");
			$wh_string = ($width && $height) ? " style=\"width:{$width}px; height:{$height}px; object-fit:contain;\" " : "";
		} else {
			$wh_string = getResizedImageStyle($thumb);
		}

		$extra_attributes = $is_folder
			? 'title="' . $item["counted_thumbs"] . ' images" data-line="XXX"'
			: 'data-line="YYY" data-hash="' . $file_hash . '" data-mtime="' . (int) @filemtime($path) . '"';

		if (!$is_folder && !preg_match("/\.m(?:ov|p4)$/i", $path)) {
			$gps = get_image_gps($path);
			if ($gps) {
				$extra_attributes .= " data-latitude='{$gps["latitude"]}' data-longitude='{$gps["longitude"]}'";
			}
		}

		$preview_url = 'index.php?preview=' . urlencode($thumb);
		$srcset_attr = '';

		if (!preg_match("/\.m(?:ov|p4)$/i", $thumb)) {
			$srcset_parts = [];
			foreach ([320, 640, 1280] as $srcset_size) {
				$srcset_parts[] = $preview_url . '&amp;size=' . $srcset_size . ' ' . $srcset_size . 'w';
			}
			$srcset_attr = ' data-srcset="' . implode(', ', $srcset_parts) . '" sizes="(max-width: 760px) 48vw, 220px"';
		}

		$img_tag = '<img ' . $wh_string . ' ' . $extra_attributes . $srcset_attr . ' loading="lazy" draggable="false" src="loading.gif" alt="Loading..." class="loading-thumbnail" data-original-url="' . $preview_url . '">';

		if (!is_dir($cacheDir)) {
			mkdir($cacheDir, 0777, true);
		}

		$path_attr = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');

		if ($is_folder) {
			$html = '<a data-href="' . urlencode($path) . '" data-path="' . $path_attr . '" class="img_element"><div class="thumbnail_folder">'
				. $img_tag
				. '<h3>' . htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') . '</h3>'
				. '<span class="checkmark">✅</span>'
				. "</div></a>\n";
		} else {
			$html = '<div class="thumbnail" data-path="' . $path_attr . '">'
				. $img_tag
				. '<span class="checkmark">✅</span>'
				. "</div>\n";
		}

		// Cache schreiben
		file_put_contents($html_cache_file, $html);

		return [$html];
	}

	function getImageSizeWithRotation($path) {
		if (!file_exists($path)) return false;

		// Cache-Pfad vorbereiten
		$hash = sha1(realpath($path));
		$cacheDir = __DIR__ . '/thumbnails_cache';
		$cacheFile = "$cacheDir/size_with_rotation_$hash.json";

		// Falls Cache existiert, direkt lesen
		if (file_exists($cacheFile)) {
			$data = @file_get_contents($cacheFile);
			if ($data !== false) {
				$decoded = json_decode($data, true);
				if (is_array($decoded) && isset($decoded[0]) && isset($decoded[1])) {
					return $decoded;
				}
			}
		}

		// Bildgröße ermitteln
		$size = getimagesize($path);
		if ($size === false) return false;

		list($width, $height) = $size;

		// Rotation via EXIF prüfen
		if (function_exists('exif_read_data') && in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II])) {
			$exif = @exif_read_data($path);
			if (!empty($exif['Orientation']) && in_array($exif['Orientation'], [5, 6, 7, 8])) {
				list($width, $height) = [$height, $width];
			}
		}

		// Cache-Verzeichnis anlegen falls nötig
		if (!is_dir($cacheDir)) {
			mkdir($cacheDir, 0777, true);
		}

		// Ergebnis cachen
		file_put_contents($cacheFile, json_encode([$width, $height]));

		return [$width, $height];
	}

	function getImagesInFolder($folderPath) {
		$folderFiles = @scandir($folderPath);

		if (is_bool($folderFiles)) {
			return [];
		}

		$images = [];

		foreach ($folderFiles as $file) {
			if ($file === '.' || $file === '..') {
				continue;
			}

			$filePath = $folderPath . '/' . $file;

			$fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

			if (in_array($fileExtension, $GLOBALS["FILETYPES"]) && !is_helper_asset($filePath)) {
				$images[] = [
					'name' => $file,
					'path' => $filePath
				];
			}
		}

		return $images;
	}

	function getRandomImageFromSubfolders($folderPath) {
		$subfolders = glob($folderPath . '/*', GLOB_ONLYDIR);

		if (count($subfolders) == 0) {
			$images = getImagesInFolder($folderPath);

			if (!empty($images)) {
				return $images[array_rand($images)];
			}
		} else {
			foreach ($subfolders as $subfolder) {
				$images_in_folder = getImagesInFolder($subfolder);
				if (count($images_in_folder)) {
					$images[] = $images_in_folder[0];
				}
			}

			if (!empty($images)) {
				return $images[array_rand($images)];
			}
		}

		return null;
	}

	function get_hash_from_file($path) {
		$path = preg_replace("/\/+/", "/", $path);
		$what_to_hash = $path;
		$md5 = md5($what_to_hash);

		return $md5;
	}

	function is_cached_already ($path) {
		$isVideo = preg_match("/\.(mov|mp4)$/i", $path);
		$ending = $isVideo ? "gif" : "jpg";
		$size = $isVideo ? VIDEO_THUMBNAIL_MAX_SIZE : THUMBNAIL_MAX_SIZE;

		$md5 = get_hash_from_file($path);

		$cacheFolder = './thumbnails_cache/';

		if (is_dir("/docker_tmp/")) {
			$cacheFolder = "/docker_tmp/";
		}

		$path = $cacheFolder . $md5 . "_" . $size . "_" . THUMBNAIL_CACHE_VERSION . "." . $ending;

		if (file_exists($path) && is_valid_image_or_video_file($path)) {
			return true;
		}

		return false;
	}

	function listAllUncachedImageFiles($directory) {
		if ($directory == "./.git" || $directory == "./docker_tmp" || $directory == "./thumbnails_cache") {
			return [];
		}

		$imageList = [];

		$files = scandir($directory);

		foreach ($files as $file) {
			if ($file != '.' && $file != '..' && $file != "loading.gif") {
				$filePath = $directory . '/' . $file;

				if (is_dir($filePath)) {
					foreach (listAllUncachedImageFiles($filePath) as $subDirectoryImage) {
						$imageList[] = $subDirectoryImage;
					}
				} else {
					if (is_valid_image_or_video_file($filePath) && !is_helper_asset($filePath) && !is_cached_already($filePath)) {
						$imageList[] = $filePath;
					}
				}
			}
		}

		return $imageList;
	}

	function getResizedImageStyle($imagePath, $thumbnailMaxWidth = THUMBNAIL_MAX_SIZE, $thumbnailMaxHeight = THUMBNAIL_MAX_SIZE) {
		if (preg_match("/\.m(ov|p4)$/i", $imagePath)) {
			return;
		}

		list($width, $height, $type) = getimagesize($imagePath);

		if (!$width || !$height) {
			return '';
		}

		if (function_exists('exif_read_data') && in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II])) {
			$exif = @exif_read_data($imagePath);
			if (!empty($exif['Orientation'])) {
				if (in_array($exif['Orientation'], [6, 8])) {
					list($width, $height) = [$height, $width];
				}
			}
		}

		$scale = min($thumbnailMaxWidth / $width, $thumbnailMaxHeight / $height, 1);
		$thumbWidth = max(1, intval(round($width * $scale)));
		$thumbHeight = max(1, intval(round($height * $scale)));

		return " style=\"width: {$thumbWidth}px; height: {$thumbHeight}px; object-fit: contain;\" ";
	}


	function gallery_tree_signature() {
		try {
			$root = '.';
			$parts = ['.:' . (int) @filemtime($root)];

			$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
			$filter = new RecursiveCallbackFilterIterator($directory, function ($current) {
				if ($current->isDir()) {
					$name = $current->getFilename();
					return !in_array($name, ['.git', 'thumbnails_cache', 'node_modules'], true);
				}
				return true;
			});
			$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

			foreach ($iterator as $item) {
				if ($item->isDir()) {
					$parts[] = $item->getPathname() . ':' . (int) $item->getMTime();
				}
			}

			sort($parts);
			return sha1(implode('|', $parts));
		} catch (Throwable $e) {
			return '';
		}
	}

	function build_search_index_entries($directory = '.') {
		$entries = [];
		$files = @scandir($directory);
		if (!is_array($files)) {
			return $entries;
		}

		$txt_by_base = [];
		foreach ($files as $file) {
			if ($file === '.' || $file === '..' || str_starts_with($file, '.')) {
				continue;
			}

			$path = preg_replace('#/+#', '/', $directory . '/' . $file);
			if (!is_file($path)) {
				continue;
			}

			if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'txt') {
				$content = @file_get_contents($path);
				if ($content !== false) {
					$txt_by_base[removeFileExtensionFromString($file)] = normalize_special_characters(sortAndCleanString($content));
				}
			}
		}

		foreach ($files as $file) {
			if ($file === '.' || $file === '..' || str_starts_with($file, '.')) {
				continue;
			}

			$path = preg_replace('#/+#', '/', $directory . '/' . $file);

			if (is_dir($path)) {
				if (in_array($file, ['thumbnails_cache', 'node_modules'], true)) {
					continue;
				}

				$entries[] = [
					'type' => 'folder',
					'path' => $path,
					'name' => $file,
					'content' => '',
					'mtime' => (int) @filemtime($path),
				];

				foreach (build_search_index_entries($path) as $entry) {
					$entries[] = $entry;
				}
				continue;
			}

			$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
			if (!in_array($extension, $GLOBALS["FILETYPES"], true) || is_helper_asset($path)) {
				continue;
			}

			$base = removeFileExtensionFromString($file);
			$entries[] = [
				'type' => 'file',
				'path' => $path,
				'name' => $base,
				'content' => $txt_by_base[$base] ?? '',
				'mtime' => (int) @filemtime($path),
			];
		}

		return $entries;
	}

	function search_index_db_path() {
		$dir = is_dir('/docker_tmp/') ? '/docker_tmp' : (__DIR__ . '/thumbnails_cache');
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		return $dir . '/search_index.sqlite';
	}

	function open_search_index_database() {
		if (!class_exists('SQLite3')) {
			return null;
		}

		try {
			$db = new SQLite3(search_index_db_path(), SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
			$db->busyTimeout(5000);
			$db->exec('PRAGMA journal_mode=WAL');
			$db->exec('PRAGMA synchronous=NORMAL');
			$db->exec('CREATE TABLE IF NOT EXISTS entries (
				path TEXT PRIMARY KEY,
				type TEXT NOT NULL,
				name TEXT NOT NULL,
				name_normalized TEXT NOT NULL,
				content TEXT NOT NULL DEFAULT "",
				mtime INTEGER NOT NULL DEFAULT 0
			)');
			$db->exec('CREATE INDEX IF NOT EXISTS idx_entries_type ON entries(type)');
			$db->exec('CREATE TABLE IF NOT EXISTS meta (
				key TEXT PRIMARY KEY,
				value TEXT NOT NULL
			)');
			return $db;
		} catch (Throwable $e) {
			return null;
		}
	}

	function search_index_meta_get($db, $key) {
		$stmt = $db->prepare('SELECT value FROM meta WHERE key = :key');
		$stmt->bindValue(':key', $key, SQLITE3_TEXT);
		$result = $stmt->execute();
		$row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
		return $row ? $row['value'] : null;
	}

	function search_index_meta_set($db, $key, $value) {
		$stmt = $db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (:key, :value)');
		$stmt->bindValue(':key', $key, SQLITE3_TEXT);
		$stmt->bindValue(':value', (string) $value, SQLITE3_TEXT);
		$stmt->execute();
	}

	function sync_search_index($db, $force = false) {
		$now = time();
		$checked = (int) (search_index_meta_get($db, 'checked_at') ?? 0);

		if (!$force && $now - $checked < SEARCH_INDEX_STAMP_TTL) {
			return;
		}

		$signature = gallery_tree_signature();
		$stored_signature = search_index_meta_get($db, 'signature') ?? '';
		$built = (int) (search_index_meta_get($db, 'built_at') ?? 0);

		if (!$force && $signature !== '' && $stored_signature === $signature && $now - $built < SEARCH_INDEX_TTL) {
			search_index_meta_set($db, 'checked_at', $now);
			return;
		}

		if (!$db->exec('BEGIN IMMEDIATE')) {
			return;
		}

		$now = time();
		$stored_signature = search_index_meta_get($db, 'signature') ?? '';
		$built = (int) (search_index_meta_get($db, 'built_at') ?? 0);

		if (!$force && $signature !== '' && $stored_signature === $signature && $now - $built < SEARCH_INDEX_TTL) {
			search_index_meta_set($db, 'checked_at', $now);
			$db->exec('COMMIT');
			return;
		}

		try {
			$entries = build_search_index_entries('.');

			$db->exec('DELETE FROM entries');
			$stmt = $db->prepare('INSERT OR REPLACE INTO entries
				(path, type, name, name_normalized, content, mtime)
				VALUES (:path, :type, :name, :name_normalized, :content, :mtime)');

			foreach ($entries as $entry) {
				$stmt->bindValue(':path', $entry['path'], SQLITE3_TEXT);
				$stmt->bindValue(':type', $entry['type'], SQLITE3_TEXT);
				$stmt->bindValue(':name', $entry['name'], SQLITE3_TEXT);
				$stmt->bindValue(':name_normalized', normalize_special_characters($entry['name']), SQLITE3_TEXT);
				$stmt->bindValue(':content', $entry['content'], SQLITE3_TEXT);
				$stmt->bindValue(':mtime', (int) $entry['mtime'], SQLITE3_INTEGER);
				$stmt->execute();
				$stmt->reset();
			}

			search_index_meta_set($db, 'built_at', time());
			search_index_meta_set($db, 'signature', $signature);
			search_index_meta_set($db, 'checked_at', time());
			$db->exec('COMMIT');
		} catch (Throwable $e) {
			@$db->exec('ROLLBACK');
		}
	}

	function search_result_from_index_row($row) {
		$type = $row['type'] ?? '';
		$path = $row['path'] ?? '';

		if (!is_path_within_gallery($path) || is_helper_asset($path)) {
			return null;
		}

		if ($type === 'folder') {
			if (!is_dir($path)) {
				return null;
			}
			$randomImage = getRandomImageFromSubfolders($path);
			return [
				'path' => $path,
				'type' => 'folder',
				'thumbnail' => $randomImage['path'] ?? '',
				'mtime' => (int) ($row['mtime'] ?? 0),
			];
		}

		if (!is_file($path)) {
			return null;
		}

		return [
			'path' => $path,
			'type' => 'file',
			'mtime' => (int) ($row['mtime'] ?? @filemtime($path)),
		];
	}

	function search_sqlite_results($db, $searchTerm, $allowFuzzy, $searchType = 'all') {
		$force = isset($_GET['refresh_index']) && $_GET['refresh_index'] === '1';
		sync_search_index($db, $force);

		$type_sql = '1 = 1';
		if ($searchType === 'file') {
			$type_sql = "type = 'file'";
		} elseif ($searchType === 'folder') {
			$type_sql = "type = 'folder'";
		}

		$results = [];

		if (!$allowFuzzy) {
			$normalized_term = normalize_special_characters(sortAndCleanString($searchTerm));
			$words = preg_split('/\s+/', $normalized_term, -1, PREG_SPLIT_NO_EMPTY);

			if (empty($words)) {
				return [];
			}

			$conditions = [];
			$params = [];
			foreach ($words as $index => $word) {
				$placeholder = ':word' . $index;
				$conditions[] = '(instr(name_normalized, ' . $placeholder . ') > 0 OR instr(content, ' . $placeholder . ') > 0)';
				$params[$placeholder] = $word;
			}

			$sql = 'SELECT path, type, name, mtime FROM entries WHERE ' . $type_sql
				. ' AND (' . implode(' AND ', $conditions) . ')'
				. " ORDER BY CASE type WHEN 'folder' THEN 0 ELSE 1 END, path ASC";
			$stmt = $db->prepare($sql);
			foreach ($params as $key => $value) {
				$stmt->bindValue($key, $value, SQLITE3_TEXT);
			}
			$result = $stmt->execute();

			while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
				$result_row = search_result_from_index_row($row);
				if ($result_row) {
					$results[] = $result_row;
				}
			}
			return $results;
		}

		$searchTermLower = strtolower(sortAndCleanString($searchTerm));
		$normalized = normalize_special_characters($searchTerm);
		$result = $db->query('SELECT path, type, name, content, mtime FROM entries WHERE ' . $type_sql
			. " ORDER BY CASE type WHEN 'folder' THEN 0 ELSE 1 END, path ASC");

		while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
			$name_matches = file_or_folder_matches($row['name'] ?? '', $searchTermLower, $normalized, true);
			$content_matches = ($row['type'] ?? '') === 'file'
				&& file_or_folder_matches($row['content'] ?? '', $searchTermLower, $normalized, true);

			if (!$name_matches && !$content_matches) {
				continue;
			}

			$result_row = search_result_from_index_row($row);
			if ($result_row) {
				$results[] = $result_row;
			}
		}

		return $results;
	}

	function search_indexed_results($searchTerm, $allowFuzzy, $searchType = 'all') {
		$db = open_search_index_database();
		if (!$db) {
			return searchFiles('.', $searchTerm, $allowFuzzy);
		}

		try {
			return search_sqlite_results($db, $searchTerm, $allowFuzzy, $searchType);
		} finally {
			$db->close();
		}
	}

	function search_and_print_results ($searchTerm, $allowFuzzy, $searchType = "all") {
		$results = array();
		try {
			$results["files"] = search_indexed_results($searchTerm, $allowFuzzy, $searchType);
		} catch (Throwable $e) {
			$results["files"] = searchFiles('.', $searchTerm, $allowFuzzy);
		}

		$seen = [];
		$unique_results = [];
		foreach ($results["files"] as $result) {
			$type = $result["type"] ?? "";
			if ($searchType === "file" && $type !== "file") {
				continue;
			}
			if ($searchType === "folder" && $type !== "folder") {
				continue;
			}

			$key = $type . ":" . ($result["path"] ?? "");
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$unique_results[] = $result;
		}
		$results["files"] = $unique_results;

		$i = 0;
		foreach ($results["files"] as $this_result) {
			$path = $this_result["path"];
			$type = $this_result["type"];

			if ($type == "file") {
				$gps = get_image_gps($path);
				if ($gps) {
					$results["files"][$i]["latitude"] = $gps["latitude"];
					$results["files"][$i]["longitude"] = $gps["longitude"];
				}
				$results["files"][$i]["hash"] = md5($path);
				$results["files"][$i]["mtime"] = (int) @filemtime($path);
			}

			$i++;
		}

		header('Content-type: application/json; charset=utf-8');
		echo json_encode($results);
	}

	function print_geolist($geolist) {
		$files = [];

		if ($geolist === ".") {
			$geolist = "./";
		}

		if ($geolist && !preg_match("/\.\./", $geolist) && preg_match("/^\.\//", $geolist) && is_dir($geolist)) {
			$files = getImagesInDirectory($geolist);
		} else {
			http_response_code(400);
			die("Wrongly formed geolist: " . htmlspecialchars($geolist));
		}

		// Cache-Pfad basierend auf einem Hash der Dateiliste
		$filelist_str = implode('|', $files); // mit Trennzeichen, damit Reihenfolge nicht kollidiert
		$filelist_hash = sha1($filelist_str); // stabiler Hash (SHA-1 oder auch md5 möglich)
		$cache_file = "thumbnails_cache/geolist_$filelist_hash.json";

		// Wenn Cache existiert, direkt ausgeben
		if (file_exists($cache_file)) {
			header('Content-type: application/json; charset=utf-8');
			readfile($cache_file);
			return;
		}

		$s = array();

		foreach ($files as $file) {
			$hash = md5($file);
			$gps = get_image_gps($file);

			if ($gps) {
				$s[] = array(
					'url' => $file,
					"latitude" => $gps["latitude"],
					"longitude" => $gps["longitude"],
					"hash" => $hash
				);
			}
		}

		// JSON erzeugen und speichern
		$json = json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_dir("thumbnails_cache")) {
			mkdir("thumbnails_cache", 0777, true);
		}
		file_put_contents($cache_file, $json);

		header('Content-type: application/json; charset=utf-8');
		print $json;
	}

	function list_all () {
		$allImageFiles = listAllUncachedImageFiles('.');

		header('Content-type: application/json; charset=utf-8');
		echo json_encode($allImageFiles);
	}

	function create_preview($imagePath, $requestedSize = THUMBNAIL_MAX_SIZE) {
		$isVideo = preg_match("/\.(mov|mp4)$/i", $imagePath);
		$thumbnailMaxSize = $isVideo ? VIDEO_THUMBNAIL_MAX_SIZE : thumbnail_size_from_request($requestedSize);

		if (!preg_match("/\.\./", $imagePath) && is_path_within_gallery($imagePath) && is_file($imagePath)) {
			$format = thumbnail_format_from_request($isVideo);
			$md5 = get_hash_from_file($imagePath);
			$cachedThumbnailPath = thumbnail_cache_file($md5, $thumbnailMaxSize, $format);

			if (file_exists($cachedThumbnailPath) && filesize($cachedThumbnailPath) > 0) {
				send_thumbnail_file($cachedThumbnailPath, $format);
			}

			if ($isVideo) {
				$ffprobe = "ffprobe -v error -select_streams v:0 -show_entries format=duration -of csv=p=0 " . escapeshellarg($imagePath);
				$duration = floatval(shell_exec($ffprobe));

				$gifDuration = 6;
				$startTime = 0;
				$frameRate = 8;

				$video_filter = "fps=$frameRate,scale=$thumbnailMaxSize:$thumbnailMaxSize:force_original_aspect_ratio=decrease";
				$ffmpeg = "ffmpeg -y -i " . escapeshellarg($imagePath)
					. " -vf " . escapeshellarg($video_filter)
					. " -t " . (int) $gifDuration
					. " -ss " . (int) $startTime
					. " " . escapeshellarg($cachedThumbnailPath);

				fwrite($GLOBALS["stderr"], "ffmpeg command:\n$ffmpeg");
				shell_exec($ffmpeg);

				if (file_exists($cachedThumbnailPath) && filesize($cachedThumbnailPath) > 0) {
					send_thumbnail_file($cachedThumbnailPath, "gif");
				}

				http_response_code(500);
				echo "Fehler beim Erstellen des Video-GIFs.";
				exit;
			}

			list($width, $height, $type) = getimagesize($imagePath);

			switch ($type) {
				case IMAGETYPE_JPEG:
					$image = imagecreatefromjpeg($imagePath);
					break;
				case IMAGETYPE_PNG:
					$image = imagecreatefrompng($imagePath);
					break;
				case IMAGETYPE_GIF:
					$image = imagecreatefromgif($imagePath);
					break;
				case IMAGETYPE_WEBP:
					$image = function_exists("imagecreatefromwebp") ? imagecreatefromwebp($imagePath) : false;
					break;
				case IMAGETYPE_AVIF:
					$image = function_exists("imagecreatefromavif") ? imagecreatefromavif($imagePath) : false;
					break;
				default:
					$image = false;
			}

			if (!$image) {
				http_response_code(415);
				echo 'Unsupported image type.';
				exit;
			}

			$exif = @exif_read_data($imagePath);
			if (!empty($exif['Orientation'])) {
				switch ($exif['Orientation']) {
				case 3:
					$image = imagerotate($image, 180, 0);
					break;
				case 6:
					$image = imagerotate($image, -90, 0);
					list($width, $height) = [$height, $width];
					break;
				case 8:
					$image = imagerotate($image, 90, 0);
					list($width, $height) = [$height, $width];
					break;
				}
			}

			$scale = min($thumbnailMaxSize / $width, $thumbnailMaxSize / $height, 1);
			$thumbnailWidth = max(1, (int) round($width * $scale));
			$thumbnailHeight = max(1, (int) round($height * $scale));

			$thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);
			$backgroundColor = imagecolorallocate($thumbnail, 255, 255, 255);
			imagefill($thumbnail, 0, 0, $backgroundColor);
			imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $thumbnailWidth, $thumbnailHeight, intval($width), intval($height));

			[$data, $actualFormat] = encode_thumbnail_image($thumbnail, $format);
			$cachedThumbnailPath = thumbnail_cache_file($md5, $thumbnailMaxSize, $actualFormat);
			file_put_contents($cachedThumbnailPath, $data);

			header('Cache-Control: public, max-age=31536000, immutable');
			header('Vary: Accept');
			header('X-Content-Type-Options: nosniff');
			header('Content-Type: ' . thumbnail_content_type($actualFormat));
			echo $data;

			imagedestroy($image);
			imagedestroy($thumbnail);
		} else {
			http_response_code(404);
			echo 'File not found.';
		}
	}

	function create_zip () {
		$zipname = 'gallery.zip';
		$zip = new ZipArchive;
		$zipFile = tempnam(sys_get_temp_dir(), 'zip');
		// tempnam() creates an empty file; ZipArchive expects to create it itself.
		@unlink($zipFile);
		$exit_code = 0;

		if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
			if (isset($_GET['folder'])) {
				$folders = is_array($_GET['folder']) ? $_GET['folder'] : [$_GET['folder']];
				foreach ($folders as $folder) {
					if (isValidPath($folder) && is_path_within_gallery($folder) && is_dir($folder)) {
						$realFolderPath = realpath($folder);

						$files = new RecursiveIteratorIterator(
							new RecursiveDirectoryIterator($realFolderPath, RecursiveDirectoryIterator::SKIP_DOTS),
							RecursiveIteratorIterator::SELF_FIRST
						);

						foreach ($files as $file) {
							if (!$file->isDir()) {
								$filePath = $file->getRealPath();

								if (is_path_within_gallery($filePath) && preg_match($GLOBALS["valid_file_ending_regex"], $filePath)) {
									$cwd = getcwd();

									$relativePath = str_replace($cwd . DIRECTORY_SEPARATOR, '', $filePath);
									$relativePath = str_replace($realFolderPath . DIRECTORY_SEPARATOR, '', $relativePath);

									$zip->addFile($filePath, $relativePath);
								}
							}
						}
					} else {
						echo 'Invalid folder: ' . htmlspecialchars($folder);
						exit(0);
					}
				}
			}

			if (isset($_GET['img'])) {
				$images = is_array($_GET['img']) ? $_GET['img'] : [$_GET['img']];
				foreach ($images as $img) {
					if (isValidPath($img) && is_path_within_gallery($img) && is_file($img)) {
						if (preg_match($GLOBALS["valid_file_ending_regex"], $img)) {
							$zip->addFile($img, basename($img));
						}
					} else {
						echo 'Invalid image: ' . htmlspecialchars($img);
						exit(0);
					}
				}
			}

			$zip->close();

			header('Content-Type: application/zip');
			header('Content-Disposition: attachment; filename="' . $zipname . '"');
			header('Content-Length: ' . filesize($zipFile));

			readfile($zipFile);
			unlink($zipFile);
		} else {
			echo 'Failed to create zip file.';
			$exit_code = 1;
		}

		return $exit_code;
	}

	function show_links_if_available() {
		$filename = 'links.txt';

		if (file_exists($filename)) {
			$file = fopen($filename, 'r');

			if ($file) {
				while (($line = fgets($file)) !== false) {
					$parts = explode(',', $line);

					$link = trim($parts[0]);
					$text = trim($parts[1]);

					echo '<a target="_blank" rel="noopener noreferrer" href="' . htmlspecialchars($link) . '">' . htmlspecialchars($text) . '</a><br>';
				}

				fclose($file);
			}
		}
	}
?>
