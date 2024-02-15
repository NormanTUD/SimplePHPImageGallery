<!DOCTYPE html>
<html>
<head>
    <title>Galerie</title>
    <script src="jquery-3.7.1.min.js"></script>

    <style>
        .thumbnail_folder {
            display: inline-block;
            margin: 10px;
            max-width: 150px;
            max-height: 150px;
            cursor: pointer;
        }

        .thumbnail_folder img {
            max-width: 100%;
            max-height: 100%;
        }



        .thumbnail {
            display: inline-block;
            margin: 10px;
            max-width: 150px;
            max-height: 150px;
            cursor: pointer;
        }

        .thumbnail img {
            max-width: 100%;
            max-height: 100%;
        }

        .fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fullscreen img {
            max-width: 90%;
            max-height: 90%;
        }
    </style>
</head>
<body>
<?php
function displayGallery($folderPath)
{
    $files = scandir($folderPath);

    $thumbnails = [];
    $images = [];

    foreach ($files as $file) {
        if ($file === '.' || $file === '..'  || preg_match("/^\./", $file)) {
            continue;
        }

        $filePath = $folderPath . '/' . $file;

        if (is_dir($filePath)) {
            $folderImages = getImagesInFolder($filePath);

            if (empty($folderImages)) {
                // If the folder itself doesn't have images, try to get a random image from subfolders
                $randomImage = getRandomImageFromSubfolders($filePath);
                $thumbnailPath = $randomImage ? $randomImage['path'] : '';
            } else {
                $randomImage = $folderImages[array_rand($folderImages)];
                $thumbnailPath = $randomImage['path'];
            }

            $thumbnails[] = [
                'name' => $file,
                'thumbnail' => $thumbnailPath,
                'path' => $filePath
            ];
        } else {
            $imageExtensions = array('jpg', 'jpeg', 'png', 'gif');
            $fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (in_array($fileExtension, $imageExtensions)) {
                $images[] = [
                    'name' => $file,
                    'path' => $filePath
                ];
            }
        }
    }

    usort($thumbnails, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    usort($images, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    foreach ($thumbnails as $thumbnail) {
        echo '<div class="thumbnail_folder" onclick="showFolder(\'' . $thumbnail['path'] . '\')">';
        if (!empty($thumbnail['thumbnail'])) {
            echo '<img src="' . $thumbnail['thumbnail'] . '" alt="' . $thumbnail['name'] . '">';
        } else {
            echo '<div>No Preview Available</div>';
        }
        echo '<h3>' . $thumbnail['name'] . '</h3>';
        echo '</div>';
    }

    foreach ($images as $image) {
        echo '<div class="thumbnail" onclick="showImage(\'' . $image['path'] . '\')">';
        echo '<img src="' . $image['path'] . '" alt="' . $image['name'] . '">';
        echo '</div>';
    }
}

function getImagesInFolder($folderPath)
{
    $folderFiles = scandir($folderPath);

    $images = [];

    foreach ($folderFiles as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $filePath = $folderPath . '/' . $file;

        $imageExtensions = array('jpg', 'jpeg', 'png', 'gif');
        $fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (in_array($fileExtension, $imageExtensions)) {
            $images[] = [
                'name' => $file,
                'path' => $filePath
            ];
        }
    }

    return $images;
}

function getRandomImageFromSubfolders($folderPath)
{
    $subfolders = glob($folderPath . '/*', GLOB_ONLYDIR);

    foreach ($subfolders as $subfolder) {
        $images = getImagesInFolder($subfolder);

        if (!empty($images)) {
            return $images[array_rand($images)];
        }
    }

    return null;
}
?>

<script>
        function showFolder(folderPath) {
                window.location.href = '?folder=' + encodeURIComponent(folderPath);
        }

        var fullscreen;

        function showImage(imagePath) {
                $(fullscreen).remove();

                fullscreen = document.createElement('div');
                fullscreen.classList.add('fullscreen');
                fullscreen.onclick = function() {
                        fullscreen.parentNode.removeChild(fullscreen);
                };

                var image = document.createElement('img');
                image.src = imagePath;

                fullscreen.appendChild(image);
                document.body.appendChild(fullscreen);
        }

        function get_fullscreen_img_name () {
                var src = $(".fullscreen").find("img").attr("src");

                if(src) {
                        src = src.replace(/.*\//, "");

                        return src;
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

        function next_or_prev (next=1) {
                var current_fullscreen = get_fullscreen_img_name();

                if(!current_fullscreen) {
                        return;
                }

                var current_idx = -1;

                var $thumbnails = $(".thumbnail");

                $thumbnails.each((i, e) => {
                        var onclick = $(e).attr("onclick");

                        onclick = onclick.replace(/.*\//, "").replace(/'.*/, "");

                        if(onclick == current_fullscreen) {
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

                var next_img = $($thumbnails[next_idx]).attr("onclick").replace(/.*?'/, '').replace(/'.*/, "");

                showImage(next_img);
        }

        document.onkeydown = checkKey;

        function checkKey(e) {
                e = e || window.event;

                if (e.keyCode == '38') {
                        // up arrow
                } else if (e.keyCode == '40') {
                        // down arrow
                } else if (e.keyCode == '37') {
                        prev_image();
                } else if (e.keyCode == '39') {
                        next_image();
                } else if (e.key === "Escape") {
                        $(fullscreen).remove();
                }
        }
</script>

<?php
$folderPath = './'; // Aktueller Ordner, in dem die index.php liegt

if (isset($_GET['folder']) && !preg_match("/\.\./", $_GET["folder"])) {
    $folderPath = $_GET['folder'];
}

displayGallery($folderPath);
?>
</body>
</html>
