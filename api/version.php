<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app-version.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode(['release' => app_release_id()], JSON_UNESCAPED_SLASHES);
