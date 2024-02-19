# SimplePHPImageGallery
This is a very simple .php-file that you can simply put into a folder and it will display the folders' contents in a web browser, easily browsable, as a photo gallery. Folders in the filesystem are folders in the GUI as well.

# Requirements

Nothing, except PHP and the JQuery file.

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
