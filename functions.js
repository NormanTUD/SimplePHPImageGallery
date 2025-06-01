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
			checkmark.style.visibility = 'hidden';
		} else {
			log(item);
			selectedFolders.push(item);
			checkmark.style.visibility = 'unset';
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
			checkmark.style.visibility = 'hidden';
		} else {
			selectedImages.push(item);
			checkmark.style.visibility = 'unset';
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

function hidePageLoadingIndicator() {
	const loadingIndicator = document.querySelector('.loading-indicator');
	if (loadingIndicator) {
		loadingIndicator.remove();
	}
}

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
				data: {
					search: searchTerm,
					allowFuzzy: is_fuzzy
				},
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
			$("#searchInput").trigger('blur');
			$("#delete_search,#searchResults").hide();
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
