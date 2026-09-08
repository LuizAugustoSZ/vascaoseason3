ALTER TABLE campeonatos
    ADD COLUMN data_inicio DATE NULL AFTER formato,
    ADD KEY idx_campeonatos_data_inicio (data_inicio);

UPDATE campeonatos c
LEFT JOIN competicao_identidades i ON i.id=c.identidade_id
SET c.data_inicio=CASE
    WHEN i.chave='brasileirao' OR LOWER(c.nome) LIKE '%brasileir%' THEN '2026-09-08'
    WHEN i.chave='champions league' OR LOWER(c.nome) LIKE '%champions%' THEN '2026-09-13'
    WHEN i.chave='libertadores g4' OR LOWER(c.nome) LIKE '%libertadores%' THEN '2026-09-19'
END
WHERE c.ativo=1 AND c.status<>'finalizado' AND c.data_inicio IS NULL
  AND (i.chave IN ('brasileirao','champions league','libertadores g4')
       OR LOWER(c.nome) LIKE '%brasileir%'
       OR LOWER(c.nome) LIKE '%champions%'
       OR LOWER(c.nome) LIKE '%libertadores%');

UPDATE partidas p
JOIN campeonatos c ON c.id=p.campeonato_id
SET p.data_partida=DATE_ADD(c.data_inicio, INTERVAL (p.rodada - 1) DAY)
WHERE p.ativo=1 AND p.data_partida IS NULL
  AND c.tipo='pontos_corridos' AND c.data_inicio IS NOT NULL;
