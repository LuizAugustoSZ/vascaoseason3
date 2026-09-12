"""Integration test: disposable localhost MySQL schema; never loads the workspace DB config.
Run: python tests/draw-workflow-test.py (PHP and local MySQL root without password required).
"""
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
DB = 'draw_test_' + os.urandom(6).hex()
TEMP = Path(tempfile.mkdtemp(prefix='draw-test-'))

def php(code):
    result = subprocess.run(['php'], input='<?php ' + code, text=True, capture_output=True, encoding='utf-8', check=True)
    return result.stdout

def sql(query, read=False):
    encoded = base64.b64encode(query.encode()).decode()
    return php(f"$p=new PDO('mysql:host=127.0.0.1;dbname={DB}', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $q=base64_decode('{encoded}'); " + ("echo json_encode($p->query($q)->fetchAll(PDO::FETCH_ASSOC));" if read else "$p->exec($q);"))

server = None
try:
    php(f"$p=new PDO('mysql:host=127.0.0.1','root',''); $p->exec('CREATE DATABASE {DB} CHARACTER SET utf8mb4');")
    sql((ROOT/'tests/draw-fixture.sql').read_text())
    for folder in ['admin', 'includes', 'assets']:
        shutil.copytree(ROOT/folder, TEMP/folder)
    (TEMP/'config').mkdir()
    (TEMP/'config/config.php').write_text("<?php return ['db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'"+DB+"','user'=>'root','pass'=>'','charset'=>'utf8mb4'],'app'=>['timezone'=>'America/Sao_Paulo']];")
    (TEMP/'test-login.php').write_text("<?php session_start(); $_SESSION['conta_id']=1; $_SESSION['conta_eh_admin']=1; $_SESSION['csrf']='fixture';")
    (TEMP/'test-delete.php').write_text("<?php require 'includes/bootstrap.php'; require 'includes/competition-delete.php'; header('Content-Type: application/json'); try {competition_delete_edition(db(),(int)$_POST['id']); echo json_encode(['ok'=>true]);} catch(Throwable $e){echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}")
    for index in range(1,17):
        sql(f"INSERT INTO participantes(nome,time_nome,sigla,escudo_url) VALUES('Técnico {index}','Clube {index}','C{index}','')")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port = sock.getsockname()[1]
    log = (TEMP/'server.log').open('w')
    server = subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(TEMP)], stdout=log, stderr=log)
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path, data=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            return client.open(f'http://127.0.0.1:{port}/{path}',body).read().decode()
        except urllib.error.HTTPError as error:
            return error.read().decode()
    for attempt in range(30):
        try: request('test-login.php'); break
        except urllib.error.URLError: time.sleep(.1)
    def draw(**values):
        return json.loads(request('admin/sorteador.php', {'csrf':'fixture','_ajax':1, **values}))
    def games(cid):
        return json.loads(sql(f'SELECT * FROM jogos_mata_mata WHERE campeonato_id={cid} ORDER BY ordem,jogo',True))
    fields = ['fase','ordem','jogo','time_a_id','time_b_id','origem_a_fase','origem_a_ordem','origem_b_fase','origem_b_ordem','origem_a_tipo','origem_b_tipo']
    canonical = lambda rows: [{key: row[key] for key in fields} for row in rows]
    for count in [4,8,10,16]:
        preview = draw(action='mata', nome_campeonato=f'Teste {count}', data_inicio='2026-09-12', formato='ida_volta', formato_final='unico', formato_terceiro='ida_volta', **{'participantes[]':list(range(1,count+1))})
        assert preview.get('preview'), preview
        if count == 10 and os.environ.get('DRAW_PREVIEW_ARTIFACT'):
            Path(os.environ['DRAW_PREVIEW_ARTIFACT']).write_text(json.dumps(preview), encoding='utf-8')
        assert json.loads(sql('SELECT COUNT(*) n FROM campeonatos',True))[0]['n'] == 0
        confirmed = draw(action='confirm_preview',draw_token=preview['token'],nome_campeonato='ALTERADO')
        assert confirmed['ok'], confirmed
        cid = json.loads(sql('SELECT id FROM campeonatos WHERE ativo=1',True))[0]['id']
        assert canonical(games(cid)) == canonical(preview['games'])
        assert not draw(action='confirm_preview',draw_token=preview['token'])['ok']
        assert json.loads(request('test-delete.php',{'id':cid}))['ok']
        assert all(row['ativo']==0 for row in games(cid))
        sql('DELETE FROM jogos_mata_mata; DELETE FROM campeonatos')
    preview = draw(action='mata',nome_campeonato='Cancelado',data_inicio='2026-09-12', **{'participantes[]':[1,2,3,4]})
    assert draw(action='cancel_preview',draw_token=preview['token'])['ok']
    assert not draw(action='confirm_preview',draw_token=preview['token'])['ok']
    assert json.loads(sql('SELECT COUNT(*) n FROM campeonatos',True))[0]['n']==0
    assert not draw(action='mata',nome_campeonato='Inválido',data_inicio='2026-09-12', **{'participantes[]':[1,2,3]})['ok']
    sql("INSERT INTO campeonatos(nome,tipo) VALUES('Brasileirão teste','pontos_corridos'); INSERT INTO partidas(campeonato_id,rodada,mandante_id,visitante_id,status) SELECT id,1,1,2,'agendada' FROM campeonatos; INSERT INTO partidas(campeonato_id,rodada,mandante_id,visitante_id,status) SELECT id,1,3,4,'agendada' FROM campeonatos;")
    source = json.loads(sql('SELECT id FROM campeonatos',True))[0]['id']
    preview = draw(action='g4',nome_campeonato='Libertadores do G4',origem_campeonato_id=source,data_inicio='2026-09-12',formato='ida_volta',formato_final='ida_volta')
    assert preview.get('preview'), preview
    assert draw(action='confirm_preview',draw_token=preview['token'])['ok']
    cid = json.loads(sql("SELECT id FROM campeonatos WHERE tipo='mata_mata'",True))[0]['id']
    assert canonical(games(cid)) == canonical(preview['games'])
    assert not json.loads(request('test-delete.php',{'id':source}))['ok']
    assert json.loads(request('test-delete.php',{'id':cid}))['ok']
    assert json.loads(request('test-delete.php',{'id':source}))['ok']
    request('admin/sorteador.php')
    identity = json.loads(sql("SELECT id FROM competicao_identidades WHERE chave='amistosos dreamteam'",True))[0]['id']
    sql(f"INSERT INTO campeonatos(nome,identidade_id,tipo,status) VALUES('Amistosos Dream Team IV',{identity},'mata_mata','finalizado')")
    params = dict(action='mata',nome_campeonato='Amistosos Dream Team V',identidade_id=identity,data_inicio='2026-09-12', **{'participantes[]':[1,2,3,4]})
    preview = draw(**params)
    assert draw(action='confirm_preview',draw_token=preview['token'])['ok']
    assert not draw(**params)['ok']
    cid = json.loads(sql(f"SELECT id FROM campeonatos WHERE identidade_id={identity} AND status='ativo'",True))[0]['id']
    sql(f"INSERT INTO titulos(campeonato_id,titulo) VALUES({cid},'Amistosos Dream Team V'); INSERT INTO artilharia(campeonato_id,gols) VALUES({cid},3)")
    assert json.loads(request('test-delete.php',{'id':cid}))['ok']
    page = request('admin/sorteador.php')
    assert '"proxima_edicao":5' in page
    assert not json.loads(sql(f'SELECT * FROM titulos WHERE campeonato_id={cid}',True))
    assert not json.loads(sql(f'SELECT * FROM artilharia WHERE campeonato_id={cid}',True))
    preview = draw(**params)
    assert draw(action='confirm_preview',draw_token=preview['token'])['ok']
    print('PASS: previews 4/8/10/16, exact confirmation, replay rejection, cancellation, invalid count, G4, dependency guard, edition V reuse, title/scorer cleanup.')
finally:
    if server: server.terminate(); server.wait(); log.close()
    php(f"$p=new PDO('mysql:host=127.0.0.1','root',''); $p->exec('DROP DATABASE IF EXISTS {DB}');")
    assert TEMP.resolve().parent == Path(tempfile.gettempdir()).resolve() and TEMP.name.startswith('draw-test-')
    shutil.rmtree(TEMP)
