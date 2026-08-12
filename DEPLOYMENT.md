# Deploy: staging e produção

O projeto usa dois ambientes persistentes e dois bancos separados. Dados de produção nunca devem ser usados para testes destrutivos.

| Ambiente | Branch | Banco | Finalidade |
|---|---|---|---|
| Staging | `develop` | MySQL de staging | Homologação e aprovação |
| Produção | `main` | MySQL de produção | Site público |

## Fluxo de trabalho

1. Criar uma branch `feature/nome-da-tarefa` a partir de `develop`.
2. Desenvolver e testar localmente.
3. Abrir pull request da feature para `develop`.
4. Conferir a versão publicada no ambiente de staging.
5. Depois da aprovação, abrir pull request de `develop` para `main`.
6. O merge em `main` publica a produção.

## Configuração no Railway

1. Criar um projeto a partir do repositório GitHub.
2. No ambiente `production`, adicionar um serviço MySQL e ligar o serviço web à branch `main`.
3. Criar um ambiente persistente `staging`, isolado de produção, e ligar o serviço web à branch `develop`.
4. Em cada serviço web, configurar as variáveis abaixo apontando para o MySQL do próprio ambiente:

```text
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASS
APP_URL
```

O arquivo `config/config.php` continua disponível apenas para desenvolvimento local. Em deploy, a aplicação usa `config/config.example.php`, que lê as variáveis do ambiente.

## Migração inicial do banco

O dump de produção deve ser importado uma única vez no novo MySQL de produção por uma conexão segura. Para staging, usar uma cópia sanitizada ou dados próprios de teste.

Antes de trocar o domínio:

1. Colocar o site antigo em manutenção para impedir novas gravações.
2. Gerar um dump final no InfinityFree.
3. Importar o dump no banco novo.
4. Testar login, painel, partidas, notícias e gravações.
5. Trocar DNS/domínio somente depois da validação.
6. Manter o InfinityFree intacto por alguns dias como rollback.

Nunca adicionar dumps, senhas ou arquivos locais de configuração ao Git.
