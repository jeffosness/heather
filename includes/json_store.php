<?php
declare(strict_types=1);

function read_json_file(string $path, $default = null)
{
    if (!is_file($path)) return $default;
    $handle = fopen($path, 'rb');
    if ($handle === false) return $default;
    try {
        if (!flock($handle, LOCK_SH)) return $default;
        $content = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        if ($content === false || $content === '') return $default;
        $data = json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    } finally {
        fclose($handle);
    }
}

function write_json_file(string $path, $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tempPath = $path . '.tmp';
    $handle = fopen($tempPath, 'wb');
    if ($handle === false) return false;
    try {
        if (!flock($handle, LOCK_EX)) return false;
        $bytes = fwrite($handle, $json . PHP_EOL);
        fflush($handle);
        flock($handle, LOCK_UN);
        if ($bytes === false) return false;
    } finally {
        fclose($handle);
    }
    return rename($tempPath, $path);
}

function gen_id(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(6));
}

/**
 * Load a JSON file, mutate the record matching $id via $mutator,
 * write back. Returns false if the record wasn't found or the save failed.
 */
function update_record_by_id(string $path, string $id, callable $mutator): bool
{
    $records = read_json_file($path, []);
    if (!is_array($records)) return false;
    foreach ($records as &$r) {
        if (($r['id'] ?? '') !== $id) continue;
        $mutator($r);
        unset($r);
        return write_json_file($path, array_values($records));
    }
    return false;
}
