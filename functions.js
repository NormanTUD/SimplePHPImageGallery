let debounce_timer = null;
let active_search_xhr = null;
let search_request_id = 0;
let folder_load_token = 0;
let media_event_delegation_installed = false;
let active_media_container = null;
let lazy_image_observer = null;
let map_draw_token = 0;

function escape_html_attribute(value) {
	return String(value)
		.replace(/&/g, "&amp;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#39;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;");
}

function decode_php_url_component(value) {
	try {
		return decodeURIComponent(String(value).replace(/\+/g, " "));
	} catch (e) {
		return String(value);
	}
}

function normalize_folder_path(value) {
	if (!value) {
		return "";
	}

	var path = String(value);
	if (path === ".") {
		return "./";
	}
	if (path.startsWith("./")) {
		return path;
	}
	return "";
}

function get_media_path(container, is_folder) {
	if (!container) {
		return "";
	}

	var direct_path = container.getAttribute("data-path");
	if (direct_path && !is_folder) {
		return direct_path;
	}

	if (is_folder) {
		if (direct_path) {
			return normalize_folder_path(direct_path);
		}

		var link = container.closest("a");
		var href = link ? link.getAttribute("data-href") : null;
		if (href) {
			return normalize_folder_path(decode_php_url_component(href));
		}

		var folder_onclick = link ? $(link).data("onclick") : null;
		if (typeof folder_onclick === "string") {
			var folder_match = folder_onclick.match(/'([^']*)'|"([^"]*)"/);
			if (folder_match) {
				return normalize_folder_path(decode_php_url_component(folder_match[1] || folder_match[2]));
			}
		}

		return "";
	}

	var img = container.querySelector("img");
	var original_url = img ? img.getAttribute("data-original-url") : null;
	if (original_url) {
		return decode_php_url_component(original_url.replace(/^index\.php\?preview=/, ""));
	}

	var onclick = $(container).data("onclick");
	if (typeof onclick === "string") {
		var match = onclick.match(/'([^']*)'|"([^"]*)"/);
		if (match) {
			return decode_php_url_component(match[1] || match[2]);
		}
	}

	return "";
}

function set_checkmark_visibility(container, visible) {
	var checkmark = container.querySelector(".checkmark");
	if (checkmark) {
		checkmark.style.display = "block";
		checkmark.style.visibility = visible ? "visible" : "hidden";
	}
}

function sync_selection_mode_ui() {
	var selectButton = document.getElementById("selectModeBtn");
	if (selectButton) {
		selectButton.textContent = enabled_selection_mode ? "Done" : "Select";
		selectButton.setAttribute("aria-pressed", enabled_selection_mode ? "true" : "false");
	}

	document.body.classList.toggle("selection-mode", enabled_selection_mode);
}

function toggle_selection_mode() {
	if (enabled_selection_mode) {
		unselectSelection();
		return;
	}

	enabled_selection_mode = true;
	sync_selection_mode_ui();
	updateDownloadButton();
	updateUnselectButton();
}

function toggle_media_selection(container, is_folder) {
	var item = get_media_path(container, is_folder);
	if (!item) {
		return;
	}

	if (is_folder) {
		if (selectedFolders.includes(item)) {
			selectedFolders = selectedFolders.filter(i => i !== item);
			set_checkmark_visibility(container, false);
			container.classList.remove("is-selected");
		} else {
			selectedFolders.push(item);
			set_checkmark_visibility(container, true);
			container.classList.add("is-selected");
		}
	} else {
		if (selectedImages.includes(item)) {
			selectedImages = selectedImages.filter(i => i !== item);
			set_checkmark_visibility(container, false);
			container.classList.remove("is-selected");
		} else {
			selectedImages.push(item);
			set_checkmark_visibility(container, true);
			container.classList.add("is-selected");
		}
	}

	updateDownloadButton();
	updateUnselectButton();
	enabled_selection_mode = true;
	sync_selection_mode_ui();
}

function updateDownloadButton() {
	var downloadBtn = document.getElementById('downloadBtn');
	if (selectedImages.length > 0 || selectedFolders.length > 0) {
		downloadBtn.style.visibility = 'inherit';
	} else {
		downloadBtn.style.visibility = 'hidden';
	}
}

function unselectSelection() {
	enabled_selection_mode = false;
	selectedImages = [];
	selectedFolders = [];

	updateDownloadButton();
	updateUnselectButton();
	sync_selection_mode_ui();

	$(".checkmark").css({ display: "block", visibility: "hidden" });
	$(".is-selected").removeClass("is-selected");
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

	var container = e.target.closest('.thumbnail_folder');
	if (!container) {
		return;
	}

	var d = new Date();
	var long_click = (d.getTime() - select_folder_timer) > 1000;

	if (long_click || enabled_selection_mode){
		e.preventDefault();
		toggle_media_selection(container, true);
	} else {
		load_folder(get_media_path(container, true));
	}

	select_folder_timer = 0;
}

function onImageMouseUp(e){
	removeProgressBar();

	var container = e.target.closest('.thumbnail');
	if (!container || container.closest('.thumbnail_folder')) {
		return;
	}

	var d = new Date();
	var long_click = (d.getTime() - select_image_timer) > 1000;

	if (long_click || enabled_selection_mode){
		e.preventDefault();
		toggle_media_selection(container, false);
	} else {
		showImage(get_media_path(container, false));
	}

	select_image_timer = 0;
}

function updateUnselectButton() {
	var unselectBtn = document.getElementById('unselectBtn');
	if (selectedImages.length > 0 || selectedFolders.length > 0) {
		unselectBtn.style.visibility = 'inherit';
	} else {
		unselectBtn.style.visibility = 'hidden';
	}
}

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

function load_lazy_image(img) {
	if (!img) {
		return;
	}

	var original_url = img.getAttribute('data-original-url');
	if (!original_url || img.getAttribute('data-lazy-loaded') === '1') {
		return;
	}

	var card = img.closest(".thumbnail, .thumbnail_folder");
	if (card) {
		card.classList.add("is-loaded");
	}

	img.setAttribute('data-lazy-loaded', '1');
	img.addEventListener('load', function () {
		img.classList.add("box-shadow", "is-loaded");
	});
	img.addEventListener('error', function () {
		img.classList.add("box-shadow", "is-loaded");
		if (!img.getAttribute("src")?.endsWith("error.svg")) {
			img.src = "error.svg";
		}
	});
	img.src = original_url;
}

function get_lazy_image_observer() {
	if (!("IntersectionObserver" in window)) {
		return null;
	}

	if (!lazy_image_observer) {
		lazy_image_observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) {
					return;
				}
				load_lazy_image(entry.target);
				lazy_image_observer.unobserve(entry.target);
			});
		}, { rootMargin: "400px 0px" });
	}

	return lazy_image_observer;
}

function unobserve_lazy_images(root) {
	if (!lazy_image_observer || !root) {
		return;
	}

	$(root).find('img[data-original-url]').each(function () {
		lazy_image_observer.unobserve(this);
	});
}

function lazy_load_images(root) {
	var scope = root ? $(root) : $(document);
	var images = scope.find('img[data-original-url]');

	if (root && root.nodeType === 1 && root.matches && root.matches('img[data-original-url]')) {
		images = images.add(root);
	}

	var observer = get_lazy_image_observer();

	images.each(function () {
		if (this.getAttribute('data-lazy-loaded') === '1') {
			return;
		}

		if (observer) {
			observer.observe(this);
		} else {
			load_lazy_image(this);
		}
	});
}

async function loadAndReplaceImages(root) {
	lazy_load_images(root);
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

function sleep(ms) {
	return new Promise(resolve => setTimeout(resolve, ms));
}

function is_hidden_or_has_hidden_parent(element) {
	return !element || element.offsetParent === null;
}

async function get_map_data () {
	var data = [];
	var seen = {};

	function push_unique (item) {
		if (!item) {
			return;
		}
		var key = item.hash || item.url;
		if (!key) {
			data.push(item);
			return;
		}
		if (seen[key]) {
			return;
		}
		seen[key] = true;
		data.push(item);
	}

	async function fetch_geolist (folder) {
		if (!folder) {
			return;
		}
		var url = `index.php?geolist=${encodeURIComponent(folder)}`;
		try {
			var folder_data = await get_json_cached(url);
			if (folder_data) {
				var _keys = Object.keys(folder_data);
				for (var i = 0; i < _keys.length; i++) {
					push_unique(folder_data[_keys[i]]);
				}
			}
		} catch (e) {
			// ignore
		}
	}

	// Collect every folder we need to scan: the current folder (recursively
	// covers ALL its subfolders via the PHP backend) plus any visible folder
	// thumbnails (e.g. in search results).
	var folders_to_fetch = [];

	var current_folder = getCurrentFolderParameter();
	if (current_folder && folders_to_fetch.indexOf(current_folder) === -1) {
		folders_to_fetch.push(current_folder);
	}

	var search_is_visible = $("#searchResults").html().length && $("#searchResults").is(":visible");

	// In gallery view the current folder already covers all subfolders
	// recursively. Only search results can point to folders outside of it.
	if (search_is_visible) {
		var $folders = $(".thumbnail_folder");
		$folders.each(function (i, e) {
			if (is_hidden_or_has_hidden_parent(e)) {
				return;
			}
			var folder = get_media_path(e, true);
			if (folder && folders_to_fetch.indexOf(folder) === -1) {
				folders_to_fetch.push(folder);
			}
		});
	}

	await Promise.all(folders_to_fetch.map(function (folder) {
		return fetch_geolist(folder);
	}));

	// Also pick up geo coords from images directly visible in the current view
	// (they are rendered with data-latitude / data-longitude attributes).
	var img_elements = $("img");

	if (search_is_visible) {
		img_elements = $("#searchResults").find("img");
	}

	img_elements.each(function (i, e) {
		if (is_hidden_or_has_hidden_parent(e)) {
			return;
		}
		var src = $(e).data("original-url");
		var hash = $(e).data("hash");
		var lat = $(e).data("latitude");
		var lon = $(e).data("longitude");

		if (src && hash && lat && lon) {
			push_unique({
				"hash": hash,
				"url": src,
				"latitude": lat,
				"longitude": lon
			});
		}
	});

	return data;
}

async function draw_map_from_current_images () {
	var token = ++map_draw_token;

	let data = await get_map_data();

	if (token !== map_draw_token) {
		return;
	}

	if (!data || data.length === 0) {
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

	if (window.map) {
		map.remove();
		map = null;
		$("#map").html("");
	}

	// Init map and bounds
	map = L.map('map').fitBounds([[minLat, minLon], [maxLat, maxLon]]);

	// OSM Layer
	L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
	}).addTo(map);

	// Cluster group
	let markerCluster = L.markerClusterGroup({
		spiderfyOnMaxZoom: true,
		showCoverageOnHover: false,
		zoomToBoundsOnClick: true,
		maxClusterRadius: 40
	});

	let markers = {};

	data.forEach(element => {
		let hash = element.hash;
		let lat = element.latitude;
		let lon = element.longitude;

		let url = decodeURIComponent(element.url.replace(/^index\.php\?preview=/, ""));
		let fullUrl = `index.php?preview=${url}`;
		let cleanUrl = url.replace(/\+/g, ' ');

		let html = `
	    <img id="preview_${hash}" 
		 data-line="__A__" 
		 src="${fullUrl}" 
		 onclick="showImage('${cleanUrl}');" />
	`;

		let marker = L.marker([lat, lon]);
		marker.bindPopup(html);
		markerCluster.addLayer(marker);
		markers[hash] = marker;
	});

	map.addLayer(markerCluster);

	return {
		data: data,
		markers: markers
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

			breadcrumb.appendChild(document.createTextNode(' / '));
		}
	});

	customizeCursorForLinks();
}

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
			currentUrl = currentUrl.replace(/(\?|&)folder=[^&]*/, `$1folder=${encodeURIComponent(folder)}`);
		} else {
			const separator = currentUrl.includes('?') ? '&' : '?';
			currentUrl += `${separator}folder=${encodeURIComponent(folder)}`;
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

function decorate_media_cards(root) {
	if (!root) {
		return;
	}

	$(root).find(".thumbnail").each(function () {
		if (this.querySelector(".thumbnail-name")) {
			return;
		}

		var path = get_media_path(this, false);
		if (!path) {
			return;
		}

		var name = path.split("/").pop();
		this.setAttribute("title", name);

		var label = document.createElement("span");
		label.className = "thumbnail-name";
		label.textContent = name;
		this.appendChild(label);
	});
}

function update_gallery_meta() {
	var gallery = document.getElementById("gallery");
	var meta = document.getElementById("galleryMeta");
	var empty_state = document.getElementById("emptyState");

	if (!gallery || !meta) {
		return;
	}

	var folders = gallery.querySelectorAll(".thumbnail_folder").length;
	var images = gallery.querySelectorAll(".thumbnail").length;
	var parts = [];

	if (folders > 0) {
		parts.push(folders + " folder" + (folders === 1 ? "" : "s"));
	}
	if (images > 0) {
		parts.push(images + " photo" + (images === 1 ? "" : "s"));
	}

	meta.textContent = parts.join(" · ");

	if (empty_state) {
		empty_state.hidden = folders + images > 0;
	}
}

function add_listeners() {
	if (media_event_delegation_installed) {
		return;
	}

	media_event_delegation_installed = true;

	document.addEventListener("mousedown", function (e) {
		if (e.button !== undefined && e.button !== 0) {
			return;
		}

		var container = e.target.closest(".thumbnail_folder");
		var is_folder = !!container;

		if (!container) {
			container = e.target.closest(".thumbnail");
			if (!container || container.closest(".thumbnail_folder")) {
				container = null;
			}
		}

		if (!container) {
			return;
		}

		active_media_container = container;

		if (is_folder) {
			onFolderMouseDown(e);
		} else {
			onImageMouseDown(e);
		}
	});

	document.addEventListener("mouseup", function (e) {
		var container = active_media_container;
		active_media_container = null;

		if (!container) {
			return;
		}

		if (!container.contains(e.target) && container !== e.target) {
			removeProgressBar();
			return;
		}

		if (container.classList.contains("thumbnail_folder")) {
			onFolderMouseUp(e);
		} else {
			onImageMouseUp(e);
		}
	});
}

async function load_folder (folder, load_map = true) {
	if (typeof folder !== "string" || folder === "") {
		return;
	}

	var load_token = ++folder_load_token;

	updateUrlParameter(folder);

	clearTimeout(debounce_timer);
	if (searchTimer) {
		clearTimeout(searchTimer);
		searchTimer = null;
	}

	if (active_search_xhr) {
		active_search_xhr.abort();
		active_search_xhr = null;
	}
	search_request_id++;
	lastSearch = "";

	$("#searchInput").val("");

	unobserve_lazy_images($("#searchResults")[0]);
	$("#searchResults").empty().hide();
	$("#galleryMeta").show();

	try {
		var response = await fetch("index.php?gallery=" + encodeURIComponent(folder), { cache: "no-store" });
		if (!response.ok) {
			throw new Error("HTTP " + response.status);
		}

		var content = await response.text();

		if (load_token !== folder_load_token) {
			return;
		}

		unobserve_lazy_images($("#gallery")[0]);
		$("#gallery").html(content).show();
	} catch (error) {
		if (load_token !== folder_load_token) {
			return;
		}

		console.error("Failed to load folder:", error);
		$("#gallery").html("<p>Folder could not be loaded.</p>").show();
	}

	add_listeners();
	decorate_media_cards($("#gallery")[0]);
	lazy_load_images($("#gallery")[0]);
	update_gallery_meta();
	createBreadcrumb(folder);

	if (load_map) {
		draw_map_from_current_images().catch(function (error) {
			console.warn("Failed to draw map:", error);
		});
	}
}

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
		if (document.querySelector(".image-info-overlay")) {
			hide_image_info();
			return;
		}
		if (document.querySelector(".shortcuts-overlay")) {
			hide_shortcuts();
			return;
		}
		$(fullscreen).remove();
		current_fullscreen_image = "";
		hide_image_info();
	} else if (e.key === "?" && !["INPUT", "TEXTAREA"].includes(document.activeElement?.nodeName)) {
		show_shortcuts();
	}
}

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

function start_search() {
	clearTimeout(debounce_timer);
	debounce_timer = setTimeout(run_start_search, 250);
}

function run_start_search() {
	try {
		_start_search();
	} catch (err) {
		console.error("search failed:", err);
	}
}

async function _start_search() {
	var searchTerm = $('#searchInput').val();

	var is_fuzzy = $("#fuzzy_search").is(":checked");

	if(`${searchTerm}___${is_fuzzy}` == lastSearch) {
		return;
	}

	lastSearch = `${searchTerm}___${is_fuzzy}`;

	function abortPreviousRequest() {
		if (searchTimer) {
			clearTimeout(searchTimer);
			searchTimer = null;
		}

		if (active_search_xhr) {
			active_search_xhr.abort();
			active_search_xhr = null;
		}
	}

	abortPreviousRequest();

	async function performSearch() {
		abortPreviousRequest();

		var request_id = ++search_request_id;

		if (!/^\s*$/.test(searchTerm)) {
			folder_load_token++;
			unselectSelection();
			$("#delete_search").show();
			$("#searchResults").show();
			$("#gallery").hide();
			$("#galleryMeta").hide();
			$("#emptyState").prop("hidden", true);

			$("#searchResults").html('<p class="search-status">Searching…</p>');

			active_search_xhr = $.ajax({
				url: 'index.php',
				type: 'GET',
				data: {
					search: searchTerm,
					allowFuzzy: is_fuzzy
				},
				success: async function (response) {
					if (request_id !== search_request_id) {
						return;
					}

					active_search_xhr = null;

					var files = response && Array.isArray(response["files"]) ? response["files"] : [];
					await displaySearchResults(searchTerm, files);
					customizeCursorForLinks();
				},
				error: function (xhr, status, error) {
					if (status === "abort" || request_id !== search_request_id) {
						return;
					}

					active_search_xhr = null;
					lastSearch = "";
					console.error(error);
					$("#searchResults").html('<p>Search failed. Please try again.</p>');
				}
			});
		} else {
			$("#searchInput").trigger('blur');
			$("#delete_search,#searchResults").hide();
			$("#gallery").show();
			$("#galleryMeta").show();
			update_gallery_meta();

			draw_map_from_current_images().catch(function (error) {
				console.warn("Failed to draw map:", error);
			});

			unselectSelection();
		}
	}

	searchTimer = setTimeout(performSearch, 10);
}

async function displaySearchResults(searchTerm, results) {
	unselectSelection();
	var $searchResults = $('#searchResults');

	unobserve_lazy_images($searchResults[0]);
	$searchResults.empty();

	if (results.length > 0) {
		$searchResults.append(`<h2>Search results (${results.length}):</h2>`);

		results.forEach(function(result) {
			if (result.type === 'folder') {
				var folderThumbnail = result.thumbnail;
				if (folderThumbnail) {
					var folder_path = escape_html_attribute(result.path);
					var folder_href = escape_html_attribute(encodeURI(result.path));
					var folder_preview = escape_html_attribute(encodeURIComponent(folderThumbnail));

					var folder_line = `<a class="img_element" data-path="${folder_path}" data-href="${folder_href}"><div class="thumbnail_folder">`;

					folder_line += `<img src="loading.gif" alt="Loading..." loading="lazy" draggable="false" class="loading-thumbnail-search img_element" data-line="Y" data-original-url="index.php?preview=${folder_preview}">`;

					folder_line += `<h3>${escape_html_attribute(result.path.replace(/\.\//, ""))}</h3><span class="checkmark">✅</span></div></a>`;
					$searchResults.append(folder_line);
				}
			} else if (result.type === 'file') {
				var image_path = escape_html_attribute(result.path);
				var image_line = `<div class="thumbnail img_element" data-path="${image_path}">`;

				var gps_data_string = "";

				if (!isNaN(result.latitude) && !isNaN(result.longitude) && result.latitude !== 0 && result.longitude !== 0) {
					gps_data_string = ` data-latitude="${escape_html_attribute(result.latitude)}" data-longitude="${escape_html_attribute(result.longitude)}" `;
				}

				var image_hash = escape_html_attribute(result.hash || "");
				var image_preview = escape_html_attribute(encodeURIComponent(result.path));

				image_line += `<img data-hash="${image_hash}" ${gps_data_string} src="loading.gif" alt="Loading..." loading="lazy" draggable="false" class="loading-thumbnail-search img_element" data-line='X' data-original-url="index.php?preview=${image_preview}">`;

				image_line += `<span class="checkmark">✅</span></div>`;
				$searchResults.append(image_line);
			}
		});
	} else {
		$searchResults.append(`<p class="no-results">No matches for <strong>${escape_html_attribute(searchTerm)}</strong>. Try enabling fuzzy search.</p>`);
	}

	add_listeners();
	decorate_media_cards($searchResults[0]);
	lazy_load_images($searchResults[0]);

	draw_map_from_current_images().catch(function (error) {
		console.warn("Failed to draw map:", error);
	});

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

function add_fullscreen_controls(container, imagePath) {
	var closeButton = document.createElement("button");
	closeButton.className = "fullscreen-close";
	closeButton.type = "button";
	closeButton.setAttribute("aria-label", "Close image");
	closeButton.innerHTML = "&#x2715;";
	closeButton.addEventListener("click", function (event) {
		event.stopPropagation();
		current_fullscreen_image = "";
		container.remove();
	});

	var infoButton = document.createElement("button");
	infoButton.className = "fullscreen-info";
	infoButton.type = "button";
	infoButton.setAttribute("aria-label", "Show image details");
	infoButton.innerHTML = "i";
	infoButton.addEventListener("click", function (event) {
		event.stopPropagation();
		show_image_info();
	});

	var downloadLink = document.createElement("a");
	downloadLink.className = "fullscreen-download";
	downloadLink.href = imagePath;
	downloadLink.download = imagePath.split("/").pop();
	downloadLink.setAttribute("aria-label", "Download this file");
	downloadLink.innerHTML = "&#x2193;";
	downloadLink.addEventListener("click", function (event) {
		event.stopPropagation();
	});

	var previousButton = document.createElement("button");
	previousButton.className = "fullscreen-nav prev";
	previousButton.type = "button";
	previousButton.setAttribute("aria-label", "Previous image");
	previousButton.innerHTML = "&#x2039;";
	previousButton.addEventListener("click", function (event) {
		event.stopPropagation();
		prev_image();
	});

	var nextButton = document.createElement("button");
	nextButton.className = "fullscreen-nav next";
	nextButton.type = "button";
	nextButton.setAttribute("aria-label", "Next image");
	nextButton.innerHTML = "&#x203a;";
	nextButton.addEventListener("click", function (event) {
		event.stopPropagation();
		next_image();
	});

	container.appendChild(closeButton);
	container.appendChild(infoButton);
	container.appendChild(downloadLink);
	container.appendChild(previousButton);
	container.appendChild(nextButton);
}

function add_fullscreen_meta(container, imagePath) {
	var thumbnails = $(".thumbnail").toArray();
	var currentIndex = -1;

	thumbnails.forEach(function (thumbnail, index) {
		if (get_media_path(thumbnail, false) === imagePath) {
			currentIndex = index;
		}
	});

	if (thumbnails.length > 0 && currentIndex >= 0) {
		var counter = document.createElement("div");
		counter.className = "fullscreen-counter";
		counter.textContent = `${currentIndex + 1} / ${thumbnails.length}`;
		container.appendChild(counter);
	}

	var caption = document.createElement("div");
	caption.className = "fullscreen-caption";
	caption.textContent = imagePath.split("/").pop() || "";
	container.appendChild(caption);
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
	current_fullscreen_image = decodedPath;
	add_fullscreen_controls(fullscreen, decodedPath);
	add_fullscreen_meta(fullscreen, decodedPath);

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
		if (!["INPUT", "IMG", "LABEL", "VIDEO", "A", "BUTTON"].includes(i.target.nodeName) && i.target.id != "swipe_toggle") {
			current_fullscreen_image = "";
			$(fullscreen).remove();
		}
	});
}

function get_fullscreen_img_name () {
	if (current_fullscreen_image) {
		return current_fullscreen_image;
	}

	var src = $(".fullscreen").find("img").attr("src");

	if(src) {
		try {
			return decodeURIComponent(src).replace(/.*path=/, "");
		} catch (e) {
			return src.replace(/.*path=/, "");
		}
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
	if (document.querySelector(".image-info-overlay")) {
		return;
	}

	const imageElement = document.querySelector('.fullscreen img');
	if (!imageElement) return;

	const imageSrc = get_fullscreen_img_name();
	if (!imageSrc) return;

	const overlay = document.createElement('div');
	overlay.className = "image-info-overlay";

	const card = document.createElement('div');
	card.className = "image-info-card";

	const header = document.createElement('div');
	header.className = "image-info-header";

	const title = document.createElement('h2');
	title.textContent = "Image details";

	const closeButton = document.createElement('button');
	closeButton.className = "image-info-close";
	closeButton.type = "button";
	closeButton.setAttribute("aria-label", "Close image details");
	closeButton.innerHTML = "&#x2715;";
	closeButton.addEventListener("click", hide_image_info);

	header.appendChild(title);
	header.appendChild(closeButton);

	const body = document.createElement('div');
	body.className = "image-info-body";

	const status = document.createElement('p');
	status.className = "image-info-status";
	status.textContent = "Loading details…";
	body.appendChild(status);

	card.appendChild(header);
	card.appendChild(body);
	overlay.appendChild(card);

	overlay.addEventListener('click', function (event) {
		if (event.target === overlay) {
			hide_image_info();
		}
	});

	document.body.appendChild(overlay);

	fetch(`index.php?file_info=${encodeURIComponent(imageSrc)}`)
		.then(response => response.json())
		.then(data => {
			if (!data) {
				return;
			}

			const preview = document.createElement("img");
			preview.className = "image-info-preview";
			preview.src = `index.php?preview=${encodeURIComponent(imageSrc)}`;
			preview.alt = imageSrc.split("/").pop() || "";

			const table = document.createElement("table");
			table.className = "image-info-table";

			Object.keys(data).forEach(key => {
				const row = document.createElement("tr");

				const label = document.createElement("th");
				label.textContent = key;

				const value = document.createElement("td");
				value.innerHTML = data[key];

				row.appendChild(label);
				row.appendChild(value);
				table.appendChild(row);
			});

			body.replaceChildren(preview, table);
		})
		.catch(error => {
			console.error('Error fetching image info:', error);
			status.textContent = "Details could not be loaded.";
		});
}

function hide_image_info() {
	const overlay = document.querySelector(".image-info-overlay");
	if (overlay) {
		overlay.remove();
	}
}

function show_shortcuts() {
	if (document.querySelector(".shortcuts-overlay")) {
		return;
	}

	const overlay = document.createElement("div");
	overlay.className = "shortcuts-overlay";

	const card = document.createElement("div");
	card.className = "shortcuts-card";

	const header = document.createElement("div");
	header.className = "shortcuts-header";

	const title = document.createElement("h2");
	title.textContent = "Shortcuts & gestures";

	const closeButton = document.createElement("button");
	closeButton.className = "shortcuts-close";
	closeButton.type = "button";
	closeButton.setAttribute("aria-label", "Close shortcuts");
	closeButton.innerHTML = "&#x2715;";
	closeButton.addEventListener("click", hide_shortcuts);

	header.appendChild(title);
	header.appendChild(closeButton);

	const content = document.createElement("div");
	content.className = "shortcuts-body";
	content.innerHTML = `
		<div class="shortcut-row"><span>Search</span><kbd>/</kbd></div>
		<div class="shortcut-row"><span>Close search / lightbox</span><kbd>Esc</kbd></div>
		<div class="shortcut-row"><span>Previous image</span><kbd>&larr;</kbd></div>
		<div class="shortcut-row"><span>Next image</span><kbd>&rarr;</kbd></div>
		<div class="shortcut-row"><span>Show details</span><kbd>&uarr;</kbd></div>
		<div class="shortcut-row"><span>Hide details</span><kbd>&darr;</kbd></div>
		<div class="shortcut-row"><span>Select on touch</span><span class="shortcut-hint">Long press a card</span></div>
		<div class="shortcut-row"><span>Select without waiting</span><span class="shortcut-hint">Use the Select button</span></div>
		<div class="shortcut-row"><span>Swipe in lightbox</span><span class="shortcut-hint">Left / right</span></div>
	`;

	card.appendChild(header);
	card.appendChild(content);
	overlay.appendChild(card);

	overlay.addEventListener("click", function (event) {
		if (event.target === overlay) {
			hide_shortcuts();
		}
	});

	document.body.appendChild(overlay);
}

function hide_shortcuts() {
	const overlay = document.querySelector(".shortcuts-overlay");
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

	if ($thumbnails.length === 0) {
		return;
	}

	$thumbnails.each((i, e) => {
		var img_url = get_media_path(e, false);

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

	next_idx = next_idx % $thumbnails.length;

	var next_img = get_media_path($thumbnails[next_idx], false);

	if (next_img) {
		showImage(next_img);
	}
}
