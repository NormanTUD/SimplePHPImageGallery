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

createBreadcrumb(current_folder_path);

$(".no_preview_available").parent().hide();

$(document).ready(async function() {
	$("#delete_search").hide();
	addLinkHighlightEffect();
	await delete_search();

	await load_folder(getCurrentFolderParameter());
	hidePageLoadingIndicator();
});
