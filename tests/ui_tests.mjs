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

test('GPS folder draws the map', async (page) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await openFolder(page, './gps', '#gallery .thumbnail');

  await page.locator('#map_container').waitFor({ state: 'visible', timeout: 20000 });
  await page.locator('#map.leaflet-container').waitFor({ state: 'visible', timeout: 10000 });
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

test('back to top button appears and scrolls home', async (page) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openFolder(page, './viele', '#gallery .thumbnail');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.locator('#backToTop.is-visible').waitFor({ state: 'visible', timeout: 10000 });

  await page.locator('#backToTop').click();
  await page.waitForFunction(() => window.scrollY < 80, { timeout: 10000 });
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
