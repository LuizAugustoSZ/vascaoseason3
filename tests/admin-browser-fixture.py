"""Painel sintético isolado para regressão visual dos scripts reais, sem banco/login.
Execute python tests/admin-browser-fixture.py e abra http://127.0.0.1:33318/admin/.
Nenhum dado da aplicação é lido ou alterado.
"""
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from pathlib import Path
import html, json, time, urllib.parse
ROOT = Path(__file__).resolve().parents[1]
name = 'Competição de teste'
saves = 0
class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        if self.path.startswith('/assets/'):
            path=(ROOT/urllib.parse.urlsplit(self.path).path.lstrip('/')).resolve()
            if not path.is_relative_to(ROOT/'assets') or not path.is_file(): self.send_error(404);return
            self.send_response(200);self.send_header('Content-Type','text/css' if path.suffix=='.css' else 'application/javascript');self.end_headers();self.wfile.write(path.read_bytes());return
        if 'campeonatos-dados.php' in self.path:
            data=b'{"ok":true,"campeonatos":[]}'
            self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();time.sleep(2);self.wfile.write(data[:10]);self.wfile.flush();time.sleep(2);self.wfile.write(data[10:]);return
        rows=''.join(f'<tr><td>{html.escape(name) if i==0 else "Clube " + str(i)}</td><td>Liga</td><td>Ativo</td><td><button type="button" class="editar-campeonato btn btn-warning" data-bs-toggle="modal" data-bs-target="#competition-edit-modal" data-id="{i+1}" data-name="{html.escape(name,quote=True)}" data-date="2026-10-03" data-status="ativo">Editar</button></td></tr>' for i in range(8))
        page=f'''<!doctype html><html><head><meta charset="utf-8"><title>Regressão Admin isolado</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/style.css">
<style>body{{padding:20px;background:#121418;color:white}}.admin-side-nav{{display:flex;gap:10px}}main{{padding-top:30px}}</style></head>
<body class="admin-is-loading"><div class="site-loading-screen admin-loading-screen"><span class="site-loading-spinner"></span><strong data-loading-label>CARREGANDO DADOS</strong></div>
<nav class="admin-side-nav"><button class="nav-link active" data-bs-target="#tab-fixture"><span>Campeonatos</span></button><button class="nav-link" data-bs-target="#tab-videos"><span>Vídeos</span></button></nav>
<main><h1>Regressão Admin — ambiente isolado</h1><p id="save-count">Edições salvas: {saves}</p><div class="tab-content"><section class="tab-pane active show" id="tab-fixture"><div class="table-responsive"><table class="table"><thead><tr><th>Nome</th><th>Modalidade</th><th>Status</th><th>Ação</th></tr></thead><tbody>{rows}</tbody></table></div>
<div class="modal fade" id="competition-edit-modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content text-dark"><form id="competition-edit-form" method="post"><div class="modal-header"><h2>Editar competição</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="fixture"><input type="hidden" name="action" value="fixture"><input type="hidden" name="campeonato_id"><label for="fixture-name">Nome</label><input id="fixture-name" name="nome" class="form-control" required><input type="date" name="data_inicio"><select name="status"><option value="ativo">Ativo</option></select></div><div class="modal-footer"><button class="btn btn-primary" type="submit">Salvar</button></div></form></div></div></div></section><section class="tab-pane" id="tab-videos"><h2>Vídeos funcionando</h2></section></div></main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="/assets/js/admin-loading.js"></script><script src="/assets/js/admin.js"></script><script src="/assets/js/admin-lists.js"></script></body></html>'''
        self.send_response(200);self.send_header('Content-Type','text/html; charset=utf-8');self.end_headers();self.wfile.write(page.encode())
    def do_POST(self):
        global name,saves
        raw=self.rfile.read(int(self.headers.get('Content-Length',0))).decode()
        # FormData multipart: extrai somente o campo da fixture, nunca dados reais.
        import re
        match=re.search(r'name="nome"\r\n\r\n([^\r]*)',raw)
        if match: name=match[1]
        saves+=1
        self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(json.dumps({'ok':True,'tab':'fixture','message':'Edição salva na fixture'}).encode())
print('Fixture isolada: http://127.0.0.1:33318/admin/',flush=True)
ThreadingHTTPServer(('127.0.0.1',33318),Handler).serve_forever()
