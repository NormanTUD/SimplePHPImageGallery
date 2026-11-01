import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const baseUrl = (process.env.BASE_URL || 'http://127.0.0.1:8765').replace(/\/$/, '');
const artifactsDir = path.resolve('tests/artifacts');
fs.mkdirSync(artifactsDir, { recursive: true });

const tests = [];
const pageErrors = [];

function test(name, callback) {
  tests.push([name, callback]);
}

function folderUrl(folder) {
  return `${baseUrl}/?${new URLSearchParams({ folder })}`;
}

async function openFolder(page, folder, expectedSelector) {
  await page.goto(folderUrl(folder), { waitUntil: 'domcontentloaded' });
  await page.locator(expectedSelector).first().waitFor({ state: 'visible', timeout: 15000 });
}

async function search(page, term, expectedSelector) {
  const input = page.locator('#searchInput');
  await input.click();
  await input.press('ControlOrMeta+A');
  await input.pressSequentially(term, { delay: 20 });
  await page.locator(expectedSelector).first().waitFor({ state: 'visible', timeout: 20000 });
}

async function ensureMapLoaded(page) {
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.locator('#map_container').waitFor({ state: 'visible', timeout: 20000 });
  await page.locator('#map.leaflet-container').waitFor({ state: 'visible', timeout: 15000 });
}

async function closeFullscreen(page) {
  await page.keyboard.press('Escape');
  await page.locator('.fullscreen').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
}

test('desktop gallery folder navigation works with one click', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  assert.equal(await page.evaluate(() => document.documentElement.dataset.layout), 'desktop', 'Desktop layout was not detected');

  await page.locator('#gallery a[data-path="./urlaub"]').click();
  await page.locator('#gallery a[data-path="./urlaub/strand"]').waitFor({ state: 'visible', timeout: 15000 });
  assert.equal(await page.locator('#gallery .thumbnail_folder').count() >= 2, true, 'Subfolders were not rendered');
});

test('preview URLs are never treated as folders', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  const rejected = await page.evaluate(() => {
    const container = document.createElement('div');
    container.className = 'thumbnail_folder';
    container.setAttribute('data-path', 'index.php?preview=.%2F');
    return window.get_media_path(container, true);
  });
  assert.equal(rejected, '', 'Preview URL was accepted as a folder path');
});

test('gallery image opens fullscreen and supports keyboard navigation', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './urlaub/strand', '#gallery .thumbnail');

  await page.locator('#gallery .thumbnail').first().click();
  await page.locator('.fullscreen').waitFor({ state: 'visible', timeout: 5000 });

  const firstSrc = await page.locator('.fullscreen img').getAttribute('src');
  await page.locator('.fullscreen-nav.next').click();
  await page.waitForFunction(
    (oldSrc) => {
      const img = document.querySelector('.fullscreen img');
      const src = img?.getAttribute('src') || '';
      return src && src !== oldSrc && !src.includes('loading.gif');
    },
    firstSrc,
    { timeout: 10000 }
  );

  const secondSrc = await page.locator('.fullscreen img').getAttribute('src');
  await page.keyboard.press('ArrowRight');
  await page.waitForFunction(
    (oldSrc) => {
      const img = document.querySelector('.fullscreen img');
      const src = img?.getAttribute('src') || '';
      return src && src !== oldSrc && !src.includes('loading.gif');
    },
    secondSrc,
    { timeout: 10000 }
  );

  await page.locator('.fullscreen-close').click();
  await page.locator('.fullscreen').waitFor({ state: 'detached', timeout: 5000 });
  assert.equal(await page.locator('.fullscreen').count(), 0, 'Close button did not close fullscreen');
});

test('search result image is clickable immediately', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  await search(page, 'sonne', '#searchResults .thumbnail');
  await page.locator('#searchResults .thumbnail').first().click();
  await page.locator('.fullscreen').waitFor({ state: 'visible', timeout: 5000 });
  await closeFullscreen(page);
});

test('search result folder navigation returns to the gallery', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  await search(page, 'urlaub', '#searchResults .thumbnail_folder');
  await page.locator('#searchResults .thumbnail_folder').first().click();

  await page.waitForFunction(
    () => {
      const results = document.getElementById('searchResults');
      return results && (results.offsetParent === null || results.innerHTML.trim() === '');
    },
    { timeout: 15000 }
  );

  await page.locator('#gallery .thumbnail_folder, #gallery .thumbnail').first().waitFor({ state: 'visible', timeout: 15000 });
});

test('fuzzy search works from the UI', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  await page.locator('#fuzzy_search').check();
  await search(page, 'sone', '#searchResults .thumbnail');

  const paths = await page.locator('#searchResults .thumbnail').evaluateAll((elements) =>
    elements.map((element) => element.getAttribute('data-path') || '')
  );
  assert.equal(paths.some((item) => item.endsWith('sonne.jpg')), true, 'Fuzzy search did not find sonne.jpg');
});

test('long press selects an image and the checkmark is visible', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './urlaub/strand', '#gallery .thumbnail');

  const firstThumbnail = page.locator('#gallery .thumbnail').first();
  const box = await firstThumbnail.boundingBox();
  assert.ok(box, 'Thumbnail has no bounding box');

  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.waitForTimeout(1100);
  await page.mouse.up();

  assert.equal(await page.evaluate(() => window.selectedImages.length), 1, 'Long press did not select the image');
  await page.locator('#downloadBtn').waitFor({ state: 'visible', timeout: 5000 });

  const checkmarkVisibility = await page.evaluate(() => {
    const checkmark = document.querySelector('#gallery .thumbnail .checkmark');
    return checkmark ? getComputedStyle(checkmark).visibility : null;
  });
  assert.equal(checkmarkVisibility, 'visible', 'Selection checkmark is not visible');

  await page.locator('#unselectBtn').click();
  assert.equal(await page.evaluate(() => window.selectedImages.length), 0, 'Unselect did not clear the selection');
});

test('special characters in search results can be opened', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  await search(page, 'sonderzeichen', '#searchResults .thumbnail');
  const thumbnail = page.locator('#searchResults .thumbnail').first();
  assert.equal(await thumbnail.getAttribute('data-path'), "./special folder/it's a test.jpg", 'Special path was mangled');

  await thumbnail.click();
  await page.locator('.fullscreen').waitFor({ state: 'visible', timeout: 5000 });
  await closeFullscreen(page);
});

test('start page draws the map even with folder=.', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${baseUrl}/?folder=.`, { waitUntil: 'domcontentloaded' });
  await page.locator('#gallery .thumbnail_folder').first().waitFor({ state: 'visible', timeout: 15000 });

  await ensureMapLoaded(page);
});

test('map stays hidden when a folder has no GPS photos', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './stadt', '#gallery .thumbnail');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(1500);
  assert.equal(await page.locator('#map_container').isVisible(), false, 'Map was shown for a folder without GPS');
});

test('map only shows photos from the current folder and its subfolders', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');
  await ensureMapLoaded(page);
  assert.equal(await page.locator('.leaflet-marker-icon').count(), 2, 'Root map should show both GPS photos');

  await page.evaluate(() => window.load_folder('./gps'));
  await page.locator('#gallery .thumbnail').first().waitFor({ state: 'visible', timeout: 15000 });
  await ensureMapLoaded(page);

  const markers = page.locator('.leaflet-marker-icon');
  assert.equal(await markers.count(), 1, 'Subfolder map should only show its own GPS photo');

  await markers.first().click();
  const popupImage = page.locator('.leaflet-popup-content img');
  await popupImage.waitFor({ state: 'visible', timeout: 10000 });
  assert.match(await popupImage.getAttribute('src'), /dresden\.jpg/, 'Wrong photo is shown on the subfolder map');
});

test('select button enables selection, marks cards and clears them', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './urlaub/strand', '#gallery .thumbnail');

  const selectButton = page.locator('#selectModeBtn');
  await selectButton.click();
  assert.equal(await selectButton.getAttribute('aria-pressed'), 'true', 'Select button did not enter selection mode');

  const firstThumbnail = page.locator('#gallery .thumbnail').first();
  await firstThumbnail.click();
  assert.equal(await page.evaluate(() => window.selectedImages.length), 1, 'Select mode click did not select an image');
  assert.equal(await firstThumbnail.evaluate((element) => element.classList.contains('is-selected')), true, 'Selected card is not highlighted');

  await selectButton.click();
  assert.equal(await page.evaluate(() => window.selectedImages.length), 0, 'Done did not clear the selection');
  assert.equal(await selectButton.getAttribute('aria-pressed'), 'false', 'Select button did not leave selection mode');
});

test('gallery metadata and empty folder state are shown', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  const metaText = await page.locator('#galleryMeta').textContent();
  assert.match(metaText, /folders?/i, 'Gallery metadata does not mention folders');

  await openFolder(page, './leer', '#emptyState');
  await page.locator('#emptyState').waitFor({ state: 'visible', timeout: 15000 });
  assert.match(await page.locator('#emptyState').textContent(), /Nothing here yet/i, 'Empty state message missing');
  assert.equal(await page.locator('#gallery .thumbnail').count(), 0, 'Empty folder unexpectedly rendered thumbnails');
});

test('lightbox shows counter, caption, details and download link', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './urlaub/strand', '#gallery .thumbnail');

  await page.locator('#gallery .thumbnail').first().click();
  await page.locator('.fullscreen').waitFor({ state: 'visible', timeout: 5000 });

  assert.match(await page.locator('.fullscreen-counter').textContent(), /^\d+ \/ 2$/, 'Lightbox counter is wrong');
  assert.match(await page.locator('.fullscreen-caption').textContent(), /\.(jpg|png)$/i, 'Lightbox caption is missing the filename');
  assert.equal(await page.locator('.fullscreen-download').getAttribute('download'), (await page.locator('.fullscreen-caption').textContent()));

  await page.locator('.fullscreen-info').click();
  await page.locator('.image-info-overlay').waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('.image-info-table').waitFor({ state: 'visible', timeout: 10000 });
  assert.match(await page.locator('.image-info-body').textContent(), /image_width/i, 'Image details are missing dimensions');

  await page.keyboard.press('Escape');
  await page.locator('.image-info-overlay').waitFor({ state: 'detached', timeout: 5000 });
  assert.equal(await page.locator('.fullscreen').count(), 1, 'Escape closed the lightbox instead of only the details');

  await page.keyboard.press('Escape');
  await page.locator('.fullscreen').waitFor({ state: 'detached', timeout: 5000 });
});

test('metadata dialog does not execute HTML from filenames', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './xss <img src=x onerror=alert(1)>', '#gallery .thumbnail');

  await page.locator('#gallery .thumbnail').first().click();
  await page.locator('.fullscreen').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('.fullscreen-info').click();
  await page.locator('.image-info-table').waitFor({ state: 'visible', timeout: 10000 });

  assert.equal(await page.locator('.image-info-table img').count(), 0, 'Filename was interpreted as HTML');
});

test('modal focus is trapped and restored', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  await page.locator('#shortcutsBtn').click();
  await page.locator('.shortcuts-overlay').waitFor({ state: 'visible', timeout: 5000 });

  for (let i = 0; i < 8; i++) {
    await page.keyboard.press('Tab');
    const focusInside = await page.evaluate(() => {
      const overlay = document.querySelector('.shortcuts-overlay');
      return !!overlay && overlay.contains(document.activeElement);
    });
    assert.equal(focusInside, true, 'Focus escaped the shortcuts dialog');
  }

  await page.keyboard.press('Escape');
  await page.locator('.shortcuts-overlay').waitFor({ state: 'detached', timeout: 5000 });
  assert.equal(await page.evaluate(() => document.activeElement?.id), 'shortcutsBtn', 'Focus was not restored');
});

test('shortcuts dialog opens and closes', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  await page.locator('#shortcutsBtn').click();
  await page.locator('.shortcuts-overlay').waitFor({ state: 'visible', timeout: 5000 });
  assert.match(await page.locator('.shortcuts-overlay').textContent(), /Shortcuts/i, 'Shortcuts dialog content missing');

  await page.locator('.shortcuts-close').click();
  await page.locator('.shortcuts-overlay').waitFor({ state: 'detached', timeout: 5000 });
});

test('image cards get readable filename labels', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './urlaub/strand', '#gallery .thumbnail');

  const label = page.locator('#gallery .thumbnail .thumbnail-name').first();
  await label.waitFor({ state: 'attached', timeout: 5000 });
  assert.match(await label.textContent(), /\.(jpg|png)$/i, 'Filename label is missing');
});

test('large folders load additional images while scrolling', async (page) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openFolder(page, './sehrviele', '#gallery .thumbnail');
  await page.waitForFunction(() => document.querySelectorAll('#gallery .thumbnail').length === 120, { timeout: 15000 });

  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForFunction(() => document.querySelectorAll('#gallery .thumbnail').length >= 150, { timeout: 20000 });
  assert.match(await page.locator('#galleryMeta').textContent(), /150/, 'Gallery metadata does not reflect the total item count');
});

test('thumbnails below the fold are lazy loaded', async (page) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openFolder(page, './viele', '#gallery .thumbnail');
  await page.waitForFunction(() => document.querySelectorAll('#gallery .thumbnail').length >= 60, { timeout: 15000 });

  const lastImageLazyState = await page.evaluate(() => {
    const thumbnails = document.querySelectorAll('#gallery .thumbnail');
    const last = thumbnails[thumbnails.length - 1];
    return last?.querySelector('img')?.getAttribute('data-lazy-loaded');
  });
  assert.notEqual(lastImageLazyState, '1', 'Bottom thumbnail was loaded eagerly');

  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForFunction(
    () => {
      const thumbnails = document.querySelectorAll('#gallery .thumbnail');
      const last = thumbnails[thumbnails.length - 1];
      return last?.querySelector('img')?.getAttribute('data-lazy-loaded') === '1';
    },
    { timeout: 20000 }
  );
});

test('favorites can be toggled, filtered and persist', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './urlaub/strand', '#gallery .thumbnail');

  const favoriteButton = page.locator('#gallery .thumbnail .favorite-btn').first();
  await favoriteButton.click();
  assert.equal(await page.locator('.fullscreen').count(), 0, 'Favorite click opened the lightbox');
  assert.equal(await favoriteButton.getAttribute('aria-pressed'), 'true', 'Favorite was not stored');
  assert.match(await page.locator('#favoritesCount').textContent(), /1/, 'Favorite counter was not updated');

  await page.locator('#favoritesBtn').click();
  assert.equal(await page.locator('#gallery .thumbnail:visible').count(), 1, 'Favorites filter did not hide other photos');
  assert.equal(await page.locator('#favoritesBtn').getAttribute('aria-pressed'), 'true', 'Favorites filter is not active');

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.locator('#gallery .thumbnail').first().waitFor({ state: 'visible', timeout: 15000 });
  assert.equal(
    await page.locator('#gallery .thumbnail .favorite-btn').first().getAttribute('aria-pressed'),
    'true',
    'Favorite did not survive a reload'
  );
});

test('timeline view groups photos and can be disabled', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './urlaub/strand', '#gallery .thumbnail');

  await page.locator('#timelineBtn').click();
  await page.locator('.timeline-heading').first().waitFor({ state: 'visible', timeout: 5000 });
  assert.equal(await page.locator('#timelineBtn').getAttribute('aria-pressed'), 'true', 'Timeline button is not active');

  await page.locator('#timelineBtn').click();
  assert.equal(await page.locator('.timeline-heading').count(), 0, 'Timeline was not restored to the gallery');
  assert.equal(await page.locator('#timelineBtn').getAttribute('aria-pressed'), 'false', 'Timeline button stayed active');
});

test('slideshow starts and can be stopped', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './urlaub/strand', '#gallery .thumbnail');

  await page.locator('#slideshowBtn').click();
  await page.locator('.fullscreen').waitFor({ state: 'visible', timeout: 5000 });
  assert.equal(await page.locator('#slideshowBtn').getAttribute('aria-pressed'), 'true', 'Slideshow did not start');

  await page.locator('.fullscreen-slideshow').click();
  assert.equal(await page.locator('#slideshowBtn').getAttribute('aria-pressed'), 'false', 'Slideshow did not stop');
  await closeFullscreen(page);
});

test('lightbox can show the current photo on a map', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './gps', '#gallery .thumbnail');

  await page.locator('#gallery .thumbnail').first().click();
  await page.locator('.fullscreen').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('.fullscreen-map').click();

  await page.locator('.location-overlay').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('.location-map.leaflet-container').waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('.location-close').click();
  await page.locator('.location-overlay').waitFor({ state: 'detached', timeout: 5000 });
});

test('search filters, highlights and remembers terms', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  await page.locator('#searchType').selectOption('file');
  await search(page, 'urlaub', '#searchResults .thumbnail');
  assert.equal(await page.locator('#searchResults .thumbnail_folder').count(), 0, 'File filter returned folders');

  await page.locator('#searchType').selectOption('folder');
  await page.locator('#searchResults .thumbnail_folder').first().waitFor({ state: 'visible', timeout: 20000 });
  assert.equal(await page.locator('#searchResults .thumbnail').count(), 0, 'Folder filter returned files');

  await page.locator('#searchType').selectOption('all');
  await search(page, 'sonne', '#searchResults .thumbnail');
  assert.equal(await page.locator('#searchResults mark').count() > 0, true, 'Search matches were not highlighted');

  const history = await page.locator('#searchHistory option').evaluateAll((options) => options.map((option) => option.value));
  assert.equal(history.includes('sonne'), true, 'Recent search was not stored');
});

test('service worker registers for offline support', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(async () => {
    if (!('serviceWorker' in navigator)) {
      return false;
    }
    const registrations = await navigator.serviceWorker.getRegistrations();
    return registrations.length > 0;
  }, { timeout: 20000 });
});

test('back to top button appears and scrolls home', async (page) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openFolder(page, './viele', '#gallery .thumbnail');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.locator('#backToTop.is-visible').waitFor({ state: 'visible', timeout: 10000 });

  await page.locator('#backToTop').click();
  await page.waitForFunction(() => window.scrollY < 80, { timeout: 10000 });
});

test('mobile topbar collapses secondary actions into a sheet', async (page) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  for (const selector of ['#timelineBtn', '#favoritesBtn', '#slideshowBtn', '#selectModeBtn', '#shortcutsBtn']) {
    assert.equal(await page.locator(selector).isVisible(), false, `${selector} still takes space on mobile`);
  }

  await page.locator('#mobileMoreBtn').click();
  await page.locator('#mobileActions').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#mobileActions .select-mode-toggle').click();
  await page.locator('#mobileActions').waitFor({ state: 'hidden', timeout: 5000 });
  assert.equal(await page.evaluate(() => window.enabled_selection_mode), true, 'Mobile action did not enable selection');

  await page.locator('#mobileMoreBtn').click();
  await page.locator('#mobileActions .select-mode-toggle').click();
  assert.equal(await page.evaluate(() => window.enabled_selection_mode), false, 'Mobile action did not disable selection');
});

test('mobile layout has no horizontal overflow and usable search', async (page) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openFolder(page, './', '#gallery .thumbnail_folder');

  assert.equal(await page.evaluate(() => document.documentElement.dataset.layout), 'mobile', 'Mobile layout was not detected');

  const hasOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  assert.equal(hasOverflow, false, 'Mobile layout overflows horizontally');

  const searchBox = await page.locator('#searchInput').boundingBox();
  assert.ok(searchBox, 'Search input is not visible on mobile');
  assert.ok(searchBox.width >= 150, 'Search input is too small on mobile');
  assert.ok(searchBox.x >= 0 && searchBox.x + searchBox.width <= 390, 'Search input is outside the viewport');

  await page.locator('#gallery .thumbnail_folder').first().click();
  await page.locator('#gallery .thumbnail_folder, #gallery .thumbnail').first().waitFor({ state: 'visible', timeout: 15000 });
});

let browser;

try {
  const launchOptions = { headless: true };
  if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE) {
    launchOptions.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE;
  }
  if (process.env.PLAYWRIGHT_CHROMIUM_ARGS) {
    launchOptions.args = process.env.PLAYWRIGHT_CHROMIUM_ARGS.split(' ');
  }

  browser = await chromium.launch(launchOptions);

  let passed = 0;
  let failed = 0;

  for (const [name, callback] of tests) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(15000);

    page.on('pageerror', (error) => {
      pageErrors.push(`${name}: ${error.message}`);
    });

    try {
      await callback(page);
      passed++;
      console.log(`PASS ${name}`);
    } catch (error) {
      failed++;
      console.log(`FAIL ${name}`);
      console.log(`     ${error.message}`);
      const screenshot = path.join(artifactsDir, `ui-failure-${failed}.png`);
      await page.screenshot({ path: screenshot, fullPage: true }).catch(() => {});
    } finally {
      await context.close();
    }
  }

  if (pageErrors.length > 0) {
    failed++;
    console.log('FAIL no uncaught browser errors');
    for (const error of pageErrors) {
      console.log(`     ${error}`);
    }
  } else {
    passed++;
    console.log('PASS no uncaught browser errors');
  }

  console.log(`\nUI tests: ${passed} passed, ${failed} failed`);
  await browser.close();
  browser = null;
  process.exit(failed === 0 ? 0 : 1);
} catch (error) {
  console.error(`UI test runner failed: ${error.stack || error.message}`);
  if (browser) {
    await browser.close().catch(() => {});
  }
  process.exit(1);
}
