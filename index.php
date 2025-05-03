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

	include("functions.php");

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

	if (isset($_GET['search'])) {
		search_and_print_results($_GET['search']);
		exit(0);
	}

	if (isset($_GET['list_all'])) {
		list_all();
		exit(0);
	}

	if (isset($_GET['preview'])) {
		create_preview($_GET['preview']);
		exit(0);
	}

	if (isset($_GET["geolist"])) {
		print_geolist($_GET["geolist"]);
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
		<link rel="stylesheet" href="style.css" />
		<script src="leaflet.js"></script>
		<script src="swiper-bundle.min.js"></script>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.7.1/dist/leaflet.css" />
		<link rel="stylesheet" href="swiper-bundle.min.css" />
		<script src="main.js"></script>
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

			document.onkeydown = checkKey;

			var touchStartX = 0;
			var touchEndX = 0;

			var json_cache = {};
			var fill_cache_images = [];

			document.addEventListener('touchstart', function(event) {
				touchStartX = event.touches[0].clientX;
				touchStartY = event.touches[0].clientY;
			}, false);

			document.addEventListener('touchend', function(event) {
				touchEndX = event.changedTouches[0].clientX;
				touchEndY = event.changedTouches[0].clientY;
				handleSwipe(event);
			}, false);

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

			showPageLoadingIndicator();

			createBreadcrumb('<?php echo $folderPath; ?>');

			$(".no_preview_available").parent().hide();

			$(document).ready(async function() {
				$("#delete_search").hide();
				addLinkHighlightEffect();
				await delete_search();

				await load_folder(getCurrentFolderParameter());
				hidePageLoadingIndicator();
			});
		</script>

		<div id="searchResults"></div>

		<div id="gallery"></div>

		<div id="map_container" style="display: none">
			<div id="map" style="height: 400px; width: 100%;"></div>
		</div>
	</body>
</html>
