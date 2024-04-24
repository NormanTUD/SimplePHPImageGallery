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

function convertToRelativePath($absolutePath) {
	try {
		// Erhalten des aktuellen Verzeichnisses
		$currentDirectory = getcwd();

		// Überprüfen, ob der angegebene Pfad im aktuellen Verzeichnis liegt
		if (strpos($absolutePath, $currentDirectory) !== false) {
			// Entfernen des aktuellen Verzeichnisses aus dem absoluten Pfad
			$relativePath = str_replace($currentDirectory, '.', $absolutePath);

			// Entfernen führender Schrägstriche oder Backslashes
			$relativePath = ltrim($relativePath, '/\\');

			return $relativePath;
		} else {
			// Wenn der angegebene Pfad nicht im aktuellen Verzeichnis liegt, Fehlermeldung ausgeben
			throw new Exception("Der angegebene Pfad liegt nicht im aktuellen Verzeichnis.");
		}
	} catch (Exception $e) {
		// Fehler abfangen und behandeln (hier: Logging und Warnung)
		error_log("Fehler beim Konvertieren des absoluten Pfads in einen relativen Pfad: " . $e->getMessage());
		echo "Fehler: " . $e->getMessage();
	}
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
					$images[] = convertToRelativePath($filePath);
				}
			}
		}
	}

	return $images;
}

if (isset($_GET["geolist"])) {
	$geolist = $_GET["geolist"];

	$untested_files = explode(";;;", $geolist);

	$files = [];

	if ($_GET["geolist"] == "1") {
		$currentDirectory = __DIR__;

		$files = getImagesInDirectory($currentDirectory);
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
		$thumbnailFileName = md5($imagePath) . '.jpg'; // Hier verwenden wir MD5 für die Eindeutigkeit, und speichern als JPEG

		// Überprüfe, ob das Thumbnail im Cache vorhanden ist
		$cachedThumbnailPath = $cacheFolder . $thumbnailFileName;
		if (file_exists($cachedThumbnailPath)) {
			// Das Thumbnail existiert im Cache, geben Sie es direkt aus
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

			// Beende die Skriptausführung
			exit;
		}
	} else {
		echo 'File not found.';
	}

	// Beende die Skriptausführung
	exit;
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

			if ($fileExtension !== 'txt' && $pathWithoutExtension == removeFileExtensionFromString($fullFilePath) && preg_match("/\.(?:jpe?g|gif|png)$/i", $fullFilePath)) {
				return $fullFilePath;
			}
		}
	}

	return null;
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

	$searchTermLower = strtolower($searchTerm);
	$normalized = normalize_special_characters($searchTerm);

	foreach ($files as $file) {
		if ($file === '.' || $file === '..' || $file === '.git' || $file === "thumbnails_cache") {
			continue;
		}

		$filePath = $fp . '/' . $file;

		$file_without_ending = preg_replace("/\.(jpe?g|png|gif)$/i", "", $file);

		if (is_dir($filePath)) {
			#print("stripos(".normalize_special_characters($file).", ".$normalized.")\n");
			if (stripos($file_without_ending, $searchTermLower) !== false || stripos(normalize_special_characters($file_without_ending), $normalized) !== false) {
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
				$textContent = file_get_contents($filePath);
				if (stripos($textContent, $searchTermLower) !== false || stripos(normalize_special_characters($textContent), $normalized) !== false) {
					$imageFilePath = searchImageFileByTXT($filePath);

					if($imageFilePath) {
						$results[] = [
							'path' => $imageFilePath,
							'type' => 'file'
						];
					}
				}
			} elseif (in_array($fileExtension, $GLOBALS["FILETYPES"])) {
				if (stripos($file_without_ending, $searchTermLower) !== false || stripos(normalize_special_characters($file), $normalized) !== false) {
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
			#delete_search {
				color: red;
				background-color: #fafafa;
				text-decoration: none;
				border: 1px groove darkblue;
				border-radius: 5px;
				margin: 3px;
				padding: 3px;
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
			}
		</style>

		<script src="https://cdn.jsdelivr.net/npm/leaflet@1.7.1/dist/leaflet.js"></script>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.7.1/dist/leaflet.css" />
	</head>
	<body>
		<input onkeyup="start_search()" onchange='start_search()' type="text" id="searchInput" placeholder="Search...">
		<button style="display: none" id="delete_search" onclick='delete_search(1)'>&#x2715;</button>
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

<?php
function getCoord( $expr ) {
	$expr_p = explode( '/', $expr );

	if (count($expr_p) == 2) {
		if($expr_p[1]) {
			return $expr_p[0] / $expr_p[1];
		}
	}

	return null;
}

function convertToDecimalLatitude($degrees, $minutes, $seconds, $direction) {
	// Convert degrees, minutes, and seconds to decimal
	$decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

	// Adjust sign based on direction (N or S)
	if ($direction == 'S') {
		$decimal *= -1;
	}

	return $decimal;
}

function convertToDecimalLongitude($degrees, $minutes, $seconds, $direction) {
	// Convert degrees, minutes, and seconds to decimal
	$decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

	// Adjust sign based on direction (E or W)
	if ($direction == 'W') {
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
		"latitude" => convertToDecimalLatitude($latitude['degrees'], $latitude['minutes'], $latitude['seconds'], $latitude_direction),
		"longitude" => convertToDecimalLongitude($longitude['degrees'], $longitude['minutes'], $longitude['seconds'], $longitude_direction)
	);

	if(is_nan($res["latitude"]) || is_nan($res["longitude"])) {
		return null;
	}

	$json_data = json_encode($res);

	file_put_contents($cache_file, $json_data);

	return $res;
}

function is_valid_image_file ($filepath) {
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
				'path' => $filePath
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
			echo '<a href="?folder=' . urlencode($thumbnail['path']) . '"><div class="thumbnail_folder">';
			echo '<img draggable="false" src="loading.gif" alt="Loading..." class="loading-thumbnail" data-original-url="index.php?preview=' . $thumbnail['thumbnail'] . '">';
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

			echo '<div class="thumbnail" onclick="showImage(\'' . $image['path'] . '\')">';
			echo '<img data-hash="'.$hash.'" '.$gps_data_string.' draggable="false" src="loading.gif" alt="Loading..." class="loading-thumbnail" data-original-url="index.php?preview=' . $image['path'] . '">';
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
?>

		<script>
			var map = null;
			var log = console.log;
			var l = log;

			var searchTimer; // Globale Variable für den Timer

			function start_search() {
				var searchTerm = $('#searchInput').val();

				// Funktion zum Abbrechen der vorherigen Suchanfrage
				function abortPreviousRequest() {
					if (searchTimer) {
						clearTimeout(searchTimer);
					}
				}

				// Funktion zum Durchführen der Suchanfrage
				function performSearch() {
					// Abbrechen der vorherigen Anfrage, falls vorhanden
					abortPreviousRequest();

					if (!/^\s*$/.test(searchTerm)) {
						$("#delete_search").show();
						$("#searchResults").show();
						$("#gallery").hide();
						$.ajax({
						url: 'index.php',
							type: 'GET',
							data: {
							search: searchTerm
						},
							success: async function (response) {
								await displaySearchResults(searchTerm, response["files"]);
							},
							error: function (xhr, status, error) {
								console.error(error);
							}
						});
					} else {
						$("#delete_search").hide();
						$("#searchResults").hide();
						$("#gallery").show();
					}
				}

				// Starten der Suche nach einer Sekunde Verzögerung
				searchTimer = setTimeout(performSearch, 1000);
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
								var folder_line = `<a href="?folder=${encodeURI(result.path)}"><div class="thumbnail_folder">`;

								// Ersetze das Vorschaubild mit einem Lade-Spinner
								folder_line += `<img src="loading.gif" alt="Loading..." class="loading-thumbnail-search" data-original-url="index.php?preview=${folderThumbnail}">`;

								folder_line += `<h3>${result.path.replace(/\.\//, "")}</h3></div></a>`;
								$searchResults.append(folder_line);
							}
						} else if (result.type === 'file') {
							var fileName = result.path.split('/').pop();
							var image_line = `<div class="thumbnail" onclick="showImage('${result.path}')">`;

							var gps_data_string = "";

							if(result.latitude && result.longitude) { // TODO: was für Geocoords 0, 0?
								gps_data_string = ` data-latitude="${result.latitude}" data-longitude="${result.longitude}" `;
							}

							// Ersetze das Vorschaubild mit einem Lade-Spinner
							image_line += `<img data-hash="${result.hash}" ${gps_data_string} src="loading.gif" alt="Loading..." class="loading-thumbnail-search" data-original-url="index.php?preview=${result.path}">`;

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

				await draw_map_from_current_images(0);
			}

			var fullscreen;

			function showImage(imagePath) {
				$(fullscreen).remove();

				// Create fullscreen div
				fullscreen = document.createElement('div');
				fullscreen.classList.add('fullscreen');
				fullscreen.onclick = function() {
					fullscreen.parentNode.removeChild(fullscreen);
				};

				// Create image element with loading.gif initially
				var image = document.createElement('img');
				image.src = "loading.gif";
				image.setAttribute('draggable', false);

				// Append image to fullscreen div
				fullscreen.appendChild(image);
				document.body.appendChild(fullscreen);

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
			}

			function get_fullscreen_img_name () {
				var src = $(".fullscreen").find("img").attr("src");

				if(src) {
					src = src.replace(/.*\//, "");

					return src;
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

				onclick = onclick.replace(/.*\//, "").replace(/'.*/, "");

				if(onclick == current_fullscreen) {
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
			}, false);

			document.addEventListener('touchend', function(event) {
				touchEndX = event.changedTouches[0].clientX;
				handleSwipe();
			}, false);

			function handleSwipe() {
				var swipeThreshold = 50; // Mindestanzahl von Pixeln, die für einen Wisch erforderlich sind

				var deltaX = touchEndX - touchStartX;

				if (Math.abs(deltaX) >= swipeThreshold) {
					if (deltaX > 0) {
						prev_image(); // Wenn nach rechts gewischt wird, zeige das vorherige Bild an
					} else {
						next_image(); // Wenn nach links gewischt wird, zeige das nächste Bild an
					}
				}
			}

			document.addEventListener('keydown', function(event) {
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

		</script>

		<!-- Ergebnisse der Suche hier einfügen -->
		<div id="searchResults"></div>

		<div id="gallery">
			<?php displayGallery($folderPath); ?>
		</div>

		<script>
			var json_cache = {};

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


			function draw_map(data) {
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
							"' src='" +
							url + 
							"' style='width: 100px; height: 100px;' onclick='showImage(\"" + 
							url.replace(/index.php\?preview=/, "") + "\");' />";

						eval(`markers['${hash}'].on('click', function() {
							var popup = L.popup().setContent(\`${text}\`);

							this.bindPopup(popup).openPopup();
						});`);

						markers[hash].addTo(map);

						i++;
					}
			}

			async function draw_map_from_current_images (show_all_available) {
				var data = [];

				if(show_all_available) {
					var url = "index.php?geolist=1";
					data = await get_json_cached(url);
				} else {
					var img_elements = $("img");

					if ($("#searchResults").html().length) {
						img_elements = $("#searchResults").find("img");
					}

					img_elements.each(function (i, e) {
						var src = $(e).data("original-url");
						var hash = $(e).data("hash");
						var lat = $(e).data("latitude");
						var lon = $(e).data("longitude");

						if(src && hash && lat && lon) {
							data.push({
								"hash": hash,
								"url": src,
								"latitude": lat,
								"longitude": lon
							});
						}
					})
				}

				//log("show_all_available:", show_all_available, "data:", data);

				draw_map(data);
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
						link.href = '?folder=' + encodeURIComponent(fullPath);
						link.textContent = folderName;

						breadcrumb.appendChild(link);

						// Füge ein Trennzeichen hinzu, außer beim letzten Element
						breadcrumb.appendChild(document.createTextNode(' / '));
					}
				});
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
					};
					img.src = originalUrl; // Starte das Laden des Bildes im Hintergrund
				});
			}

			function delete_search(trigger=0) {
				$("#searchInput").val("");
				$("#delete_search").hide();
				if(trigger) {
					$("#searchInput").trigger("change");
					draw_map_from_current_images();
				}
			}

			$(document).ready(async function() {
				$("#delete_search").hide();
				delete_search();

				loadAndReplaceImages();

<?php
				$is_startpage = $_GET["folder"] == "./" || !isset($_GET["folder"]);

				if($is_startpage) {
?>
					await draw_map_from_current_images(1);
<?php
				} else {
?>
					await draw_map_from_current_images(0);
<?php
				}
?>
			});
		</script>

		<div id="map_container" style="display: none">
			<div id="map" style="height: 400px; width: 100%;"></div>
		</div>
	</body>
</html>
