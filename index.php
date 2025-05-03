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
		$exit_code = create_zip();
		exit($exit_code);
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
		display_gallery($_GET["gallery"]);
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
		<script src="functions.js"></script>
	</head>
	<body>
		<input onkeyup="start_search()" onchange='start_search()' type="text" id="searchInput" placeholder="Search...">
		<button style="display: none" id="delete_search" onclick='delete_search()'>&#x2715;</button>
		<button class="download-btn" id="downloadBtn" onclick="downloadSelected()">Download</button>
		<button class="unselect-btn" id="unselectBtn" onclick="unselectSelection()">Unselect</button>
<?php
		show_links_if_available();
?>
		<div id="breadcrumb"></div>
		<script>
			var current_folder_path = '<?php echo $folderPath; ?>'
		</script>

		<script src="main.js"></script>

		<div id="searchResults"></div>

		<div id="gallery"></div>

		<div id="map_container" style="display: none">
			<div id="map" style="height: 400px; width: 100%;"></div>
		</div>
	</body>
</html>
