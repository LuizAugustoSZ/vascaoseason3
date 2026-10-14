"""Real PHP/MySQL + Chromium regression. Synthetic localhost DB, never config/config.php."""
import base64, json, os, shutil, socket, subprocess, tempfile, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
DB='crud_test_'+os.urandom(6).hex()
TEMP=Path(tempfile.mkdtemp(prefix='vascao-crud-'))
def php(code):
 r=subprocess.run(['php'],input='<?php '+code,text=True,capture_output=True,encoding='utf-8')
 if r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return r.stdout
def sql(query):
 encoded=base64.b64encode(query.encode()).decode()
 return php(f"$p=new PDO('mysql:host=127.0.0.1;dbname={DB}','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=base64_decode('{encoded}');$p->exec($q);")
server=None;created=False
try:
 php(f"$p=new PDO('mysql:host=127.0.0.1','root','');$p->exec('CREATE DATABASE {DB} CHARACTER SET utf8mb4');")
 created=True
 sql((ROOT/'tests/draw-fixture.sql').read_text())
 sql('''ALTER TABLE participantes ADD descricao TEXT;
 ALTER TABLE jogos_mata_mata ADD data_partida DATETIME NULL,ADD criado_em DATETIME DEFAULT CURRENT_TIMESTAMP;
 ALTER TABLE titulos ADD participante_id INT NULL,ADD temporada VARCHAR(30),ADD conquistado_em DATE,ADD tecnico_nome VARCHAR(120),ADD time_nome VARCHAR(120);
 ALTER TABLE artilharia ADD jogador VARCHAR(120),ADD participante_id INT;
 CREATE TABLE gols_partida(id INT AUTO_INCREMENT PRIMARY KEY,partida_id INT,participante_id INT,jogador VARCHAR(120),minuto VARCHAR(30),tipo VARCHAR(30));
 CREATE TABLE gols_mata_mata(id INT AUTO_INCREMENT PRIMARY KEY,jogo_mata_mata_id INT,participante_id INT,jogador VARCHAR(120),minuto VARCHAR(30),tipo VARCHAR(30));
 CREATE TABLE contas(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,participante_id INT NULL,nome VARCHAR(120),email VARCHAR(190) UNIQUE,senha_hash VARCHAR(255),eh_admin INT DEFAULT 0,trocar_senha INT DEFAULT 0,ativo INT DEFAULT 1,ultimo_acesso_em DATETIME NULL);
 CREATE TABLE videos(id INT AUTO_INCREMENT PRIMARY KEY,titulo VARCHAR(190),youtube_url VARCHAR(255),ativo INT DEFAULT 1,criado_em DATETIME DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE noticias(id INT AUTO_INCREMENT PRIMARY KEY,titulo VARCHAR(190),resumo TEXT,capa_base64 LONGTEXT,conteudo LONGTEXT,autor VARCHAR(120),publicado_em DATETIME DEFAULT CURRENT_TIMESTAMP,ativo INT DEFAULT 1);
 ALTER TABLE noticias ADD atualizado_em DATETIME NULL;
 CREATE TABLE configuracoes_site(chave VARCHAR(100) PRIMARY KEY,valor TEXT);
 INSERT INTO participantes(nome,time_nome,sigla,escudo_url,descricao) VALUES('Tecnico A','Clube A','A','',''),('Tecnico B','Clube B','B','','');
 INSERT INTO contas(id,nome,email,senha_hash,eh_admin,participante_id) VALUES(1,'Admin Teste','admin@example.test','fixture',1,1);
 INSERT INTO campeonatos(nome,tipo,formato,status,data_inicio) VALUES('Liga teste','pontos_corridos','ida','ativo','2026-10-01'),('Copa teste','mata_mata','unico','ativo','2026-10-02');
 INSERT INTO partidas(campeonato_id,rodada,turno,mandante_id,visitante_id,data_partida,status) VALUES(1,1,1,1,2,'2026-10-01 20:00:00','agendada');
 INSERT INTO jogos_mata_mata(campeonato_id,fase,ordem,jogo,time_a_id,time_b_id,data_partida,status) VALUES(2,'Final',1,1,1,2,'2026-10-02 20:00:00','agendado');''')
 sql((ROOT/'sql/atualizacao-v8.8-sumulas-dreamteam.sql').read_text())
 for folder in ['admin','includes','assets','api','vendor','sql']: shutil.copytree(ROOT/folder,TEMP/folder)
 for path in ROOT.glob('*.php'):shutil.copy2(path,TEMP/path.name)
 (TEMP/'config').mkdir()
 (TEMP/'config/config.php').write_text("<?php return ['db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'"+DB+"','user'=>'root','pass'=>'','charset'=>'utf8mb4'],'app'=>['timezone'=>'America/Sao_Paulo','environment'=>'test','base_url'=>'']];")
 (TEMP/'test-login.php').write_text("<?php session_start();$_SESSION=['conta_id'=>1,'conta_eh_admin'=>1,'conta_nome'=>'Admin Teste','conta_email'=>'admin@example.test','participante_id'=>1,'csrf'=>'fixture','conta_trocar_senha'=>0];echo 'ok';")
 (TEMP/'test-state.php').write_text("<?php require 'includes/bootstrap.php';header('Content-Type: application/json');echo json_encode(['league'=>db()->query('SELECT data_partida FROM partidas WHERE id=1')->fetchColumn(),'knockout'=>db()->query('SELECT data_partida FROM jogos_mata_mata WHERE id=1')->fetchColumn(),'participants'=>db()->query('SELECT id,nome,time_nome,ativo FROM participantes')->fetchAll(),'videos'=>db()->query('SELECT * FROM videos')->fetchAll(),'news'=>db()->query('SELECT id,titulo,ativo FROM noticias')->fetchAll(),'titles'=>db()->query('SELECT * FROM titulos')->fetchAll(),'scorers'=>db()->query('SELECT * FROM artilharia')->fetchAll(),'accounts'=>db()->query('SELECT id,nome,email,participante_id,ativo FROM contas')->fetchAll(),'config'=>db()->query('SELECT * FROM configuracoes_site')->fetchAll()]);")
 (TEMP/'test-roster-state.php').write_text("<?php require 'includes/bootstrap.php';header('Content-Type: application/json');echo json_encode(['starters'=>db()->query(\"SELECT COUNT(*) FROM jogadores_elenco WHERE campeonato_id=1 AND participante_id=1 AND ativo=1 AND grupo='titular'\")->fetchColumn(),'players'=>db()->query('SELECT nome FROM jogadores_gerais WHERE participante_id=1 AND ativo=1')->fetchAll()]);")
 (TEMP/'test-reset.php').write_text("<?php require 'includes/bootstrap.php';require 'includes/password-reset.php';password_reset_ensure_schema(db());$id=db()->query(\"SELECT id FROM contas WHERE email='public@example.test'\")->fetchColumn();db()->prepare('INSERT INTO recuperacoes_senha(conta_id,token_hash,expira_em) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))')->execute([$id,hash('sha256','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')]);echo 'ok';")
 (TEMP/'test-write-failure.php').write_text("<?php require 'includes/bootstrap.php';if(isset($_GET['clear']))db()->exec('DROP TRIGGER IF EXISTS crud_reject_date');else db()->exec(\"CREATE TRIGGER crud_reject_date BEFORE UPDATE ON partidas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture persistence failure'\");echo 'ok';")
 (TEMP/'test-finished-dates.php').write_text("""<?php require 'includes/bootstrap.php';require 'includes/statistics.php';$p=db();if(isset($_GET['finish'])){$p->exec("UPDATE partidas SET status='finalizada',gols_mandante=2,gols_visitante=1 WHERE id=1");$p->exec("UPDATE jogos_mata_mata SET status='finalizado',gols_a=2,gols_b=1,penaltis_a=4,penaltis_b=3,vencedor_id=1 WHERE id=1");$p->exec("INSERT INTO gols_partida(partida_id,participante_id,jogador,minuto,tipo) VALUES(1,1,'Teste Data',10,'normal')");$p->exec("INSERT INTO gols_mata_mata(jogo_mata_mata_id,participante_id,jogador,minuto,tipo) VALUES(1,1,'Teste Data',10,'normal')");$summary=json_encode(['teams'=>[['code'=>'A'],['code'=>'B']],'home_name'=>$p->query('SELECT time_nome FROM participantes WHERE id=1')->fetchColumn(),'away_name'=>$p->query('SELECT time_nome FROM participantes WHERE id=2')->fetchColumn(),'events'=>[['type'=>'goal','team_code'=>'A','player'=>'Teste Data']]]);$p->prepare("INSERT INTO sumulas_dreamteam(dreamteam_id,origem,jogo_mata_mata_id,dados_json,texto_original) VALUES('test-date-history','mata',1,?,'Fixture de data')")->execute([$summary]);}header('Content-Type: application/json');echo json_encode(['league'=>$p->query('SELECT * FROM partidas WHERE id=1')->fetch(),'knockout'=>$p->query('SELECT * FROM jogos_mata_mata WHERE id=1')->fetch(),'summaries'=>$p->query('SELECT * FROM sumulas_dreamteam')->fetchAll(),'leagueGoals'=>$p->query('SELECT * FROM gols_partida')->fetchAll(),'knockoutGoals'=>$p->query('SELECT * FROM gols_mata_mata')->fetchAll(),'history'=>statistics_matches($p)]);""")
 (TEMP/'test-market.php').write_text("""<?php require 'includes/bootstrap.php';require 'includes/mercado.php';require 'includes/elenco-geral.php';$p=db();mercado_garantir_estrutura($p);elenco_geral_garantir_estrutura($p);mercado_clube($p,1,1);$p->exec("UPDATE clubes_campeonato SET saldo=1000,cofre_configurado=1,formacao='4-3-3' WHERE participante_id=1");elenco_geral_clube($p,1);$positions=['GOL','ZAG','ZAG','LD','LE','VOL','MC','MEI','ATA','PD','PE','ATA'];foreach($positions as $i=>$pos){$p->prepare('INSERT INTO jogadores_gerais(participante_id,nome,overall,posicao) VALUES(1,?,90,?)')->execute(['JOGADOR '.($i+1),$pos]);}echo 'ok';""")
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=(TEMP/'server.log').open('w',encoding='utf-8')
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(TEMP)],stdout=log,stderr=log)
 time.sleep(.5)
 runtime=os.environ['CODEX_NODE_MODULES']
 result=subprocess.run(['node',str(ROOT/'tests/crud-browser-test.js'),f'http://127.0.0.1:{port}',runtime],text=True,capture_output=True,encoding='utf-8')
 print(result.stdout);print(result.stderr)
 if result.returncode:print('\n'.join((TEMP/'server.log').read_text(encoding='utf-8').splitlines()[-12:]));raise RuntimeError('Browser regression failed')
 print('PASS: isolated real PHP/MySQL persistence and browser checks.')
finally:
 if server:server.terminate();server.wait();log.close()
 if created:php(f"$p=new PDO('mysql:host=127.0.0.1','root','');$p->exec('DROP DATABASE IF EXISTS {DB}');")
 assert TEMP.resolve().is_relative_to(Path(tempfile.gettempdir()).resolve()) and TEMP.name.startswith('vascao-crud-')
 shutil.rmtree(TEMP)
