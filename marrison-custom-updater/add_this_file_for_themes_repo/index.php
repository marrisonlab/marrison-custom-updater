<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Percorso della cartella corrente
$directory = __DIR__;

// Array per memorizzare i temi trovati
$themes = [];

// Scansiona tutti i file ZIP ricorsivamente (cartella corrente + sottocartelle)
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'zip') {
        $files[] = $file->getPathname();
    }
}

foreach ($files as $zip_file) {
    $zip = new ZipArchive();
    
    if ($zip->open($zip_file) === true) {
        // Cerca il file style.css del tema
        $theme_info = null;
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            // Cerca style.css nel primo livello o nella cartella principale del tema
            // Solitamente è "nome-tema/style.css" oppure "style.css" se lo zip è flat
            if (preg_match('/^[^\/]+\/style\.css$/', $filename) || $filename === 'style.css') {
                $content = $zip->getFromIndex($i);
                
                // Verifica se contiene l'header del tema
                if (preg_match('/Theme Name:/i', $content)) {
                    $theme_info = [
                        'content' => $content,
                        'file' => $filename
                    ];
                    break;
                }
            }
        }
        
        $zip->close();
        
        if ($theme_info) {
            // Estrae le informazioni dall'header del tema
            $name = '';
            $version = '';
            $author = '';
            $description = '';
            $requires_php = '7.0';
            
            if (preg_match('/Theme Name:\s*(.+)/i', $theme_info['content'], $matches)) {
                $name = trim($matches[1]);
            }
            
            if (preg_match('/Version:\s*(.+)/i', $theme_info['content'], $matches)) {
                $version = trim($matches[1]);
            }
            
            if (preg_match('/Author:\s*(.+)/i', $theme_info['content'], $matches)) {
                $author = trim($matches[1]);
            }
            
            if (preg_match('/Description:\s*(.+)/i', $theme_info['content'], $matches)) {
                $description = trim($matches[1]);
            }
            
            if (preg_match('/Requires PHP:\s*(.+)/i', $theme_info['content'], $matches)) {
                $requires_php = trim($matches[1]);
            }
            
            // Estrae lo slug dal nome del file ZIP
            $zip_filename = basename($zip_file, '.zip');
            
            // Rimuove la versione dal nome del file se presente
            // Es: my-theme-1.0.0.zip -> my-theme
            // my-theme-custom-1.2.3.zip -> my-theme-custom
            // my-theme-v2.1.0.zip -> my-theme
            $slug = preg_replace('/[-_]?\d+(\.\d+)*.*$/', '', $zip_filename);
            $slug = preg_replace('/[-_]?(v|version)[-_]?\d+.*$/i', '', $slug);
            $slug = trim($slug, '-_');
            
            // Se non riesce a estrarre lo slug dal nome file, usa il nome della cartella interna
            if (empty($slug) && strpos($theme_info['file'], '/') !== false) {
                $slug = dirname($theme_info['file']);
            }
            
            // Fallback: usa il nome del file senza estensione
            if (empty($slug)) {
                $slug = $zip_filename;
            }
            
            // URL completo per il download (preserva il percorso relativo della sottocartella)
            $relative_path = ltrim(str_replace($directory, '', $zip_file), DIRECTORY_SEPARATOR . '/');
            $relative_path = str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);
            $download_url = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $relative_path;
            
            $themes[] = [
                'name' => $name ?: 'Unknown Theme',
                'slug' => $slug,
                'version' => $version ?: '1.0.0',
                'author' => $author ?: 'Unknown',
                'description' => $description ?: 'Custom Theme',
                'requires_php' => $requires_php,
                'download_url' => $download_url,
                'homepage' => '',
                'zip_file' => basename($zip_file),
                'file_size' => filesize($zip_file),
                'last_modified' => date('Y-m-d H:i:s', filemtime($zip_file))
            ];
        }
    }
}

// Ordina per nome
usort($themes, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// Restituisce il JSON
echo json_encode($themes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
