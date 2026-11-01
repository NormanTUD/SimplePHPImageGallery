let debounce_timer = null;
let active_search_xhr = null;
let search_request_id = 0;
let folder_load_token = 0;
let media_event_delegation_installed = false;
let active_media_container = null;
let lazy_image_observer = null;
let map_draw_token = 0;
let map_loading = false;
let map_lazy_observer = null;
let gallery_sentinel_observer = null;
let favorites_filter_active = false;
let favorites_loaded = false;
let favorites = new Set();
let timeline_mode = false;
let timeline_original_order = null;
let slideshow_timer = null;
let location_modal_map = null;
let modal_return_focus = null;
const FAVORITES_STORAGE_KEY = "spg_favorites_v1";
const SEARCH_HISTORY_STORAGE_KEY = "spg_searches_v1";
const GALLERY_PAGE_SIZE = 120;
let gallery_page_state = {
	folder: null,
	nextOffset: 0,
	total: 0,
	loaded: 0,
	hasMore: false,
	loading: false,
	token: 0
};

function escape_html_attribute(value) {
	return String(value)
		.replace(/&/g, "&amp;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#39;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;");
}

function build_preview_srcset_attribute(path) {
	if (!path || /\.(mp4|mov)$/i.test(path)) {
		return "";
	}

	var encoded = encodeURIComponent(path);
	var parts = [320, 640, 1280].map(function (size) {
		return `index.php?preview=${encoded}&size=${size} ${size}w`;
	});

	return ` data-srcset="${escape_html_attribute(parts.join(', '))}" sizes="(max-width: 760px) 48vw, 220px"`;
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

function show_toast(message) {
	var region = document.getElementById("toastRegion");

	if (!region) {
		region = document.createElement("div");
		region.id = "toastRegion";
		region.className = "toast-region";
		region.setAttribute("aria-live", "polite");
		document.body.appendChild(region);
	}

	var toast = document.createElement("div");
	toast.className = "toast";
	toast.textContent = message;
	region.appendChild(toast);

	requestAnimationFrame(function () {
		toast.classList.add("is-visible");
	});

	setTimeout(function () {
		toast.classList.remove("is-visible");
		setTimeout(function () {
			toast.remove();
		}, 250);
	}, 2600);
}

function modal_focusable_elements(container) {
	return Array.from(container.querySelectorAll(
		'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
	)).filter(function (element) {
		return !element.disabled && element.offsetParent !== null;
	});
}

function trap_modal_focus(event) {
	if (event.key !== "Tab") {
		return;
	}

	var container = event.currentTarget;
	var focusable = modal_focusable_elements(container);
	if (focusable.length === 0) {
		return;
	}

	var first = focusable[0];
	var last = focusable[focusable.length - 1];

	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault();
		last.focus();
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault();
		first.focus();
	}
}

function activate_modal(container, label) {
	modal_return_focus = document.activeElement;
	container.setAttribute("role", "dialog");
	container.setAttribute("aria-modal", "true");
	if (label) {
		container.setAttribute("aria-label", label);
	}
	container.addEventListener("keydown", trap_modal_focus);

	setTimeout(function () {
		var focusable = modal_focusable_elements(container);
		if (focusable.length > 0) {
			focusable[0].focus();
		}
	}, 0);
}

function deactivate_modal() {
	if (modal_return_focus && typeof modal_return_focus.focus === "function") {
		modal_return_focus.focus();
	}
	modal_return_focus = null;
}

function load_favorites_store() {
	if (favorites_loaded) {
		return;
	}

	favorites_loaded = true;

	try {
		var stored = JSON.parse(localStorage.getItem(FAVORITES_STORAGE_KEY) || "[]");
		if (Array.isArray(stored)) {
			favorites = new Set(stored.filter(function (item) {
				return typeof item === "string";
			}));
		}
	} catch (error) {
		favorites = new Set();
	}

	update_favorites_count();
}

function save_favorites_store() {
	try {
		localStorage.setItem(FAVORITES_STORAGE_KEY, JSON.stringify(Array.from(favorites)));
	} catch (error) {
		// Local storage can be unavailable in private modes.
	}
}

function favorite_path_for_container(container) {
	var is_folder = container.classList.contains("thumbnail_folder");
	return {
		path: get_media_path(container, is_folder),
		is_folder: is_folder
	};
}

function update_favorite_state_for_container(container) {
	load_favorites_store();

	var info = favorite_path_for_container(container);
	var is_favorite = !!info.path && favorites.has(info.path);

	container.classList.toggle("is-favorite", is_favorite);

	if (info.is_folder) {
		var anchor = container.closest(".img_element");
		if (anchor) {
			anchor.classList.toggle("is-favorite", is_favorite);
		}
	}

	var button = container.querySelector(":scope > .favorite-btn");
	if (button) {
		button.setAttribute("aria-pressed", is_favorite ? "true" : "false");
		button.setAttribute("aria-label", is_favorite ? "Remove from favorites" : "Add to favorites");
		button.title = is_favorite ? "Remove from favorites" : "Add to favorites";
		button.innerHTML = is_favorite ? "&#9829;" : "&#9825;";
	}
}

function update_favorites_count() {
	var counter = document.getElementById("favoritesCount");
	if (counter) {
		counter.textContent = favorites.size;
	}
}

function refresh_all_favorite_states() {
	$(".thumbnail, .thumbnail_folder").each(function () {
		update_favorite_state_for_container(this);
	});
	update_favorites_count();
}

function decorate_favorite_buttons(root) {
	if (!root) {
		return;
	}

	load_favorites_store();

	$(root).find(".thumbnail, .thumbnail_folder").each(function () {
		var container = this;

		if (container.querySelector(":scope > .favorite-btn")) {
			update_favorite_state_for_container(container);
			return;
		}

		var info = favorite_path_for_container(container);
		if (!info.path) {
			return;
		}

		var button = document.createElement("button");
		button.type = "button";
		button.className = "favorite-btn";
		button.addEventListener("mousedown", function (event) {
			event.stopPropagation();
		});
		button.addEventListener("mouseup", function (event) {
			event.stopPropagation();
		});
		button.addEventListener("click", function (event) {
			event.preventDefault();
			event.stopPropagation();
			toggle_favorite_for_container(container);
		});

		container.appendChild(button);
		update_favorite_state_for_container(container);
	});
}

function toggle_favorite_for_container(container) {
	load_favorites_store();

	var info = favorite_path_for_container(container);
	if (!info.path) {
		return;
	}

	var was_favorite = favorites.has(info.path);
	if (was_favorite) {
		favorites.delete(info.path);
	} else {
		favorites.add(info.path);
	}

	save_favorites_store();
	refresh_all_favorite_states();
	show_toast(was_favorite ? "Removed from favorites" : "Added to favorites");
}

function toggle_favorites_filter() {
	load_favorites_store();

	favorites_filter_active = !favorites_filter_active;
	document.body.classList.toggle("favorites-only", favorites_filter_active);

	var button = document.getElementById("favoritesBtn");
	if (button) {
		button.setAttribute("aria-pressed", favorites_filter_active ? "true" : "false");
	}

	if (favorites_filter_active && favorites.size === 0) {
		show_toast("No favorites yet. Tap the heart on a photo.");
	}
}

function update_timeline_button() {
	var button = document.getElementById("timelineBtn");
	if (button) {
		button.setAttribute("aria-pressed", timeline_mode ? "true" : "false");
	}
}

function reset_timeline_state() {
	timeline_mode = false;
	timeline_original_order = null;
	update_timeline_button();
}

function timeline_label(timestamp) {
	if (!timestamp) {
		return "Unknown date";
	}

	return new Date(timestamp * 1000).toLocaleDateString(undefined, {
		year: "numeric",
		month: "long"
	});
}

function enable_timeline_view() {
	var gallery = document.getElementById("gallery");
	if (!gallery) {
		return;
	}

	var cards = Array.from(gallery.children).filter(function (element) {
		return element.matches(".img_element, .thumbnail");
	});

	if (cards.length === 0) {
		return;
	}

	timeline_original_order = cards.slice();

	var folders = cards.filter(function (element) {
		return element.classList.contains("img_element");
	});

	var images = cards.filter(function (element) {
		return element.classList.contains("thumbnail");
	}).sort(function (a, b) {
		var aTime = Number(a.querySelector("img")?.getAttribute("data-mtime") || 0);
		var bTime = Number(b.querySelector("img")?.getAttribute("data-mtime") || 0);
		return bTime - aTime;
	});

	var groups = new Map();
	images.forEach(function (card) {
		var timestamp = Number(card.querySelector("img")?.getAttribute("data-mtime") || 0);
		var key = timestamp ? timeline_label(timestamp) : "Unknown date";
		if (!groups.has(key)) {
			groups.set(key, { label: key, timestamp: timestamp, items: [] });
		}
		groups.get(key).items.push(card);
	});

	var sorted_groups = Array.from(groups.values()).sort(function (a, b) {
		return b.timestamp - a.timestamp;
	});

	gallery.replaceChildren();

	folders.forEach(function (folder) {
		gallery.appendChild(folder);
	});

	sorted_groups.forEach(function (group) {
		var heading = document.createElement("h2");
		heading.className = "timeline-heading";
		heading.textContent = group.label;

		var count = document.createElement("span");
		count.className = "timeline-count";
		count.textContent = group.items.length;
		heading.appendChild(count);

		gallery.appendChild(heading);
		group.items.forEach(function (card) {
			gallery.appendChild(card);
		});
	});

	timeline_mode = true;
	update_timeline_button();
	show_toast("Timeline sorted by newest first");
}

function disable_timeline_view() {
	var gallery = document.getElementById("gallery");

	if (gallery) {
		gallery.querySelectorAll(".timeline-heading").forEach(function (heading) {
			heading.remove();
		});

		(timeline_original_order || []).forEach(function (card) {
			gallery.appendChild(card);
		});
	}

	reset_timeline_state();
}

function toggle_timeline_view() {
	if ($("#searchResults").is(":visible")) {
		show_toast("Timeline is available in folder view.");
		return;
	}

	if (timeline_mode) {
		disable_timeline_view();
	} else {
		enable_timeline_view();
	}
}

function update_slideshow_button() {
	var button = document.getElementById("slideshowBtn");
	if (button) {
		button.setAttribute("aria-pressed", slideshow_timer ? "true" : "false");
		button.textContent = slideshow_timer ? "Stop slideshow" : "Slideshow";
	}

	var fullscreen_button = document.querySelector(".fullscreen-slideshow");
	if (fullscreen_button) {
		fullscreen_button.setAttribute("aria-pressed", slideshow_timer ? "true" : "false");
		fullscreen_button.setAttribute("aria-label", slideshow_timer ? "Pause slideshow" : "Start slideshow");
		fullscreen_button.innerHTML = slideshow_timer ? "&#10074;&#10074;" : "&#9654;";
	}
}

function stop_slideshow() {
	if (slideshow_timer) {
		clearInterval(slideshow_timer);
		slideshow_timer = null;
	}
	update_slideshow_button();
}

function visible_image_cards() {
	var scope = $("#searchResults").is(":visible") ? $("#searchResults") : $("#gallery");
	return scope.find(".thumbnail").toArray().filter(function (element) {
		return element.offsetParent !== null;
	});
}

function toggle_slideshow() {
	if (slideshow_timer) {
		stop_slideshow();
		return;
	}

	var images = visible_image_cards();
	if (images.length === 0) {
		show_toast("No photos to play in this view.");
		return;
	}

	if (!document.querySelector(".fullscreen")) {
		showImage(get_media_path(images[0], false));
	}

	slideshow_timer = setInterval(function () {
		next_image();
	}, 4500);

	update_slideshow_button();
	show_toast("Slideshow started");
}

function find_gallery_card_for_path(path) {
	var found = null;

	$(".thumbnail").each(function () {
		if (get_media_path(this, false) === path) {
			found = this;
			return false;
		}
	});

	return found;
}

function hide_location_modal() {
	if (location_modal_map) {
		location_modal_map.remove();
		location_modal_map = null;
	}

	var overlay = document.querySelector(".location-overlay");
	if (overlay) {
		overlay.remove();
		deactivate_modal();
	}
}

function show_location_modal(latitude, longitude, path) {
	hide_location_modal();

	var overlay = document.createElement("div");
	overlay.className = "location-overlay";

	var card = document.createElement("div");
	card.className = "location-card";

	var header = document.createElement("div");
	header.className = "location-header";

	var title = document.createElement("h2");
	title.textContent = "Photo location";

	var closeButton = document.createElement("button");
	closeButton.type = "button";
	closeButton.className = "location-close";
	closeButton.setAttribute("aria-label", "Close map");
	closeButton.innerHTML = "&#x2715;";
	closeButton.addEventListener("click", hide_location_modal);

	header.appendChild(title);
	header.appendChild(closeButton);

	var mapElement = document.createElement("div");
	mapElement.className = "location-map";

	card.appendChild(header);
	card.appendChild(mapElement);
	overlay.appendChild(card);

	overlay.addEventListener("click", function (event) {
		if (event.target === overlay) {
			hide_location_modal();
		}
	});

	document.body.appendChild(overlay);
	activate_modal(overlay, "Photo location");

	setTimeout(function () {
		if (typeof L === "undefined") {
			show_toast("Map is not available.");
			return;
		}

		location_modal_map = L.map(mapElement).setView([latitude, longitude], 13);
		L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		}).addTo(location_modal_map);

		L.marker([latitude, longitude])
			.addTo(location_modal_map)
			.bindPopup(path.split("/").pop() || "Photo")
			.openPopup();
	}, 80);
}

function show_current_image_on_map() {
	var path = get_fullscreen_img_name();
	if (!path) {
		return;
	}

	var card = find_gallery_card_for_path(path);
	var image = card ? card.querySelector("img") : null;
	var latitude = image ? Number(image.getAttribute("data-latitude")) : NaN;
	var longitude = image ? Number(image.getAttribute("data-longitude")) : NaN;

	if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
		show_toast("No GPS location for this photo.");
		return;
	}

	show_location_modal(latitude, longitude, path);
}

function load_recent_searches() {
	var datalist = document.getElementById("searchHistory");
	if (!datalist) {
		return;
	}

	var terms = [];
	try {
		terms = JSON.parse(localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY) || "[]");
	} catch (error) {
		terms = [];
	}

	datalist.replaceChildren();
	if (!Array.isArray(terms)) {
		return;
	}

	terms.slice(0, 8).forEach(function (term) {
		if (typeof term !== "string" || term.trim() === "") {
			return;
		}
		var option = document.createElement("option");
		option.value = term;
		datalist.appendChild(option);
	});
}

function remember_search(term) {
	if (typeof term !== "string" || term.trim() === "") {
		return;
	}

	var terms = [];
	try {
		terms = JSON.parse(localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY) || "[]");
	} catch (error) {
		terms = [];
	}

	if (!Array.isArray(terms)) {
		terms = [];
	}

	terms = [term].concat(terms.filter(function (item) {
		return item !== term;
	})).slice(0, 8);

	try {
		localStorage.setItem(SEARCH_HISTORY_STORAGE_KEY, JSON.stringify(terms));
	} catch (error) {
		// Ignore unavailable local storage.
	}

	load_recent_searches();
}

function highlight_search_matches(root, term) {
	if (!root || !term) {
		return;
	}

	var needle = term.toLowerCase();

	$(root).find(".thumbnail-name, .thumbnail_folder h3").each(function () {
		var element = this;
		if (element.querySelector("mark")) {
			return;
		}

		var text = element.textContent || "";
		var index = text.toLowerCase().indexOf(needle);
		if (index === -1) {
			return;
		}

		element.replaceChildren();

		while (index !== -1) {
			if (index > 0) {
				element.appendChild(document.createTextNode(text.slice(0, index)));
			}

			var mark = document.createElement("mark");
			mark.textContent = text.slice(index, index + term.length);
			element.appendChild(mark);

			text = text.slice(index + term.length);
			index = text.toLowerCase().indexOf(needle);
		}

		if (text) {
			element.appendChild(document.createTextNode(text));
		}
	});
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

	var srcset = img.getAttribute("data-srcset");
	if (srcset) {
		img.srcset = srcset;
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
	var search_is_visible = $("#searchResults").html().length && $("#searchResults").is(":visible");
	var current_folder = normalize_folder_path(getCurrentFolderParameter()) || "./";
	var geolist_prefix = search_is_visible ? "" : current_folder;

	function push_unique (item) {
		if (!item) {
			return;
		}

		if (geolist_prefix && item.url) {
			var item_url = String(item.url).replace(/^index\.php\?preview=/, "");
			var prefix = geolist_prefix.endsWith("/") ? geolist_prefix : geolist_prefix + "/";
			if (item_url !== geolist_prefix && !item_url.startsWith(prefix)) {
				return;
			}
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
		folder = normalize_folder_path(folder);
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

	if (current_folder && folders_to_fetch.indexOf(current_folder) === -1) {
		folders_to_fetch.push(current_folder);
	}

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

	$("#map_container").removeClass("is-collapsed").show();

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

		let cleanUrl = String(element.url || "").replace(/^index\.php\?preview=/, "");

		let popupImage = document.createElement("img");
		popupImage.src = `index.php?preview=${encodeURIComponent(cleanUrl)}`;
		popupImage.alt = cleanUrl.split("/").pop() || "";
		popupImage.setAttribute("draggable", "false");
		popupImage.addEventListener("click", function () {
			showImage(cleanUrl);
		});

		let marker = L.marker([lat, lon]);
		marker.bindPopup(popupImage);
		markerCluster.addLayer(marker);
		markers[hash] = marker;
	});

	map.addLayer(markerCluster);

	return {
		data: data,
		markers: markers
	};
}

function reset_map_ui() {
	map_loading = false;

	var container = document.getElementById("map_container");
	if (container) {
		container.classList.remove("is-loading");
		container.classList.add("is-collapsed");
		container.hidden = false;
	}

	var button = document.getElementById("showMapBtn");
	if (button) {
		button.disabled = false;
		button.textContent = "Show map";
	}

	if (window.map) {
		map.remove();
		map = null;
		$("#map").empty();
	}
}

function maybe_request_map_if_visible() {
	var container = document.getElementById("map_container");
	if (!container || container.hidden || map_loading) {
		return;
	}

	var rect = container.getBoundingClientRect();
	if (rect.top < window.innerHeight + 700 && rect.bottom > -700) {
		request_map();
	}
}

async function request_map() {
	if (map_loading) {
		return;
	}

	map_loading = true;

	var container = document.getElementById("map_container");
	var button = document.getElementById("showMapBtn");

	if (container) {
		container.classList.add("is-loading");
	}
	if (button) {
		button.disabled = true;
		button.textContent = "Loading map…";
	}

	try {
		await draw_map_from_current_images();
	} catch (error) {
		console.warn("Failed to draw map:", error);
	} finally {
		map_loading = false;
		if (container) {
			container.classList.remove("is-loading");
		}
		if (button) {
			button.disabled = false;
			button.textContent = "Show map";
		}
	}
}

function setup_map_lazy_observer() {
	var container = document.getElementById("map_container");

	if (!container || map_lazy_observer || !("IntersectionObserver" in window)) {
		return;
	}

	map_lazy_observer = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.isIntersecting) {
				request_map();
			}
		});
	}, { rootMargin: "700px 0px" });

	map_lazy_observer.observe(container);
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
			link.textContent = folderName;

			(function (path) {
				link.addEventListener("click", function () {
					load_folder(path);
				});
			})(fullPath);

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

		return normalize_folder_path(decode_php_url_component(match[1])) || "./";
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
	var loaded = folders + images;
	var total = gallery_page_state.folder !== null ? gallery_page_state.total : loaded;
	var parts = [];

	if (folders > 0) {
		parts.push(folders + " folder" + (folders === 1 ? "" : "s"));
	}
	if (images > 0) {
		parts.push(images + " photo" + (images === 1 ? "" : "s"));
	}
	if (total > loaded) {
		parts.push(loaded + " of " + total + " loaded");
	}

	meta.textContent = parts.join(" · ");

	if (empty_state) {
		empty_state.hidden = total > 0;
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

function set_gallery_sentinel(has_more) {
	var sentinel = document.getElementById("gallerySentinel");
	if (!sentinel) {
		return;
	}

	sentinel.hidden = !has_more;

	if (has_more && gallery_sentinel_observer) {
		gallery_sentinel_observer.unobserve(sentinel);
		gallery_sentinel_observer.observe(sentinel);
	}
}

function maybe_load_more_gallery_page() {
	var sentinel = document.getElementById("gallerySentinel");
	if (!sentinel || sentinel.hidden || timeline_mode || gallery_page_state.loading || !gallery_page_state.hasMore) {
		return;
	}

	var rect = sentinel.getBoundingClientRect();
	if (rect.top < window.innerHeight + 600) {
		load_next_gallery_page();
	}
}

async function load_next_gallery_page() {
	var state = gallery_page_state;

	if (!state.folder || state.loading || !state.hasMore || timeline_mode) {
		return;
	}

	state.loading = true;
	var token = state.token;
	var sentinel = document.getElementById("gallerySentinel");

	if (sentinel) {
		sentinel.hidden = false;
		sentinel.classList.add("is-loading");
	}

	try {
		var url = "index.php?gallery=" + encodeURIComponent(state.folder)
			+ "&offset=" + state.nextOffset
			+ "&limit=" + GALLERY_PAGE_SIZE;
		var response = await fetch(url, { cache: "no-store" });

		if (!response.ok) {
			throw new Error("HTTP " + response.status);
		}

		var content = await response.text();

		if (state !== gallery_page_state || token !== folder_load_token) {
			return;
		}

		var fragment = $('<div>').html(content);
		$("#gallery").append(fragment.contents());

		state.nextOffset = Number(response.headers.get("X-Gallery-Next-Offset") || state.nextOffset);
		state.total = Number(response.headers.get("X-Gallery-Total") || state.total);
		state.hasMore = response.headers.get("X-Gallery-Has-More") === "1";

		decorate_media_cards($("#gallery")[0]);
		decorate_favorite_buttons($("#gallery")[0]);
		lazy_load_images($("#gallery")[0]);
		update_gallery_meta();
		set_gallery_sentinel(state.hasMore);

		if (state.hasMore) {
			setTimeout(maybe_load_more_gallery_page, 150);
		}
	} catch (error) {
		console.warn("Failed to load more gallery items:", error);
	} finally {
		state.loading = false;
		if (sentinel) {
			sentinel.classList.remove("is-loading");
		}
	}
}

function setup_gallery_infinite_scroll() {
	var sentinel = document.getElementById("gallerySentinel");

	if (!sentinel || gallery_sentinel_observer || !("IntersectionObserver" in window)) {
		return;
	}

	gallery_sentinel_observer = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.isIntersecting) {
				load_next_gallery_page();
			}
		});
	}, { rootMargin: "600px 0px" });

	gallery_sentinel_observer.observe(sentinel);
}

async function load_folder (folder, load_map = true) {
	if (typeof folder !== "string" || folder === "") {
		return;
	}

	folder = normalize_folder_path(folder);
	if (!folder) {
		return;
	}

	var load_token = ++folder_load_token;

	reset_timeline_state();
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

	var total_items = 0;
	var next_offset = 0;
	var has_more = false;

	try {
		var response = await fetch(
			"index.php?gallery=" + encodeURIComponent(folder) + "&offset=0&limit=" + GALLERY_PAGE_SIZE,
			{ cache: "no-store" }
		);
		if (!response.ok) {
			throw new Error("HTTP " + response.status);
		}

		var content = await response.text();
		total_items = Number(response.headers.get("X-Gallery-Total") || 0);
		next_offset = Number(response.headers.get("X-Gallery-Next-Offset") || 0);
		has_more = response.headers.get("X-Gallery-Has-More") === "1";

		if (load_token !== folder_load_token) {
			return;
		}

		gallery_page_state = {
			folder: folder,
			nextOffset: next_offset,
			total: total_items,
			loaded: 0,
			hasMore: has_more,
			loading: false,
			token: load_token
		};

		unobserve_lazy_images($("#gallery")[0]);
		$("#gallery").html(content).show();
	} catch (error) {
		if (load_token !== folder_load_token) {
			return;
		}

		gallery_page_state = {
			folder: null,
			nextOffset: 0,
			total: 0,
			loaded: 0,
			hasMore: false,
			loading: false,
			token: load_token
		};

		console.error("Failed to load folder:", error);
		$("#gallery").html("<p>Folder could not be loaded.</p>").show();
	}

	add_listeners();
	decorate_media_cards($("#gallery")[0]);
	decorate_favorite_buttons($("#gallery")[0]);
	lazy_load_images($("#gallery")[0]);
	update_gallery_meta();
	set_gallery_sentinel(has_more);
	createBreadcrumb(folder);

	if (load_map) {
		reset_map_ui();
		maybe_request_map_if_visible();
	}

	if (has_more) {
		setTimeout(maybe_load_more_gallery_page, 100);
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
		if (document.querySelector(".location-overlay")) {
			hide_location_modal();
			return;
		}
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
		stop_slideshow();
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
	var search_type = $("#searchType").val() || "all";

	if(`${searchTerm}___${is_fuzzy}___${search_type}` == lastSearch) {
		return;
	}

	lastSearch = `${searchTerm}___${is_fuzzy}___${search_type}`;

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
			reset_timeline_state();
			unselectSelection();
			$("#delete_search").show();
			$("#searchResults").show();
			$("#gallery").hide();
			$("#galleryMeta").hide();
			$("#emptyState").prop("hidden", true);
			$("#gallerySentinel").prop("hidden", true);

			$("#searchResults").html('<p class="search-status">Searching…</p>');

			active_search_xhr = $.ajax({
				url: 'index.php',
				type: 'GET',
				data: {
					search: searchTerm,
					allowFuzzy: is_fuzzy,
					type: search_type
				},
				success: async function (response) {
					if (request_id !== search_request_id) {
						return;
					}

					active_search_xhr = null;

					var files = response && Array.isArray(response["files"]) ? response["files"] : [];
					await displaySearchResults(searchTerm, files);
					remember_search(searchTerm);
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
			set_gallery_sentinel(gallery_page_state.hasMore);
			reset_map_ui();
			maybe_request_map_if_visible();

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
					var folder_srcset = build_preview_srcset_attribute(folderThumbnail);

					var folder_line = `<a class="img_element" data-path="${folder_path}" data-href="${folder_href}"><div class="thumbnail_folder">`;

					folder_line += `<img src="loading.gif" alt="Loading..." loading="lazy" draggable="false" class="loading-thumbnail-search img_element" data-line="Y" data-original-url="index.php?preview=${folder_preview}"${folder_srcset}>`;

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
				var image_srcset = build_preview_srcset_attribute(result.path);

				image_line += `<img data-hash="${image_hash}" data-mtime="${escape_html_attribute(result.mtime || "")}" ${gps_data_string} src="loading.gif" alt="Loading..." loading="lazy" draggable="false" class="loading-thumbnail-search img_element" data-line='X' data-original-url="index.php?preview=${image_preview}"${image_srcset}>`;

				image_line += `<span class="checkmark">✅</span></div>`;
				$searchResults.append(image_line);
			}
		});
	} else {
		$searchResults.append(`<p class="no-results">No matches for <strong>${escape_html_attribute(searchTerm)}</strong>. Try enabling fuzzy search.</p>`);
	}

	add_listeners();
	decorate_media_cards($searchResults[0]);
	decorate_favorite_buttons($searchResults[0]);
	highlight_search_matches($searchResults[0], searchTerm);
	lazy_load_images($searchResults[0]);
	reset_map_ui();
	maybe_request_map_if_visible();

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

function update_fullscreen_favorite_button(button, path) {
	if (!button) {
		return;
	}

	load_favorites_store();
	var is_favorite = favorites.has(path);
	button.setAttribute("aria-pressed", is_favorite ? "true" : "false");
	button.setAttribute("aria-label", is_favorite ? "Remove from favorites" : "Add to favorites");
	button.innerHTML = is_favorite ? "&#9829;" : "&#9825;";
}

function toggle_current_favorite() {
	var path = current_fullscreen_image;
	if (!path) {
		return;
	}

	load_favorites_store();

	var was_favorite = favorites.has(path);
	if (was_favorite) {
		favorites.delete(path);
	} else {
		favorites.add(path);
	}

	save_favorites_store();
	refresh_all_favorite_states();
	update_fullscreen_favorite_button(document.querySelector(".fullscreen-favorite"), path);
	show_toast(was_favorite ? "Removed from favorites" : "Added to favorites");
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
		stop_slideshow();
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

	var mapButton = document.createElement("button");
	mapButton.className = "fullscreen-map";
	mapButton.type = "button";
	mapButton.setAttribute("aria-label", "Show photo on map");
	mapButton.innerHTML = "&#9678;";
	mapButton.addEventListener("click", function (event) {
		event.stopPropagation();
		show_current_image_on_map();
	});

	var favoriteButton = document.createElement("button");
	favoriteButton.className = "fullscreen-favorite";
	favoriteButton.type = "button";
	favoriteButton.addEventListener("click", function (event) {
		event.stopPropagation();
		toggle_current_favorite();
	});
	update_fullscreen_favorite_button(favoriteButton, imagePath);

	var slideshowButton = document.createElement("button");
	slideshowButton.className = "fullscreen-slideshow";
	slideshowButton.type = "button";
	slideshowButton.setAttribute("aria-pressed", slideshow_timer ? "true" : "false");
	slideshowButton.setAttribute("aria-label", slideshow_timer ? "Pause slideshow" : "Start slideshow");
	slideshowButton.innerHTML = slideshow_timer ? "&#10074;&#10074;" : "&#9654;";
	slideshowButton.addEventListener("click", function (event) {
		event.stopPropagation();
		toggle_slideshow();
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
	container.appendChild(mapButton);
	container.appendChild(favoriteButton);
	container.appendChild(slideshowButton);
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
			stop_slideshow();
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
	activate_modal(overlay, "Image details");

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
				if (key === "exif") {
					value.innerHTML = data[key];
				} else {
					value.textContent = data[key];
				}

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
		deactivate_modal();
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
	activate_modal(overlay, "Shortcuts and gestures");
}

function hide_shortcuts() {
	const overlay = document.querySelector(".shortcuts-overlay");
	if (overlay) {
		overlay.remove();
		deactivate_modal();
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
