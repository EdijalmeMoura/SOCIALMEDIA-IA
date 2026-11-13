# Instalador pelo navegador

Caminho de implantação para quem não quer operar SSH nem editar `.env` à mão: a
própria aplicação abre um assistente em `/install` **apenas enquanto a instância
não tem conta** e, ao concluir, grava a configuração privada, cria o proprietário
e fecha a rota para sempre.

## Quando ele aparece

| Situação | `/install` | `/` (anônimo) |
| --- | --- | --- |
| Instância nova (sem conta e sem `installer-state.json`) | 200 | redireciona para `/install` |
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

## Solução de problemas

| Sintoma | Causa provável | Ação |
| --- | --- | --- |
| `/install` responde 410 | já existe conta/registro | use `/login`; para recomeçar, veja acima |
| `/install` responde 403 | `SOCIALMEDIA_INSTALL_TOKEN` definido | abra com `?access_token=…` |
| "Sem permissão de escrita" | volume/diretório somente leitura | ajuste a permissão de `SOCIALMEDIA_DATA` |
| "Valor inválido para …" | caractere não aceito em segredo | use apenas letras, números e `._-:/+@=%,&?~` |
| Login não persiste | proxy sem `X-Forwarded-Proto: https` e cookie `Secure` | encaminhe o header ou reinicie com `COOKIE_SECURE=1` só atrás de HTTPS |
| Rascunho perdido | processo reiniciou antes da confirmação | refaça a etapa 1 |
| Contêiner não sobe após instalar | `SECRET_KEY` persistente ausente e instância já instalada | monte o volume com `installer.env` ou defina `SECRET_KEY` |

## O que o instalador **não** faz

- Não configura DNS, TLS, firewall, proxy reverso ou a revisão do App Meta.
- Não valida a chave da OpenAI nem as credenciais Meta ao vivo (o teste real é
  feito em **Conexões Meta** e no primeiro uso do planejamento/estúdio).
- Não publica conteúdo, não envia e-mails e não cria dados de demonstração.
- Não migra dados de outra instância.
