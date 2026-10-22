<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');

$storageRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-hub-storage';
$filesRoot = $storageRoot . DIRECTORY_SEPARATOR . 'files';
$metadataPath = $storageRoot . DIRECTORY_SEPARATOR . 'metadata.json';

function respond(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureStorage(string $root, string $files, string $metadata): void {
    foreach ([$root, $files] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            respond(500, ['error' => 'Nepodarilo sa vytvoriť úložiskový priečinok.']);
        }
    }
    if (!file_exists($metadata)) {
        file_put_contents($metadata, json_encode(['documents' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($metadata, 0640);
    }
}

function authorizeRequest(): void {
    $provided = $_SERVER['HTTP_X_ADMIN_HUB_TOKEN'] ?? '';
    if (ADMIN_HUB_UPLOAD_TOKEN === 'CHANGE-THIS-TO-A-RANDOM-SECRET-AT-LEAST-40-CHARS'
        || strlen(ADMIN_HUB_UPLOAD_TOKEN) < 40) {
        respond(503, ['error' => 'Najprv nastav tímové heslo v api/config.php (minimálne 40 znakov).']);
    }
    if (!is_string($provided) || $provided === '' || !hash_equals(ADMIN_HUB_UPLOAD_TOKEN, $provided)) {
        respond(401, ['error' => 'Neplatné heslo privátneho úložiska.']);
    }
}

function withMetadata(string $path, bool $write, callable $callback) {
    $fp = @fopen($path, 'c+');
    if (!$fp) respond(500, ['error' => 'Nepodarilo sa otvoriť register dokumentov.']);
    if (!flock($fp, $write ? LOCK_EX : LOCK_SH)) {
        fclose($fp);
        respond(500, ['error' => 'Register dokumentov je práve zamknutý.']);
    }
    rewind($fp);
    $raw = stream_get_contents($fp);
    $db = json_decode($raw ?: '', true);
    if (!is_array($db) || !isset($db['documents']) || !is_array($db['documents'])) $db = ['documents' => []];
    $result = $callback($db);
    if ($write) {
        rewind($fp);
        ftruncate($fp, 0);
        $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || fwrite($fp, $json) === false) {
            flock($fp, LOCK_UN); fclose($fp);
            respond(500, ['error' => 'Nepodarilo sa uložiť register dokumentov.']);
        }
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function formatBytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', ' ') . ' KB';
    return number_format($bytes / 1048576, 1, ',', ' ') . ' MB';
}

function extensionType(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, ['jpg','jpeg','png','webp','gif','svg'], true)) return 'image';
    if (in_array($ext, ['xls','xlsx'], true)) return 'xlsx';
    if ($ext === 'csv') return 'csv';
    if (in_array($ext, ['mp4','mov','webm','avi'], true)) return 'video';
    if (in_array($ext, ['doc','docx'], true)) return 'docx';
    if (in_array($ext, ['txt','md'], true)) return 'txt';
    if (in_array($ext, ['html','htm'], true)) return 'html';
    return 'file';
}

function publicDocument(array $d): array {
    $current = $d;
    unset($current['storedName'], $current['contentType']);
    if (isset($current['extractedText'])) $current['extractedText'] = substr((string)$current['extractedText'], 0, 3000);
    $safeVersions = [];
    foreach (($d['versions'] ?? []) as $v) {
        unset($v['storedName'], $v['contentType']);
        $safeVersions[] = $v;
    }
    $current['versions'] = $safeVersions;
    return $current;
}

function findDocument(array $db, string $id): ?array {
    foreach ($db['documents'] as $doc) if (($doc['id'] ?? '') === $id) return $doc;
    return null;
}

ensureStorage($storageRoot, $filesRoot, $metadataPath);
authorizeRequest();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string)($_GET['action'] ?? '');

if ($method === 'GET' && isset($_GET['id'])) {
    $id = (string)$_GET['id'];
    $versionNumber = isset($_GET['version']) ? (int)$_GET['version'] : null;
    $doc = withMetadata($metadataPath, false, function (&$db) use ($id) { return findDocument($db, $id); });
    if (!$doc) respond(404, ['error' => 'Dokument sa nenašiel.']);
    $item = $doc;
    if ($versionNumber !== null) {
        $item = null;
        foreach (($doc['versions'] ?? []) as $v) if ((int)($v['version'] ?? -1) === $versionNumber) { $item = $v; break; }
        if (!$item) respond(404, ['error' => 'Verzia dokumentu sa nenašla.']);
    }
    $stored = basename((string)($item['storedName'] ?? ''));
    $path = $filesRoot . DIRECTORY_SEPARATOR . $stored;
    if ($stored === '' || !is_file($path)) respond(404, ['error' => 'Súbor chýba v úložisku.']);
    $name = (string)($item['name'] ?? $doc['name'] ?? 'document.bin');
    $mime = (string)($item['contentType'] ?? 'application/octet-stream');
    if (preg_match('/[\r\n]/', $name)) $name = 'document.bin';
    // The UI downloads via fetch and creates its own Blob URL. HTML/SVG are never served inline.
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: attachment; filename="' . addcslashes(basename($name), '"\\') . '"');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    readfile($path);
    exit;
}

if ($method === 'GET') {
    $db = withMetadata($metadataPath, false, fn (&$db) => $db);
    respond(200, ['documents' => array_map('publicDocument', array_reverse($db['documents']))]);
}

if ($method === 'POST' && $action === 'upload') {
    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) respond(400, ['error' => 'Nebola prijatá nahrávaná položka file.']);
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) respond(400, ['error' => 'PHP upload chyba: ' . (string)$f['error']]);
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) respond(400, ['error' => 'Súbor je prázdny.']);
    if ($size > ADMIN_HUB_MAX_FILE_BYTES) respond(413, ['error' => 'Súbor prekračuje limit 15 MiB.']);
    $originalName = str_replace('\\', '/', (string)($f['name'] ?? 'document.bin'));
    $originalName = basename($originalName);
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'document.bin';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ADMIN_HUB_ALLOWED_EXTENSIONS, true)) respond(415, ['error' => 'Nepovolený typ súboru.']);
    $id = bin2hex(random_bytes(16));
    $storedName = $id . '.bin';
    $target = $filesRoot . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($f['tmp_name'], $target)) respond(500, ['error' => 'Nepodarilo sa zapísať súbor na hosting. Skontroluj práva priečinka.']);
    @chmod($target, 0640);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $contentType = $finfo->file($target) ?: 'application/octet-stream';
    if (in_array($ext, ['html','htm','svg'], true)) $contentType = 'text/plain';
    $stamp = gmdate('c');
    $authorId = substr(preg_replace('/[^a-zA-Z0-9_@.\-]/', '', (string)($_POST['authorId'] ?? 'unknown')), 0, 100) ?: 'unknown';
    $authorName = substr(trim((string)($_POST['authorName'] ?? '')), 0, 120);
    $extractedText = trim((string)($_POST['extractedText'] ?? ''));
    if (strlen($extractedText) > 50000) $extractedText = substr($extractedText, 0, 50000);
    $newRecord = withMetadata($metadataPath, true, function (&$db) use ($originalName, $id, $storedName, $size, $ext, $contentType, $stamp, $authorId, $authorName, $extractedText) {
        $idx = null;
        foreach ($db['documents'] as $i => $item) if (strtolower((string)$item['name']) === strtolower($originalName)) { $idx = $i; break; }
        if ($idx !== null) {
            $old = $db['documents'][$idx];
            $versions = $old['versions'] ?? [];
            $nextVersion = 1;
            foreach ($versions as $v) $nextVersion = max($nextVersion, ((int)($v['version'] ?? 0)) + 1);
            $versions[] = [
                'version'=>$nextVersion, 'name'=>$old['name'], 'storedName'=>$old['storedName'],
                'sizeBytes'=>(int)$old['sizeBytes'], 'size'=>$old['size'], 'date'=>substr((string)$old['uploadedAt'],0,10),
                'createdAt'=>$old['uploadedAt'], 'uploadedAt'=>$old['uploadedAt'], 'authorId'=>$old['authorId'],
                'authorName'=>$old['authorName'] ?? '', 'contentType'=>$old['contentType']
            ];
            $versions = array_slice($versions, -ADMIN_HUB_MAX_VERSIONS);
            $old['storedName'] = $storedName;
            $old['sizeBytes'] = $size;
            $old['size'] = formatBytes($size);
            $old['type'] = extensionType($originalName);
            $old['contentType'] = $contentType;
            $old['updatedAt'] = $stamp;
            $old['uploadedAt'] = $stamp;
            $old['authorId'] = $authorId;
            $old['authorName'] = $authorName;
            $old['extractedText'] = $extractedText;
            $old['versions'] = $versions;
            $old['tags'] = $old['tags'] ?? [];
            $db['documents'][$idx] = $old;
            return $old;
        }
        $record = [
            'id'=>$id, 'name'=>$originalName, 'storedName'=>$storedName,
            'type'=>extensionType($originalName), 'sizeBytes'=>$size, 'size'=>formatBytes($size),
            'contentType'=>$contentType, 'authorId'=>$authorId, 'authorName'=>$authorName,
            'createdBy'=>$authorId, 'createdAt'=>$stamp, 'uploadedAt'=>$stamp, 'updatedAt'=>$stamp,
            'date'=>substr($stamp,0,10), 'tags'=>[], 'versions'=>[], 'extractedText'=>$extractedText
        ];
        $db['documents'][] = $record;
        return $record;
    });
    respond(201, ['document' => publicDocument($newRecord)]);
}

if ($method === 'POST' && $action === 'restore') {
    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) respond(400, ['error' => 'Neplatné JSON telo.']);
    $id = (string)($input['id'] ?? '');
    $verNo = (int)($input['version'] ?? 0);
    $authorId = substr(preg_replace('/[^a-zA-Z0-9_@.\-]/', '', (string)($input['authorId'] ?? 'unknown')),0,100) ?: 'unknown';
    $stamp = gmdate('c');
    $record = withMetadata($metadataPath, true, function (&$db) use ($id, $verNo, $authorId, $stamp) {
        foreach ($db['documents'] as &$doc) {
            if (($doc['id'] ?? '') !== $id) continue;
            $found = null;
            foreach (($doc['versions'] ?? []) as $i => $v) if ((int)($v['version'] ?? -1) === $verNo) { $found = $i; break; }
            if ($found === null) return null;
            $old = $doc;
            $nextVersion = 1;
            foreach (($doc['versions'] ?? []) as $v) $nextVersion = max($nextVersion, ((int)($v['version'] ?? 0)) + 1);
            $doc['versions'][] = [
                'version'=>$nextVersion, 'name'=>$old['name'], 'storedName'=>$old['storedName'],
                'sizeBytes'=>(int)$old['sizeBytes'], 'size'=>$old['size'], 'date'=>substr((string)$old['uploadedAt'],0,10),
                'createdAt'=>$old['uploadedAt'], 'uploadedAt'=>$old['uploadedAt'], 'authorId'=>$old['authorId'],
                'authorName'=>$old['authorName'] ?? '', 'contentType'=>$old['contentType']
            ];
            $v=$doc['versions'][$found];
            $doc['storedName']=$v['storedName']; $doc['sizeBytes']=$v['sizeBytes']; $doc['size']=$v['size'];
            $doc['contentType']=$v['contentType']; $doc['authorId']=$authorId; $doc['uploadedAt']=$stamp; $doc['updatedAt']=$stamp;
            $doc['extractedText']='';
            $doc['versions']=array_slice($doc['versions'],-ADMIN_HUB_MAX_VERSIONS);
            return $doc;
        }
        unset($doc);
        return null;
    });
    if (!$record) respond(404, ['error' => 'Dokument alebo jeho verzia sa nenašli.']);
    respond(200, ['document'=>publicDocument($record)]);
}

if ($method === 'DELETE' && isset($_GET['id'])) {
    $id = (string)$_GET['id'];
    $deleted = withMetadata($metadataPath, true, function (&$db) use ($id) {
        foreach ($db['documents'] as $i => $doc) if (($doc['id'] ?? '') === $id) { return array_splice($db['documents'], $i, 1)[0]; }
        return null;
    });
    if (!$deleted) respond(404, ['error' => 'Dokument sa nenašiel.']);
    $files = [$deleted['storedName'] ?? ''];
    foreach (($deleted['versions'] ?? []) as $v) $files[] = $v['storedName'] ?? '';
    foreach ($files as $file) { $path=$filesRoot . DIRECTORY_SEPARATOR . basename((string)$file); if ($file && is_file($path)) @unlink($path); }
    respond(200, ['ok'=>true]);
}

respond(405, ['error' => 'Nepodporovaná operácia.']);
