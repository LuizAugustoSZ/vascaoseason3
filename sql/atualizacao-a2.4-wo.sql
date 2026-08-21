-- W.O. encerra partidas de mata-mata com placar administrativo de 3 a 0.
ALTER TABLE jogos_mata_mata
    MODIFY status ENUM('agendado','finalizado','wo') NOT NULL DEFAULT 'agendado';
