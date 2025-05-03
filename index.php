<?php
	$GLOBALS["stderr"] = fopen('php://stderr', 'w');

	$GLOBALS["allowed_content_types"] = ["image/png", "image/jpeg", "image/gif"];
	$validTypes = ['jpg', 'jpeg', 'png', 'gif'];

	if (shell_exec('which ffmpeg')) {
		$validTypes = array_merge($validTypes, ['mp4', 'mov']);
		$GLOBALS["allowed_content_types"] = array_merge($GLOBALS["allowed_content_types"], ["video/quicktime", "video/mp4"]);
	}

	set_time_limit(60);

	$GLOBALS["FILETYPES"] = $validTypes;
	$GLOBALS["valid_file_ending_regex"] = "/\.(" . implode("|", $validTypes) . ")$/i";

	$folderPath = './';

	if (!isset($_GET["zip"]) && isset($_GET['folder']) && !preg_match("/\.\./", $_GET["folder"])) {
		$folderPath = $_GET['folder'];
	}

	ini_set('memory_limit', '2048M');
	$images_path = "/docker_images/";
	setLocale(LC_ALL, ["en.utf", "en_US.utf", "en_US.UTF-8", "en", "en_US"]);

	if (is_dir($images_path)) {
		chdir($images_path);
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

	function dier ($msg) {
		print("<pre>");
		print(var_dump($msg));
		print("</pre>");
		exit(0);
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

	if (isset($_GET["file_info"])) {
		print_file_metadata();

		exit(0);
	}

	if (isset($_GET['zip']) && $_GET['zip'] == 1) {
		$zipname = 'gallery.zip';
		$zip = new ZipArchive;
		$zipFile = tempnam(sys_get_temp_dir(), 'zip');

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

			exit(0);
		} else {
			echo 'Failed to create zip file.';
			exit(1);
		}
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

	function file_or_folder_matches ($file_or_folder, $searchTermLower, $normalized) {
		return
			stripos($file_or_folder, $searchTermLower) !== false ||
			stripos(normalize_special_characters($file_or_folder), $normalized) !== false
		;
	}

	function searchFiles($fp, $searchTerm) {
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
				if (file_or_folder_matches($fileNameBase, $searchTermLower, $normalized)) {
					$randomImage = getRandomImageFromSubfolders($filePath);
					$results[] = [
						'path' => $filePath,
						'type' => 'folder',
						'thumbnail' => $randomImage['path'] ?? ''
					];
				}
				$results = array_merge($results, searchFiles($filePath, $searchTerm));
				continue;
			}

			if ($fileExtension === 'txt') {
				$content = strtolower(file_get_contents($filePath));
				$cleanContent = sortAndCleanString($content);
				if (file_or_folder_matches($cleanContent, $searchTermLower, $normalized)) {
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
				if (file_or_folder_matches($fileNameBase, $searchTermLower, $normalized)) {
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

	function displayGallery($fp) {
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

	if (isset($_GET['search'])) {
		$searchTerm = $_GET['search'];
		$results = array();
		$results["files"] = searchFiles('.', $searchTerm);

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
		exit;
	}

	if (isset($_GET['list_all'])) {
		$allImageFiles = listAllUncachedImageFiles('.');

		header('Content-type: application/json; charset=utf-8');
		echo json_encode($allImageFiles);

		exit(0);
	}

	if (isset($_GET['preview'])) {
		$imagePath = $_GET['preview'];
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

		exit;
	}

	if (isset($_GET["geolist"])) {
		$geolist = $_GET["geolist"];

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

		exit(0);
	}

	if (isset($_GET["gallery"])) {
		displayGallery($_GET["gallery"]);
		exit(0);
	}
?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="UTF-8">
		<title>Galerie</title>
		<script src="jquery-3.7.1.min.js"></script>

		<style>
			.toggle-switch {
				width: 100%;
				margin: 0 auto;
			}

			#toggleSwitch {
				width: 40px;
				height: 20px;
				}

			#swipe_toggle {
				display: flex;
				justify-content: center;
				align-items: center;
				padding: 10px;
			}

			.loading-bar-container {
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 5px;
				background-color: rgba(0, 0, 0, 0.1);
				z-index: 10000;
			}

			.loading-bar {
				width: 0;
				height: 100%;
				background-color: #3498db;
				animation: progress 1s ease-in-out;
			}

			@keyframes progress {
				0% {
					width: 0;
				}
				100% {
					width: 100%;
				}
			}

			.loading-bar.undulating {
				background-image: linear-gradient(135deg, #3498db 25%, #1abc9c 50%, #3498db 75%);
				background-size: 200% 100%;
				animation: progress 1s ease-in-out, undulate 1s linear infinite;
			}

			@keyframes undulate {
				0% {
					background-position: 0% 0%;
				}
				100% {
					background-position: 100% 100%;
				}
			}

			.checkmark {
				bottom: 5px;
				left: 5px;
				font-size: 24px;
				color: green;
				display: none;
			}

			.unselect-btn {
				display: none;
				margin-top: 20px;
				padding: 10px 20px;
				background-color: red;
				color: white;
				border: none;
				cursor: pointer;
			}

			.unselect-btn:disabled {
				background-color: #ccc;
				cursor: not-allowed;
			}

			.download-btn {
				display: none;
				margin-top: 20px;
				padding: 10px 20px;
				background-color: #4CAF50;
				color: white;
				border: none;
				cursor: pointer;
			}

			.download-btn:disabled {
				background-color: #ccc;
				cursor: not-allowed;
			}

			.fullscreen {
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background-color: rgba(0, 0, 0, 0.8);
				display: flex;
				justify-content: center;
				align-items: center;
			}

			.swiper-container {
				width: 80%;
				height: 80%;
			}

			.swiper-slide img {
				width: 100%;
				height: 100%;
				object-fit: contain;
			}

			.toggle-switch {
				position: absolute;
				top: 10px;
				left: 10px;
				z-index: 9999;
				cursor: pointer;
			}

			.toggle-switch input[type="checkbox"] {
				display: none;
			}

			.toggle-switch-label {
				display: block;
				position: relative;
				width: 10vw;
				height: 5vw;
				background-color: #ccc;
				border-radius: 20px;
			}

			.toggle-switch-label:before {
				content: '';
				position: absolute;
				width: 4vw;
				height: 4vw;
				top: 50%;
				transform: translateY(-50%);
				background-color: white;
				border-radius: 50%;
				transition: transform 0.3s ease-in-out;
			}

			.toggle-switch input[type="checkbox"]:checked + .toggle-switch-label {
				background-color: #2ecc71;
			}

			.toggle-switch input[type="checkbox"]:checked + .toggle-switch-label:before {
				transform: translateX(5vw) translateY(-50%);
			}

			.toggle-switch {
				width: 100%;
				display: flex;
				justify-content: center;
			}

			#swipe_toggle {
				background-color: red;
				width: fit-content;
				background-color: white;
				border-radius: 20px;
				overflow: hidden;
				display: flex;
				justify-content: center;
				align-items: center;
				box-shadow: inset 0 0 0 20px rgba(255, 255, 255, 0);
				padding: 5px;
				font-size: 5vh;
			}

			@keyframes aurora {
				0% {
					background-color: #4e54c8;
				}
				50% {
					background-color: #8f94fb;
				}
				100% {
					background-color: #4e54c8;
				}
			}

			.loading-indicator {
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 10px;
				animation: aurora 3s infinite;
			}

			.loading-thumbnail:hover {
				transform: scale(1.1);
			}

			#searchInput {
				width: calc(150px + 1.5vw);;
				height: calc(50px + 1.5vw);;
				max-height: 50px;
				max-width: 400px;
			}

			#delete_search {
				color: red;
				background-color: #fafafa;
				text-decoration: none;
				border: 1px groove darkblue;
				border-radius: 5px;
				margin: 3px;
				padding: 3px;
				width: 4vw;
				height: 4vw;
				max-height: 50px;
				max-width: 50px;
			}

			a {
				color: black;
				text-decoration: none;
			}

			a:visited {
				color: black;
			}

			h3 {
				line-break: anywhere;
			}

			.thumbnail_folder {
				display: inline-block;
				margin: 10px;
				max-width: 150px;
				max-height: 150px;
				cursor: pointer;
			}

			.thumbnail_folder img {
				max-width: 100%;
				max-height: 100%;
			}

			body {
				font-family: sans-serif;
				user-select: none;
			}

			.thumbnail {
				display: inline-block;
				margin: 10px;
				max-width: 150px;
				max-height: 150px;
				cursor: pointer;
			}

			.thumbnail img {
				max-width: 100%;
				max-height: 100%;
			}

			.fullscreen {
				z-index: 9999;
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background-color: rgba(0, 0, 0, 0.9);
				display: flex;
				align-items: center;
				justify-content: center;
			}

			.fullscreen img {
				max-width: 90%;
				max-height: 90%;
			}

			#breadcrumb {
				font-size: 2.7vw;
				padding: 10px;
			}

			.breadcrumb_nav {
				background-color: #fafafa;
				text-decoration: none;
				color: black;
				border: 1px groove darkblue;
				border-radius: 5px;
				margin: 3px;
				padding: 3px;
				height: 3vw;
				display: inline-block;
				min-height: 30px;
				font-size: calc(12px + 1.5vw);;
			}

			.box-shadow {
				box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
				transition: 0.3s;
			}

			.box-shadow:hover {
				box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2);
			}

			.leaflet-popup-content {
				width: fit-content !important;
				height: fit-content !important;
			}

			#searchInput {
				padding-left: 45px;
				background-image: url('search.svg');
				background-size: 32px 32px;
				background-position: 10px center;
				background-repeat: no-repeat;
			}
		</style>

		<script src="https://cdn.jsdelivr.net/npm/leaflet@1.7.1/dist/leaflet.js"></script>
		<script src="https://unpkg.com/swiper/swiper-bundle.min.js"></script>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.7.1/dist/leaflet.css" />
		<link rel="stylesheet" href="swiper-bundle.min.css" />
	</head>
	<body>
		<input onkeyup="start_search()" onchange='start_search()' type="text" id="searchInput" placeholder="Search...">
		<button style="display: none" id="delete_search" onclick='delete_search()'>&#x2715;</button>
		<button class="download-btn" id="downloadBtn" onclick="downloadSelected()">Download</button>
		<button class="unselect-btn" id="unselectBtn" onclick="unselectSelection()">Unselect</button>
<?php
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
?>
		<div id="breadcrumb"></div>
		<script>
			var select_image_timer = 0;
			var select_folder_timer = 0;

			var selectedImages = [];
			var selectedFolders = [];

			var enabled_selection_mode = false;

			var map = null;
			var fullscreen;

			const log = console.log;
			const debug = console.debug;
			const l = log;

			var searchTimer;
			var lastSearch = "";

			function removeProgressBar() {
				$(".loading-bar-container").remove();
			}

			async function showProgressBar(_sleep = 1) {
				if (document.querySelector('.loading-bar-container') || enabled_selection_mode) {
					return;
				}

				const container = document.createElement('div');
				container.classList.add('loading-bar-container');

				const loadingBar = document.createElement('div');
				loadingBar.classList.add('loading-bar', 'undulating');

				container.appendChild(loadingBar);
				document.body.appendChild(container);

				loadingBar.style.animationDuration = `${_sleep}s`;

				await sleep(_sleep * 1000);

				try {
					document.body.removeChild(container);
				} catch (e) {
					//
				}
			}

			async function start_search() {
				var searchTerm = $('#searchInput').val();

				if(searchTerm == lastSearch) {
					return;
				}

				lastSearch = searchTerm;

				function abortPreviousRequest() {
					if (searchTimer) {
						clearTimeout(searchTimer);
						searchTimer = null;
					}
				}

				abortPreviousRequest();

				async function performSearch() {
					abortPreviousRequest();

					showPageLoadingIndicator();

					if (!/^\s*$/.test(searchTerm)) {
						unselectSelection();
						$("#delete_search").show();
						$("#searchResults").show();
						$("#gallery").hide();
						$.ajax({
						url: 'index.php',
							type: 'GET',
							data: { search: searchTerm },
							success: async function (response) {
								await displaySearchResults(searchTerm, response["files"]);
								customizeCursorForLinks();
								hidePageLoadingIndicator();
							},
							error: function (xhr, status, error) {
								console.error(error);
							}
						});
					} else {
						$("#delete_search").hide();
						$("#searchResults").hide();
						$("#gallery").show();
						await draw_map_from_current_images();

						unselectSelection();
					}
				}

				searchTimer = setTimeout(performSearch, 10);
			}

			async function displaySearchResults(searchTerm, results) {
				unselectSelection();
				var $searchResults = $('#searchResults');
				$searchResults.empty();

				if (results.length > 0) {
					$searchResults.append('<h2>Search results:</h2>');

					results.forEach(function(result) {
						if (result.type === 'folder') {
							var folderThumbnail = result.thumbnail;
							if (folderThumbnail) {
								var folder_line = `<a class='img_element' data-onclick="load_folder('${encodeURI(result.path)}')" data-href="${encodeURI(result.path)}"><div class="thumbnail_folder">`;

								folder_line += `<img src="loading.gif" alt="Loading..." class="loading-thumbnail-search img_element" data-line="Y" data-original-url="index.php?preview=${folderThumbnail}">`;

								folder_line += `<h3>${result.path.replace(/\.\//, "")}</h3><span class="checkmark">✅</span></div></a>`;
								$searchResults.append(folder_line);
							}
						} else if (result.type === 'file') {
							var fileName = result.path.split('/').pop();
							var image_line = `<div class="thumbnail" class='img_element' href="${result.path}" data-onclick="showImage('${result.path}')">`;

							var gps_data_string = "";

							if (!isNaN(result.latitude) && !isNaN(result.longitude) && result.latitude !== 0 && result.longitude !== 0) {
								gps_data_string = ` data-latitude="${result.latitude}" data-longitude="${result.longitude}" `;
							}

							image_line += `<img data-hash="${result.hash}" ${gps_data_string} src="loading.gif" alt="Loading..." class="loading-thumbnail-search img_element" data-line='X' data-original-url="index.php?preview=${encodeURIComponent(result.path)}">`;

							image_line += `<span class="checkmark">✅</span></div>`;
							$searchResults.append(image_line);
						}
					});

					$('.loading-thumbnail-search').each(function() {
						var $thumbnail = $(this);
						var originalUrl = $thumbnail.attr('data-original-url');

						var img = new Image();
						img.onload = function() {
							$thumbnail.attr('src', originalUrl);
						};
						img.src = originalUrl;
					});
				} else {
					$searchResults.append('<p>No results found.</p>');
				}

				await draw_map_from_current_images();

				add_listeners();
				unselectSelection();
			}

			function toggleSwitch() {
				var toggleSwitchLabel = document.querySelector('.toggle-switch-label');
				if (toggleSwitchLabel) {
					toggleSwitchLabel.click();
				} else {
					console.warn("Toggle switch label not found!");
				}
			}

			function isSwipeDevice() {
				return 'ontouchstart' in window || navigator.maxTouchPoints > 0 || navigator.msMaxTouchPoints > 0;
			}

	
			function showImage(imagePath) {
				$(fullscreen).remove();

				fullscreen = document.createElement('div');
				fullscreen.classList.add('fullscreen');

				var image = document.createElement('img');
				image.src = "loading.gif";
				image.setAttribute('draggable', false);

				fullscreen.appendChild(image);
				document.body.appendChild(fullscreen);

				if (isSwipeDevice()) {
					var toggleSwitch = document.createElement('div');
					toggleSwitch.classList.add('toggle-switch');
					toggleSwitch.innerHTML = `
						<div onclick="toggleSwitch()" id="swipe_toggle">
							Swipe?
							<input type="checkbox" id="toggleSwitch" checked>
							<label class="toggle-switch-label" for="toggleSwitch"></label>
						</div>
					`;
					fullscreen.appendChild(toggleSwitch);
				}

				var decodedPath = decodeURIComponent(imagePath);

				if (decodedPath.match(/\.(mp4|mov)$/i)) {
					var video = document.createElement('video');
					video.controls = true;
					video.autoplay = true;
					video.style.maxWidth = "100%";
					video.style.maxHeight = "100%";

					video.onloadeddata = function () {
						fullscreen.replaceChild(video, image);
					};

					video.onerror = function () {
						console.warn("Failed to load video:", decodedPath);
						image.src = "error.svg";
					};

					video.src = decodedPath;
				} else {
					var img = new Image();
					img.onload = function () {
						fullscreen.replaceChild(img, image);
					};
					img.onerror = function () {
						console.warn("Failed to load image:", decodedPath);
						image.src = "error.svg";
					};
					img.src = decodedPath;
				}

				$(fullscreen).on("click", function (i) {
					if (!["INPUT", "IMG", "LABEL", "VIDEO"].includes(i.target.nodeName) && i.target.id != "swipe_toggle") {
						$(fullscreen).remove();
					}
				});
			}

			function getToggleSwitchValue() {
				var toggleSwitch = document.getElementById('toggleSwitch');
				if (toggleSwitch) {
					return toggleSwitch.checked;
				} else {
					console.warn("Toggle switch element not found!");
					return null;
				}
			}

			function get_fullscreen_img_name () {
				var src = decodeURIComponent($(".fullscreen").find("img").attr("src"));

				if(src) {
					return src.replace(/.*path=/, "");
				} else {
					console.warn("No index");
					return "";
				}
			}

			function next_image () {
				next_or_prev(1);
			}

			function prev_image () {
				next_or_prev(0);
			}

			function show_image_info() {
				const existing_overlay = document.querySelector('div[style*="position: fixed"]');
				if (existing_overlay) return;

				const imageElement = document.querySelector('.fullscreen img');
				if (!imageElement) return;

				const imageSrc = get_fullscreen_img_name();

				const overlay = document.createElement('div');
				overlay.style.position = 'fixed';
				overlay.style.top = '0';
				overlay.style.left = '0';
				overlay.style.width = '100vw';
				overlay.style.height = '100vh';
				overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
				overlay.style.zIndex = '9999';
				overlay.style.display = 'flex';
				overlay.style.alignItems = 'center';
				overlay.style.justifyContent = 'center';
				overlay.style.backdropFilter = 'blur(10px)';

				// Container für Scrollbarkeit
				const scrollContainer = document.createElement('div');
				scrollContainer.style.maxHeight = '80vh';
				scrollContainer.style.overflowY = 'auto';
				scrollContainer.style.backgroundColor = 'rgba(255, 255, 255, 0.9)';
				scrollContainer.style.padding = '20px';
				scrollContainer.style.borderRadius = '10px';
				scrollContainer.style.boxShadow = '0 4px 10px rgba(0, 0, 0, 0.3)';
				scrollContainer.style.maxWidth = '90vw';

				// ESC zum Schließen
				overlay.addEventListener('click', e => {
				if (e.target === overlay) overlay.remove();
				});

				document.addEventListener('keydown', function escListener(e) {
					if (e.key === 'Escape') {
						overlay.remove();
						document.removeEventListener('keydown', escListener);
					}
				});

				fetch(`index.php?file_info=${encodeURIComponent(imageSrc)}`)
					.then(response => response.json())
					.then(data => {
					if (data) {
						const table = document.createElement('table');
						table.style.width = '100%';
						table.style.borderCollapse = 'collapse';

						Object.keys(data).forEach(key => {
						const row = document.createElement('tr');

						const cell1 = document.createElement('td');
						cell1.textContent = key;
						cell1.style.padding = '5px';
						cell1.style.fontWeight = 'bold';
						cell1.style.verticalAlign = 'top';

						const cell2 = document.createElement('td');
						cell2.innerHTML = data[key];
						cell2.style.padding = '5px';

						row.appendChild(cell1);
						row.appendChild(cell2);
						table.appendChild(row);
						});

						scrollContainer.appendChild(table);

						const img = document.createElement("img");
						img.src = `index.php?preview=${encodeURIComponent(imageSrc)}`;

						overlay.appendChild(img);

						overlay.appendChild(scrollContainer);
					}
					})
					.catch(error => {
						console.error('Error fetching image info:', error);
					});

				document.body.appendChild(overlay);
			}

			function hide_image_info() {
				const overlay = document.querySelector('div[style*="position: fixed"]');
				if (overlay) {
					overlay.remove();
				}
			}

			function next_or_prev (next=1) {
				hide_image_info();

				var current_fullscreen = get_fullscreen_img_name();

				if(!current_fullscreen) {
					return;
				}

				var current_idx = -1;

				var $thumbnails = $(".thumbnail");

				$thumbnails.each((i, e) => {
					var onclick = $(e).data("onclick");

					var img_url = decodeURIComponent(onclick.replace(/^.*?'/, "").replace(/'.*/, ""));

					if(img_url == current_fullscreen) {
						current_idx = i;
					}
				});

				var next_idx = current_idx + 1;

				if(!next) {
					next_idx = current_idx - 1;
				}

				if(next_idx < 0) {
					next_idx = $thumbnails.length - 1;
				}

				next_idx = next_idx %  $thumbnails.length;

				var next_img = $($thumbnails[next_idx]).data("onclick").replace(/.*?'/, '').replace(/'.*/, "");

				showImage(next_img);
			}

			document.onkeydown = checkKey;

			function checkKey(e) {
				e = e || window.event;

				if (e.keyCode == '38') {
					show_image_info();
				} else if (e.keyCode == '40') {
					hide_image_info();
				} else if (e.keyCode == '37') {
					prev_image();
				} else if (e.keyCode == '39') {
					next_image();
				} else if (e.key === "Escape") {
					$(fullscreen).remove();
					hide_image_info();
				}
			}

			var touchStartX = 0;
			var touchEndX = 0;

			document.addEventListener('touchstart', function(event) {
				touchStartX = event.touches[0].clientX;
				touchStartY = event.touches[0].clientY;
			}, false);

			document.addEventListener('touchend', function(event) {
				touchEndX = event.changedTouches[0].clientX;
				touchEndY = event.changedTouches[0].clientY;
				handleSwipe(event);
			}, false);

			function isZooming(event) {
				return event.touches.length > 1;
			}

			function handleSwipe(event) {
				if(!getToggleSwitchValue()) {
					return;
				}

				var swipeThreshold = 50;

				var deltaX = touchEndX - touchStartX;
				var deltaY = touchEndY - touchStartY;
				var absDeltaX = Math.abs(deltaX);
				var absDeltaY = Math.abs(deltaY);

				var deltaXLargerThanThreshold = absDeltaX >= swipeThreshold;
				var deltaXLargerThanDeltaY = absDeltaX > absDeltaY;

				if (deltaXLargerThanThreshold && deltaXLargerThanDeltaY) {
					if (deltaX > 0) {
						prev_image();
					} else {
						next_image();
					}
				}

				if (isZooming(event)) {
					event.preventDefault();
				}
			}

			document.addEventListener('keydown', function(event) {
				if($(fullscreen).is(":visible")) {
					return;
				}

				var charCode = event.which || event.keyCode;
				var charStr = String.fromCharCode(charCode);

				if (charCode === 8) {
					var searchInput = document.getElementById('searchInput');
					if (document.activeElement !== searchInput) {
						searchInput.value = '';
						$(searchInput).focus();
					}
				} else if (charCode == 27) {
					var searchInput = document.getElementById('searchInput');
					searchInput.value = '';
				}
			});

			document.addEventListener('keypress', function(event) {
				if($(fullscreen).is(":visible")) {
					return;
				}

				var charCode = event.which || event.keyCode;
				var charStr = String.fromCharCode(charCode);

				if (/[a-zA-Z0-9]/.test(charStr)) {
					var searchInput = document.getElementById('searchInput');
					if (document.activeElement !== searchInput) {
						searchInput.value = '';
					}

					var selectionStart = searchInput.selectionStart;
					var selectionEnd = searchInput.selectionEnd;
					var currentValue = searchInput.value;
					var newValue = currentValue.substring(0, selectionStart) + charStr + currentValue.substring(selectionEnd);
					searchInput.value = newValue;

					searchInput.selectionStart = searchInput.selectionEnd = selectionStart + 1;

					if (!$(searchInput).is(":focus")) {
						searchInput.focus();
					}

					event.preventDefault();
				} else if (charCode === 8) {
					var searchInput = document.getElementById('searchInput');
					if (document.activeElement === searchInput) {
						searchInput.value = '';
						$(searchInput).focus();
					}
				}
			});

			function url_content (strUrl) {
				var strReturn = "";

				jQuery.ajax({
					url: strUrl,
					success: function(html) {
						strReturn = html;
					},
					async: false
				});

				return strReturn;
			}

			function updateUrlParameter(folder) {
				try {
					let currentUrl = window.location.href;

					if (currentUrl.includes('?folder=')) {
						currentUrl = currentUrl.replace(/(\?|&)folder=[^&]*/, `$1folder=${folder}`);
					} else {
						const separator = currentUrl.includes('?') ? '&' : '?';
						currentUrl += `${separator}folder=${folder}`;
					}

					window.history.replaceState(null, null, currentUrl);
				} catch (error) {
					console.warn('An error occurred while updating URL parameter "folder":', error);
				}
			}

			function getCurrentFolderParameter() {
				try {
					const currentUrl = window.location.href;

					const folderRegex = /[?&]folder=([^&]*)/;

					const match = currentUrl.match(folderRegex);

					if (!match) {
						return "./";
					}

					return decodeURIComponent(match[1]);
				} catch (error) {
					console.warn('An error occurred while getting current folder parameter:', error);
					return "./";
				}
			}

			function add_listeners() {
				$(".thumbnail_folder").mousedown(onFolderMouseDown).mouseup(onFolderMouseUp);

				$(".thumbnail").mousedown(onImageMouseDown).mouseup(onImageMouseUp);
			}

			async function load_folder (folder) {
				updateUrlParameter(folder);

				showPageLoadingIndicator()

				var content = url_content("index.php?gallery=" + folder);

				$("#searchInput").val("");

				$("#searchResults").empty().hide();
				$("#gallery").html(content).show();

				var _promise = draw_map_from_current_images();

				var _replace_images_promise = loadAndReplaceImages();

				createBreadcrumb(folder);

				await _promise;

				await _replace_images_promise;

				hidePageLoadingIndicator();

				add_listeners();
			}

			var json_cache = {};

			async function showPageLoadingIndicator(_sleep=0) {
				if($(".loading-indicator").length) {
					return;
				}

				const loadingIndicator = document.createElement('div');
				loadingIndicator.classList.add('loading-indicator');
				document.body.appendChild(loadingIndicator);

				if(_sleep) {
					await sleep(_sleep * 1000);

					hidePageLoadingIndicator();
				}
			}

			showPageLoadingIndicator();

			function hidePageLoadingIndicator() {
				const loadingIndicator = document.querySelector('.loading-indicator');
				if (loadingIndicator) {
					loadingIndicator.remove();
				}
			}

			function customizeCursorForLinks() {
				const links = document.querySelectorAll('a');
				links.forEach(link => {
				link.style.cursor = 'pointer';
				});
			}

			function addLinkHighlightEffect() {
				const style = document.createElement('style');
				style.textContent = `
					a:hover {
						color: #ff6600; /* Change to your desired highlight color */
					}
				`;

				document.head.appendChild(style);
			}

			async function get_json_cached (url) {
				if (Object.keys(json_cache).includes(url)) {
					return json_cache[url];
				}

				var d = null;
				await $.getJSON(url, function(internal_data) {
					d = internal_data;
				});

				json_cache[url] = d;

				return d;
			}

			function _draw_map(data) {
				if(Object.keys(data).length == 0) {
					$("#map_container").hide();
					return;
				}

				$("#map_container").show();

				let minLat = data[0].latitude;
				let maxLat = data[0].latitude;
				let minLon = data[0].longitude;
				let maxLon = data[0].longitude;

				data.forEach(item => {
					minLat = Math.min(minLat, item.latitude);
					maxLat = Math.max(maxLat, item.latitude);
					minLon = Math.min(minLon, item.longitude);
					maxLon = Math.max(maxLon, item.longitude);
				});

				if(map) {
					map.remove();
					map = null;
					$("#map").html("");
				}

				map = L.map('map').fitBounds([[minLat, minLon], [maxLat, maxLon]]);

				L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
					attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
				}).addTo(map);

				var markers = {};

				var keys = Object.keys(data);

				var i = 0

				while (i < keys.length) {
					var element = data[keys[i]];

					var hash = element["hash"];
					var url = element["url"];

					markers[hash] = L.marker([element['latitude'], element['longitude']]);

					var text = "<img id='preview_" + hash + "' data-line='__A__' src='index.php?preview=" +
						decodeURIComponent(url.replace(/index.php\?preview=/, "")) +
						"' onclick='showImage(\"" +
						decodeURIComponent(url.replace(/index.php\?preview=/, "")).replace(/\+/g, ' ') +
						"\");' />";

					eval(`markers['${hash}'].on('click', function(e) {
						var popup = L.popup().setContent(\`${text}\`);

						this.bindPopup(popup).openPopup();

						markers['${hash}'].unbindPopup();
					});`);

					markers[hash].addTo(map);

					i++;
				}

				return markers;
			}

			function sleep(ms) {
				return new Promise(resolve => setTimeout(resolve, ms));
			}

			function is_hidden_or_has_hidden_parent(element) {
				if ($(element).css("display") == "none") {
					return true;
				}

				var parents = $(element).parents();

				for (var i = 0; i < parents.length; i++) {
					if ($(parents[i]).css("display") == "none") {
						return true;
					}
				}

				return false;
			}

			async function get_map_data () {
				var data = [];

				var $folders = $(".loading-thumbnail-search,.thumbnail_folder");

				var folders_gone_through = 0;
				var $filtered_folders = [];

				$folders.each(async function (i, e) {
					if(!is_hidden_or_has_hidden_parent(e)) {
						var link_element = $(e).parent()[0];

						if($(link_element).parent().hasClass("thumbnail_folder")) {
							link_element = $(link_element).parent()[0];
						}

						if($(link_element).hasClass("img_element")) {
							$filtered_folders.push(link_element);
						}
					}
				});

				$filtered_folders.forEach(async function (e, i) {
					var folder = decodeURIComponent($(e).data("href"));

					var url = `index.php?geolist=${folder}`;
					try {
						var folder_data = await get_json_cached(url);

						var _keys = Object.keys(folder_data);
						if(_keys.length) {
							for (var i = 0; i < _keys.length; i++) {
								var this_data = folder_data[_keys[i]];

								data.push(this_data);
							}
						}

						folders_gone_through++;
					} catch (e) {
						return;
					}
				});

				var img_elements = $("img");

				if ($("#searchResults").html().length && $("#searchResults").is(":visible")) {
					img_elements = $("#searchResults").find("img");
				}

				var filtered_img_elements = [];

				img_elements.each(function (i, e) {
					if(!is_hidden_or_has_hidden_parent(e)) {
						filtered_img_elements.push(e);
					}
				});

				filtered_img_elements.forEach(function (e, i) {
					//log(e);
					var src = $(e).data("original-url");
					var hash = $(e).data("hash");
					var lat = $(e).data("latitude");
					var lon = $(e).data("longitude");

					if(src && hash && lat && lon) {
						var this_data = {
							"hash": hash,
							"url": src,
							"latitude": lat,
							"longitude": lon
						};

						data.push(this_data);
					}
				});

				while ($filtered_folders.length > folders_gone_through) {
					await sleep(100);
				}
				//log("filtered_folders: ", $filtered_folders);

				return data;
			}

			async function draw_map_from_current_images () {
				var data = await get_map_data();

				//log("$filtered_folders:", $filtered_folders);
				//log("data:", data);

				try {
					var markers = _draw_map(data);

					return {
						"data": data,
						"markers": markers
					};
				} catch (e) {
					console.error("Error drawing map: ", e)
				}
			}

			function createBreadcrumb(currentFolderPath) {
				var breadcrumb = document.getElementById('breadcrumb');
				breadcrumb.innerHTML = '';

				var pathArray = currentFolderPath.split('/');
				var fullPath = '';

				pathArray.forEach(function(folderName, index) {
					if (folderName !== '') {
						var originalFolderName = folderName;
						if(folderName == '.') {
							folderName = "Start";
						}
						fullPath += originalFolderName + '/';

						var link = document.createElement('a');
						link.classList.add("breadcrumb_nav");
						link.classList.add("box-shadow");
						link.textContent = decodeURI(folderName);

						eval(`$(link).on("click", async function () {
							await load_folder("${fullPath}")
						});`);

						breadcrumb.appendChild(link);

						breadcrumb.appendChild(document.createTextNode(' / '));
					}
				});

				customizeCursorForLinks();
			}

			createBreadcrumb('<?php echo $folderPath; ?>');

			$(".no_preview_available").parent().hide();

			async function loadAndReplaceImages() {
				$('.loading-thumbnail').each(function() {
					var $thumbnail = $(this);
					var originalUrl = $thumbnail.attr('data-original-url');

					var img = new Image();
					img.onload = function() {
						$thumbnail.attr('src', originalUrl);
						$thumbnail[0].classList.add("box-shadow");
					};
					img.src = originalUrl;
				});
			}

			async function delete_search() {
				$("#searchInput").val("");
				await start_search();
			}

			async function getListAllJSON() {
				try {
					const response = await fetch('index.php?list_all=1');
					const data = await response.json();
					return data;
				} catch (error) {
					console.error('Fehler beim Abrufen der JSON-Datei:', error);
				}
			}

			var fill_cache_images = [];

			function splitArrayIntoSubarrays(arr, n) {
				const elementsPerSubarray = Math.ceil(arr.length / n);

				const subarrays = [];

				try {
					for (let i = 0; i < arr.length; i += elementsPerSubarray) {
						const subarray = arr.slice(i, i + elementsPerSubarray);
						subarrays.push(subarray);
					}
				} catch (error) {
					console.error("Fehler beim Aufteilen des Arrays:", error);
					return null;
				}

				return subarrays;
			}

			async function fill_cache (nr=5) {
				var promises = [];
				var imageList = await getListAllJSON();
				if(imageList.length == 0) {
					log("No uncached images");
					return;
				}
				var num_total_items = imageList.length;

				var sub_arrays = splitArrayIntoSubarrays(imageList, nr);

				for (var i = 0; i < nr; i++) {
					promises.push(_fill_cache(sub_arrays[i], i, num_total_items));
				}

				await Promise.all(promises);

				$("#fill_cache_percentage").remove();
				log("Done filling cache");
			}

			async function _fill_cache(imageList, id, num_total_items) {
				try {
					if(id == 0) {
						percentage_element = document.createElement('div');
						percentage_element.setAttribute('id', 'fill_cache_percentage');
						document.body.appendChild(percentage_element);
					}

					const container = document.createElement('div');
					container.setAttribute('id', 'image-container_' + id);
					document.body.appendChild(container);

					var percentage_element = null;

					for (let i = 0; i < imageList.length; i++) {
						const imageUrl = `index.php?preview=${imageList[i]}`;
						if (!fill_cache_images.includes(imageUrl)) {
							const imageElement = document.createElement('img');
							imageElement.setAttribute('src', imageUrl);

							await new Promise((resolve, reject) => {
								imageElement.addEventListener('load', () => {
									container.appendChild(imageElement);
									setTimeout(() => {
										container.removeChild(imageElement);
										resolve();
									}, 1000);
								});
								imageElement.addEventListener('error', () => {
									console.warn('Fehler beim Laden des Bildes:', imageUrl);
									reject('Bildladefehler');
								});
							});

							fill_cache_images.push(imageUrl);

							var percent = Math.round((fill_cache_images.length / num_total_items) * 100);

							$("#fill_cache_percentage").html(
								`Cache-filling: ${fill_cache_images.length}/${num_total_items} (${percent}%)`
							);
						}
					}

					document.body.removeChild(container);
				} catch (error) {
					console.error('Fehler beim Anzeigen der Bilder:', error);
				}
			}

			function onFolderMouseDown(e){
				var d = new Date();
				select_folder_timer = d.getTime();

				showProgressBar(1);
			}

			function onImageMouseDown(e){
				var d = new Date();
				select_image_timer = d.getTime();

				showProgressBar(1);
			}

			function onFolderMouseUp(e){
				removeProgressBar();

				var d = new Date();
				var long_click = (d.getTime() - select_folder_timer) > 1000;
				if (long_click || enabled_selection_mode){
					e.preventDefault();
					var container = e.target.closest('.thumbnail, .thumbnail_folder');
					var checkmark = container.querySelector('.checkmark');
					var item = decodeURIComponent($(container.querySelector('img')).parent().parent().data("href"));

					item = decodeURIComponent(item.replace(/.*preview=/, ""));

					if (selectedFolders.includes(item)) {
						selectedFolders = selectedFolders.filter(i => i !== item);
						checkmark.style.display = 'none';
					} else {
						log(item);
						selectedFolders.push(item);
						checkmark.style.display = 'block';
					}

					updateDownloadButton();
					updateUnselectButton();
					enabled_selection_mode = true;
				} else {
					var _onclick = $(e.currentTarget).parent().data("onclick");
					log(_onclick)
					eval(_onclick);
				}

				select_folder_timer = 0;

				hidePageLoadingIndicator();
			}

			function onImageMouseUp(e){
				removeProgressBar();

				var d = new Date();
				var long_click = (d.getTime() - select_image_timer) > 1000;
				if (long_click || enabled_selection_mode){
					e.preventDefault();
					var container = e.target.closest('.thumbnail, .thumbnail_folder');
					var checkmark = container.querySelector('.checkmark');
					var item = container.querySelector('img').getAttribute('src');

					item = decodeURIComponent(item.replace(/.*preview=/, ""));

					if (selectedImages.includes(item)) {
						selectedImages = selectedImages.filter(i => i !== item);
						checkmark.style.display = 'none';
					} else {
						selectedImages.push(item);
						checkmark.style.display = 'block';
					}

					updateDownloadButton();
					updateUnselectButton();
					enabled_selection_mode = true;
				} else {
					var _onclick = $(e.currentTarget).data("onclick");
					eval(_onclick);
				}

				select_image_timer = 0;

				hidePageLoadingIndicator();
			}

			function updateUnselectButton() {
				var unselectBtn = document.getElementById('unselectBtn');
				if (selectedImages.length > 0 || selectedFolders.length > 0) {
					unselectBtn.style.display = 'inline-block';
				} else {
					unselectBtn.style.display = 'none';
				}
			}

			function updateDownloadButton() {
				var downloadBtn = document.getElementById('downloadBtn');
				if (selectedImages.length > 0 || selectedFolders.length > 0) {
					downloadBtn.style.display = 'inline-block';
				} else {
					downloadBtn.style.display = 'none';
				}
			}

			function unselectSelection() {
				enabled_selection_mode = false;
				selectedImages = [];
				selectedFolders = [];

				updateDownloadButton();
				updateUnselectButton();

				$(".checkmark").hide();
			}

			function downloadSelected() {
				if (selectedImages.length > 0 || selectedFolders.length > 0) {
					if (selectedImages.length == 1 && selectedFolders.length == 0) {
						selectedImages.forEach(item => {
							var a = document.createElement('a');
							a.href = item;
							a.download = item.split('/').pop();
							document.body.appendChild(a);
							a.click();
							document.body.removeChild(a);
						});
					} else {
						if(selectedImages.length || selectedFolders.length) {
							var download_url_parts = [];

							if(selectedFolders.length) {
								download_url_parts.push("folder=" + selectedFolders.join("&folder[]="));
							}

							if(selectedImages.length) {
								download_url_parts.push("img[]=" + selectedImages.join("&img[]="));
							}

							if(download_url_parts.length) {
								var download_url = "index.php?zip=1&" + download_url_parts.join("&");

								var a = document.createElement('a');
								a.href = download_url;
								document.body.appendChild(a);
								a.click();
								document.body.removeChild(a);
							} else {
								log("No download-url-parts found");
							}
						} else {
							log("selectedImages and selectedFolders were empty");
						}
					}
				} else {
					log("selectedImages and selectedFolders were empty (top)");
				}
			}

			$(document).ready(async function() {
				$("#delete_search").hide();
				addLinkHighlightEffect();
				await delete_search();

				await load_folder(getCurrentFolderParameter());
				hidePageLoadingIndicator();
			});
		</script>

		<!-- Ergebnisse der Suche hier einfügen -->
		<div id="searchResults"></div>

		<div id="gallery"></div>

		<div id="map_container" style="display: none">
			<div id="map" style="height: 400px; width: 100%;"></div>
		</div>
	</body>
</html>
