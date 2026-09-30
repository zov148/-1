<?php
/** Responsive assets retain JPEG compatibility with existing database paths. */
function product_image(string $path, string $alt = '', bool $priority = false, string $sizes = '100vw'): string {
  $local = preg_match('~^assets/img/(?:products/)?[a-z0-9-]+\.jpg$~D', $path);
  $base = $local ? substr($path, 0, -4) : '';
  $responsive = $local && is_file(__DIR__ . '/' . $base . '-480.webp');
  $loading = $priority ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"';
  $presentation = $responsive ? 'photo-studio' : 'photo-framed';
  $out = '<picture class="product-photo ' . $presentation . '">';
  if ($responsive) {
    $out .= '<source type="image/webp" srcset="' . h($base) . '-480.webp 480w, ' . h($base) . '-800.webp 800w, ' . h($base) . '.webp 1200w" sizes="' . h($sizes) . '">';
  }
  $out .= '<img src="' . h($path) . '" alt="' . h($alt) . '" width="1200" height="1200" ' . $loading . ' decoding="async" onerror="this.onerror=null;this.parentNode.querySelectorAll(\'source\').forEach(function(s){s.remove()});this.src=\'assets/img/fallback.svg\'">';
  return $out . '</picture>';
}
