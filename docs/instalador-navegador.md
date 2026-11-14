# Instalador pelo navegador

Caminho de implantação para quem não quer operar SSH nem editar `.env` à mão: a
própria aplicação abre um assistente em `/install` **apenas enquanto a instância
não tem conta** e, ao concluir, grava a configuração privada, cria o proprietário
e fecha a rota para sempre.

## Três modos de instalar

| Modo | Quem dispara | Interação | Quando usar |
| --- | --- | --- | --- |
| **Automático (boot)** | `SOCIALMEDIA_AUTO_INSTALL=1` + variáveis do proprietário | nenhuma | "deploy e esquece": Render, Railway, Fly, Docker, VPS com systemd |
| **Automático (1 clique)** | `/install?auto=1` | 1 clique | variáveis já definidas, mas você quer ver a tela de conclusão |
| **Assistente guiado** | `/install` | 3 telas | instalação local ou quando ninguém preparou o ambiente |

Os três caminhos usam **a mesma validação, o mesmo arquivo `0600`, a mesma
transação de criação da conta e as mesmas garantias**. Não há duas
implementações para divergirem.

### Instalação automática no boot

Defina no painel da hospedagem (nunca no Git):

```sh
SOCIALMEDIA_AUTO_INSTALL=1
SOCIALMEDIA_AGENCY_NAME="Estúdio Aurora"
SOCIALMEDIA_OWNER_NAME="Ana Souza"
SOCIALMEDIA_OWNER_EMAIL="ana@suaagencia.com"
SOCIALMEDIA_OWNER_PASSWORD="uma-senha-forte-e-unica"   # 8+ caracteres, sem senhas óbvias
SOCIALMEDIA_PUBLIC_ORIGIN="https://app.suaagencia.com"  # usada no callback Meta e nos cookies
# opcionais: OPENAI_API_KEY / OPENAI_MODEL / OPENAI_IMAGE_MODEL
# opcionais: META_APP_ID / META_APP_SECRET / META_GRAPH_VERSION
```

O que acontece no primeiro boot:

1. O entrypoint confere se as cinco variáveis existem; se faltar alguma, falha
   com uma mensagem clara (`SOCIALMEDIA_AUTO_INSTALL=1 requires: …`).
2. A aplicação gera `SECRET_KEY` (e a chave Fernet da Meta, se configurada),
   grava o arquivo privado `0600`, cria agência + proprietário em uma
   transação e registra a instalação.
3. Reinícios seguintes detectam a instalação, **não recriam nada** e logam
   `Automatic installation skipped: this instance is already installed.`
4. Falhas são **não fatais**: o app sobe e `/install` continua disponível para
   concluir manualmente. Nada fica pela metade.
5. Depois de instalado, **remova `SOCIALMEDIA_OWNER_PASSWORD`** do serviço. O
   hash já está no banco; a variável não é usada de novo (e o log lembra isso).

Com uma variável ausente o boot **não** quebra no modo Docker: o entrypoint
rejeita e explica. Sem `SOCIALMEDIA_AUTO_INSTALL`, nada muda.

### Um clique em `/install`

Se as variáveis estiverem completas, a tela do assistente mostra o botão
**Instalar automaticamente** (ou abra `/install?auto=1`). Ele reaproveita a
mesma rotina e cai na tela de conclusão com o resumo — útil para conferir
callback Meta, arquivo de configuração e próximos passos sem preencher nada.
Variáveis incompletas mostram a tela "Faltam dados no servidor" com a lista do
que falta, sem criar conta. Se `SOCIALMEDIA_INSTALL_TOKEN` estiver definido, o
token também é exigido nesse caminho.

## Quando o assistente aparece

| Situação | `/install` | `/` (anônimo) |
| --- | --- | --- |
| Instância nova (sem conta e sem `installer-state.json`) | 200 | redireciona para `/install` |
| Instância criada no boot pelas variáveis | 410 | redireciona para `/login` com nota "Instância instalada" |
| Instância instalada | 410 (encerrado) | redireciona para `/login` |
| Banco já tem usuários, sem registro do instalador | 410 | redireciona para `/login` |

O instalador não substitui o cadastro normal (`/register`) nem o login: ele
existe para o **primeiro** acesso de uma instância recém-publicada.

## Fluxo

1. **Ambiente e workspace** (`GET /install`): a página executa verificações
   reais e mostra o resultado — escrita no diretório de dados, estado do
   SQLite, existência de configuração anterior, HTTPS detectado e se há token
   de instalação configurado. Depois, o formulário pede:
   - nome da agência, nome e e-mail do proprietário e senha (mínimo 8
     caracteres, com bloqueio de senhas óbvias);
   - URL pública da instância (pré-preenchida com a origem detectada);
   - opcionalmente a chave da OpenAI e os modelos;
   - opcionalmente App ID, App Secret e versão do Graph da Meta.
2. **Revisão** (`POST /install/validate` → `GET /install/review`): **nada é
   gravado** nessa etapa. Os dados ficam em memória do processo e a tela mostra
   segredos mascarados (a senha nunca, a chave da OpenAI e o App Secret apenas
   com o final visível). Também exibe o callback OAuth calculado
   (`<origem>/integrations/meta/callback`) e o caminho do arquivo privado.
3. **Confirmação** (`POST /install/run`): grava a configuração, cria a conta em
   uma única transação e encerra o instalador.
4. **Conclusão** (`GET /install/done`): resumo do que ficou configurado, o que
   ainda depende de você (HTTPS, revisão do App Meta) e o link para o login.

## O que é gravado

| Arquivo | Conteúdo | Permissão |
| --- | --- | --- |
| `$SOCIALMEDIA_DATA/installer.env` | `SECRET_KEY`, `COOKIE_SECURE`, `OPENAI_*`, `META_*` | `0600` |
| `$SOCIALMEDIA_DATA/installer-state.json` | registro da instalação (sem segredos) | `0600` |
| `$SOCIALMEDIA_DATA/socialmedia.db` | agência + usuário proprietário | — |

- `SECRET_KEY`: 48 bytes aleatórios (`secrets.token_urlsafe`). É gerado uma vez
  e reutilizado em todos os reinícios. Trocar esse valor invalida sessões e
  links assinados.
- `META_TOKEN_ENCRYPTION_KEY`: chave Fernet gerada apenas quando a Meta é
  configurada. Perdê-la impede abrir tokens Meta já salvos.
- A senha do proprietário é gravada só como hash PBKDF2 (Werkzeug). Ela não
  aparece no arquivo, no estado, nos logs ou na página.
- Nenhum segredo é devolvido ao navegador depois de enviado, e todas as
  respostas do instalador vão com `Cache-Control: private, no-store` e
  `X-Robots-Tag: noindex, nofollow`.

### Precedência da configuração

O arquivo gerado é apenas **fallback**: variáveis de ambiente reais sempre
vencem. Para sobrescrever algo, defina a variável no servidor/orquestrador e
reinicie. O caminho do arquivo pode ser alterado com
`SOCIALMEDIA_INSTALLER_ENV` (padrão: `$SOCIALMEDIA_DATA/installer.env`).
`SOCIALMEDIA_ENV_FILE` continua válido e aponta para um arquivo de segredos já
existente, que o instalador reaproveita (ex.: env file do Docker).

Somente as chaves da lista `CONFIG_ENV_KEYS` (`installer.py`) são lidas de
volta; linhas desconhecidas, valores com espaços ou aspas são ignorados, então
um arquivo adulterado não injeta configuração arbitrária no processo.

### Arquivo `.env` na raiz do projeto

Além do `installer.env` gerado, a aplicação lê um `.env` opcional **na mesma
pasta do `app.py`** (ou no caminho de `SOCIALMEDIA_DOTENV`) no momento do boot,
antes de qualquer configuração ser lida. Ele é útil no cPanel/VPS, onde o mais
natural é editar um arquivo em vez de mexer no painel.

Ordem de precedência, da mais forte para a mais fraca:

1. variáveis reais do ambiente (cPanel, Docker, systemd);
2. `.env` ao lado do código;
3. `$SOCIALMEDIA_DATA/installer.env` gravado pelo instalador.

Como só vale o que ainda não existe no ambiente, um `.env` nunca sobrescreve
uma variável definida pelo painel da hospedagem. O formato aceito é
`CHAVE=valor`, com `#` para comentários, `export` opcional e aspas opcionais.
Use `chmod 600 .env` e mantenha o arquivo fora do Git (`.env` já é ignorado).

```sh
# ~/socialai/.env
SOCIALMEDIA_BASE_PATH=/edijalmemoura/socialai
SOCIALMEDIA_PUBLIC_ORIGIN=https://projetoredesnordeste.com.br/edijalmemoura/socialai
SECRET_KEY=<48+ caracteres aleatórios>
META_TOKEN_ENCRYPTION_KEY=<chave Fernet>
COOKIE_SECURE=1
SOCIALMEDIA_INSTALL_TOKEN=<token para abrir /install>
```

## Proteção do primeiro acesso

Enquanto não existe conta, quem abrir a URL pode instalar. Duas defesas:

1. **Instale imediatamente após publicar** (o assistente leva menos de dois
   minutos).
2. **Use token**: defina `SOCIALMEDIA_INSTALL_TOKEN` com um valor aleatório
   longo e abra `/install?access_token=…`. Sem o token, o instalador responde
   403 e o formulário de acesso pede o valor. O token é comparado em tempo
   constante, com limite de 10 tentativas por 5 minutos por origem. Mesmo com
   token correto, a rota fecha depois de instalada.

```sh
# gere e guarde o token
python -c 'import secrets; print(secrets.token_urlsafe(32))'
```

Se você só publica o endereço depois de instalar (por exemplo, instalando em
`localhost` via túnel), o token é opcional.

## Como instalar em cada caminho

### Local

```sh
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python app.py
# abra http://localhost:5000 — a raiz redireciona para /install
```

### Docker Compose (instância única)

```sh
docker compose -f compose.yaml up -d --build
# abra http://127.0.0.1:5000/install (via túnel/proxy se estiver remoto)
```

No contêiner, `SOCIALMEDIA_ENV_FILE` não precisa existir: o arquivo de ambiente
no `compose.yaml` é opcional. Sem `SECRET_KEY` configurado, o entrypoint entra
em **modo instalador**: usa um segredo efêmero só para o processo de bootstrap,
avisa no log e libera `/install`. Depois da instalação, o `SECRET_KEY` persistente
do arquivo privado assume o controle; qualquer reinício em uma instância já
instalada **sem** `SECRET_KEY` persistente falha fechado, como antes.

`COOKIE_SECURE=1` continua obrigatório nesse caminho (o Compose já define).

### cPanel / hospedagem compartilhada (app dentro de uma subpasta)

Quando o endereço é `https://seu-dominio.com.br/edijalmemoura/socialai/`, a
aplicação **é servida dentro de uma pasta**: o servidor web (LiteSpeed/Apache
do cPanel) entrega o prefixo em `SCRIPT_NAME`/`X-Forwarded-Prefix` e a app
precisa montar todas as URLs sob essa pasta. Um `403 Forbidden` nesse endereço
significa apenas que nenhuma aplicação Python está atendendo a pasta — só
enviar os arquivos não resolve. O roteiro completo (Passenger e CGI) está em
[`deploy/cpanel/INSTALAR.md`](../deploy/cpanel/INSTALAR.md).

```sh
# variável de ambiente da aplicação no cPanel (Setup Python App → Environment variables)
SOCIALMEDIA_BASE_PATH=/edijalmemoura/socialai
```

Com ela, `app.py` ativa o `ProxyFix`, define `APPLICATION_ROOT` e normaliza a
requisição raiz, de modo que:

- `GET /edijalmemoura/socialai/` → `302` para `…/socialai/install`
  (a tela de instalação aparece em vez do 403);
- CSS, JS e todos os formulários saem com o prefixo (`…/socialai/static/app.css`);
- o cookie de sessão e o CSRF do instalador usam o caminho da subpasta;
- o callback Meta vira `…/socialai/integrations/meta/callback`.

No assistente, informe a **URL pública com a subpasta** — é ela que alimenta
`META_REDIRECT_URI` e `META_PUBLIC_BASE_URL`:

```
https://projetoredesnordeste.com.br/edijalmemoura/socialai
```

Se o ambiente já injeta `X-Forwarded-Prefix` (Passenger e alguns proxies
fazem isso sozinhos), `SOCIALMEDIA_BASE_PATH` pode ficar vazio: o prefixo
repassado é respeitado do mesmo jeito.

## Escolhas de segurança (e seus limites)

- **Um processo só**: os rascunhos da revisão e o ticket da tela final ficam em
  memória. Não rode o instalador com múltiplos workers; para este produto o
  deploy documentado é instância única com Waitress.
- **Sem escrita antes da confirmação**: as chaves digitadas na etapa 1 só vão
  para o disco no `POST /install/run`.
- **Tudo ou nada**: se a criação da conta falhar, o arquivo de configuração é
  removido e o instalador continua disponível (nada fica "meio instalado").
- **CSRF próprio**: o assistente usa um token assinado com `SECRET_KEY`, `HttpOnly`,
  `SameSite=Lax`, restrito ao caminho `/install`, sem depender de cookies de
  sessão — justamente porque ainda não existe usuário.
- **Sem HTTPS pelo instalador**: TLS continua sendo responsabilidade do proxy
  reverso. A página detecta `X-Forwarded-Proto` para marcar cookies como `Secure`
  e avisa quando a conexão não é segura.
- **Sem recuperação de senha**: se você perder a senha do proprietário, a
  recuperação é operacional (outro owner/admin em `/team` ou reset no servidor).
- **Backup obrigatório**: o volume de dados sozinho não reconstrói sessões e
  tokens Meta. Inclua `installer.env` no backup seguro, separado do volume.

## Reabrir ou refazer a instalação

- **Reconfigurar segredos**: edite `installer.env` (ou defina variáveis de
  ambiente) e reinicie. O instalador **não** reabre — isso é intencional.
- **Começar do zero**: com backup antes, pare a aplicação e remova o banco,
  `installer-state.json`, `installer.env` e os uploads. Sem conta e sem
  registro, `/install` volta a responder 200.
- **Instância antiga (contas criadas em `/register`)**: o instalador se fecha
  sozinho (410). Continue pela configuração manual de variáveis de ambiente.

## Verificação rápida

```sh
curl -s https://seu-dominio/health
# {"status":"ok","module":"stage-08-foundation","database":"sqlite",
#  "installation":{"automatic":"installed","pending":false,"recorded":true}}
```

`installation.automatic` mostra como o boot se comportou (`installed`,
`already_installed`, `skipped`, `failed`); `pending: true` significa que ainda
não há workspace e `/install` segue aberto.

## Solução de problemas

| Sintoma | Causa provável | Ação |
| --- | --- | --- |
| `/install` responde 410 | já existe conta/registro | use `/login`; para recomeçar, veja acima |
| `/install` responde 403 | `SOCIALMEDIA_INSTALL_TOKEN` definido | abra com `?access_token=…` |
| "Sem permissão de escrita" | volume/diretório somente leitura | ajuste a permissão de `SOCIALMEDIA_DATA` |
| "Valor inválido para …" | caractere não aceito em segredo | use apenas letras, números e `._-:/+@=%,&?~` |
| Login não persiste | proxy sem `X-Forwarded-Proto: https` e cookie `Secure` | encaminhe o header ou reinicie com `COOKIE_SECURE=1` só atrás de HTTPS |
| Rascunho perdido | processo reiniciou antes da confirmação | refaça a etapa 1 |
| `SOCIALMEDIA_AUTO_INSTALL=1 requires: …` no log | falta variável obrigatória | defina as cinco variáveis e reinicie, ou remova a flag e use `/install` |
| `/install?auto=1` responde 409 | variáveis incompletas ou valor rejeitado | veja a lista na própria tela; corrija no servidor |
| Instalação automática "skipped": já instalado | conta/registro existentes | esperado; sobrescrever exige remover banco + `installer.env` + `installer-state.json` |
| Senha do proprietário não funciona | `SOCIALMEDIA_OWNER_PASSWORD` mudou depois do boot | a senha vale só na **criação**; redefina por `/team` (outro admin) ou recomeçe do zero |
| Contêiner não sobe após instalar | `SECRET_KEY` persistente ausente e instância já instalada | monte o volume com `installer.env` ou defina `SECRET_KEY` |

## Segurança do modo automático

- A senha vira hash imediatamente e **não** é gravada em `installer.env`, no
  `installer-state.json`, em logs ou em telas.
- As variáveis do proprietário são lidas apenas no boot; depois da instalação
  elas não têm efeito (a rota `/install` está fechada).
- O arquivo gerado continua `0600` e com precedência invertida: variáveis de
  ambiente vencem o arquivo.
- Fail-closed preservado: instância já instalada sem `SECRET_KEY` persistente
  recusa subir (o arquivo privado é o segredo).
- Se a senha vazar no painel da hospedagem, troque-a em `/team` com outro
  owner/admin — a variável não reabre o instalador.

## O que o instalador **não** faz

- Não configura DNS, TLS, firewall, proxy reverso ou a revisão do App Meta.
- Não valida a chave da OpenAI nem as credenciais Meta ao vivo (o teste real é
  feito em **Conexões Meta** e no primeiro uso do planejamento/estúdio).
- Não publica conteúdo, não envia e-mails e não cria dados de demonstração.
- Não migra dados de outra instância.
