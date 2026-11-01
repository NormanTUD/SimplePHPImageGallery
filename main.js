"use strict";

var select_image_timer = 0;
var select_folder_timer = 0;

var selectedImages = [];
var selectedFolders = [];

var enabled_selection_mode = false;

var map = null;
var fullscreen;
var current_fullscreen_image = "";

const mobile_layout_query = window.matchMedia('(max-width: 760px), (pointer: coarse)');

function update_layout_mode() {
	document.documentElement.dataset.layout = mobile_layout_query.matches ? 'mobile' : 'desktop';
}

update_layout_mode();
if (mobile_layout_query.addEventListener) {
	mobile_layout_query.addEventListener('change', update_layout_mode);
} else if (mobile_layout_query.addListener) {
	mobile_layout_query.addListener(update_layout_mode);
}
window.addEventListener('orientationchange', function () {
	setTimeout(update_layout_mode, 120);
}, { passive: true });

const back_to_top_button = document.getElementById('backToTop');

function update_back_to_top_visibility() {
	if (!back_to_top_button) {
		return;
	}
	back_to_top_button.classList.toggle('is-visible', window.scrollY > 650);
}

window.addEventListener('scroll', update_back_to_top_visibility, { passive: true });
update_back_to_top_visibility();

var deferred_install_prompt = null;

window.addEventListener('beforeinstallprompt', function (event) {
	event.preventDefault();
	deferred_install_prompt = event;
	document.querySelectorAll('.install-toggle').forEach(function (button) {
		button.hidden = false;
	});
});

window.addEventListener('appinstalled', function () {
	deferred_install_prompt = null;
	document.querySelectorAll('.install-toggle').forEach(function (button) {
		button.hidden = true;
	});
});

function install_pwa() {
	if (!deferred_install_prompt) {
		show_toast('Installation is available from your browser menu.');
		return;
	}

	deferred_install_prompt.prompt();
	deferred_install_prompt = null;
	document.querySelectorAll('.install-toggle').forEach(function (button) {
		button.hidden = true;
	});
}

if ('serviceWorker' in navigator) {
	window.addEventListener('load', function () {
		navigator.serviceWorker.register('sw.js', { updateViaCache: 'none' })
			.then(function (registration) {
				return registration.update();
			})
			.catch(function (error) {
				console.warn('Service worker registration failed:', error);
			});
	});
}

const log = console.log;
const debug = console.debug;
const l = log;

var searchTimer;
var lastSearch = "";

document.onkeydown = checkKey;

var json_cache = {};
var fill_cache_images = [];

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
	} else if (event.key === '/') {
		var searchInput = document.getElementById('searchInput');
		if (document.activeElement !== searchInput) {
			event.preventDefault();
			searchInput.focus();
		}
	}
});

document.addEventListener('keypress', function(event) {
	if($(fullscreen).is(":visible")) {
		return;
	}

	var charCode = event.which || event.keyCode;
	var charStr = String.fromCharCode(charCode);

	if (/[a-zA-Z0-9äÄöÖÜüß]/.test(charStr)) {
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

createBreadcrumb(current_folder_path);

$(".no_preview_available").parent().hide();

let touchStartX = 0, touchStartY = 0;
let touchEndX = 0, touchEndY = 0;

$(document).ready(async function() {
	function isZooming(event) {
		return event.touches && event.touches.length > 1;
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

	function handleSwipe() {
		if (!getToggleSwitchValue()) {
			return
		}
		const deltaX = touchEndX - touchStartX;
		const deltaY = touchEndY - touchStartY;

		const absDeltaX = Math.abs(deltaX);
		const absDeltaY = Math.abs(deltaY);
		const swipeThreshold = 50;

		if (absDeltaX > swipeThreshold && absDeltaX > absDeltaY) {
			if (deltaX > 0) {
				prev_image();
			} else {
				next_image();
			}
		}
	}

	document.addEventListener('touchstart', function (event) {
		if (isZooming(event)) return;
		touchStartX = event.touches[0].clientX;
		touchStartY = event.touches[0].clientY;
	}, { passive: true });

	document.addEventListener('touchend', function (event) {
		if (isZooming(event)) return;
		touchEndX = event.changedTouches[0].clientX;
		touchEndY = event.changedTouches[0].clientY;
		handleSwipe();
	}, { passive: false });

	$("#delete_search, #searchResults").hide();
	addLinkHighlightEffect();
	unselectSelection();
	load_favorites_store();
	load_recent_searches();
	setup_gallery_infinite_scroll();
	setup_map_lazy_observer();

	await load_folder(getCurrentFolderParameter(), true);
});
