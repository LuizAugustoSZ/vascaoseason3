ALTER TABLE partidas
    MODIFY status ENUM('agendada','finalizada','wo','penalidade') NOT NULL DEFAULT 'agendada';
