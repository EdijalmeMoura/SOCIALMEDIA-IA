# SocialMedia IA — versão PHP

Port do produto para **PHP 8.0+ puro**, pensado para hospedagem compartilhada
(cPanel): sobe os arquivos e funciona. Sem Composer, sem Node, sem Passenger.

> Estado atual: **produto completo** — instalador de 3 telas, login, dashboard,
> banco SQLite, sessão/CSRF, tratamento de subpasta, portfólio de clientes
> (perfil, identidade visual, logo e referências), calendário editorial com IA,
> criação de artes com prancheta, histórico de versões e exportação, revisão pelo
> cliente em portal próprio com histórico e ciclos, a prévia do feed montada a
> partir do que já foi produzido, o relatório operacional com exportação em CSV e
> o módulo **Meta**: conexões de Páginas Facebook/Instagram por OAuth, agenda
> interna e publicação manual real (com resultado incerto quando a Meta não
> responde).

## Baixar o pacote pronto

**Última versão: [`php-v1.11`](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.11)** — todos os módulos,
incluindo Meta (conexões e publicação real) e IA com os modelos atuais da OpenAI.

> **O pacote não está anexado como asset das releases** (o upload de binário
> falha no ambiente que as publica). Os links da coluna *Pacote* apontam para o
> arquivo dentro da branch — funcionam igual para baixar, e o `SHA256SUMS.txt`
> confere a integridade.
>
> Se um dia o asset subir, os dois caminhos levam ao mesmo arquivo.

| Versão | O que entrou | Release | Pacote |
|---|---|---|---|
| **v1.11** | **Diagnóstico** em `/install/diagnostico` e falhas que dizem a causa (arquivo:linha) | [php-v1.11](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.11) | [`SOCIALMEDIA-IA-php-v1.11.zip`](downloads/SOCIALMEDIA-IA-php-v1.11.zip) |
| v1.10 | **Token do instalador**: não se perde no fluxo e a tela de 403 aceita colar o token | [php-v1.10](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.10) | [`SOCIALMEDIA-IA-php-v1.11.zip`](downloads/SOCIALMEDIA-IA-php-v1.10.zip) |
| v1.9 | **Instalador completo**: tela final "Instância pronta" e `/install/run` sem 405 | [php-v1.9](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.9) | [`SOCIALMEDIA-IA-php-v1.9.zip`](downloads/SOCIALMEDIA-IA-php-v1.9.zip) |
| v1.8 | **IA em dia**: modelos padrão atuais (`gpt-6-luna`, `gpt-image-2`) e erro que diz qual modelo falhou | [php-v1.8](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.8) | [`SOCIALMEDIA-IA-php-v1.8.zip`](downloads/SOCIALMEDIA-IA-php-v1.8.zip) |
| v1.7 | Meta: conexões Facebook/Instagram, agenda interna e publicação manual real | [php-v1.7](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.7) | [`SOCIALMEDIA-IA-php-v1.7.zip`](downloads/SOCIALMEDIA-IA-php-v1.7.zip) |
| v1.6 | Relatórios com recorte de período e CSV | [php-v1.6](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.6) | [`SOCIALMEDIA-IA-php-v1.6.zip`](downloads/SOCIALMEDIA-IA-php-v1.6.zip) |
| v1.5 | Feed: grade 3×3 e reordenação | [php-v1.5](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.5) | [`SOCIALMEDIA-IA-php-v1.5.zip`](downloads/SOCIALMEDIA-IA-php-v1.5.zip) |
| v1.4 | Aprovações e portal do cliente | [php-v1.4](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.4) | [`SOCIALMEDIA-IA-php-v1.4.zip`](downloads/SOCIALMEDIA-IA-php-v1.4.zip) |
| v1.3 | Base + clientes + planejamento + estúdio | [php-v1.3](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.3) | [`SOCIALMEDIA-IA-php-v1.3.zip`](downloads/SOCIALMEDIA-IA-php-v1.3.zip) |
| v1.2 | Base + clientes + planejamento | [php-v1.2](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.2) | [`SOCIALMEDIA-IA-php-v1.2.zip`](downloads/SOCIALMEDIA-IA-php-v1.2.zip) |
| v1.1 | Base + clientes | [php-v1.1](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.1) | [`SOCIALMEDIA-IA-php-v1.1.zip`](downloads/SOCIALMEDIA-IA-php-v1.1.zip) |

Atalhos:

- Todas as releases: <https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases>
- Todos os pacotes: [`php/downloads/`](https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/tree/arena/4b255bc3-socialmedia-ia/php/downloads)
- Integridade: [`php/downloads/SHA256SUMS.txt`](downloads/SHA256SUMS.txt)

> A pasta `php/downloads/` está na branch de entrega do port. Se o link acima
> ainda não abrir na `main`, é porque a branch ainda não foi mesclada — os
> arquivos `.zip`/`.tar.gz` também vão junto no download do repositório.

Cada `.zip` é o pacote completo (`.env` com o endereço preenchido,
`COMECE-AQUI.txt`, `data/` protegido) e tem um `.tar.gz` irmão. Para conferir o
download:

```sh
sha256sum SOCIALMEDIA-IA-php-v1.11.zip   # compare com SHA256SUMS.txt
```

O pacote publicado **não contém segredos seus**: o `.env` vem com `SECRET_KEY` e
`SOCIALMEDIA_INSTALL_TOKEN` gerados na hora do empacotamento — troque o token e
apague-o depois de instalar (instruções no `COMECE-AQUI.txt`).

Para gerar um pacote a partir do código desta branch:

```sh
git archive --format=zip -o socialai-php.zip HEAD:php
```

### Descrição de cada módulo

- **v1.11 — Diagnóstico.** `GET /install/diagnostico` mostra, sem pedir token e
  sem exibir segredo, o estado real do servidor: versão do pacote, PHP, sessão,
  CSRF, pasta de dados (gravável?), banco, se já está instalado e a data dos
  arquivos-chave. É o primeiro lugar a olhar quando algo não fecha. Além disso,
  falha na confirmação passou a separar **gravar** de **acabar**: se a conta já
  existe, a tela diz isso e leva ao login; se não existe, mostra a causa com
  **arquivo:linha**. O layout do instalador também passou a exibir as mensagens
  da requisição anterior (antes, "sessão expirada" era gravada e nunca vista).
- **v1.10 — Token do instalador.** Todo redirecionamento interno carrega o
  `?access_token=` adiante: antes um "voltar" caía em `/install` sem o token e a
  própria proteção devolvia **403** no meio do assistente. A tela de 403 ganhou
  um campo para colar o token (`POST /install/access`) e guarda de onde a pessoa
  veio. O rodapé do instalador passou a mostrar a versão (`v1.10`), para saber
  se o arquivo enviado é o novo.
- **v1.9 — Instalador completo.** O assistente agora termina na tela
  **“Instância pronta”**, com o registro da instalação e o botão de entrar —
  antes a confirmação pulava direto para o login. Abrir `/install/run` no
  navegador deixou de devolver **405**: num servidor ainda virgem ele explica e
  volta ao passo 1; depois de instalar, mostra a conclusão. O F5 não reenvia
  mais o formulário (o POST redireciona). Abrir `/install` continua 410.
- **v1.8 — IA em dia.** Os modelos padrão passaram a ser os atuais da OpenAI
  (`gpt-6-luna` para texto, `gpt-image-2` para imagem) e a chamada usa
  `max_completion_tokens` com `reasoning_effort: none`. Quando a OpenAI
  desliga um modelo, a tela agora diz **qual modelo** falhou, o HTTP e a
  variável a ajustar — antes isso virava "tente mais tarde". Nada de dado
  antigo muda: é migração nenhuma, só configuração.
- **v1.7 — Meta.** Conectar Páginas do Facebook e contas profissionais do
  Instagram por OAuth, com token da Página cifrado em repouso e painel de
  evidências (configuração → OAuth → leitura Graph → TLS → mídia pública →
  publicação comprovada pela API). Agenda interna em UTC + fuso e publicação
  **manual**, num clique em horário vencido. Se a Meta não responde, a tentativa
  fica **incerta** e bloqueia repetição até alguém conferir a conta. Guia:
  [`docs/meta.md`](docs/meta.md).
- **v1.6 — Relatórios.** O que a agência fez no período (recorte de até 366 dias,
  filtro por cliente) e exportação CSV para planilha. Sem métrica de rede
  inventada.
- **v1.5 — Feed.** Grade 3×3 montada com artes e pautas, reordenação por arrastar
  com 409 quando outra aba mudou a lista. É prévia: nada é publicado.
- **v1.4 — Aprovações.** Envio de arte/pauta para o cliente revisar no portal,
  com fotografia do conteúdo aprovado, ciclos, comentários e avisos.
- **v1.3 — Base + clientes + planejamento + estúdio.** Instalador de 3 telas,
  login, dashboard, portfólio de clientes, calendário editorial e criação de
  artes com prancheta.
- **v1.1 / v1.2** — primeiras entregas (base instalável, clientes e
  planejamento). Ficam publicadas para quem precisa reproduzir uma instalação
  antiga; a v1.11 já contém tudo delas.

Atualizando de qualquer versão anterior: envie **por cima apenas `app/` e
`public/`**, mantenha `.env` e `data/`. O esquema migra sozinho e nada é
apagado.

## Por que existe

O endereço `https://projetoredesnordeste.com.br/edijalmemoura/socialai/`
respondia `403` porque a pasta não tinha aplicação PHP/Python atendendo. PHP é
nativo no cPanel: nenhum processo extra, nenhum `.htaccess` de CGI, nenhuma
dependência para instalar.

## Instalação no cPanel (3 minutos)

0. **Baixe o pacote** (não precisa clonar nada):

   ```sh
   curl -LO https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/raw/php-v1.11/php/downloads/SOCIALMEDIA-IA-php-v1.11.zip
   unzip SOCIALMEDIA-IA-php-v1.11.zip -d socialai-php
   ```

   Versão mais nova: <https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases>.
   Se o link acima mudar de nome, os mesmos arquivos estão em
   [`php/downloads/`](downloads/) com os hashes em `SHA256SUMS.txt`.

1. Envie o conteúdo desta pasta (`php/`) para `~/socialai-php` — **fora** de
   `public_html` — e aponte o domínio/subpasta para `~/socialai-php/public`.

   Se preferir o caminho tradicional do cPanel, envie a pasta inteira para
   `~/public_html/edijalmemoura/socialai/`. O `.htaccess` da raiz do projeto
   (versionado) manda tudo para `public/` e bloqueia `.env`, `data/`, `app/`
   e `tests/`, então nada fica exposto nem precisa ser configurado à mão.

2. Copie `.env.example` para `.env` e ajuste **só o endereço e o token**
   (o `SECRET_KEY` pode ficar vazio: o instalador gera e grava um):

   ```sh
   SOCIALMEDIA_BASE_PATH=/edijalmemoura/socialai
   SOCIALMEDIA_PUBLIC_ORIGIN=https://projetoredesnordeste.com.br/edijalmemoura/socialai
   SOCIALMEDIA_INSTALL_TOKEN=<invente um token>
   COOKIE_SECURE=1
   # SECRET_KEY fica vazio: o instalador gera e grava em data/installer.env
   ```

3. Permissões: `chmod 600 .env` e `chmod 755 data`.

4. Abra `https://seu-dominio/edijalmemoura/socialai/` → redireciona para
   `/install`. Com token configurado, use
   `.../socialai/install?access_token=SEU_TOKEN`.

5. Conclua o assistente: **ambiente → revisão → pronto**. Nada é gravado antes
   da confirmação. Ao final ele cria o workspace, o proprietário, o banco, o
   `data/installer.env` (0600) e **se fecha** (`410`); você entra por `/login`.

### O instalador pelo navegador

Três telas, nenhum comando e nenhum segredo digitado no navegador além da senha
do proprietário.

| Rota | Método | O que faz |
|---|---|---|
| `/install` | GET | verificações do ambiente + formulário do workspace |
| `/install/validate` | POST | valida e grava um **rascunho criptografado** em `data/installer-drafts/` |
| `/install/review` | GET | revisão com segredos **mascarados** (a senha nunca volta) |
| `/install/run` | POST (e GET) | confirma: cria empresa, proprietário e arquivo de segredos |
| `/install/cancel` | POST (e GET) | descarta o rascunho |

O fim do assistente é a tela **“Instância pronta”** (`/install/run`): ela mostra o
registro da instalação (agência, e-mail do proprietário, data e URL) e o botão
para entrar. Depois da confirmação:

- `POST` cria tudo e **redireciona** para `/install/run` — por isso o F5 não
  reenvia o formulário nem dá erro de “sessão expirada”;
- abrir `/install/run` no navegador (GET) nessa hora mostra a mesma tela de
  conclusão, sem criar nada e sem 405;
- `/install` passa a responder **410** com o resumo da instalação.

> Caiu em `/install/run` sem passar pela revisão? A tela explica o que fazer e
> devolve para `/install` — nada é criado sem o botão de confirmação.

O que ele garante:

- **Só existe enquanto não há conta.** Com qualquer usuário no banco, todas as
  rotas do instalador respondem **410** com o resumo da instalação.
- **Token obrigatório** quando `SOCIALMEDIA_INSTALL_TOKEN` está no `.env`:
  sem token (ou com token errado) a resposta é **403** — e essa tela tem um
  **campo para colar o token** (`POST /install/access`). Antes ela só mandava
  procurar no `.env`, sem saída: quem abria o endereço sem o parâmetro ficava
  preso. Sem a variável, a tela avisa em âmbar que qualquer pessoa com a URL
  pode instalar.
- **O token nunca se perde no caminho**: todo redirecionamento interno do
  assistente carrega o `?access_token=` adiante. Era o que fazia a instalação
  "voltar para o início" com 403 no meio do fluxo.
- **Versão visível**: o rodapé do instalador mostra `v1.10` — serve para saber,
  sem adivinhar, se o arquivo enviado ao servidor é o pacote novo.
- **Nada gravado antes da confirmação.** O que fica em disco entre as telas é um
  rascunho JSON em `data/installer-drafts/` (0600, validade de 30 min). A senha
  já vai como hash — nunca em texto.
- **Senha**: mínimo de 8 caracteres, recusa as óbvias (`12345678`, `senha123`,
  `instagram`…) e a senha igual ao e-mail. Hash com **Argon2id** (ou
  `PASSWORD_DEFAULT` onde não houver).
- **URL pública**: aceita subpasta (`https://dominio/edijalmemoura/socialai`),
  exige HTTPS (HTTP só em `localhost`), recusa credenciais, query e `..`.
  É essa URL que define o callback da Meta.
- **Verificações de ambiente**: PHP ≥ 8.0, extensões `pdo_sqlite`, `mbstring`,
  `json` e `openssl`, escrita **real** na pasta de dados (cria e apaga um
  arquivo de teste), HTTPS e se há token configurado.
- **Segredos no servidor**: o `data/installer.env` sai com `0600` e guarda o
  `SECRET_KEY`, a origem pública e `COOKIE_SECURE`. Como a precedência é
  **ambiente do servidor > `.env` > `installer.env`**, o `.env` continua
  mandando — e reiniciar o servidor não perde nada.
- Páginas do instalador saem com `Cache-Control: private, no-store` e
  `X-Robots-Tag: noindex, nofollow`.

> **Nunca troque o `SECRET_KEY` depois de instalar:** ele assina as URLs de
> mídia enviadas à Meta. Já `META_TOKEN_ENCRYPTION_KEY`, se mudar, torna
> ilegíveis os tokens das Páginas guardados no banco.

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

### Estúdio (pronto)

Um assunto vira arte pronta: texto, imagem de fundo, ajuste na prancheta e
arquivo final para publicar.

| Rota | Método | O que faz | Quem pode |
|---|---|---|---|
| `/studio` | GET | formulário de criação + galeria de artes | todos |
| `/studio/generate` | POST | cria a arte (IA ou rascunho local) | owner, admin, editor |
| `/studio/posts/{id}` | GET | editor com prancheta, controles e versões | todos |
| `/studio/posts/{id}/save` | POST | salva texto, layout e cor numa nova versão | owner, admin, editor |
| `/studio/posts/{id}/image` | POST | gera a imagem de fundo com IA | owner, admin, editor |
| `/studio/posts/{id}/image/upload` | POST | envia sua imagem (ajustada ao formato) | owner, admin, editor |
| `/studio/posts/{id}/export` | POST | recebe o PNG/JPG renderizado e libera o download | owner, admin, editor |
| `/studio/posts/{id}/download` | GET | baixa a arte exportada | todos |
| `/studio/posts/{id}/variation` | POST | cria uma versão criativa alternativa | owner, admin, editor |
| `/studio/posts/{id}/versions/{v}/restore` | POST | restaura uma versão anterior | owner, admin, editor |
| `/studio/posts/{id}/delete` | POST | exclui a arte, as versões e os arquivos | owner, admin, editor |
| `/studio/files?file=…` | GET | serve imagem/exportação **só da própria empresa** | todos |

Sete formatos com as mesmas medidas do produto Python: post quadrado (1080×1080),
post vertical e anúncio (1080×1350), stories (1080×1920), carrossel (1080×1350),
banner (1200×628) e capa (1640×624).

Regras que valem a pena saber:

- **A prancheta desenha no navegador**, não no servidor: título, subtítulo, CTA e
  assinatura da marca seguem um layout JSON validado no PHP (quatro elementos,
  coordenadas dentro do canvas, cores `#rrggbb`). Sem biblioteca gráfica no
  servidor — funciona em qualquer cPanel.
- A exportação manda o PNG/JPG renderizado de volta ao PHP, que confere a
  assinatura dos bytes, grava o arquivo e libera o download nomeado
  (`socialmedia-ia-{id}.png`).
- **Sem `OPENAI_API_KEY`** o estúdio cria um *rascunho local* marcado como tal e
  deixa o botão de imagem desabilitado; enviar a própria imagem funciona.
- Com chave: texto pelo Chat Completions e imagem pelo endpoint de imagens
  (`OPENAI_IMAGE_MODEL`, padrão `gpt-image-2`). O consumo entra em
  `ai_generations` (`studio_copy`, `studio_image`, `studio_variation`).
- **Qual chave usar**: `OPENAI_API_KEY` aceita uma chave de *service account*
  (`sk-svcacct-…`), que é a certa para servidor — ela pertence ao projeto e não
  morre quando alguém sai da equipe. Chave de usuário (`sk-proj-…`) funciona,
  mas cai junto com o acesso da pessoa; chave de organização (`sk-admin-…`)
  **não** serve para chamar modelo. Recomendado: um projeto só para este
  produto, permissão **Restricted** (só Chat Completions + Images, escrita) e
  um limite de gasto no projeto.
- **Modelo desligado**: se a OpenAI aposentar o modelo, a geração falha com
  HTTP 404 e a tela mostra o erro citando o modelo e mandando ajustar a
  variável — não é preciso mexer no código. Os padrões atuais são
  `gpt-6-luna` (texto) e `gpt-image-2` (imagem); `gpt-image-1`, que era o
  padrão antigo, foi desligado em 23/10/2026 e não funciona mais.
- **Por que `reasoning_effort: none`**: os modelos atuais de texto são de
  raciocínio e recusam `max_tokens` e recusam `temperature` com raciocínio
  ligado. A chamada usa `max_completion_tokens` e o esforço `none`, que é o
  que mantém temperatura, custo e latência previsíveis para este uso.
- **Cada ação vira versão**: gerar, editar, trocar imagem, exportar e restaurar
  guardam uma cópia do estado — dá para voltar atrás sem perder trabalho.
- A imagem é sempre reenquadrada para o formato (cobre e corta o excesso,
  centralizada) e validada pela assinatura dos bytes; limite de 8 MB.
- Conteúdo da IA é entrada não confiável: escapado na tela e limitado no layout.
- Isolamento por `company_id` em tudo, inclusive no arquivo servido; a exclusão
  remove arte, versões em cascata e os arquivos do disco.

### Aprovações (pronto)

O conteúdo sai da agência, é revisado pelo cliente e volta com histórico. Cada
envio é uma **fotografia**: o que o cliente aprovou continua sendo exatamente
aquilo, mesmo que a arte mude depois no estúdio.

**Agencia** (menu lateral → Aprovações):

| Rota | Método | O que faz | Quem pode |
|---|---|---|---|
| `/approvals` | GET | fila de revisões, conteúdos disponíveis e acessos do portal | todos |
| `/approvals` | POST | envia arte exportada ou pauta para o cliente | owner, admin, editor |
| `/approvals/{id}` | GET | fotografia enviada + histórico + comentários | todos |
| `/approvals/{id}/comment` | POST | comenta (com imagem opcional) e avisa o cliente | owner, admin, editor |
| `/approvals/{id}/cancel` | POST | cancela a revisão e libera o conteúdo | owner, admin, editor |
| `/approvals/{id}/media/{arquivo}` | GET | serve a arte ou um anexo **daquela revisão** | todos |
| `/approvals/portal-users` | POST | cria acesso do cliente ao portal | owner, admin |
| `/approvals/portal-users/{id}/toggle` | POST | pausa/reativa o acesso | owner, admin |
| `/approvals/notifications/read` | POST | marca os avisos da equipe como lidos | todos |

**Portal do cliente** (endereço separado, `/portal`):

| Rota | Método | O que faz |
|---|---|---|
| `/portal/login` | GET/POST | entrada do cliente (e-mail e senha próprios) |
| `/portal/logout` | POST | sai do portal |
| `/portal/approvals` | GET | meus conteúdos, pendentes primeiro |
| `/portal/approvals/{id}` | GET | conteúdo aberto, histórico e comentários |
| `/portal/approvals/{id}/respond` | POST | aprova, pede alterações ou rejeita |
| `/portal/approvals/{id}/comment` | POST | comenta (com imagem opcional) |
| `/portal/approvals/{id}/media/{arquivo}` | GET | vê a arte e os anexos da revisão |
| `/portal/notifications/read` | POST | marca os avisos do cliente como lidos |

Regras que valem a pena saber:

- **Fotografia, não link vivo**: no envio, o conteúdo é congelado em
  `approvals.snapshot_json` (arte: formato, medidas, texto, imagem e arquivo
  exportado; pauta: rede, data, objetivo, texto, legenda, CTA e briefing). O
  cliente revisa o que foi enviado, não o que o estúdio tem agora.
- **Uma pendência por conteúdo**, garantida por índice único parcial: dois
  cliques ao mesmo tempo não criam duas revisões. Enviar de novo um conteúdo já
  pendente responde com "Este conteúdo já está aguardando aprovação."
- **Ciclos**: responder encerra o ciclo; um novo envio cria o ciclo 2, 3… com o
  histórico anterior intacto (é o que torna a revisão auditável).
- Só entra em revisão **arte exportada** (`status='exported'` com arquivo) ou
  pauta do planejamento. Rascunho do estúdio não aparece na lista.
- O cliente decide **uma vez**: responder de novo devolve **409** em vez de
  sobrescrever. Pedir alterações exige texto ou imagem anexada.
- **Sessões separadas**: o portal usa `portal_user_id`, o painel usa `user_id`.
  Entrar como cliente não abre a agência; entrar na agência encerra a sessão do
  portal no mesmo navegador. Desativar a conta derruba o acesso na hora, mesmo
  com a sessão aberta.
- **Avisos dentro do produto** (não por e-mail, que cPanel compartilhado não
  entrega): a equipe recebe "Retorno do cliente" e "Novo comentário do cliente";
  o cliente recebe "Novo conteúdo para revisão" e "Novo comentário da agência".
- Entrada do portal tem trava de **10 tentativas por 5 minutos**; a mensagem é
  sempre a mesma para e-mail inexistente e senha errada.
- Mídia só é servida se o arquivo estiver na fotografia daquela revisão ou for
  anexo de um comentário dela, sempre no escopo da empresa (agência) ou da
  empresa + cliente (portal), com `Cache-Control: private, no-store`.
- Escritas deixam evento em `approval_events` e rastro em `audit_log`
  (`approval.sent`, `approval.cancelled`, `portal.user_created`).

O cliente entra por `https://seu-dominio/.../socialai/portal/login` — mande esse
endereço junto com o e-mail e a senha temporária. Ele nunca vê o painel da
agência, nem outros clientes.

### Feed (pronto)

A grade 3×3 do Instagram montada com o que a agência já produziu — para ver a
sequência como o seguidor veria antes de mandar para aprovação.

| Rota | Método | O que faz | Quem pode |
|---|---|---|---|
| `/feed` | GET | perfil da marca, grade 3×3 e fontes fora da grade | todos |
| `/feed/{cliente}/add/creative/{arte}` | POST | coloca uma arte do estúdio no fim da grade | owner, admin, editor |
| `/feed/{cliente}/add/post/{pauta}` | POST | coloca uma pauta do planejamento no fim da grade | owner, admin, editor |
| `/feed/{cliente}/remove/{item}` | POST | tira o item da prévia (o conteúdo original fica) | owner, admin, editor |
| `/feed/{cliente}/reorder` | POST | grava a nova ordem (JSON `{"order":[ids]}`) | owner, admin, editor |

Regras que valem a pena saber:

- **É prévia, não agenda**: nada é publicado nem agendado. Datas editoriais são
  recomendação e o rodapé da tela diz isso explicitamente.
- A ordem é sua, não da data: cada item tem `position`, refeita a cada
  reorganização. Arrastar só muda a tela; gravar é um clique em "Salvar ordem".
- **Reordenar é tudo ou nada**: a lista enviada precisa ser exatamente a lista
  atual. Se outra aba adicionou/removeu algo, a resposta é **409** com "atualize
  a página" — nunca embaralha o que outra pessoa mexeu.
- Arte e pauta convivem na mesma grade com o mesmo formato: arte mostra a imagem
  exportada (ou o rascunho com selo), pauta mostra tema, rede e briefing no bloco
  de texto.
- **Excluir a origem remove o item** (cascata): a prévia nunca aponta para um
  arquivo que não existe mais. Remover da grade, por outro lado, não exclui o
  conteúdo — ele volta para a lista de fontes.
- O mesmo conteúdo não entra duas vezes (índice único por arte/pauta na grade),
  nem em marcas diferentes: a checagem é por empresa + cliente.
- Escritas deixam rastro em `audit_log` (`feed.added`, `feed.removed`,
  `feed.reordered`).

### Relatórios (pronto)

O que a agência **fez** no período, contado a partir dos registros do próprio
produto — e nada além disso.

| Rota | Método | O que faz | Quem pode |
|---|---|---|---|
| `/reports` | GET | recorte por período, números do mês, quebras e registros | todos |
| `/reports/export.csv` | GET | o mesmo recorte em CSV para planilha | todos |

Parâmetros (todos opcionais): `start_date`, `end_date` (AAAA-MM-DD) e
`client_id`.

Regras que valem a pena saber:

- **Não existe métrica de rede aqui.** Alcance, curtidas, impressões e cliques
  só viriam de API de rede social; sem isso, o relatório não mostra — e a tela
  diz isso em um aviso fixo. Um número honesto a menos vale mais que um gráfico
  inventado.
- O recorte é por **data de registro** em UTC (quando o item entrou no produto),
  não pela data editorial da pauta. O padrão é do dia 1º do mês até hoje.
- Período máximo de **366 dias**. `fim` antes de `início` é recusado.
- Recorte inválido **não derruba a tela**: a página volta ao padrão com um aviso
  âmbar. No CSV, o mesmo recorte inválido responde **400** com o motivo em texto
  puro — planilha não deve receber um arquivo pela metade.
- `client_id` de outra empresa → **404** (a marca nem existe para você). Id
  malformado volta com "Escolha um cliente válido." e o relatório completo.
- A lista de registros mostra os **300 mais recentes** e avisa quando cortou; o
  CSV tem o mesmo limite, para tela e planilha contarem a mesma história.
- O CSV sai com `;` (padrão do Excel em português), BOM UTF-8 e apóstrofo antes
  de `=`, `+`, `-`, `@`, tabulação e retorno de carro: texto vindo do banco nunca
  vira fórmula na sua planilha.
- Dois painéis só existem aqui: **Consumo de IA** (`ai_generations` — chamadas,
  modelo e tokens por recurso; rascunho local aparece como `local-draft` com
  zero token) e o card **Na prévia do feed** (`feed_items`).
- Relatório é leitura: **nenhuma tabela nova**, nenhuma auditoria, nenhum dado
  alterado. A versão do esquema continua a mesma do feed (5).

### Meta — conexões e publicação (pronto)

O módulo que fecha o produto: conectar Páginas do Facebook e contas profissionais
do Instagram e publicar, na mão, o que o cliente aprovou. Guia completo em
[`docs/meta.md`](docs/meta.md).

| Rota | Método | O que faz | Quem pode |
|---|---|---|---|
| `/meta` | GET | painel de conexões e evidências | owner/admin |
| `/meta/connect` | POST | inicia o OAuth (state na sessão, 600 s) | owner/admin |
| `/integrations/meta/callback` | GET | troca código por token e grava as Páginas | owner/admin |
| `/meta/{id}/test` | POST | teste de leitura do Graph na Página | owner/admin |
| `/meta/{id}/disconnect` | POST | remove a Página e o token cifrado | owner/admin |
| `/meta/graph-check` | POST | handshake TLS com `graph.facebook.com` | owner/admin |
| `/meta/media/{arquivo}` | GET | imagem assinada que a Meta baixa (sem sessão) | qualquer um com assinatura |
| `/schedule` | GET/POST | agenda interna e inclusão de ciclo aprovado | todos leem; owner/admin/editor escrevem |
| `/schedule/{id}/reschedule` | POST | novo horário (UTC + fuso) | owner/admin/editor |
| `/schedule/{id}/cancel` | POST | cancela mantendo o histórico | owner/admin/editor |
| `/schedule/{id}/publish` | POST | publica **agora** na Página escolhida | owner/admin/editor |
| `/schedule/meta-attempt/{id}/reconcile` | POST | registra a conferência manual de um resultado incerto | owner/admin |

O que vale saber antes de confiar no módulo:

- **Falha fechado.** Sem `META_APP_ID`, `META_APP_SECRET`, `META_REDIRECT_URI`,
  `META_GRAPH_VERSION` e `META_TOKEN_ENCRYPTION_KEY` no `.env`, nenhuma chamada
  é feita e a tela lista o que falta. As chaves estão no `.env.example`.
- **Token cifrado em repouso** (AES-256-GCM, prefixo `v1:`) e nunca exibido.
  Trocar `META_TOKEN_ENCRYPTION_KEY` torna os tokens ilegíveis: é preciso
  reconectar as Páginas.
- **Ler o Graph ≠ poder publicar.** Cada evidência do painel diz o que provou; a
  publicação só fica verde com ID devolvido pela Meta.
- **Publicação é manual** e só depois do horário agendado. Não há robô, cron nem
  agendamento do lado da Meta — e a tela diz isso.
- **Resultado incerto é um estado de primeira classe**: 5xx, timeout ou resposta
  ilegível marcam a tentativa como `unknown`, bloqueiam nova tentativa e pedem
  conferência na conta. Tentativa parada em `publishing` por 10 min vira
  `unknown` sozinha ao abrir a agenda.
- **Facebook** publica foto (com arte aprovada) ou texto; **Instagram** passa por
  contêiner + verificação de estado + `media_publish`, com PNG convertido para
  JPEG temporário. **TikTok e LinkedIn** ficam só como registro interno da
  agenda.
- **Mídia pública assinada**: HMAC com a `SECRET_KEY`, prazo de 10 min, só
  PNG/JPG da pasta de uploads, `noindex`. A rota é pública porque quem busca é a
  Meta.
- **Reconciliação não é confirmação da API.** A conferência humana fica
  registrada e move a fila, mas o relatório continua contando apenas o que a Meta
  respondeu.
- Esquema **6**: as dez tabelas novas entram por migração aditiva; atualizar o
  pacote nunca apaga dados de uma instalação em uso.

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
npm test           # parse de todos os arquivos + 895 verificações funcionais
```

Cobre: normalização de subpasta, `.env`, detecção de caminho, token do
instalador, CSRF, validação de formulário, regras de URL pública (com
subpasta e rejeições), instalação completa, hash da senha, permissão 0600 do
arquivo de segredos, login, logout, dashboard, 404/405, isolamento entre
instâncias e o módulo de clientes (cadastro, edição, cores, upload validado
por assinatura, permissões por papel, exclusão em cascata), planejamento, estúdio
e aprovações (envio com fotografia, ciclos, decisão do cliente, 409 em resposta
dupla, anexos, contas do portal, sessões separadas, mídia restrita, migração
aditiva sobre banco antigo), feed (grade 3×3, fontes, arrastar com 409 quando a
lista muda, remoção sem excluir o original, cascata ao excluir a arte),
relatórios (recorte de período com limite de 366 dias, contagem por registro,
isolamento por empresa, CSV com BOM e proteção contra fórmula, limite de 300
linhas e migração sem tabela nova) e Meta (configuração que falha fechado,
`state` de 600 s consumido uma vez, callback com subpasta, permissões guardadas
por conexão, token cifrado, URL de mídia assinada servida de verdade, agenda com
fuso, publicação real de foto/texto no Facebook e de contêiner + `media_publish`
no Instagram **contra um Graph simulado**, erro 5xx virando resultado incerto,
reconciliação manual com nota mínima, prazo de token, desconexão apagando o token
e preservando o histórico).

O servidor falso do Graph (`tests/fake-graph.mjs`) é o que permite testar
publicação de verdade sem internet: a suíte sobe o processo, escreve o endereço
em `tests/.grafo-falso` e aponta `META_GRAPH_ENDPOINT` para ele. Em produção essa
variável fica vazia.

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

- Todos os módulos do produto estão portados; não há mais "EM BREVE" no painel.
- A publicação automática por agendador continua fora de escopo nas duas
  versões: publicar é uma ação humana em horário vencido.
- Instalação automática por variáveis (`SOCIALMEDIA_AUTO_INSTALL`) entra junto
  com o módulo de configurações.
- O `requirements.txt`/Docker continuam válidos para a versão Python, que
  permanece na raiz do repositório e não foi alterada.
