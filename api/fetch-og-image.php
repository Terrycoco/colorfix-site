<?php
$url = $_GET['url'] ?? '';
if (!$url) {
  echo json_encode(['error' => 'No URL provided']);
  exit;
}

$html = @file_get_contents($url);
if ($html === false) {
  echo json_encode(['error' => 'Failed to fetch HTML']);
  exit;
}

// Match og:image content (property or name)
if (preg_match('/<meta[^>]+(?:property|name)=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
  $ogImage = $matches[1];

  // If og:image is relative, resolve it
  if (strpos($ogImage, '//') === 0) {
    // Starts with // — add scheme
    $parsed = parse_url($url);
    $ogImage = $parsed['scheme'] . ':' . $ogImage;
  } elseif (strpos($ogImage, '/') === 0) {
    // Starts with / — add full domain
    $parsed = parse_url($url);
    $ogImage = $parsed['scheme'] . '://' . $parsed['host'] . $ogImage;
  }

  echo json_encode(['image' => $ogImage]);
} else {
  echo json_encode(['error' => 'og:image not found']);
}
?>
