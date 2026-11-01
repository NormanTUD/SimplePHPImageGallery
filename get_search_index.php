<?php
// get_search_index.php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

ini_set('memory_limit', '512M');
set_time_limit(120);

require_once 'functions.php';

$db = open_search_index_database();
$entries = [];

if ($db) {
    sync_search_index($db, isset($_GET['refresh']) && $_GET['refresh'] === '1');

    $result = $db->query("SELECT path, name, content FROM entries WHERE type = 'file' ORDER BY path ASC");
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        if (!is_path_within_gallery($row['path']) || is_helper_asset($row['path'])) {
            continue;
        }

        $entries[] = [
            'p' => $row['path'],
            't' => basename($row['path']),
            'c' => $row['content'],
        ];
    }

    $db->close();
}

echo json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
