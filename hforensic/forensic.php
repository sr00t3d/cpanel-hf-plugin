<?php
// Compatibility entrypoint: keep direct URL working and redirect to .live.php for cPanel chrome integration.
$query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
$target = 'forensic.live.php' . ($query !== '' ? '?' . $query : '');
if (!headers_sent()) {
    header('Location: ' . $target, true, 302);
}
exit;
?>
