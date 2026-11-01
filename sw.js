const SHELL_CACHE = "spg-shell-v2";
const RUNTIME_CACHE = "spg-runtime-v2";
const OFFLINE_KEY = new URL("./index.php", self.registration.scope).href;

const SHELL_ASSETS = [
	"./",
	"./index.php",
	"./style.css",
	"./functions.js",
	"./main.js",
	"./jquery-3.7.1.min.js",
	"./leaflet.js",
	"./leaflet.markercluster.js",
	"./MarkerCluster.Default.css",
	"./loading.gif",
	"./error.svg",
	"./search.svg",
	"./icon.svg",
	"./icon-192.png",
	"./icon-512.png",
	"./manifest.webmanifest",
];

self.addEventListener("install", (event) => {
	event.waitUntil(
		caches.open(SHELL_CACHE)
			.then((cache) => cache.addAll(SHELL_ASSETS))
			.catch(() => undefined)
			.then(() => self.skipWaiting())
	);
});

self.addEventListener("activate", (event) => {
	event.waitUntil(
		caches.keys()
			.then((keys) => Promise.all(
				keys
					.filter((key) => key !== SHELL_CACHE && key !== RUNTIME_CACHE)
					.map((key) => caches.delete(key))
			))
			.then(() => self.clients.claim())
	);
});

async function trim_cache(cache_name, max_entries) {
	const cache = await caches.open(cache_name);
	const keys = await cache.keys();
	if (keys.length <= max_entries) {
		return;
	}
	await Promise.all(keys.slice(0, keys.length - max_entries).map((key) => cache.delete(key)));
}

async function cache_first(request) {
	const cached = await caches.match(request);
	if (cached) {
		return cached;
	}

	const response = await fetch(request);
	if (response.ok) {
		const cache = await caches.open(RUNTIME_CACHE);
		await cache.put(request, response.clone());
		await trim_cache(RUNTIME_CACHE, 400);
	}
	return response;
}

async function stale_while_revalidate(request) {
	const cache = await caches.open(SHELL_CACHE);
	const cached = await cache.match(request, { ignoreSearch: true });
	const network = fetch(request)
		.then((response) => {
			if (response.ok) {
				cache.put(request, response.clone());
			}
			return response;
		})
		.catch(() => cached);

	return cached || network;
}

async function network_first_navigation(request) {
	try {
		const response = await fetch(request);
		if (response.ok) {
			const cache = await caches.open(SHELL_CACHE);
			await cache.put(OFFLINE_KEY, response.clone());
		}
		return response;
	} catch (error) {
		const cached = await caches.match(OFFLINE_KEY);
		if (cached) {
			return cached;
		}
		throw error;
	}
}

self.addEventListener("fetch", (event) => {
	const request = event.request;

	if (request.method !== "GET") {
		return;
	}

	const url = new URL(request.url);
	if (url.origin !== self.location.origin) {
		return;
	}

	if (url.pathname.endsWith("/sw.js") || url.pathname.endsWith("/manifest.webmanifest")) {
		return;
	}

	if (request.mode === "navigate") {
		event.respondWith(network_first_navigation(request));
		return;
	}

	if (request.destination === "image" || url.pathname.endsWith("/image.php")) {
		event.respondWith(cache_first(request));
		return;
	}

	if (/\.(css|js|svg|png|gif|webp|avif|woff2?)$/i.test(url.pathname)) {
		event.respondWith(stale_while_revalidate(request));
	}
});
