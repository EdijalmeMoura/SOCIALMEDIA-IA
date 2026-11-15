# SocialMedia IA — versão PHP

Port do produto para **PHP 8.0+ puro**, pensado para hospedagem compartilhada
(cPanel): sobe os arquivos e funciona. Sem Composer, sem Node, sem Passenger.

> Estado atual: **base funcional + clientes + planejamento** — instalador de 3
> telas, login, dashboard, banco SQLite, sessão/CSRF, tratamento de subpasta, o
> portfólio de clientes (perfil, identidade visual, logo e referências) e o
> calendário editorial com IA. Os demais módulos (estúdio, aprovações, feed,
> relatórios, Meta) entram um a um, reaproveitando esta mesma base e o mesmo
> esquema de dados.

## Baixar o pacote pronto

`downloads/SOCIALMEDIA-IA-php-v1.2.zip` nesta pasta é o pacote completo (`.env`
com o endereço preenchido, `COMECE-AQUI.txt`, `data/` protegido). Baixe a versão
publicada em
[`php/downloads/`](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/tree/arena/4b255bc3-socialmedia-ia/php/downloads)
ou gere um novo a qualquer momento:

```sh
git archive --format=zip -o socialai-php.zip HEAD:php
```

O pacote publicado **não contém segredos**: preencha `SECRET_KEY` e
`SOCIALMEDIA_INSTALL_TOKEN` no `.env` antes de enviar (instruções no
`COMECE-AQUI.txt`).

## Por que existe

O endereço `https://projetoredesnordeste.com.br/edijalmemoura/socialai/`
respondia `403` porque a pasta não tinha aplicação PHP/Python atendendo. PHP é
nativo no cPanel: nenhum processo extra, nenhum `.htaccess` de CGI, nenhuma
dependência para instalar.

## Instalação no cPanel (3 minutos)

1. Envie o conteúdo desta pasta (`php/`) para `~/socialai-php` — **fora** de
   `public_html` — e aponte o domínio/subpasta para `~/socialai-php/public`.

   Se preferir o caminho tradicional do cPanel, envie a pasta inteira para
   `~/public_html/edijalmemoura/socialai/`. O `.htaccess` da raiz do projeto
   (versionado) manda tudo para `public/` e bloqueia `.env`, `data/`, `app/`
   e `tests/`, então nada fica exposto nem precisa ser configurado à mão.

2. Copie `.env.example` para `.env` e ajuste:

   ```sh
   SOCIALMEDIA_BASE_PATH=/edijalmemoura/socialai
   SECRET_KEY=<php -r "echo bin2hex(random_bytes(32));">
   SOCIALMEDIA_INSTALL_TOKEN=<invente um token>
   COOKIE_SECURE=1
   ```

3. Permissões: `chmod 600 .env` e `chmod 755 data` (o app cria
   `data/socialmedia.sqlite` e `data/installer.env` com 0600).

4. Abra `https://seu-dominio/edijalmemoura/socialai/` → redireciona para
   `/install`. Com token configurado, use
   `.../socialai/install?access_token=SEU_TOKEN`.

5. Conclua o assistente. O instalador **se fecha** (`410`) e você entra por
   `/login` com o e-mail e a senha cadastrados.

### Alternativa: sem rewrite na raiz

Se o servidor não honrar o `.htaccess` da raiz (raro no cPanel), crie
`public_html/edijalmemoura/socialai/index.php` apenas com:

```php
<?php require __DIR__ . '/public/index.php';
```

O `SOCIALMEDIA_BASE_PATH` continua mandando no prefixo das URLs.

## Estrutura

```
php/
├── public/            ← única pasta exposta pelo servidor
│   ├── index.php      ← front controller
│   ├── .htaccess      ← rewrite portátil (RewriteBase /)
│   └── assets/        ← CSS/JS compartilhados com a versão Python
├── app/
│   ├── Application.php      ← monta rotas, banco, sessão
│   ├── Http/                ← Request, Response, Router
│   ├── Support/             ← Config, EnvFile, Session, View, Upload, Modules
│   ├── Database/            ← conexão SQLite + esquema versionado
│   ├── Installer/           ← regras de instalação (espelham o Python)
│   ├── Auth/                ← login, trava de tentativas, rehash
│   ├── Clients/             ← regras dos clientes (multi-tenant, cores, arquivos)
│   ├── Controllers/         ← instalador, autenticação, dashboard, clientes
│   └── views/               ← templates PHP com escape obrigatório
├── .htaccess          ← raiz: manda para public/ e bloqueia app/, data/, tests/
├── data/              ← SQLite, .env gerado e rascunhos (conteúdo ignorado no Git;
│                        o .htaccess que nega acesso é versionado)
└── tests/             ← suíte que roda em PHP 8.3 real (php-wasm)
```

## Módulos

### Clientes (pronto)

Portfólio de marcas do workspace, com o mesmo modelo de dados da versão Python.

| Rota | Método | O que faz | Quem pode |
|---|---|---|---|
| `/clients` | GET | grade de clientes com busca instantânea | todos |
| `/clients/new` | GET/POST | cadastro (negócio + canais) | owner, admin, editor |
| `/clients/{id}` | GET/POST | perfil: dados, identidade visual, referências | ver: todos · editar: owner/admin/editor |
| `/clients/{id}/delete` | POST | exclui cliente, logo e referências | owner, admin |
| `/clients/{id}/logo` | POST | envia/substitui o logotipo | owner, admin, editor |
| `/clients/{id}/assets` | POST | adiciona imagem de referência | owner, admin, editor |
| `/clients/{id}/assets/{a}/delete` | POST | remove uma referência | owner, admin, editor |
| `/clients/file?file=…` | GET | serve logo/referência **só da própria empresa** | todos |

Regras que valem a pena saber:

- Toda consulta filtra por `company_id`: id de outro workspace responde 404,
  sem revelar que existe.
- Cores aceitam apenas `#rrggbb`; valor inválido cai no padrão da marca
  (`#3158d8`, `#f57a45`, `#16a68a`).
- Upload aceita PNG/JPG/WEBP até 8 MB e valida a **assinatura dos bytes** —
  renomear um `.php` para `.png` não passa. O nome salvo é gerado pelo sistema.
- Excluir o cliente remove em cascata identidade visual e referências, e apaga
  os arquivos do disco.
- Cada escrita (criar, editar, excluir, upload) deixa evento no `audit_log`,
  visível no dashboard.

### Planejamento (pronto)

Calendário editorial por cliente: a IA lê o perfil e a identidade visual da marca
e devolve as publicações do período, com tema, objetivo, legenda, CTA e briefing
visual.

| Rota | Método | O que faz | Quem pode |
|---|---|---|---|
| `/planner` | GET | formulário de geração + lista de calendários | todos |
| `/planner/generate` | POST | cria o calendário (IA ou rascunho local) | owner, admin, editor |
| `/planner/{id}` | GET | calendário: fatos, grade semanal e publicações | todos |
| `/planner/{id}/production` | POST | envia plano e publicações para produção | owner, admin, editor |
| `/planner/{id}/delete` | POST | exclui o calendário e as publicações | owner, admin |
| `/planner/posts/{id}/edit` | GET/POST | edita uma publicação | ver: todos · editar: owner/admin/editor |
| `/planner/posts/{id}/duplicate` | POST | duplica a publicação ao fim do plano | owner, admin, editor |
| `/planner/posts/{id}/delete` | POST | remove a publicação | owner, admin, editor |

Regras que valem a pena saber:

- **Sem `OPENAI_API_KEY` o planejamento continua funcionando**: ele cria um
  *rascunho local* marcado como tal (`ai_model = local-draft`, 0 tokens) e a tela
  diz "Rascunho local (sem IA)". O produto nunca apresenta texto de template como
  se fosse IA.
- Com chave, a chamada usa o mesmo contrato da versão Python (JSON com os 11
  campos por post, `temperature 0.7`, `max_tokens 10000`) e o consumo fica em
  `ai_generations`. `OPENAI_ENDPOINT` permite apontar para Azure ou um proxy.
- **Nada entra no banco sem validação**: quantidade exata de posts, datas dentro
  do período (inclusivo), redes apenas as escolhidas, campos essenciais
  preenchidos. Resposta fora disso vira mensagem tratada, nunca erro 500.
- A quantidade de publicações é limitada a 45 por período (7, 15 ou 30 dias).
- O conteúdo gerado é tratado como **não confiável** e escapado na tela.
- Toda consulta filtra por `company_id`: calendário de outra agência responde 404.
- Excluir o calendário remove as publicações em cascata; duplicar soma 1 ao alvo
  do plano, remover subtrai 1 (nunca abaixo de zero).
- Escritas deixam evento no `audit_log` (`planner.created`, `planner.production`,
  `planner.deleted`).

### Próximos

estúdio → aprovações → feed → relatórios → Meta. Cada um entra por migração
aditiva do `Schema` (versão 2 = clientes, versão 3 = planejamento), então
atualizar o pacote nunca apaga dados de uma instalação em uso.

## Subpasta: como é resolvido

O `Request::resolvePaths()` cobre os casos que aparecem em cPanel:

| Servidor entrega | Resultado |
| --- | --- |
| `REQUEST_URI=/edijalmemoura/socialai/install` | prefixo `/edijalmemoura/socialai`, rota `/install` |
| `SCRIPT_NAME=/.../index.php` + `PATH_INFO=/install` | usa o `PATH_INFO` |
| Só `PATH_INFO=/install` | usa o prefixo do script |
| `SOCIALMEDIA_BASE_PATH` declarado | tem prioridade sobre a detecção |

Com isso, `url()`, o cookie de sessão, o CSRF e os redirecionamentos saem
sempre com o prefixo — inclusive quando o servidor não anuncia nada.

## Testes

A suíte roda **PHP 8.3 de verdade** (WebAssembly), sem precisar de PHP
instalado na máquina:

```sh
cd php
npm install        # @php-wasm/universal + @php-wasm/node (só para testes)
npm test           # parse de todos os arquivos + 136 verificações funcionais
```

Cobre: normalização de subpasta, `.env`, detecção de caminho, token do
instalador, CSRF, validação de formulário, regras de URL pública (com
subpasta e rejeições), instalação completa, hash da senha, permissão 0600 do
arquivo de segredos, login, logout, dashboard, 404/405, isolamento entre
instâncias e o módulo de clientes (cadastro, edição, cores, upload validado
por assinatura, permissões por papel, exclusão em cascata).

Para exercitar o caminho HTTP completo (cookies, redirecionamentos, HTML):

```sh
node tests/serve.mjs 8099 /edijalmemoura/socialai
```

## Dados e backup

| Arquivo | Permissão | Backup |
| --- | --- | --- |
| `data/socialmedia.sqlite` | 0600 | sim (contém tudo) |
| `data/installer.env` | 0600 | sim (SECRET_KEY e segredos) |
| `.env` | 0600 | sim |

Sem `SECRET_KEY` persistente a sessão não sobrevive a reinícios; sem
`META_TOKEN_ENCRYPTION_KEY` os tokens da Meta ficam ilegíveis. Guarde os dois.

## Diferenças em relação à versão Python (por enquanto)

- Módulos de negócio ainda não portados (aparecem como "EM BREVE" no painel).
- Instalação automática por variáveis (`SOCIALMEDIA_AUTO_INSTALL`) entra junto
  com o módulo de configurações.
- O `requirements.txt`/Docker continuam válidos para a versão Python, que
  permanece na raiz do repositório e não foi alterada.
