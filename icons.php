<?php
// Local SVG icons: one grid, one stroke weight, no icon font.
function svg_icon(string $name): string {
  $icons = [
    'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
    'cart' => '<path d="M3 3h2l2.4 12h11.2L21 7H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    'home' => '<path d="m3 10 9-7 9 7M5 9v12h5v-7h4v7h5V9"/>',
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'service' => '<path d="M14.7 6.3a5 5 0 0 0-6.2 6.2L3.8 17.2a2.1 2.1 0 0 0 3 3l4.7-4.7a5 5 0 0 0 6.2-6.2L15 12l-3-3 2.7-2.7Z"/>',
    'chevron-right' => '<path d="m9 5 7 7-7 7"/>',
    'check' => '<path d="m5 12 4 4L19 6"/>',
    'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'arrow-up' => '<path d="M12 20V4m-7 7 7-7 7 7"/>',
    'arrow-right' => '<path d="M4 12h16m-7-7 7 7-7 7"/>',
    'arrow-left' => '<path d="M20 12H4m7-7-7 7 7 7"/>',
    'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
    'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
    'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
    'delivery' => '<path d="M3 5h11v12H3V5Zm11 5h4l3 4v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    'briefcase' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V4h8v3M3 12a22 22 0 0 0 18 0M12 12v4"/>',
    'brand' => '<path d="M5 19V5l14 14V5"/>',
    'printer' => '<path d="M7 8V3h10v5M7 17H4V8h16v9h-3M7 14h10v7H7zM16 11h1"/>',
    'mfu' => '<path d="M5 8V3h14v5M4 8h16v13H4zM7 12h10M7 16h10M7 19h10M8 5h8"/>',
    'scanner' => '<path d="m5 12 2-9 13 4-2 5M3 13h18v7H3zM7 16h1M11 16h6"/>',
    'projector' => '<rect x="2" y="6" width="20" height="12" rx="3"/><circle cx="16" cy="12" r="3"/><path d="M5 10h3M5 13h3M6 18v3M18 18v3"/>',
    'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M12 17v4M7 21h10"/>',
  ];
  if (!isset($icons[$name])) return "";
  return '<svg class="ui-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
}
