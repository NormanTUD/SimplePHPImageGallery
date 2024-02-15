<?php
function dier ($msg) {
	print(var_dump($msg));
	exit(0);
}

if (isset($_GET['preview'])) {
	$imagePath = $_GET['preview'];
	$thumbnailMaxWidth = 150; // Definiere maximale Thumbnail-Breite
	$thumbnailMaxHeight = 150; // Definiere maximale Thumbnail-Höhe
	$cacheFolder = './thumbnails_cache/'; // Ordner für den Zwischenspeicher

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
			$exif = exif_read_data($imagePath);
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
			$thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);

			// Fülle den Hintergrund des Thumbnails mit weißer Farbe, um schwarze Ränder zu vermeiden
			$backgroundColor = imagecolorallocate($thumbnail, 255, 255, 255);
			imagefill($thumbnail, 0, 0, $backgroundColor);

			// Verkleinere Originalbild auf Thumbnail-Abmessungen
			imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $thumbnailWidth, $thumbnailHeight, $width, $height);

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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Galerie</title>
    <script src="jquery-3.7.1.min.js"></script>

    <style>
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
    </style>
</head>
<body>
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
<?php
function displayGallery($folderPath)
{
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
			$imageExtensions = array('jpg', 'jpeg', 'png', 'gif');
			$fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

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
			echo '<img src="index.php?preview=' . $thumbnail['thumbnail'] . '" alt="' . $thumbnail['name'] . '">';
		} else {
			echo '<div>No Preview Available</div>';
		}
		echo '<h3>' . $thumbnail['name'] . '</h3>';
		echo "</div>\n";
	}

	foreach ($images as $image) {
		echo '<div class="thumbnail" onclick="showImage(\'' . $image['path'] . '\')">';
		echo '<img src="index.php?preview=' . $image['path'] . '" alt="' . $image['name'] . '">';
		echo "</div>\n";
	}
}

function getImagesInFolder($folderPath)
{
	$folderFiles = scandir($folderPath);

	$images = [];

	foreach ($folderFiles as $file) {
		if ($file === '.' || $file === '..') {
			continue;
		}

		$filePath = $folderPath . '/' . $file;

		$imageExtensions = array('jpg', 'jpeg', 'png', 'gif');
		$fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

		if (in_array($fileExtension, $imageExtensions)) {
			$images[] = [
				'name' => $file,
				'path' => $filePath
			];
		}
	}

	return $images;
}

function getRandomImageFromSubfolders($folderPath)
{
	$subfolders = glob($folderPath . '/*', GLOB_ONLYDIR);

	foreach ($subfolders as $subfolder) {
		$images = getImagesInFolder($subfolder);

		if (!empty($images)) {
			return $images[array_rand($images)];
		}
	}

	return null;
}
?>

<script>
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
	image.src = imagePath;

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
</script>

<?php
$folderPath = './'; // Aktueller Ordner, in dem die index.php liegt

if (isset($_GET['folder']) && !preg_match("/\.\./", $_GET["folder"])) {
	$folderPath = $_GET['folder'];
}

displayGallery($folderPath);
?>
</body>
</html>

