<?php
declare(strict_types=1);
// Existing multipart URL; the current PHOTOS uploader owns storage and direct palette association.
require_once __DIR__ . '/../auth.php';
$_POST['source_type'] = 'saved_palette_photo';
// Preserve this URL's existing Full-photo Gallery default; an explicit opt-out is honored.
$_POST['show_in_gallery'] = $_POST['show_in_gallery'] ?? '1';
require __DIR__ . '/../photo-library/upload.php';
