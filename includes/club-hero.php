<?php
declare(strict_types=1);

// IDs das duas tabelas pertencem a espaços diferentes.
function club_hero_candidates(array $generalRoster, array $competitionRoster): array
{
    $result = [];
    foreach ($generalRoster as $player) {
        $player['hero_value'] = 'geral:' . (int)$player['id'];
        $result[] = $player;
    }
    foreach ($competitionRoster as $player) {
        // Cartas vinculadas ao geral usam a propriedade e disponibilidade atuais.
        if (!empty($player['jogador_geral_id'])) continue;
        $player['hero_value'] = (string)(int)$player['id'];
        $result[] = $player;
    }
    return $result;
}
