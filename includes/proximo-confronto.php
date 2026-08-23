<?php

declare(strict_types=1);

/**
 * Remove partidas de pontos corridos que ficaram agendadas em rodadas já
 * ultrapassadas e ordena as restantes pela menor rodada futura disponível.
 */
function ordenar_proximos_confrontos(array $jogos, array $finalizados): array
{
    $ultimaRodadaFinalizada = [];
    foreach ($finalizados as $jogo) {
        if (($jogo['origem'] ?? '') !== 'pontos') continue;
        $campeonatoId = (int)($jogo['campeonato_id'] ?? 0);
        $rodada = (int)($jogo['etapa'] ?? 0);
        $ultimaRodadaFinalizada[$campeonatoId] = max($ultimaRodadaFinalizada[$campeonatoId] ?? 0, $rodada);
    }

    $jogos = array_values(array_filter($jogos, static function (array $jogo) use ($ultimaRodadaFinalizada): bool {
        if (($jogo['origem'] ?? '') !== 'pontos') return true;
        $campeonatoId = (int)($jogo['campeonato_id'] ?? 0);
        return (int)($jogo['etapa'] ?? 0) > ($ultimaRodadaFinalizada[$campeonatoId] ?? 0);
    }));

    usort($jogos, static function (array $a, array $b): int {
        if (($a['origem'] ?? '') === 'pontos' && ($b['origem'] ?? '') === 'pontos') {
            $rodada = (int)($a['etapa'] ?? 0) <=> (int)($b['etapa'] ?? 0);
            if ($rodada !== 0) return $rodada;
        }

        $dataA = strtotime((string)($a['data_jogo'] ?? '')) ?: null;
        $dataB = strtotime((string)($b['data_jogo'] ?? '')) ?: null;
        if ($dataA !== null || $dataB !== null) {
            if ($dataA === null) return 1;
            if ($dataB === null) return -1;
            if ($dataA !== $dataB) return $dataA <=> $dataB;
        }

        if (($a['origem'] ?? '') !== ($b['origem'] ?? '')) {
            return strcmp((string)($a['origem'] ?? ''), (string)($b['origem'] ?? ''));
        }
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    return $jogos;
}
