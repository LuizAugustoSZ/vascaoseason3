<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$requiredMarkers = [
    'time.php' => ['profileNotice', 'data-flash-toast'],
    'mercado.php' => ['$message', '$error', 'data-flash-toast'],
    'elenco-geral.php' => ['$message', '$error', 'data-flash-toast'],
    'importar-elenco.php' => ['$erro', 'data-flash-toast'],
    'notificacoes.php' => ['$notice', 'data-flash-toast'],
    'admin/index.php' => ['$notice', 'data-flash-toast'],
    'admin/sorteador.php' => ['$notice', 'data-flash-toast'],
    'admin/campeonatos.php' => ['$notice', 'data-flash-toast'],
    'login.php' => ['$error', 'data-flash-toast'],
    'cadastro.php' => ['$error', 'data-flash-toast'],
    'trocar-senha.php' => ['$error', 'data-flash-toast'],
];

foreach ($requiredMarkers as $file => $markers) {
    $content = file_get_contents($root . '/' . $file);
    if ($content === false) throw new RuntimeException("Não foi possível ler {$file}.");
    foreach ($markers as $marker) {
        if (!str_contains($content, $marker)) throw new RuntimeException("{$file} não contém {$marker}.");
    }
}

$script = file_get_contents($root . '/assets/js/feedback-toast.js');
if ($script === false || !str_contains($script, 'window.siteToast') || !str_contains($script, 'duration=5000')) {
    throw new RuntimeException('Componente global de toast inválido.');
}

echo "OK: feedback transitório padronizado nas telas públicas, autenticação e Admin.\n";
