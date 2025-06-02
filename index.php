<?php
	$dir = __DIR__ . '/thumbnails_cache';

	if (!is_dir($dir)) {
		die("mkdir thumbnails_cache");
	}

	$euid = posix_geteuid();
	$apacheUserInfo = posix_getpwuid($euid);
	$apacheUser = $apacheUserInfo['name'];

	$stat = stat($dir);
	$ownerUid = $stat['uid'];
	$mode = $stat['mode'];

	if ($ownerUid !== $euid) {
		$ownerName = posix_getpwuid($ownerUid)['name'];
		die("need <pre>chown $apacheUser thumbnails_cache  # (currently owned by $ownerName)");
	}

	$ownerPerms = ($mode & 0b111000000) >> 6;
	$canRead = ($ownerPerms & 0b100) > 0;
	$canWrite = ($ownerPerms & 0b010) > 0;

	if (!$canRead || !$canWrite) {
		$permOctal = substr(sprintf('%o', $mode), -4);
		die("need <pre>chmod u+rw thumbnails_cache  # (currently $permOctal)");
	}


	$GLOBALS["stderr"] = fopen('php://stderr', 'w');

	$GLOBALS["allowed_content_types"] = ["image/png", "image/jpeg", "image/gif"];
	$validTypes = ['jpg', 'jpeg', 'png', 'gif'];

	if (is_executable('/usr/bin/ffmpeg')) {
		$validTypes = array_merge($validTypes, ['mp4', 'mov']);
		$GLOBALS["allowed_content_types"] = array_merge($GLOBALS["allowed_content_types"], ["video/mp4", "video/quicktime"]);
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
		$enable_fuzzy = isset($_GET["allowFuzzy"]) && $_GET["allowFuzzy"] == "true";
		search_and_print_results($_GET['search'], $enable_fuzzy);
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

	$servername = "Galerie";
	if(isset($_SERVER["CONTEXT_PREFIX"])) {
		$servername = $_SERVER["CONTEXT_PREFIX"];
		$servername = ucfirst(preg_replace("/\/*/", "", $servername));
	}
?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="UTF-8">
		<title><?php print $servername; ?></title>
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
		<input type="checkbox" name="fuzzy_search" onclick="start_search()" id="fuzzy_search" checked>Fuzzy-Search?</input>
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
