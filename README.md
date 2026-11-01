# SimplePHPImageGallery
This is a very simple .php-file that you can simply put into a folder and it will display the folders' contents in a web browser, easily browsable, as a photo gallery. Folders in the filesystem are folders in the GUI as well.

# Requirements

Nothing, except PHP and the JQuery file.

# Run in Docker:

```command
bash docker.sh --debug --local-port 3312 --image-path ~/path/to/images # can be relative or absolute
```

# Showing links

To show links at the top, write a file called "links.txt" into the main directory where the index.php is. It has this structure:

```
https://link.com/, name
```

# Caching preview images

```console
mkdir ./thumbnails_cache/
sudo chown www-data:www-data ./thumbnails_cache/
```

# Keywords for images

For each image file, e.g. `IMG_6563.JPG` you can add a  `.txt`-file of basically the same name (`IMG_6563.txt`), that can contain keywords for that image. When searching, the `.txt` files are searched as well and displayed as results.

# Tests and CI

The repository ships with an isolated integration and browser test suite:

```console
npm ci
npx playwright install --with-deps chromium
bash tests/run.sh
```

The suite generates example images, folders, TXT keywords and GPS data in a temporary
document root, starts PHP's built-in server and checks the gallery, search, previews,
metadata, map data, ZIP downloads, responsive layout, infinite scrolling, favorites,
timeline view, slideshow, PWA support and lazy loading.

CI runs on every pushed commit and pull request. After a successful push build, the
workflow creates and pushes a tag named after the run and the GitHub Actions runner,
for example `ci-42-123456789-1-my-runner`.

# Features

- Folder navigation plus a fully automatic SQLite search index with filename/TXT
  search, fuzzy matching, type filters and shareable deep links.
- Responsive high-DPI thumbnails (320/640/1280 px, JPEG/WebP/AVIF output) with
  lazy loading and browser caching.
- Infinite scrolling for very large folders.
- Lightbox with keyboard/swipe navigation, preloading, pinch/double-click zoom,
  details, download, favorites, slideshow and "show on map".
- Map view with lazy loading and clustering, limited to the current folder tree.
- Timeline view, favorites filter, recent searches and installable PWA support.
