<?php

declare(strict_types=1);

function competition_identity_key(string $name): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($name), 'UTF-8'));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii !== false ? $ascii : $name) ?? '');
}

function competition_identity_defaults(): array
{
    return [
        'brasileirao' => ['Brasileirão', 'brasileirao-logo.webp', 'brasileirao-trofeu.webp'],
        'amistosos dreamteam' => ['Amistosos Dream Team', 'amistosos-dreamteam-logo.webp', 'amistosos-dreamteam-trofeu.webp'],
        'copa do brasil' => ['Copa do Brasil', 'copa-do-brasil-logo.webp', 'copa-do-brasil-trofeu.webp'],
        'supercopa r' => ['Supercopa R', 'supercopa-r-logo.webp', 'supercopa-r-trofeu.webp'],
        'mundial' => ['Mundial de Clubes', 'mundial-logo.webp', 'mundial-trofeu.webp'],
        'evento carnavalesco' => ['Evento Carnavalesco', 'evento-carnavalesco-logo.webp', 'evento-carnavalesco-trofeu.webp'],
        'evento pascualino' => ['Evento Pascualino', 'evento-pascualino-logo.webp', 'evento-pascualino-trofeu.webp'],
        'champions league' => ['Champions League', 'champions-league-logo.webp', 'champions-league-trofeu.webp'],
        'libertadores g4' => ['Libertadores do G4', '', ''],
    ];
}

/** Instala a migration de forma idempotente no primeiro acesso do ambiente. */
function competition_identities_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS competicao_identidades (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        chave VARCHAR(120) NOT NULL,
        nome VARCHAR(150) NOT NULL,
        logo_base64 MEDIUMTEXT NULL,
        trofeu_base64 MEDIUMTEXT NULL,
        atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_competicao_identidade_chave (chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $column = $pdo->query("SHOW COLUMNS FROM campeonatos LIKE 'identidade_id'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE campeonatos
            ADD COLUMN identidade_id INT UNSIGNED NULL AFTER nome,
            ADD KEY idx_campeonatos_identidade (identidade_id),
            ADD CONSTRAINT fk_campeonatos_identidade FOREIGN KEY (identidade_id) REFERENCES competicao_identidades(id)");
    }
    $titleImageColumn = $pdo->query("SHOW COLUMNS FROM titulos LIKE 'imagem_base64'")->fetch();
    if (!$titleImageColumn) $pdo->exec("ALTER TABLE titulos ADD COLUMN imagem_base64 MEDIUMTEXT NULL AFTER descricao");
    $titleCompetitionColumn = $pdo->query("SHOW COLUMNS FROM titulos LIKE 'campeonato_id'")->fetch();
    if (!$titleCompetitionColumn) $pdo->exec("ALTER TABLE titulos ADD COLUMN campeonato_id INT UNSIGNED NULL AFTER participante_id, ADD UNIQUE KEY uk_titulo_campeonato (campeonato_id)");
}

function competition_identity_data_url(string $filename): string
{
    $path = __DIR__ . '/../assets/img/competitions/' . $filename;
    return is_file($path) ? 'data:image/webp;base64,' . base64_encode((string) file_get_contents($path)) : '';
}

function competition_identity_match(string $name): ?string
{
    $key = competition_identity_key($name);
    $compact = str_replace(' ', '', $key);
    if (str_contains($compact, 'brasileir')) return 'brasileirao';
    if (str_contains($compact, 'amistoso') && str_contains($compact, 'dream')) return 'amistosos dreamteam';
    if (str_contains($compact, 'copadobrasil')) return 'copa do brasil';
    if (str_contains($compact, 'supercopa')) return 'supercopa r';
    if (str_starts_with($compact, 'mundial')) return 'mundial';
    if (str_starts_with($compact, 'eventocarnavalesco')) return 'evento carnavalesco';
    if (str_starts_with($compact, 'eventopascualino')) return 'evento pascualino';
    if (str_starts_with($compact, 'championsleague') || str_starts_with($compact, 'champiosleague')) return 'champions league';
    if (str_contains($compact, 'libertadores') && str_contains($compact, 'g4')) return 'libertadores g4';
    return null;
}

function competition_roman(int $number): string
{
    $map = [1000=>'M',900=>'CM',500=>'D',400=>'CD',100=>'C',90=>'XC',50=>'L',40=>'XL',10=>'X',9=>'IX',5=>'V',4=>'IV',1=>'I'];
    $result = '';
    foreach ($map as $value => $roman) while ($number >= $value) { $result .= $roman; $number -= $value; }
    return $result;
}

function competition_sync_champion_title(PDO $pdo, int $championshipId): ?int
{
    competition_identities_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT id,nome,status,tipo FROM campeonatos WHERE id=? AND ativo=1 LIMIT 1');
    $stmt->execute([$championshipId]); $competition = $stmt->fetch();
    if (!$competition) return null;
    $winnerId = competition_champion_id($pdo, $championshipId);
    if (!$winnerId) return null;
    $description = $competition['status'] === 'finalizado'
        ? 'Título entregue automaticamente ao encerrar a competição'
        : 'Título entregue automaticamente após confirmação matemática antecipada';
    $insert = $pdo->prepare("INSERT INTO titulos(campeonato_id,participante_id,titulo,temporada,descricao,conquistado_em) VALUES(?,?,?,'Season 3',?,CURDATE()) ON DUPLICATE KEY UPDATE participante_id=VALUES(participante_id),titulo=VALUES(titulo),descricao=VALUES(descricao)");
    $insert->execute([$championshipId,$winnerId,$competition['nome'],$description]);
    return $winnerId;
}

/** Preenche as quatro identidades e associa edições antigas sem sobrescrever artes editadas. */
function competition_identities_seed(PDO $pdo): void
{
    competition_identities_ensure_schema($pdo);
    $select = $pdo->prepare('SELECT id,logo_base64,trofeu_base64 FROM competicao_identidades WHERE chave=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO competicao_identidades(chave,nome,logo_base64,trofeu_base64) VALUES(?,?,?,?)');
    $updateEmpty = $pdo->prepare("UPDATE competicao_identidades SET logo_base64=COALESCE(NULLIF(logo_base64,''),?),trofeu_base64=COALESCE(NULLIF(trofeu_base64,''),?) WHERE id=?");
    $ids = [];
    foreach (competition_identity_defaults() as $key => [$name, $logo, $trophy]) {
        $select->execute([$key]);
        $row = $select->fetch();
        if (!$row) {
            $logoData = competition_identity_data_url($logo);
            $trophyData = competition_identity_data_url($trophy);
            $insert->execute([$key, $name, $logoData, $trophyData]);
            $ids[$key] = (int) $pdo->lastInsertId();
        } else {
            $ids[$key] = (int) $row['id'];
            if (empty($row['logo_base64']) || empty($row['trofeu_base64'])) {
                $logoData = competition_identity_data_url($logo);
                $trophyData = competition_identity_data_url($trophy);
                $updateEmpty->execute([$logoData, $trophyData, $row['id']]);
            }
        }
    }
    $championships = $pdo->query('SELECT id,nome,identidade_id FROM campeonatos WHERE ativo=1')->fetchAll();
    $associate = $pdo->prepare('UPDATE campeonatos SET identidade_id=? WHERE id=? AND identidade_id IS NULL');
    foreach ($championships as $championship) {
        $key = competition_identity_match((string) $championship['nome']);
        if ($key && isset($ids[$key])) $associate->execute([$ids[$key], $championship['id']]);
    }
}

function competition_image_url(int $championshipId, string $type = 'logo'): string
{
    return 'api/competicao-imagem.php?campeonato_id=' . $championshipId . '&tipo=' . ($type === 'trofeu' ? 'trofeu' : 'logo');
}

function competition_uploaded_data_url(string $field): ?string
{
    if (empty($_FILES[$field]['tmp_name']) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ((int)$_FILES[$field]['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a imagem da competição.');
    if ((int)$_FILES[$field]['size'] > 4 * 1024 * 1024) throw new RuntimeException('Cada imagem pode ter no máximo 4 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$_FILES[$field]['tmp_name']);
    if (!in_array($mime, ['image/png', 'image/webp', 'image/jpeg'], true)) throw new RuntimeException('Envie logo e taça em PNG, WebP ou JPEG.');
    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents((string)$_FILES[$field]['tmp_name']));
}

function competition_posted_data_url(string $field): ?string
{
    $dataUrl = trim((string)($_POST[$field] ?? ''));
    if ($dataUrl === '') return null;
    if (!preg_match('#^data:image/(?:png|webp|jpeg);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match)) {
        throw new RuntimeException('A imagem editada da competição é inválida.');
    }
    $binary = base64_decode($match[1], true);
    if ($binary === false || strlen($binary) > 4 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
        throw new RuntimeException('A imagem editada ficou inválida ou maior que 4 MB.');
    }
    return $dataUrl;
}
