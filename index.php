<?php
	$GLOBALS["FILETYPES"] = array('jpg', 'jpeg', 'png');

	$folderPath = './'; // Aktueller Ordner, in dem die index.php liegt

	if (isset($_GET['folder']) && !preg_match("/\.\./", $_GET["folder"])) {
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
			return $normalized_char !== false ? $normalized_char : ''; // Überprüfe auf Fehler bei der Konvertierung
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

	function getImagesInDirectory($directory) {
		$images = [];

		// Überprüfen, ob das Verzeichnis existiert und lesbar ist
		assert(is_dir($directory), "Das Verzeichnis existiert nicht oder ist nicht lesbar: $directory");

		// Verzeichnisinhalt lesen
		try {
			$files = scandir($directory);
		} catch (Exception $e) {
			// Fehler beim Lesen des Verzeichnisses
			warn("Fehler beim Lesen des Verzeichnisses $directory: " . $e->getMessage());
			return $images;
		}

		foreach ($files as $file) {
			if ($file !== '.' && $file !== '..') {
				$filePath = $directory . '/' . $file;
				if (is_dir($filePath)) {
					// Rekursiv alle Bilder im Unterverzeichnis sammeln
					$images = array_merge($images, getImagesInDirectory($filePath));
				} else {
					// Überprüfen, ob die Datei eine unterstützte Bildendung hat
					$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
					if (in_array($extension, ['jpg', 'jpeg', 'png'])) {
						// Bild zur Liste hinzufügen
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
					preg_match("/\.(?:jpe?g|gif|png)$/i", $fullFilePath)
				) {
					return $fullFilePath;
				}
			}
		}

		return null;
	}

	function sortAndCleanString($inputString) {
		// Leerzeichen am Anfang und Ende entfernen und doppelte Leerzeichen zusammenführen
		$cleanedString = trim(preg_replace('/\s+/', ' ', $inputString));

		// String in ein Array von Wörtern aufteilen und alphabetisch sortieren
		$wordsArray = explode(' ', $cleanedString);
		sort($wordsArray);

		// Array von Wörtern zu einem String mit Leerzeichen als Trennzeichen zusammenführen
		$sortedString = implode(' ', $wordsArray);

		return $sortedString;
	}

	function file_or_folder_matches ($file_or_folder, $searchTermLower, $normalized) {
		return
			stripos($file_or_folder, $searchTermLower) !== false ||
			stripos(normalize_special_characters($file_or_folder), $normalized) !== false
		;
	}

	// Funktion zum Durchsuchen von Ordnern und Dateien rekursiv
	function searchFiles($fp, $searchTerm) {
		$results = [];

		if (!is_dir($fp)) {
			return [];
		}

		$files = @scandir($fp);

		if(is_bool($files)) {
			return [];
		}

		$searchTerm = sortAndCleanString($searchTerm);

		$searchTermLower = strtolower($searchTerm);
		$normalized = normalize_special_characters($searchTerm);

		foreach ($files as $file) {
			if ($file === '.' || $file === '..' || $file === '.git' || $file === "thumbnails_cache") {
				continue;
			}

			$filePath = $fp . '/' . $file;

			$file_without_ending = preg_replace("/\.(jpe?g|png|gif)$/i", "", $file);

			if (is_dir($filePath)) {
				if (file_or_folder_matches($file_without_ending, $searchTermLower, $normalized)) {
					$randomImage = getRandomImageFromSubfolders($filePath);
					$thumbnailPath = $randomImage ? $randomImage['path'] : '';

					$results[] = [
						'path' => $filePath,
						'type' => 'folder',
						'thumbnail' => $thumbnailPath
					];
				}

				$subResults = searchFiles($filePath, $searchTerm);
				$results = array_merge($results, $subResults);
			} else {
				$fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

				if ($fileExtension === 'txt') {
					$textContent = sortAndCleanString(strtolower(file_get_contents($filePath)));
					if (file_or_folder_matches($textContent, $searchTermLower, $normalized)) {
						$imageFilePath = searchImageFileByTXT($filePath);

						if($imageFilePath) {
							$results[] = [
								'path' => $imageFilePath,
								'type' => 'file'
							];
						}
					}
				} elseif (in_array($fileExtension, $GLOBALS["FILETYPES"])) {
					if (file_or_folder_matches($file_without_ending, $searchTermLower, $normalized)) {
						$results[] = [
							'path' => $filePath,
							'type' => 'file'
						];
					}
				}
			}
		}

		return $results;
	}

	function getCoord( $expr ) {
		$expr_p = explode( '/', $expr );

		if (count($expr_p) == 2) {
			if($expr_p[1]) {
				return $expr_p[0] / $expr_p[1];
			}
		}

		return null;
	}

	function convertLatLonToDecimal($degrees, $minutes, $seconds, $direction) {
		// Convert degrees, minutes, and seconds to decimal
		$decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

		// Adjust sign based on direction (N or S)
		if ($direction == 'S' || $direction == 'W') {
			$decimal *= -1;
		}

		return $decimal;
	}

	function get_image_gps($img) {
		$cacheFolder = './thumbnails_cache/'; // Ordner für den Zwischenspeicher

		if(is_dir("/docker_tmp/")) {
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

		// Latitude
		$latitude['degrees'] = getCoord($exif['GPS']['GPSLatitude'][0]);
		if(is_null($latitude["degrees"])) { return null; }
		$latitude['minutes'] = getCoord($exif['GPS']['GPSLatitude'][1]);
		if(is_null($latitude["minutes"])) { return null; }
		$latitude['seconds'] = getCoord($exif['GPS']['GPSLatitude'][2]);
		if(is_null($latitude["seconds"])) { return null; }
		$latitude_direction = $exif['GPS']['GPSLatitudeRef'];

		// Longitude
		$longitude['degrees'] = getCoord($exif['GPS']['GPSLongitude'][0]);
		if(is_null($longitude["degrees"])) { return null; }
		$longitude['minutes'] = getCoord($exif['GPS']['GPSLongitude'][1]);
		if(is_null($longitude["minutes"])) { return null; }
		$longitude['seconds'] = getCoord($exif['GPS']['GPSLongitude'][2]);
		if(is_null($longitude["seconds"])) { return null; }
		$longitude_direction = $exif['GPS']['GPSLongitudeRef'];

		$res = array(
			"latitude" => convertLatLonToDecimal($latitude['degrees'], $latitude['minutes'], $latitude['seconds'], $latitude_direction),
			"longitude" => convertLatLonToDecimal($longitude['degrees'], $longitude['minutes'], $longitude['seconds'], $longitude_direction)
		);

		if(is_nan($res["latitude"]) || is_nan($res["longitude"])) {
			return null;
		}

		$json_data = json_encode($res);

		file_put_contents($cache_file, $json_data);

		return $res;
	}

	function is_valid_image_file ($filepath) {
		if(!is_file($filepath)) {
			return false;
		}

		if(!is_readable($filepath)) {
			return false;
		}

		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$type = finfo_file($finfo, $filepath);

		if (isset($type) && in_array($type, array("image/png", "image/jpeg", "image/gif"))) {
			return true;
		} else {
			return false;
		}
	}

	function displayGallery($fp) {
		if(preg_match("/\.\./", $fp)) {
			print("Invalid folder");
			return [];
		}

		if(!is_dir($fp)) {
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
					// If the folder itself doesn't have images, try to get a random image from subfolders
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

		usort($thumbnails, function ($a, $b) {
			return strcmp($a['name'], $b['name']);
		});
		usort($images, function ($a, $b) {
			return strcmp($a['name'], $b['name']);
		});

		foreach ($thumbnails as $thumbnail) {
			if(preg_match('/jpg|jpeg|png/i', $thumbnail["thumbnail"])) {
				echo '<a data-href="'.urlencode($thumbnail["path"]).'" class="img_element" onclick="load_folder(\'' . $thumbnail['path'] . '\')"><div class="thumbnail_folder">';
				echo '<img title="'.$thumbnail["counted_thumbs"].' images" data-line="XXX" draggable="false" src="loading.gif" alt="Loading..." class="loading-thumbnail" data-original-url="index.php?preview=' . urlencode($thumbnail['thumbnail']) . '">';
				echo '<h3>' . $thumbnail['name'] . '</h3>';
				echo "</div></a>\n";
			}
		}

		foreach ($images as $image) {
			if(is_file($image["path"]) && is_valid_image_file($image["path"])) {
				$gps = get_image_gps($image["path"]);
				$hash = md5($image["path"]);

				$gps_data_string = "";

				if($gps) {
					$gps_data_string = " data-latitude='".$gps["latitude"]."' data-longitude='".$gps["longitude"]."' ";
				}

				echo '<div class="thumbnail" onclick="showImage(\'' . urlencode($image['path']) . '\')">';
				echo '<img data-line="YYY" data-hash="'.$hash.'" '.$gps_data_string.' draggable="false" src="loading.gif" alt="Loading..." class="loading-thumbnail" data-original-url="index.php?preview=' . urlencode($image['path']) . '">';
				echo "</div>\n";
			}
		}
	}

	function getImagesInFolder($folderPath) {
		$folderFiles = @scandir($folderPath);

		if(is_bool($folderFiles)) {
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
				if(count($images_in_folder)) {
					$images[] = $images_in_folder[0];
				}
			}

			if (!empty($images)) {
				return $images[array_rand($images)];
			}
		}

		return null;
	}

	function listAllImageFiles($directory) {
		if($directory == "./.git" || $directory == "./docker_tmp" || $directory == "./thumbnails_cache") {
			return [];
		}

		$imageList = [];

		$files = scandir($directory);

		foreach ($files as $file) {
			if ($file != '.' && $file != '..' && $file != "loading.gif") {
				$filePath = $directory . '/' . $file;

				if (is_dir($filePath)) {
					$subDirectoryImages = listAllImageFiles($filePath);
					$imageList = array_merge($imageList, $subDirectoryImages);
				} else {
					if (is_valid_image_file($filePath)) {
						$imageList[] = $filePath;
					}
				}
			}
		}

		return $imageList;
	}



	// AJAX-Handler für die Suche
	if (isset($_GET['search'])) {
		$searchTerm = $_GET['search'];
		$results = array();
		$results["files"] = searchFiles('.', $searchTerm); // Suche im aktuellen Verzeichnis

		$i = 0;
		foreach ($results["files"] as $this_result) {
			$path = $this_result["path"];
			$type = $this_result["type"];

			if($type == "file") {
				$gps = get_image_gps($path);
				if($gps) {
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
		$allImageFiles = listAllImageFiles('.');

		header('Content-type: application/json; charset=utf-8');
		echo json_encode($allImageFiles);

		exit(0);
	}

	if (isset($_GET['preview'])) {
		$imagePath = $_GET['preview'];
		$thumbnailMaxWidth = 150; // Definiere maximale Thumbnail-Breite
		$thumbnailMaxHeight = 150; // Definiere maximale Thumbnail-Höhe
		$cacheFolder = './thumbnails_cache/'; // Ordner für den Zwischenspeicher

		if(is_dir("/docker_tmp/")) {
			$cacheFolder = "/docker_tmp/";
		}

		// Überprüfe, ob die Datei existiert
		if (!preg_match("/\.\./", $imagePath) && file_exists($imagePath)) {
			// Generiere einen eindeutigen Dateinamen für das Thumbnail
			$thumbnailFileName = md5(file_get_contents($imagePath)) . '.jpg'; // Hier verwenden wir MD5 für die Eindeutigkeit, und speichern als JPEG

			// Überprüfe, ob das Thumbnail im Cache vorhanden ist
			$cachedThumbnailPath = $cacheFolder . $thumbnailFileName;
			if (file_exists($cachedThumbnailPath) && is_valid_image_file($thumbnails_cache)) {
				// Das Thumbnail existiert im Cache, geben Sie es direkt aus
				dier($cachedThumbnailPath);
				header('Content-Type: image/jpeg');
				readfile($cachedThumbnailPath);
				exit;
			} else {
				// Das Thumbnail ist nicht im Cache vorhanden, erstelle es

				// Hole Bildabmessungen und Typ
				list($width, $height, $type) = getimagesize($imagePath);

				// Lade Bild basierend auf dem Typ
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

				// Überprüfe und korrigiere Bildausrichtung gegebenenfalls
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

				// Berechne Thumbnail-Abmessungen unter Beibehaltung des Seitenverhältnisses und unter Berücksichtigung der maximalen Breite und Höhe
				$aspectRatio = $width / $height;
				$thumbnailWidth = $thumbnailMaxWidth;
				$thumbnailHeight = $thumbnailMaxHeight;
				if ($width > $height) {
					// Landscape orientation
					$thumbnailHeight = $thumbnailWidth / $aspectRatio;
				} else {
					// Portrait or square orientation
					$thumbnailWidth = $thumbnailHeight * $aspectRatio;
				}

				// Erstelle ein neues Bild mit Thumbnail-Abmessungen
				$thumbnail = imagecreatetruecolor(intval($thumbnailWidth), intval($thumbnailHeight));

				// Fülle den Hintergrund des Thumbnails mit weißer Farbe, um schwarze Ränder zu vermeiden
				$backgroundColor = imagecolorallocate($thumbnail, 255, 255, 255);
				imagefill($thumbnail, 0, 0, $backgroundColor);

				// Verkleinere Originalbild auf Thumbnail-Abmessungen
				imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, intval($thumbnailWidth), intval($thumbnailHeight), intval($width), intval($height));

				// Speichere das Thumbnail im Cache
				imagejpeg($thumbnail, $cachedThumbnailPath);

				// Gib Bild direkt im Browser aus
				header('Content-Type: image/jpeg'); // Passe den Inhaltstyp basierend auf dem Bildtyp an
				imagejpeg($thumbnail); // Gib JPEG-Thumbnail aus (ändern Sie den Funktionsaufruf für PNG/GIF)

				// Freigabe des Speichers
				imagedestroy($image);
				imagedestroy($thumbnail);
			}
		} else {
			echo 'File not found.';
		}

		// Beende die Skriptausführung
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

		foreach ($untested_files as $file) {
			if(!preg_match("/\.\.\//", $file) && is_valid_image_file($file)) {
				$files[] = $file;
			}
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
<?php
		$jquery_file = 'jquery-3.7.1.min.js';
		if(!file_exists($jquery_file)) {
			$jquery_file = "https://code.jquery.com/jquery-3.7.1.js";
		}
?>
		<script src="<?php print $jquery_file; ?>"></script>

		<style>
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
					background-color: #4e54c8; /* Dunkelblau */
				}
				50% {
					background-color: #8f94fb; /* Hellblau */
				}
				100% {
					background-color: #4e54c8; /* Dunkelblau */
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
				transform: scale(1.1); /* Example of hover effect */
			}

			#searchInput {
				width: 20vw;
				height: 4vw;
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
				min-height: 20px;
				font-size: 2.7vw;
			}

			.box-shadow {
				box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
				transition: 0.3s;
			}

			.box-shadow:hover {
				box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2);
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
<?php
$filename = 'links.txt';

if(file_exists($filename)) {
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
			var map = null;
			var fullscreen;

			const log = console.log;
			const debug = console.debug;
			const l = log;

			var searchTimer; // Globale Variable für den Timer
			var lastSearch = "";

			async function start_search() {
				var searchTerm = $('#searchInput').val();

				if(searchTerm == lastSearch) {
					return;
				}

				lastSearch = searchTerm;

				// Funktion zum Abbrechen der vorherigen Suchanfrage
				function abortPreviousRequest() {
					if (searchTimer) {
						clearTimeout(searchTimer);
						searchTimer = null;
					}
				}

				abortPreviousRequest();

				// Funktion zum Durchführen der Suchanfrage
				async function performSearch() {
					// Abbrechen der vorherigen Anfrage, falls vorhanden
					abortPreviousRequest();

					showPageLoadingIndicator();

					if (!/^\s*$/.test(searchTerm)) {
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
					}
				}

				// Starten der Suche nach 10 ms Verzögerung
				searchTimer = setTimeout(performSearch, 10);
			}

			// Funktion zur Anzeige der Suchergebnisse
			async function displaySearchResults(searchTerm, results) {
				var $searchResults = $('#searchResults');
				$searchResults.empty();

				if (results.length > 0) {
					$searchResults.append('<h2>Search results:</h2>');

					results.forEach(function(result) {
						if (result.type === 'folder') {
							var folderThumbnail = result.thumbnail;
							if (folderThumbnail) {
								var folder_line = `<a class='img_element' onclick="load_folder('${encodeURI(result.path)}')" data-href="${encodeURI(result.path)}"><div class="thumbnail_folder">`;

								// Ersetze das Vorschaubild mit einem Lade-Spinner
								folder_line += `<img src="loading.gif" alt="Loading..." class="loading-thumbnail-search img_element" data-line="Y" data-original-url="index.php?preview=${folderThumbnail}">`;

								folder_line += `<h3>${result.path.replace(/\.\//, "")}</h3></div></a>`;
								$searchResults.append(folder_line);
							}
						} else if (result.type === 'file') {
							var fileName = result.path.split('/').pop();
							var image_line = `<div class="thumbnail" class='img_element' href="${result.path}" onclick="showImage('${result.path}')">`;

							var gps_data_string = "";

							if(result.latitude && result.longitude) { // TODO: was für Geocoords 0, 0?
								gps_data_string = ` data-latitude="${result.latitude}" data-longitude="${result.longitude}" `;
							}

							// Ersetze das Vorschaubild mit einem Lade-Spinner
							image_line += `<img data-hash="${result.hash}" ${gps_data_string} src="loading.gif" alt="Loading..." class="loading-thumbnail-search img_element" data-line='X' data-original-url="index.php?preview=${encodeURIComponent(result.path)}">`;

							image_line += `</div>`;
							$searchResults.append(image_line);
						}
					});

					// Hintergrundladen und Austauschen der Vorschaubilder
					$('.loading-thumbnail-search').each(function() {
						var $thumbnail = $(this);
						var originalUrl = $thumbnail.attr('data-original-url');

						// Bild im Hintergrund laden
						var img = new Image();
						img.onload = function() {
							$thumbnail.attr('src', originalUrl); // Bild austauschen, wenn geladen
						};
						img.src = originalUrl; // Starte das Laden des Bildes im Hintergrund
					});
				} else {
					$searchResults.append('<p>No results found.</p>');
				}

				await draw_map_from_current_images();
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

				// Create fullscreen div
				fullscreen = document.createElement('div');
				fullscreen.classList.add('fullscreen');

				// Create image element with loading.gif initially
				var image = document.createElement('img');
				image.src = "loading.gif";
				image.setAttribute('draggable', false);

				// Append image to fullscreen div
				fullscreen.appendChild(image);
				document.body.appendChild(fullscreen);

				if(isSwipeDevice()) {
					// Create and append toggle switch
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

				// Start separate request to load the correct image
				var url = "image.php?path=" + imagePath;
				var request = new XMLHttpRequest();
				request.open('GET', url, true);
				request.onreadystatechange = function() {
					if (request.readyState === XMLHttpRequest.DONE) {
						if (request.status === 200) {
							// Replace loading.gif with the correct image
							image.src = url;
						} else {
							console.warn("Failed to load image:", request.status);
						}
					}
				};
				request.send();

				$(fullscreen).on("click", function (i) {
					if(!["INPUT", "IMG", "LABEL"].includes(i.target.nodeName) && i.target.id != "swipe_toggle") {
						$(fullscreen).remove();
					}
				})
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

			function next_or_prev (next=1) {
				var current_fullscreen = get_fullscreen_img_name();

				if(!current_fullscreen) {
					return;
				}

				var current_idx = -1;

				var $thumbnails = $(".thumbnail");

				$thumbnails.each((i, e) => {
					var onclick = $(e).attr("onclick");

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

				var next_img = $($thumbnails[next_idx]).attr("onclick").replace(/.*?'/, '').replace(/'.*/, "");

				showImage(next_img);
			}

			document.onkeydown = checkKey;

			function checkKey(e) {
				e = e || window.event;

				if (e.keyCode == '38') {
					// up arrow
				} else if (e.keyCode == '40') {
					// down arrow
				} else if (e.keyCode == '37') {
					prev_image();
				} else if (e.keyCode == '39') {
					next_image();
				} else if (e.key === "Escape") {
					$(fullscreen).remove();
				}
			}

			var touchStartX = 0;
			var touchEndX = 0;

			document.addEventListener('touchstart', function(event) {
				touchStartX = event.touches[0].clientX;
				touchStartY = event.touches[0].clientY; // Speichere auch die Start-Y-Position
			}, false);

			document.addEventListener('touchend', function(event) {
				touchEndX = event.changedTouches[0].clientX;
				touchEndY = event.changedTouches[0].clientY; // Speichere auch die End-Y-Position
				handleSwipe(event); // Übergebe das Event-Objekt an die handleSwipe-Funktion
			}, false);

			function isZooming(event) {
				return event.touches.length > 1;
			}

			function handleSwipe(event) { // Übernimm das Event-Objekt als Parameter
				if(!getToggleSwitchValue()) {
					return;
				}

				var swipeThreshold = 50; // Mindestanzahl von Pixeln, die für einen Wisch erforderlich sind

				var deltaX = touchEndX - touchStartX;
				var deltaY = touchEndY - touchStartY;
				var absDeltaX = Math.abs(deltaX);
				var absDeltaY = Math.abs(deltaY);

				if (!isZooming(event) && absDeltaX >= swipeThreshold && absDeltaX > absDeltaY) {
					if (deltaX > 0) {
						prev_image(); // Wenn nach rechts gewischt wird, zeige das vorherige Bild an
					} else {
						next_image(); // Wenn nach links gewischt wird, zeige das nächste Bild an
					}
				}

				if (isZooming(event)) {
					event.preventDefault(); // Verhindere das Standardverhalten des Events, wenn das Zoomen erkannt wird
				}
			}

			document.addEventListener('keydown', function(event) {
				if($(fullscreen).is(":visible")) {
					return;
				}

				var charCode = event.which || event.keyCode;
				var charStr = String.fromCharCode(charCode);

				if (charCode === 8) { // Backspace-Taste (keyCode 8)
					// Überprüfe, ob der Fokus im Suchfeld liegt
					var searchInput = document.getElementById('searchInput');
					if (document.activeElement !== searchInput) {
						// Lösche den Inhalt des Suchfelds, wenn die Backspace-Taste gedrückt wird
						searchInput.value = '';
						$(searchInput).focus();
					}
				} else if (charCode == 27) { // Escape
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

				// Überprüfe, ob der eingegebene Wert ein Buchstabe oder eine Zahl ist
				if (/[a-zA-Z0-9]/.test(charStr)) {
					// Überprüfe, ob der Fokus nicht bereits im Suchfeld liegt
					var searchInput = document.getElementById('searchInput');
					if (document.activeElement !== searchInput) {
						// Lösche die Suchanfrage, wenn der Fokus nicht im Suchfeld liegt
						searchInput.value = '';
					}

					// Ersetze den markierten Text durch den eingegebenen Buchstaben oder die Zahl
					var selectionStart = searchInput.selectionStart;
					var selectionEnd = searchInput.selectionEnd;
					var currentValue = searchInput.value;
					var newValue = currentValue.substring(0, selectionStart) + charStr + currentValue.substring(selectionEnd);
					searchInput.value = newValue;

					// Aktualisiere die Position des Cursors
					searchInput.selectionStart = searchInput.selectionEnd = selectionStart + 1;

					// Fokussiere das Suchfeld
					if (!$(searchInput).is(":focus")) {
						searchInput.focus();
					}

					// Verhindere das Standardverhalten des Zeichens (z. B. das Hinzufügen eines Zeichens in einem Textfeld)
					event.preventDefault();
				} else if (charCode === 8) { // Backspace-Taste (keyCode 8)
					// Überprüfe, ob der Fokus im Suchfeld liegt
					var searchInput = document.getElementById('searchInput');
					if (document.activeElement === searchInput) {
						// Lösche den Inhalt des Suchfelds, wenn die Backspace-Taste gedrückt wird
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
					async:false
				});

				return strReturn;
			}

			function updateUrlParameter(folder) {
				try {
					// Aktuelle URL holen
					let currentUrl = window.location.href;

					// Überprüfen, ob der Parameter "folder" bereits vorhanden ist
					if (currentUrl.includes('?folder=')) {
						// Falls vorhanden, aktualisieren wir den Wert des Parameters
						currentUrl = currentUrl.replace(/(\?|&)folder=[^&]*/, `$1folder=${folder}`);
					} else {
						// Ansonsten fügen wir den Parameter hinzu
						const separator = currentUrl.includes('?') ? '&' : '?';
						currentUrl += `${separator}folder=${folder}`;
					}

					// Die aktualisierte URL in der Adressleiste setzen
					window.history.replaceState(null, null, currentUrl);
				} catch (error) {
					// Fehlerbehandlung
					console.warn('An error occurred while updating URL parameter "folder":', error);
				}
			}

			function getCurrentFolderParameter() {
				try {
					// Aktuelle URL holen
					const currentUrl = window.location.href;

					// Regex-Muster, um den Wert des "folder"-Parameters zu extrahieren
					const folderRegex = /[?&]folder=([^&]*)/;

					// Den Wert des "folder"-Parameters aus der URL extrahieren
					const match = currentUrl.match(folderRegex);

					// Falls der Parameter nicht vorhanden ist, "./" zurückgeben
					if (!match) {
						return "./";
					}

					// Den extrahierten Wert des "folder"-Parameters zurückgeben
					return decodeURIComponent(match[1]);
				} catch (error) {
					// Fehlerbehandlung
					console.warn('An error occurred while getting current folder parameter:', error);
					// Falls ein Fehler auftritt, "./" zurückgeben
					return "./";
				}
			}

			async function load_folder (folder) {
				updateUrlParameter(folder);

				showPageLoadingIndicator()

				var content = url_content("index.php?gallery=" + folder);

				$("#searchInput").val("");

				$("#searchResults").empty().hide();
				$("#gallery").html(content).show();

				var _promise = draw_map_from_current_images();

				loadAndReplaceImages();

				createBreadcrumb(folder);

				await _promise;

				hidePageLoadingIndicator();
			}

			var json_cache = {};

			function showPageLoadingIndicator() {
				if($(".loading-indicator").length) {
					return;
				}
				const loadingIndicator = document.createElement('div');
				loadingIndicator.classList.add('loading-indicator');
				document.body.appendChild(loadingIndicator);
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

			// JavaScript
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

				// Durchlaufen der Daten, um die minimalen und maximalen Koordinaten zu finden
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

					var text = "<img id='preview_" + hash +
						"' data-line='__A__' src='index.php?preview=" +
						decodeURI(url.replace(/index.php\?preview=/, "")) +
						"' style='width: 100px; height: 100px;' onclick='showImage(\"" +
						decodeURI(url.replace(/index.php\?preview=/, "")) + "\");' />";

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

				//log("filtered_img_elements:", filtered_img_elements);

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

				var markers = _draw_map(data);

				return {
					"data": data,
					"markers": markers
				};
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

						// Füge ein Trennzeichen hinzu, außer beim letzten Element
						breadcrumb.appendChild(document.createTextNode(' / '));
					}
				});

				customizeCursorForLinks();
			}

			// Rufe die Funktion zum Erstellen der Breadcrumb-Leiste auf
			createBreadcrumb('<?php echo $folderPath; ?>');

			$(".no_preview_available").parent().hide();

			function loadAndReplaceImages() {
				$('.loading-thumbnail').each(function() {
					var $thumbnail = $(this);
					var originalUrl = $thumbnail.attr('data-original-url');

					// Bild im Hintergrund laden
					var img = new Image();
					img.onload = function() {
						$thumbnail.attr('src', originalUrl); // Bild austauschen, wenn geladen
						$thumbnail[0].classList.add("box-shadow");
					};
					img.src = originalUrl; // Starte das Laden des Bildes im Hintergrund
				});
			}

			async function delete_search() {
				$("#searchInput").val("");
				await start_search();
			}

			// Funktion zum Abrufen der JSON-Datei
			async function getListAllJSON() {
				try {
					const response = await fetch('index.php?list_all=1');
					const data = await response.json();
					return data;
				} catch (error) {
					console.error('Fehler beim Abrufen der JSON-Datei:', error);
					// Weitere Fehlerbehandlung hier einfügen, falls benötigt
				}
			}

			// Funktion zum Anzeigen der Bilder
			async function fill_cache () {
				try {
					const imageList = await getListAllJSON();
					const container = document.createElement('div');
					container.setAttribute('id', 'image-container');

					for (let i = 0; i < imageList.length; i++) {
						const imageUrl = `index.php?preview=${imageList[i]}`;
						const imageElement = document.createElement('img');
						imageElement.setAttribute('src', imageUrl);

						// Bild anzeigen und warten
						document.body.appendChild(container);
						container.appendChild(imageElement);
						await new Promise(resolve => setTimeout(resolve, 1000)); // Wartezeit in Millisekunden (hier 1 Sekunde)

						// Altes Bild entfernen
						container.removeChild(imageElement);
					}

					// Entferne den gesamten Container am Ende
					document.body.removeChild(container);
				} catch (error) {
					console.error('Fehler beim Anzeigen der Bilder:', error);
					// Weitere Fehlerbehandlung hier einfügen, falls benötigt
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
