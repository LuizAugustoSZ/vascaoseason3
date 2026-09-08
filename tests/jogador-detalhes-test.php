<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__.'/../api/jogador-detalhes.php');
if ($source === false) throw new RuntimeException('API não encontrada.');
$functions = strstr($source, 'try {', true);
if ($functions === false) throw new RuntimeException('Funções da API não encontradas.');
eval(substr($functions, strpos($functions, 'function normalized_identity')));

function check_player_details(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$summary = [
    'home_name' => 'Gari Saint German',
    'away_name' => 'Locomotiva FC',
    'teams' => [['code' => 'GSG'], ['code' => 'LOC']],
];

check_player_details(summary_participant_code($summary, 'Gari Saint German') === 'GSG', 'Código do mandante');
check_player_details(summary_participant_code($summary, 'Locomotiva FC') === 'LOC', 'Código do visitante');
check_player_details(summary_participant_code($summary, 'Outro clube') === null, 'Clube desconhecido');
check_player_details(same_player('Jhon Arias', 'JHON ÁRIAS'), 'Normalização do jogador');

echo "Player details tests passed.\n";
