# Vascão Season 3

Aplicação web usada para organizar e acompanhar as competições da comunidade DreamTeam. O projeto reúne campeonatos, clubes, elencos, partidas, classificações, estatísticas, notícias, mercado de transferências e o histórico das competições.

**Aplicação em produção:** [vascaoseason3-ironhaven.up.railway.app](https://vascaoseason3-ironhaven.up.railway.app/)

## Funcionalidades

- gerenciamento de competições, temporadas e clubes;
- cadastro de participantes e elencos;
- registro de partidas, resultados, súmulas e decisões por W.O.;
- classificação, artilharia e estatísticas de jogadores;
- competições por pontos corridos, mata-mata e supercopas;
- notícias, histórico de títulos e mercado de transferências;
- painel administrativo com controle de acesso;
- ambientes separados de homologação e produção.

## Tecnologias

- PHP 8.3 e Apache;
- MySQL com acesso por PDO;
- HTML, CSS e JavaScript sem framework;
- Docker para empacotamento da aplicação;
- Railway para homologação e produção.

## Estrutura do projeto

```text
vascaoseason3/
├── admin/       Painel administrativo
├── api/         Endpoints usados pela interface
├── assets/      Folhas de estilo, scripts e imagens
├── config/      Configuração local e variáveis de ambiente
├── docker/      Configurações da imagem Docker
├── includes/    Funções e componentes PHP reutilizáveis
├── sql/         Atualizações incrementais do banco de dados
├── tests/       Testes executados pela linha de comando
└── index.php    Página inicial da aplicação
```

## Execução local com XAMPP

1. Use PHP 8.1 ou mais recente e crie um banco MySQL para o projeto.
2. Copie `config/config.example.php` para `config/config.php`.
3. Ajuste em `config/config.php` o host, a porta, o banco, o usuário e a senha locais.
4. Importe a estrutura do banco utilizada pela aplicação e, quando necessário, execute os arquivos de `sql/` na ordem das versões.
5. Coloque o projeto em `htdocs` e acesse `http://localhost/vascaoseason3/`.

O arquivo `config/config.php` não é versionado, para evitar que credenciais locais sejam enviadas ao repositório.

## Execução com Docker

Crie a imagem:

```bash
docker build -t vascao-season3 .
```

Inicie o contêiner apontando para um banco MySQL existente:

```bash
docker run --rm -p 8080:80 \
  -e DB_HOST=host.docker.internal \
  -e DB_PORT=3306 \
  -e DB_NAME=vascaoseason3 \
  -e DB_USER=root \
  -e DB_PASS=sua_senha \
  -e APP_URL=http://localhost:8080 \
  vascao-season3
```

Depois, acesse `http://localhost:8080`. Em Linux, substitua `host.docker.internal` pelo endereço alcançável do servidor MySQL ou execute os dois serviços na mesma rede Docker.

## Configuração por ambiente

A aplicação reconhece as variáveis abaixo:

| Variável | Finalidade |
|---|---|
| `DB_HOST` | Endereço do MySQL |
| `DB_PORT` | Porta do MySQL |
| `DB_NAME` | Nome do banco |
| `DB_USER` | Usuário do banco |
| `DB_PASS` | Senha do banco |
| `APP_URL` | Endereço público da aplicação |
| `APP_ENV` | Nome do ambiente, como `local` ou `production` |
| `SYNC_SOURCE_URL` | Origem autorizada para sincronização |
| `SYNC_SECRET` | Segredo compartilhado da sincronização |

O Railway também pode fornecer as variáveis nativas `MYSQL_URL`, `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER` e `MYSQLPASSWORD`.

## Verificações

Para conferir a sintaxe de um arquivo PHP:

```bash
php -l caminho/do/arquivo.php
```

Para executar o teste automatizado disponível:

```bash
php tests/proximo-confronto-test.php
```

O fluxo de publicação e os cuidados com os bancos estão detalhados em [DEPLOYMENT.md](DEPLOYMENT.md).
