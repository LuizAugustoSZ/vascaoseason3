<?php
declare(strict_types=1);

function site_favicon(?string $clubIcon = null, string $root = ''): void
{
    $iconPath = __DIR__ . '/../assets/img/favicon-season3.png';
    $fallback = $root . 'assets/img/favicon-season3.png?v=' . filemtime($iconPath);
    $clubIcon = trim((string)$clubIcon);
    $validClubIcon = preg_match('~^(?:https?://|data:image/(?:png|webp|jpeg|gif);base64,|(?:\.?\.?/)?assets/)~i', $clubIcon) === 1;
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    echo '<link id="site-favicon" rel="icon" type="image/png" href="' . $escape($fallback) . '"';
    if ($validClubIcon) echo ' data-club-icon="' . $escape($clubIcon) . '"';
    echo '>';
    echo '<link rel="apple-touch-icon" href="' . $escape($root . 'assets/img/apple-touch-icon.png') . '">';
    if ($validClubIcon) echo '<script defer src="' . $escape($root . 'assets/js/favicon.js?v=' . filemtime(__DIR__ . '/../assets/js/favicon.js')) . '"></script>';
}
