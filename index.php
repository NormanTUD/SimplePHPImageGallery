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

	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: no-referrer');
	header('X-Frame-Options: SAMEORIGIN');
	header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://server.arcgisonline.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline'; connect-src 'self' https://server.arcgisonline.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");

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

	function asset_url($file) {
		$path = __DIR__ . '/' . $file;
		return $file . '?v=' . (file_exists($path) ? filemtime($path) : '1');
	}

	if (isset($_GET["file_info"])) {
		print_file_metadata();
		exit(0);
	}

	if (isset($_GET['zip']) && $_GET['zip'] == 1) {
		$exit_code = create_zip();
		exit($exit_code);
	}

	if (isset($_GET['search'])) {
		$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
		$requested_with = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
		$is_browser_navigation = strpos($accept, 'text/html') !== false && $requested_with !== 'xmlhttprequest';

		// Browser navigations render the app shell for search deep links.
		// API/search clients keep the JSON response behaviour.
		if (!$is_browser_navigation) {
			$enable_fuzzy = isset($_GET["allowFuzzy"]) && $_GET["allowFuzzy"] == "true";
			$search_type = $_GET["type"] ?? "all";
			search_and_print_results($_GET['search'], $enable_fuzzy, $search_type);
			exit(0);
		}
	}

	if (isset($_GET['list_all'])) {
		list_all();
		exit(0);
	}

	if (isset($_GET['preview'])) {
		create_preview($_GET['preview'], thumbnail_size_from_request($_GET['size'] ?? ''));
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
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
		<meta name="theme-color" content="#f4efe7" media="(prefers-color-scheme: light)">
		<meta name="theme-color" content="#111318" media="(prefers-color-scheme: dark)">
		<link rel="manifest" href="manifest.webmanifest">
		<link rel="icon" href="icon.svg" type="image/svg+xml">
		<link rel="apple-touch-icon" href="icon-192.png">
		<title><?php echo htmlspecialchars($servername); ?></title>
		<script defer src="<?php echo asset_url('jquery-3.7.1.min.js'); ?>"></script>
		<link rel="stylesheet" href="<?php echo asset_url('style.css'); ?>" />
		<script defer src="<?php echo asset_url('leaflet.js'); ?>"></script>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.7.1/dist/leaflet.css" />
		<link rel="stylesheet" href="MarkerCluster.Default.css" />
		<script defer src="<?php echo asset_url('leaflet.markercluster.js'); ?>"></script>
		<script defer src="<?php echo asset_url('functions.js'); ?>"></script>
	</head>
	<body>
		<div class="app-shell">
			<header class="app-header">
				<div class="topbar">
					<a class="brand" href="?folder=./" aria-label="Go to gallery start">
						<span class="brand-mark" aria-hidden="true"></span>
						<span class="brand-name"><?php echo htmlspecialchars($servername); ?></span>
					</a>
					<div class="search-field">
						<input onkeyup="start_search()" onchange="start_search()" type="text" id="searchInput" list="searchHistory" placeholder="Search images, folders, tags…" aria-label="Search images, folders and tags" autocomplete="off" spellcheck="false">
						<datalist id="searchHistory"></datalist>
					</div>
					<label class="fuzzy-toggle" for="fuzzy_search">
						<input type="checkbox" name="fuzzy_search" onclick="start_search()" id="fuzzy_search">
						<span>Fuzzy</span>
					</label>
					<select class="search-type" id="searchType" onchange="start_search()" aria-label="Search result type">
						<option value="all">All</option>
						<option value="file">Photos</option>
						<option value="folder">Folders</option>
					</select>
					<button class="icon-btn" id="delete_search" onclick="delete_search()" aria-label="Clear search" style="display: none">&#x2715;</button>
					<button class="icon-btn shortcuts-btn" id="shortcutsBtn" onclick="show_shortcuts()" aria-label="Keyboard shortcuts and gestures" title="Keyboard shortcuts and gestures">?</button>
					<button class="icon-btn mobile-more-btn" id="mobileMoreBtn" type="button" onclick="show_mobile_actions()" aria-label="More actions" aria-expanded="false">&#x22ef;</button>
					<button class="select-btn select-mode-toggle" id="selectModeBtn" onclick="toggle_selection_mode()" aria-label="Toggle selection mode">Select</button>
					<button class="select-btn view-btn timeline-toggle" id="timelineBtn" onclick="toggle_timeline_view()" aria-pressed="false">Timeline</button>
					<button class="select-btn view-btn favorites-toggle" id="favoritesBtn" onclick="toggle_favorites_filter()" aria-pressed="false">Favorites <span class="favorites-count" id="favoritesCount">0</span></button>
					<button class="select-btn view-btn slideshow-toggle" id="slideshowBtn" onclick="toggle_slideshow()" aria-pressed="false">Slideshow</button>
					<button class="select-btn view-btn warm-cache-toggle" id="warmCacheBtn" onclick="toggle_cache_warmup()" aria-pressed="false">Warm cache</button>
					<button class="select-btn view-btn install-toggle" id="installAppBtn" onclick="install_pwa()" hidden>Install app</button>
					<div class="action-buttons">
						<button class="download-btn" id="downloadBtn" onclick="downloadSelected()">Download</button>
						<button class="unselect-btn" id="unselectBtn" onclick="unselectSelection()">Unselect</button>
					</div>
				</div>
				<div class="site-links"><?php show_links_if_available(); ?></div>
				<div id="breadcrumb"></div>
			</header>
			<main class="app-content">
				<div id="galleryMeta" class="gallery-meta" aria-live="polite"></div>
				<div id="searchResults" aria-live="polite"></div>
				<div id="gallery"></div>
				<div id="gallerySentinel" class="gallery-sentinel" aria-hidden="true" hidden></div>
				<div id="emptyState" class="empty-state" hidden>
					<div class="empty-state-mark" aria-hidden="true"></div>
					<h2>Nothing here yet</h2>
					<p>This folder has no photos or subfolders.</p>
					<button class="empty-state-btn" type="button" onclick="load_folder('./')">Back to start</button>
				</div>
				<div id="mapTrigger" class="map-trigger" aria-hidden="true"></div>
				<div id="map_container" class="map-card" hidden>
					<div id="map"></div>
				</div>
			</main>
		</div>
		<button class="back-to-top" id="backToTop" type="button" onclick="window.scrollTo({ top: 0, behavior: 'smooth' })" aria-label="Back to top">&#x2191;</button>
		<div id="cacheProgress" class="cache-progress" role="status" aria-live="polite" hidden>
			<span class="cache-progress-spinner" aria-hidden="true"></span>
			<span id="cacheProgressText">Warming cache…</span>
			<button type="button" class="cache-progress-cancel" aria-label="Stop warming cache" onclick="cancel_cache_warmup()">&#x2715;</button>
		</div>
		<div class="mobile-actions" id="mobileActions" hidden>
			<button class="mobile-actions-backdrop" type="button" onclick="hide_mobile_actions()" aria-label="Close actions"></button>
			<div class="mobile-actions-sheet" role="dialog" aria-modal="true" aria-label="More actions">
				<div class="mobile-actions-handle" aria-hidden="true"></div>
				<div class="mobile-actions-grid">
					<button class="mobile-action select-mode-toggle" type="button" onclick="toggle_selection_mode(); hide_mobile_actions()">Select</button>
					<button class="mobile-action timeline-toggle" type="button" aria-pressed="false" onclick="toggle_timeline_view(); hide_mobile_actions()">Timeline</button>
					<button class="mobile-action favorites-toggle" type="button" aria-pressed="false" onclick="toggle_favorites_filter(); hide_mobile_actions()">Favorites <span class="favorites-count">0</span></button>
					<button class="mobile-action slideshow-toggle" type="button" aria-pressed="false" onclick="toggle_slideshow(); hide_mobile_actions()">Slideshow</button>
					<button class="mobile-action download-btn" type="button" onclick="downloadSelected(); hide_mobile_actions()">Download</button>
					<button class="mobile-action unselect-btn" type="button" onclick="unselectSelection(); hide_mobile_actions()">Unselect</button>
					<button class="mobile-action warm-cache-toggle" type="button" aria-pressed="false" onclick="toggle_cache_warmup(); hide_mobile_actions()">Warm cache</button>
					<button class="mobile-action install-toggle" type="button" onclick="install_pwa()" hidden>Install app</button>
				</div>
			</div>
		</div>
		<script>
			var current_folder_path = <?php echo json_encode($folderPath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
		</script>

		<script defer src="<?php echo asset_url('main.js'); ?>"></script>
	</body>
</html>
