<?php
function checkWhites(array $color): array {
    $categories = [];

    $l = $color['hcl_l'] ?? null;   // perceptual lightness
    $c = $color['hcl_c'] ?? null;   // perceptual chroma

    if ($l === null || $c === null) return $categories;

    // Whites = very light, low chroma
 
      if ($l >= 92 && $c <= 8) {
    $categories[] = 'Whites';
}

 

    return $categories;
}
?>
