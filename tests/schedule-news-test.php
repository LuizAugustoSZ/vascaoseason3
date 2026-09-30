<?php
declare(strict_types=1);
require __DIR__ . '/../includes/competition-schedule.php';
require __DIR__ . '/../includes/news-sharing.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
check(competition_schedule_datetime('2026-10-03T20:30') === '2026-10-03 20:30:00', 'Horário inválido');
foreach (['2026-02-30T20:00', '2026-10-03T25:00', 'invalid'] as $invalid) {
    try { competition_schedule_datetime($invalid); throw new LogicException('Aceitou data inválida'); }
    catch (RuntimeException $expected) {}
}
$config = ['app'=>['base_url'=>'https://example.com']];
$prompt = news_discord_prompt(['id'=>43,'titulo'=>'Campeão','resumo'=>'Vitória em 19/09','conteudo'=>'<p>Lords ganhou.</p><p>Dois gols.</p>']);
check(str_contains($prompt, 'https://example.com/noticia.php?id=43'), 'Não usou ID real');
check(str_contains($prompt, '@everyone') && str_contains($prompt, '1.900') && !str_contains($prompt, '<p>'), 'Modelo inválido');
echo "Datas e divulgação: OK\n";

// Integração opcional: banco efêmero exclusivo, nunca usa config/config.php.
$dsn = getenv('SCHEDULE_TEST_DSN');
if (!$dsn) exit;
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TEMPORARY TABLE campeonatos(id INT PRIMARY KEY,nome VARCHAR(80),tipo VARCHAR(30),status VARCHAR(30),ativo INT,data_inicio DATE)');
$pdo->exec('CREATE TEMPORARY TABLE partidas(id INT PRIMARY KEY,campeonato_id INT,rodada INT,status VARCHAR(30),ativo INT,data_partida DATETIME)');
$pdo->exec('CREATE TEMPORARY TABLE jogos_mata_mata(id INT PRIMARY KEY,campeonato_id INT,status VARCHAR(30),ativo INT,data_partida DATETIME)');
$pdo->exec("INSERT INTO campeonatos VALUES (1,'Liga','pontos_corridos','ativo',1,'2026-10-03'),(2,'Copa','mata_mata','ativo',1,'2026-10-09'),(3,'Encerrada','mata_mata','finalizado',1,'2026-09-19'),(4,'Nova','mata_mata','ativo',1,NULL)");
$pdo->exec("INSERT INTO partidas VALUES (1,1,1,'finalizada',1,'2026-10-03 21:30:00'),(2,1,2,'agendada',1,'2026-10-04 22:15:00'),(3,1,3,'agendada',1,NULL),(4,1,4,'agendada',0,'2026-10-06 10:00:00')");
$pdo->exec("INSERT INTO jogos_mata_mata VALUES (1,2,'agendado',1,'2026-10-09 18:00:00'),(2,2,'agendado',1,NULL),(3,4,'agendado',1,NULL)");
$pdo->beginTransaction();
competition_schedule_reschedule($pdo, 1, '2026-10-05');
$dates=$pdo->query('SELECT data_partida FROM partidas ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check($dates === ['2026-10-05 21:30:00','2026-10-06 22:15:00','2026-10-07 00:00:00','2026-10-06 10:00:00'], 'Deslocamento não preservou calendário');
$pdo->commit();
$pdo->beginTransaction();
try { competition_schedule_reschedule($pdo,1,'2026-10-09'); throw new LogicException('Aceitou conflito'); } catch (RuntimeException $expected) {}
$pdo->rollBack();
check($pdo->query('SELECT data_inicio FROM campeonatos WHERE id=1')->fetchColumn()==='2026-10-05','Conflito alterou dados');
$pdo->beginTransaction(); competition_schedule_reschedule($pdo,2,'2026-10-12'); $pdo->commit();
check($pdo->query('SELECT data_partida FROM jogos_mata_mata WHERE id=1')->fetchColumn()==='2026-10-12 18:00:00','Mata-mata perdeu horário');
$pdo->beginTransaction(); competition_schedule_reschedule($pdo,4,'2026-10-15'); $pdo->commit();
check($pdo->query('SELECT data_partida FROM jogos_mata_mata WHERE id=3')->fetchColumn()==='2026-10-15 00:00:00','Calendário sem data não preenchido');
$order=$pdo->query('SELECT c.id FROM campeonatos c ORDER BY '.competition_schedule_order_sql())->fetchAll(PDO::FETCH_COLUMN);
check(array_map('intval',$order)===[1,2,4,3], 'Prioridade incorreta');
echo "MySQL: adiamento, horários, conflito, jogos sem data e ordenação OK\n";
