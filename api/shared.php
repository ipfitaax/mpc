<?php
/**
 * Shared helpers for the MPC form endpoints.
 *
 * Both endpoints write student data to disk, so where that goes and how input
 * is cleaned lives in ONE place. Two copies of a storage path is how one
 * endpoint quietly starts writing somewhere nobody reads.
 */

/**
 * Where enquiries and subscribers are recorded.
 *
 * Prefers a directory ABOVE the web root, because these files hold students'
 * names, phone numbers and email addresses — a listing of them should never be
 * reachable over HTTP. Falls back to ./storage, which ships with an .htaccess
 * that denies web access, for hosts that will not allow anything outside the
 * document root.
 */
function mpc_storage_path()
{
    $outside = dirname(__DIR__, 2) . '/mpc-storage';
    if (is_dir($outside) && is_writable($outside)) {
        return $outside;
    }

    return dirname(__DIR__) . '/storage';
}

/**
 * Trims a submitted value and strips control characters.
 *
 * Control characters have no legitimate use in a form field, and stripping them
 * is what stops a newline in a name from becoming a second mail header.
 */
function mpc_clean($value, $max = 500)
{
    $value = is_string($value) ? $value : '';
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

    return trim(mb_substr($value, 0, $max));
}

/** Appends one JSON line to a file in the storage directory. Returns success. */
function mpc_append($filename, array $row)
{
    $dir = mpc_storage_path();
    if (! is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    return @file_put_contents($dir . '/' . $filename, $line, FILE_APPEND | LOCK_EX) !== false;
}
