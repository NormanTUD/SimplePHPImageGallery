"use strict";

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

showPageLoadingIndicator();

createBreadcrumb(current_folder_path);

$(".no_preview_available").parent().hide();

let touchStartX = 0, touchStartY = 0;
let touchEndX = 0, touchEndY = 0;

$(document).ready(async function() {
	function isZooming(event) {
		return event.touches && event.touches.length > 1;
	}

	function handleSwipe() {
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

	$("#delete_search").hide();
	addLinkHighlightEffect();
	await delete_search();

	load_folder(getCurrentFolderParameter(), false);

	draw_map_from_current_images();

	hidePageLoadingIndicator();
});
