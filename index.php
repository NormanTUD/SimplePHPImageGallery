<?php
ini_set('memory_limit', '2048M');
$images_path = "/docker_images/";

if (is_dir($images_path)) {
	chdir($images_path);
}

function dier ($msg) {
	print(var_dump($msg));
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
	$folderPath = dirname($txtFilePath);

	$files = scandir($folderPath);

	foreach ($files as $file) {
		$fullFilePath = $folderPath . DIRECTORY_SEPARATOR . $file;

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
function searchFiles($folderPath, $searchTerm) {
	$results = [];
	$fileCount = 0; // Zähler für die Anzahl der gefundenen Dateien

	if (!is_dir($folderPath)) {
		return [];
	}

	$files = @scandir($folderPath);

	if(is_bool($files)) {
		return [];
	}

	$searchTermLower = strtolower($searchTerm);

	foreach ($files as $file) {
		if ($file === '.' || $file === '..' || $file === '.git' || $file === "thumbnails_cache") {
			continue;
		}

		$filePath = $folderPath . '/' . $file;

		if (is_dir($filePath)) {
			if (stripos($file, $searchTermLower) !== false) {
				$randomImage = getRandomImageFromSubfolders($filePath);
				$thumbnailPath = $randomImage ? $randomImage['path'] : '';

				$results[] = [
					'path' => $filePath,
					'type' => 'folder',
					'thumbnail' => $thumbnailPath
				];
				$fileCount++;

				if ($fileCount >= 50) {
					break; // Abbruch der Schleife, wenn die maximale Anzahl erreicht ist
				}
			}

			$subResults = searchFiles($filePath, $searchTerm);
			$results = array_merge($results, $subResults);
		} else {
			$fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

			$imageExtensions = array('jpg', 'jpeg');

			if ($fileExtension === 'txt') {
				$textContent = file_get_contents($filePath);
				if (stripos($textContent, $searchTermLower) !== false) {
					$imageFilePath = searchImageFileByTXT($filePath);

					if($imageFilePath) {
						$results[] = [
							'path' => $imageFilePath,
							'type' => 'file'
						];
						$fileCount++;

						if ($fileCount >= 50) {
							break;
						}
					}
				}
			} elseif (in_array($fileExtension, $imageExtensions)) {
				if (stripos($file, $searchTermLower) !== false) {
					$results[] = [
						'path' => $filePath,
						'type' => 'file'
					];
					$fileCount++;

					if ($fileCount >= 50) {
						break;
					}
				}
			}
		}
	}

	return $results;
}

// AJAX-Handler für die Suche
if (isset($_GET['search'])) {
	$searchTerm = $_GET['search'];
	$results = searchFiles('.', $searchTerm); // Suche im aktuellen Verzeichnis

	// Ausgabe der Ergebnisse als JSON
	echo json_encode($results);
	exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Galerie</title>
<?php
	$jquery_file = 'jquery-3.7.1.min.js';
	if(!file_exists($jquery_file)) {
		$jquery_file = "https://code.jquery.com/jquery-3.7.1.js";
	}
?>
    <script src="<?php print $jquery_file; ?>"></script>

    <style>
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
</head>
<body>
<input onkeyup="start_search()" onchange='start_search()' type="text" id="searchInput" placeholder="Suche...">
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
function displayGallery($folderPath) {
	if(!is_dir($folderPath)) {
		print("Folder not found");
		return [];
	}

	$files = scandir($folderPath);

	$thumbnails = [];
	$images = [];

	foreach ($files as $file) {
		if ($file === '.' || $file === '..'  || preg_match("/^\./", $file) || $file === "thumbnails_cache") {
			continue;
		}

		$filePath = $folderPath . '/' . $file;

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

			$imageExtensions = array('jpg', 'jpeg');
			if (in_array($fileExtension, $imageExtensions)) {
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
		echo '<div class="thumbnail_folder" onclick="showFolder(\'' . $thumbnail['path'] . '\')">';
		if (!empty($thumbnail['thumbnail'])) {
			echo '<img draggable="false" src="index.php?preview=' . $thumbnail['thumbnail'] . '" alt="' . $thumbnail['name'] . '">';
		} else {
			echo '<div class="no_preview_available">No Preview Available</div>';
		}
		echo '<h3>' . $thumbnail['name'] . '</h3>';
		echo "</div>\n";
	}

	foreach ($images as $image) {
		echo '<div class="thumbnail" onclick="showImage(\'' . $image['path'] . '\')">';
		echo '<img draggable="false" src="index.php?preview=' . $image['path'] . '" alt="' . $image['name'] . '">';
		echo "</div>\n";
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

		$imageExtensions = array('jpg', 'jpeg');
		if (in_array($fileExtension, $imageExtensions)) {
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
var log = console.log;
var l = log;

function start_search () {
	var searchTerm = $('#searchInput').val();

	if(!/^\s*$/.test(searchTerm)) {
		$("#searchResults").show();
		$("#gallery").hide();
		$.ajax({
		url: 'index.php',
			type: 'GET',
			data: {
			search: searchTerm
		},
			success: function(response) {
				displaySearchResults(searchTerm, JSON.parse(response));
			},
			error: function(xhr, status, error) {
				console.error(error);
			}
		});
	} else {
		$("#searchResults").hide();
		$("#gallery").show();
	}
}

// Funktion zur Anzeige der Suchergebnisse
function displaySearchResults(searchTerm, results) {
	var $searchResults = $('#searchResults');
	$searchResults.empty();

	if (results.length > 0) {
		$searchResults.append('<h2>Suchergebnisse:</h2>');

		results.forEach(function(result) {
			// Link oder Vorschaubild für das Suchergebnis anzeigen
			// Hier kannst du die Logik anpassen, um Links oder Vorschaubilder anzuzeigen
			if (result.type === 'folder') {
				var folderThumbnail = result.thumbnail;
				var folder_line = `<div class="thumbnail_folder" onclick="showFolder('${result.path}')">`;

				// Falls ein Vorschaubild vorhanden ist, verwenden wir es, sonst zeigen wir an, dass kein Vorschaubild verfügbar ist
				if (folderThumbnail) {
					folder_line += `<img draggable="false" src="index.php?preview=${folderThumbnail}" alt="${result.path}">`;
				} else {
					folder_line += '<div class="no_preview_available">No Preview Available</div>';
				}

				folder_line += `<h3>${result.path.replace(/\.\//, "")}</h3></div>`;
				$searchResults.append(folder_line);
			} else if (result.type === 'file') {
				var fileName = result.path.split('/').pop(); // Dateiname aus dem Dateipfad extrahieren
				var image_line = `<div class="thumbnail" onclick="showImage('${result.path}')"><img draggable="false" src="index.php?preview=${result.path}" alt="${fileName}"></div>`
					$searchResults.append(image_line);
				//$searchResults.append('<div><a href="' + result.path + '">' + fileName + '</a> (Bild)</div>');
			}
		});
	} else {
		$searchResults.append('<p>Keine Ergebnisse gefunden.</p>');
	}
}

function showFolder(folderPath) {
	window.location.href = '?folder=' + encodeURIComponent(folderPath);
}

var fullscreen;

function showImage(imagePath) {
	$(fullscreen).remove();

	fullscreen = document.createElement('div');
	fullscreen.classList.add('fullscreen');
	fullscreen.onclick = function() {
		fullscreen.parentNode.removeChild(fullscreen);
	};

	var image = document.createElement('img');
	image.src = "image.php?path=" + imagePath;
	image.setAttribute('draggable', false);

	fullscreen.appendChild(image);
	document.body.appendChild(fullscreen);
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
		}
	}
});

</script>

<?php
$folderPath = './'; // Aktueller Ordner, in dem die index.php liegt

if (isset($_GET['folder']) && !preg_match("/\.\./", $_GET["folder"])) {
	$folderPath = $_GET['folder'];
}
?>

<!-- Ergebnisse der Suche hier einfügen -->
<div id="searchResults"></div>

<div id="gallery">
<?php displayGallery($folderPath); ?>
</div>
<script>
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

$(".no_preview_available").parent().hide()
</script>
</body>
</html>
