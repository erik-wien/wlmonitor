<?php
/**
 * web/avatar.php
 *
 * Serves a user's profile picture from the img_blob column in wl_accounts.
 *
 * Usage: avatar.php?id=<userId>
 *
 * Returns HTTP 200 + the image binary if a blob is stored, or HTTP 404 if
 * the user has no blob avatar. The caller is responsible for rendering a
 * fallback (e.g. a Font Awesome icon via onerror).
 *
 * Security: id is cast to int; no authentication required (avatars are
 * considered public within the application, just like gravatar URLs).
 * Cache-Control is set to 1 hour so browsers don't hit the DB on every page load.
 */

require_once(__DIR__ . '/../inc/initialize.php');

$uid = (int) ($_GET['id'] ?? 0);
if ($uid <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $con->prepare(
    'SELECT img_blob, img_type FROM jardyx_auth.auth_accounts WHERE id = ? AND img_blob IS NOT NULL'
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['img_blob'])) {
    http_response_code(404);
    exit;
}

$mime = $row['img_type'] ?: 'image/jpeg';

header('Content-Type: '  . $mime);
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . strlen($row['img_blob']));
echo $row['img_blob'];
