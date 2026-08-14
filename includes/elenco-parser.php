<?php

declare(strict_types=1);

function parse_elenco_dreamteam(string $texto): array
{
    $linhas = preg_split('/\R/u', trim($texto)) ?: [];
    $jogadores = [];
    $nome = null;
    $mapa = [
        'goleiro' => 'GOL',
        'lateral direito' => 'LD',
        'lateral esquerdo' => 'LE',
        'zagueiro' => 'ZAG',
        'volante' => 'VOL',
        'meia central' => 'MC',
        'meia ofensivo' => 'MEI',
        'ponta direita' => 'PD',
        'ponta esquerda' => 'PE',
        'centro avante' => 'ATA',
        'centroavante' => 'ATA',
        'atacante' => 'ATA',
    ];
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if (preg_match('/\*\*(.+?)\*\*/u', $linha, $match)) {
            $nome = trim($match[1]);
            continue;
        }
        if ($nome !== null && preg_match('/^(\d{1,2})\s*\|\s*(.+)$/u', $linha, $match)) {
            $overall = (int)$match[1];
            $descricao = mb_strtolower(trim($match[2]), 'UTF-8');
            $posicao = $mapa[$descricao] ?? null;
            if ($overall < 1 || $overall > 99 || $posicao === null) {
                throw new RuntimeException("Não foi possível interpretar {$nome}: {$linha}");
            }
            $jogadores[] = ['nome' => $nome, 'overall' => $overall, 'posicao' => $posicao, 'descricao' => trim($match[2])];
            $nome = null;
            continue;
        }
        if ($linha !== '' && !preg_match('/^\d{1,2}\s*\|/u', $linha)) {
            $candidato = preg_replace('/\s*:.*/u', '', $linha) ?? $linha;
            $candidato = trim(str_replace(['**', '⭐'], '', $candidato));
            if ($candidato !== '') $nome = $candidato;
        }
    }
    if (!$jogadores) throw new RuntimeException('Nenhum jogador foi identificado no texto colado.');
    return $jogadores;
}
