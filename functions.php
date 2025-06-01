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

function getImagesInDirectory($directory) {
	$images = [];

	assert(is_dir($directory), "Das Verzeichnis existiert nicht oder ist nicht lesbar: $directory");

	try {
		$files = scandir($directory);
	} catch (Exception $e) {
		warn("Fehler beim Lesen des Verzeichnisses $directory: " . $e->getMessage());
		return $images;
	}

	foreach ($files as $file) {
		if ($file !== '.' && $file !== '..') {
			$filePath = $directory . '/' . $file;
			if (is_dir($filePath)) {
				$images = array_merge($images, getImagesInDirectory($filePath));
			} else {
				$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
				if (in_array($extension, $GLOBALS["FILETYPES"])) {
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
			$results = array_merge($results, searchFiles($filePath, $searchTerm, $allowFuzzy));
			continue;
		}

		if ($fileExtension === 'txt') {
			$content = strtolower(file_get_contents($filePath));
			$cleanContent = sortAndCleanString($content);
			if (file_or_folder_matches($cleanContent, $searchTermLower, $normalized, $allowFuzzy)) {
				$imageFilePath = searchImageFileByTXT($filePath);
				if ($imageFilePath) {
					$results[] = [
						'path' => $imageFilePath,
						'type' => 'file'
					];
				}
			}
			continue;
		}

		if (in_array($fileExtension, $GLOBALS["FILETYPES"])) {
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

	$exif = @exif_read_data($img, 0, true);

	if (empty($exif["GPS"])) {
		return null;
	}

	$latitude = array();
	$longitude = array();

	if (empty($exif['GPS']['GPSLatitude'])) {
		return null;
	}

	$coordinates = ['latitude' => 'GPSLatitude', 'longitude' => 'GPSLongitude'];
	$direction = ['latitude' => 'GPSLatitudeRef', 'longitude' => 'GPSLongitudeRef'];

	foreach ($coordinates as $coord => $coord_key) {
		for ($i = 0; $i < 3; $i++) {
			$value = getCoord($exif['GPS'][$coord_key][$i]);
			if (is_null($value)) {
				return null;
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
		return null;
	}

	$json_data = json_encode($res);

	file_put_contents($cache_file, $json_data);

	return $res;
}

function is_valid_image_or_video_file($filepath) {
	if (!is_file($filepath) || !is_readable($filepath)) {
		return false;
	}

	if (!isset($GLOBALS["finfo"])) {
		$GLOBALS['finfo'] = finfo_open(FILEINFO_MIME_TYPE);
	}

	$type = finfo_file($GLOBALS['finfo'], $filepath);
	return isset($type) && in_array($type, $GLOBALS["allowed_content_types"]);
}

function display_gallery($fp) {
	if (preg_match("/\.\./", $fp)) {
		print("Invalid folder");
		return [];
	}

	if (!is_dir($fp)) {
		print("Folder not found");
		return [];
	}

	$files = scandir($fp);

	$thumbnails = [];
	$images = [];

	foreach ($files as $file) {
		if ($file === '.' || $file === '..'  || preg_match("/^\./", $file) || $file === "thumbnails_cache") {
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

			if (in_array($fileExtension, $GLOBALS["FILETYPES"])) {
				$images[] = [
					'name' => $file,
					'path' => $filePath
				];
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

	$html_parts = [];

	foreach ($thumbnails as $thumbnail) {
		if (preg_match($GLOBALS["valid_file_ending_regex"], $thumbnail["thumbnail"])) {
			$html_parts = array_merge($html_parts, create_thumbnail_html($thumbnail, true));
		}
	}

	foreach ($images as $image) {
		if (is_file($image["path"]) && is_valid_image_or_video_file($image["path"]) && !preg_match("/^\.\/\/loading.gif$/", $image["path"])) {
			$html_parts = array_merge($html_parts, create_thumbnail_html($image, false));
		}
	}

	foreach ($html_parts as $html_part) {
		echo $html_part;
	}
}

function create_thumbnail_html($item, $is_folder = false) {
	$path = $item["path"];
	$thumb = $is_folder ? $item["thumbnail"] : $path;
	$file_hash = get_hash_from_file($thumb);
	$cached_preview = "thumbnails_cache/$file_hash.jpg";

	if (file_exists($cached_preview)) {
		list($width, $height) = getImageSizeWithRotation($cached_preview);
		$wh_string = ($width && $height) ? " style=\"width:{$width}px; height:{$height}px; object-fit:contain;\" " : "";
	} else {
		$wh_string = getResizedImageStyle($thumb);
	}

	$extra_attributes = $is_folder
		? 'title="' . $item["counted_thumbs"] . ' images" data-line="XXX"'
		: 'data-line="YYY" data-hash="' . $file_hash . '"';

	if (!$is_folder && !preg_match("/\.m(?:ov|p4)$/i", $path)) {
		$gps = get_image_gps($path);
		if ($gps) {
			$extra_attributes .= " data-latitude='{$gps["latitude"]}' data-longitude='{$gps["longitude"]}'";
		}
	}

	$img_tag = '<img ' . $wh_string . ' ' . $extra_attributes . ' draggable="false" src="loading.gif" alt="Loading..." class="loading-thumbnail" data-original-url="index.php?preview=' . urlencode($thumb) . '">';

	if ($is_folder) {
		return [
			'<a data-href="' . urlencode($path) . '" class="img_element" data-onclick="load_folder(\'' . $path . '\')"><div class="thumbnail_folder">',
			$img_tag,
			'<h3>' . $item['name'] . '</h3>',
			'<span class="checkmark">✅</span>',
			"</div></a>\n"
		];
	} else {
		return [
			'<div class="thumbnail" data-onclick="showImage(\'' . rawurlencode($path) . '\')">',
			$img_tag,
			'<span class="checkmark">✅</span>',
			"</div>\n"
		];
	}
}

function getImageSizeWithRotation($path) {
	if (!file_exists($path)) return false;

	$size = getimagesize($path);
	if ($size === false) return false;

	list($width, $height) = $size;

	if (function_exists('exif_read_data') && in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II])) {
		$exif = @exif_read_data($path);
		if (!empty($exif['Orientation']) && in_array($exif['Orientation'], [5, 6, 7, 8])) {
			list($width, $height) = [$height, $width];
		}
	}

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

		if (in_array($fileExtension, $GLOBALS["FILETYPES"])) {
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
	$ending = "jpg";

	if (!preg_match("/\.(mov|mp4)$/i", $path)) {
		$ending = "gif";
	}

	$md5 = get_hash_from_file($path);

	$cacheFolder = './thumbnails_cache/';

	if (is_dir("/docker_tmp/")) {
		$cacheFolder = "/docker_tmp/";
	}

	$path = $cacheFolder . $md5 . "." . $ending;

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
				$subDirectoryImages = listAllUncachedImageFiles($filePath);
				$imageList = array_merge($imageList, $subDirectoryImages);
			} else {
				if (is_valid_image_or_video_file($filePath) && !is_cached_already($filePath)) {
					$imageList[] = $filePath;
				}
			}
		}
	}

	return $imageList;
}

function getResizedImageStyle($imagePath, $thumbnailMaxWidth = 150, $thumbnailMaxHeight = 150) {
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

	$aspectRatio = $width / $height;
	$thumbWidth = $thumbnailMaxWidth;
	$thumbHeight = $thumbnailMaxHeight;

	if ($width > $height) {
		$thumbHeight = $thumbWidth / $aspectRatio;
	} else {
		$thumbWidth = $thumbHeight * $aspectRatio;
	}

	$thumbWidth = intval($thumbWidth);
	$thumbHeight = intval($thumbHeight);

	return "width: {$thumbWidth}px; height: {$thumbHeight}px;";
}


function search_and_print_results ($searchTerm, $allowFuzzy) {
	$results = array();
	$results["files"] = searchFiles('.', $searchTerm, $allowFuzzy);

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
		}

		$i++;
	}

	header('Content-type: application/json; charset=utf-8');
	echo json_encode($results);
}

function print_geolist ($geolist) {
	$files = [];

	if ($geolist && !preg_match("/\.\./", $geolist) && preg_match("/^\.\//", $geolist)) {
		$files = getImagesInDirectory($geolist);
	} else {
		die("Wrongly formed geolist: ".$geolist);
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

	header('Content-type: application/json; charset=utf-8');
	print json_encode($s);
}

function list_all () {
	$allImageFiles = listAllUncachedImageFiles('.');

	header('Content-type: application/json; charset=utf-8');
	echo json_encode($allImageFiles);
}

function create_preview($imagePath) {
	$thumbnailMaxWidth = 150;
	$thumbnailMaxHeight = 150;
	$cacheFolder = './thumbnails_cache/';

	$isVideo = preg_match("/\.(mov|mp4)$/i", $imagePath);

	if (is_dir("/docker_tmp/")) {
		$cacheFolder = "/docker_tmp/";
	}

	if (!preg_match("/\.\./", $imagePath) && file_exists($imagePath)) {
		$file_ending = "jpg";

		if ($isVideo) {
			$file_ending = "gif";
		}

		$md5 = get_hash_from_file($imagePath);

		$thumbnailFileName = $md5 . '.' . $file_ending;

		$cachedThumbnailPath = $cacheFolder . $thumbnailFileName;
		if (file_exists($cachedThumbnailPath) && is_valid_image_or_video_file($cachedThumbnailPath)) {
			if ($isVideo) {
				header('Content-Type: image/gif');
			} else {
				header('Content-Type: image/jpeg');
			}
			readfile($cachedThumbnailPath);
			exit;
		} else {
			if ($isVideo) {
				$ffprobe = "ffprobe -v error -select_streams v:0 -show_entries format=duration -of csv=p=0 \"$imagePath\"";
				$duration = floatval(shell_exec($ffprobe));

				$gifDuration = 10;
				$startTime = 0;

				$frameRate = 10;
				$frameCount = $gifDuration * $frameRate;

				$ffmpeg = "ffmpeg -y -i \"$imagePath\" -vf \"fps=$frameRate,scale=$thumbnailMaxWidth:$thumbnailMaxHeight:force_original_aspect_ratio=decrease\" -t $gifDuration -ss $startTime \"$cachedThumbnailPath\"";

				fwrite($GLOBALS["stderr"], "ffmpeg command:\n$ffmpeg");

				shell_exec($ffmpeg);

				if (file_exists($cachedThumbnailPath)) {
					header('Content-Type: image/gif');
					readfile($cachedThumbnailPath);
					exit;
				} else {
					echo "Fehler beim Erstellen des Video-GIFs.";
					exit;
				}
			} else {
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
					default:
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

				$aspectRatio = $width / $height;
				$thumbnailWidth = $thumbnailMaxWidth;
				$thumbnailHeight = $thumbnailMaxHeight;
				if ($width > $height) {
					$thumbnailHeight = $thumbnailWidth / $aspectRatio;
				} else {
					$thumbnailWidth = $thumbnailHeight * $aspectRatio;
				}

				$thumbnail = imagecreatetruecolor(intval($thumbnailWidth), intval($thumbnailHeight));

				$backgroundColor = imagecolorallocate($thumbnail, 255, 255, 255);
				imagefill($thumbnail, 0, 0, $backgroundColor);

				imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, intval($thumbnailWidth), intval($thumbnailHeight), intval($width), intval($height));
			}

			ob_start();
			imagejpeg($thumbnail);
			$data = ob_get_clean();
			file_put_contents($cachedThumbnailPath, $data);
			header('Content-Type: image/jpeg');
			echo $data;

			imagedestroy($image);
			imagedestroy($thumbnail);
		}
	} else {
		echo 'File not found.';
	}
}

function create_zip () {
	$zipname = 'gallery.zip';
	$zip = new ZipArchive;
	$zipFile = tempnam(sys_get_temp_dir(), 'zip');
	$exit_code = 0;

	if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
		if (isset($_GET['folder'])) {
			$folders = is_array($_GET['folder']) ? $_GET['folder'] : [$_GET['folder']];
			foreach ($folders as $folder) {
				if (isValidPath($folder) && is_dir($folder)) {
					$realFolderPath = realpath($folder);

					$files = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator($realFolderPath, RecursiveDirectoryIterator::SKIP_DOTS),
						RecursiveIteratorIterator::SELF_FIRST
					);

					foreach ($files as $file) {
						if (!$file->isDir()) {
							$filePath = $file->getRealPath();

							if (preg_match($GLOBALS["valid_file_ending_regex"], $filePath)) {
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
				if (isValidPath($img) && file_exists($img)) {
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

				echo '<a target="_blank" href="' . htmlspecialchars($link) . '">' . htmlspecialchars($text) . '</a><br>';
			}

			fclose($file);
		}
	}
}
