<?php
declare(strict_types=1);
require __DIR__.'/../includes/elenco-troca.php';
const MERCADO_POSICOES=['GOL','LD','LE','ZAG','VOL','MC','MEI','PD','PE','ATA'];
function mercado_estado_clube(PDO $pdo,int $champ,int $team): array { return ['locked'=>$champ===99]; }
function mercado_venda_bloqueada(array $state): bool { return $state['locked']; }
class TradePDO extends PDO {
 public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
function check_trade(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function fixture_trade(string $origin='passe',bool $locked=false): PDO {
 $p=new TradePDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$p->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
 $p->exec('CREATE TABLE jogadores_gerais(id INTEGER PRIMARY KEY,participante_id INT,nome TEXT,overall INT,posicao TEXT,ativo INT DEFAULT 1,entrou_em TEXT,saiu_em TEXT,UNIQUE(participante_id,nome,overall,posicao)); CREATE TABLE movimentacoes_elenco_geral(id INTEGER PRIMARY KEY,participante_id INT,jogador_geral_id INT,tipo TEXT,origem TEXT,origem_detalhe TEXT,jogador_nome TEXT,jogador_overall INT,jogador_posicao TEXT,valor REAL,saldo_anterior REAL,saldo_posterior REAL,conta_id INT,criado_em TEXT DEFAULT CURRENT_TIMESTAMP); CREATE TABLE jogadores_elenco(id INTEGER PRIMARY KEY,participante_id INT,jogador_geral_id INT,campeonato_id INT,ativo INT,saiu_em TEXT); CREATE TABLE movimentacoes_elenco(id INTEGER PRIMARY KEY,jogador_id INT,participante_id INT,tipo TEXT,origem TEXT,origem_detalhe TEXT,criado_em TEXT); CREATE TABLE campeonatos(id INTEGER PRIMARY KEY,nome TEXT,tipo TEXT); CREATE TABLE clubes_gerais(id INT,saldo REAL); INSERT INTO clubes_gerais VALUES(1,1234);');
 $p->exec("INSERT INTO jogadores_gerais VALUES(1,1,'THIBAUT COURTOIS',93,'GOL',1,NULL,NULL)");
 $p->prepare("INSERT INTO movimentacoes_elenco_geral(participante_id,jogador_geral_id,tipo,origem,jogador_nome,valor) VALUES(1,1,'compra',?,'THIBAUT COURTOIS',0)")->execute([$origin]);
 if($locked)$p->exec("INSERT INTO campeonatos VALUES(99,'Brasileirão','pontos_corridos');INSERT INTO jogadores_elenco VALUES(1,1,1,99,1,NULL)");
 return $p;
}
function perform_trade(PDO $p,?array $replacement=null,int $team=1): void {
 $p->beginTransaction();try{elenco_trocar_passe($p,$team,1,3,1234,$replacement);$p->commit();}catch(Throwable $error){$p->rollBack();throw $error;}
}
foreach(['passe','troca_passe'] as $origin){$p=fixture_trade($origin);perform_trade($p);check_trade((int)$p->query('SELECT ativo FROM jogadores_gerais WHERE id=1')->fetchColumn()===0,'Outgoing card removed');check_trade($p->query("SELECT tipo FROM movimentacoes_elenco_geral ORDER BY id DESC LIMIT 1")->fetchColumn()==='troca','Exit classified as exchange');check_trade((float)$p->query('SELECT saldo FROM clubes_gerais')->fetchColumn()===1234.0,'Balance unchanged');}
$p=fixture_trade();perform_trade($p,['nome'=>'MARIO GÖTZE','overall'=>93,'posicao'=>'MEI']);check_trade((int)$p->query('SELECT COUNT(*) FROM jogadores_gerais WHERE ativo=1')->fetchColumn()===1,'Exactly one incoming card');$m=$p->query('SELECT * FROM movimentacoes_elenco_geral ORDER BY id DESC LIMIT 1')->fetch();check_trade($m['origem']==='troca_passe'&&$m['tipo']==='compra'&&(float)$m['valor']===0.0&&(float)$m['saldo_anterior']===(float)$m['saldo_posterior'],'Incoming origin forced and free');
foreach([['pack',false,null,1],['compra_direta',false,null,1],['passe',true,null,1],['passe',false,['nome'=>'','overall'=>93,'posicao'=>'MEI'],1],['passe',false,['nome'=>'THIBAUT COURTOIS','overall'=>93,'posicao'=>'GOL'],1],['passe',false,null,2]] as [$origin,$locked,$replacement,$team]){
 $p=fixture_trade($origin,$locked);$blocked=false;try{perform_trade($p,$replacement,$team);}catch(RuntimeException $error){$blocked=true;}check_trade($blocked,'Invalid swap blocked');check_trade((int)$p->query('SELECT ativo FROM jogadores_gerais WHERE id=1')->fetchColumn()===1,'Rejected swap keeps outgoing card');check_trade((int)$p->query('SELECT COUNT(*) FROM movimentacoes_elenco_geral')->fetchColumn()===1,'Rejected swap adds no history');
}
$p=fixture_trade();$p->exec('DELETE FROM movimentacoes_elenco_geral');$p->exec("INSERT INTO jogadores_elenco VALUES(10,1,1,1,1,NULL);INSERT INTO movimentacoes_elenco VALUES(1,10,1,'compra','passe',NULL,CURRENT_TIMESTAMP)");perform_trade($p);check_trade((int)$p->query('SELECT ativo FROM jogadores_elenco WHERE id=10')->fetchColumn()===0,'Legacy purchase origin and registration removed');
$p=fixture_trade();$p->exec("INSERT INTO jogadores_gerais VALUES(2,1,'MARIO GÖTZE',93,'MEI',0,NULL,NULL)");perform_trade($p,['nome'=>'MARIO GÖTZE','overall'=>93,'posicao'=>'MEI']);check_trade((int)$p->query('SELECT ativo FROM jogadores_gerais WHERE id=2')->fetchColumn()===1,'Inactive incoming card reused');
$p=fixture_trade();
$p->exec("CREATE TRIGGER fail_incoming BEFORE INSERT ON movimentacoes_elenco_geral WHEN NEW.tipo='compra' AND NEW.origem='troca_passe' BEGIN SELECT RAISE(ABORT,'forced incoming failure'); END");
$failed=false;try{perform_trade($p,['nome'=>'RECEBIDO','overall'=>93,'posicao'=>'MEI']);}catch(Throwable $error){$failed=true;}
check_trade($failed&&(int)$p->query('SELECT ativo FROM jogadores_gerais WHERE id=1')->fetchColumn()===1,'Failure after exit restores outgoing card');
check_trade((int)$p->query('SELECT COUNT(*) FROM jogadores_gerais')->fetchColumn()===1&&(int)$p->query('SELECT COUNT(*) FROM movimentacoes_elenco_geral')->fetchColumn()===1,'Failure rolls back replacement and both movements');
echo "Passe exchange tests passed.\n";
