<?php
require __DIR__ . "/../includes/bootstrap.php";
admin_required();
header(
    "Location: index.php?tab=noticias",
    true,
    $_SERVER["REQUEST_METHOD"] === "POST" ? 307 : 302,
);
exit();
