<?php
// get_search_index.php
header('Content-Type: application/json');

// Erlaube PHP mehr Zeit und Speicher für den ersten Index-Lauf
ini_set('memory_limit', '512M');
set_time_limit(120);

require_once 'functions.php';

$cache_file = 'thumbnails_cache/search_index.json';
$cache_lifetime = 3600; // 1 Stunde in Sekunden

// Falls der Cache existiert und nicht zu alt ist, diesen ausgeben
if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime)) {
    echo file_get_contents($cache_file);
    exit;
}

// Ansonsten: Index neu aufbauen
$search_data = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator('.', RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm'];

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $ext = strtolower($file->getExtension());
        
        // Nur Bild/Video-Dateien indizieren
        if (in_array($ext, $valid_extensions)) {
            $path = $file->getPathname();
            
            // Verwandte .txt Datei suchen (für Tags/Beschreibung)
            $txt_file = $path . '.txt';
            $content = "";
            if (file_exists($txt_file)) {
                $content = file_get_contents($txt_file);
                // Bereinigen (Zeilenumbeüche zu Leerzeichen)
                $content = str_replace(["\r", "\n"], " ", $content);
                $content = normalize_special_characters($content);
            }

            $search_data[] = [
                'p' => $path,           // Pfad zum Bild
                't' => basename($path), // Dateiname
                'c' => $content         // Inhalt der TXT Datei
            ];
        }
    }
}

$json_output = json_encode($search_data);

// Im Cache speichern für die nächsten Anfragen
if (!is_dir('thumbnails_cache')) {
    mkdir('thumbnails_cache', 0777, true);
}
file_put_contents($cache_file, $json_output);

echo $json_output;
