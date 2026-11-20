# Módulo Meta — conexões e publicação real

> Pacote: **v1.8** · <https://github.com/EdijalmeMoura/SOCIALMEDIA-IA/releases/tag/php-v1.8>
> (este módulo entrou na v1.7; o pacote atual é o `SOCIALMEDIA-IA-php-v1.8.zip`
> em `php/downloads/` · confira o SHA256 em
> `php/downloads/SHA256SUMS.txt`)

Este módulo liga o SocialMedia IA às Páginas do Facebook e às contas
profissionais do Instagram de cada cliente. Ele faz duas coisas — e só duas:

1. **Conecta** Páginas por OAuth e prova que consegue **ler** cada uma.
2. **Publica** um conteúdo já aprovado pelo cliente, quando alguém clica em
   **Publicar via Meta** num horário que já venceu.

Não existe publicação automática, nem agendamento do lado da Meta. A agenda é
interna: ela organiza o que deve sair, quando e em qual rede. Quem publica é uma
pessoa, na hora, olhando para a tela.

## O que o produto nunca faz

- **Nunca** mostra um token em tela, log ou mensagem de erro. O token da Página
  é cifrado (AES-256-GCM, prefixo `v1:`) antes de ir para o banco.
- **Nunca** repete uma publicação sozinho. Se a resposta da Meta não chegou, o
  resultado é **incerto** e a tela manda conferir a conta — repetir às cegas
  duplicaria o post.
- **Nunca** promete o que não testou: ler o Graph **não** prova permissão de
  publicar, e a tela repete isso em cada evidência.

## Configuração no `.env`

Todas as chaves são obrigatórias, menos as duas últimas:

| Chave | Para que serve |
| --- | --- |
| `META_APP_ID` | ID do app criado em <https://developers.facebook.com/apps/> |
| `META_APP_SECRET` | Segredo do app; também assina o `appsecret_proof` |
| `META_REDIRECT_URI` | URL HTTPS que **termina em `/integrations/meta/callback`** |
| `META_GRAPH_VERSION` | Formato `vNN.N`, igual à versão habilitada no app |
| `META_TOKEN_ENCRYPTION_KEY` | Chave do AES-256-GCM dos tokens das Páginas |
| `META_PUBLIC_BASE_URL` | Origem HTTPS de onde a Meta baixa as artes (opcional quando `SOCIALMEDIA_PUBLIC_ORIGIN` já é o endereço certo) |
| `META_GRAPH_ENDPOINT` | **Só para testes**; em produção deixe vazio |

Gere a chave de criptografia com:

```sh
php -r "echo base64_encode(random_bytes(32));"
```

Duas chaves, se trocadas, quebram coisas sem volta:

- `SECRET_KEY` — assina as URLs de mídia; trocar invalida links em uso.
- `META_TOKEN_ENCRYPTION_KEY` — trocar **torna ilegíveis todos os tokens já
  guardados**. Depois disso, só reconectando cada Página.

Sem configuração completa o módulo **falha fechado**: a tela diz exatamente
quais chaves faltam e nenhuma chamada é feita ao Graph.

### Callback em subpasta

Se a instalação vive em `https://exemplo.com/edijalmemoura/socialai`, o valor é:

```text
META_REDIRECT_URI=https://exemplo.com/edijalmemoura/socialai/integrations/meta/callback
```

O produto compara **host + caminho completo** (com a subpasta), então um
callback sem a subpasta não confere. A tela de conexões mostra se o endereço
declarado bate com o host que você está usando agora.

## Fluxo de conexão (OAuth)

1. A pessoa clica em **Conectar Páginas** (só `owner`/`admin`; editor e leitor
   recebem 403).
2. O produto gera um `state` aleatório, guarda na sessão por **600 s**, e manda
   o navegador para `https://www.facebook.com/vNN.N/dialog/oauth` pedindo cinco
   escopos: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`,
   `instagram_basic`, `instagram_content_publish`.
3. A Meta volta em `/integrations/meta/callback`. O `state` é conferido e
   **consumido** ali (um segundo callback com o mesmo `state` é recusado).
4. Código → token curto → token longo (60 dias). O prazo informado é guardado e
   vira aviso quando se aproxima — é "prazo informado", não garantia.
5. `me/accounts` é lido **paginado**, com limite de 20 páginas e proteção contra
   cursor repetido. Cada Página é gravada com o token cifrado, o Instagram
   vinculado (`instagram_business_account`) e as permissões concedidas.

Se o usuário não tiver nenhuma Página, nada é gravado e a tela explica o
motivo. Se o `code` for recusado pela Meta, também nada é gravado.

## O que a tela de conexões mostra

O painel `/meta` é um painel de **evidências**, separado por etapas — porque
cada uma prova uma coisa diferente:

| Etapa | O que prova |
| --- | --- |
| Configuração | As chaves do `.env` estão completas e o callback bate com o host |
| OAuth | Existe uma Página conectada com token guardado |
| Leitura Graph | O token abre **aquela** Página (teste por conexão) |
| TLS | O servidor consegue abrir TLS com `graph.facebook.com` |
| Mídia pública | Existe uma origem HTTPS para a Meta baixar as artes |
| Publicação real | Existe publicação **confirmada pela API** para Facebook/Instagram |

A última linha só fica verde quando a Meta devolveu um ID de publicação. Antes
disso, ela diz **"Ainda não comprovada"** — mesmo que a leitura esteja ok.

## Agenda interna

`/schedule` lista os ciclos **aprovados pelo cliente** e permite agendar uma
rede por vez. Regras:

- Só ciclo aprovado entra na fila; para o mesmo ciclo e rede existe **um**
  horário ativo (índice único parcial).
- Data/hora são guardadas em **UTC** e exibidas no fuso escolhido (lista fixa,
  padrão `America/Recife`). Horário que não existe pela mudança de fuso é
  recusado com motivo, e o mínimo é 5 minutos à frente.
- Reagendar e cancelar preservam histórico em `schedule_events` e `audit_log`.
- **TikTok e LinkedIn não publicam**: ficam como registro interno da agenda, e a
  tela diz isso.

## Publicação manual

O botão **Publicar via Meta** aparece quando:

- o horário já venceu;
- a configuração Meta está completa e há origem HTTPS para a mídia;
- existe Página compatível com a rede (**sem** permissão marcada como recusada);
- no Instagram, existe arte exportada aprovada.

Antes de qualquer chamada, o produto abre uma tentativa com status
`publishing`. Depois:

| Situação | Status | O que a tela diz |
| --- | --- | --- |
| A Meta devolveu ID | `published` | Confirmado, com o ID |
| 4xx com resposta clara | `failed` | O motivo, e que nada foi publicado |
| 5xx, timeout ou sem resposta | `unknown` | **Incerto** — confira a conta |
| Ficou `publishing` por mais de 10 min | `unknown` | Marcada ao abrir a agenda |

Facebook publica **foto** (quando o ciclo tem arte) ou **texto** no feed.
Instagram passa por contêiner → verificação de estado (3 tentativas, 2 s entre
elas) → `media_publish`. PNG é convertido para JPEG temporário (proporção de
feed 4:5 a 1,91:1, até 8 MB) e a cópia é apagada depois — o arquivo aprovado
nunca é alterado.

Uma segunda tentativa no mesmo horário é recusada: o índice único cobre
`publishing`, `published` e `unknown`. Para repetir depois de uma falha
definitiva, use a agenda após conferir a conta.

## Reconciliação (conferência manual)

Quando o status é `unknown`, `owner`/`admin` podem registrar o que viram na
Meta: **publicação encontrada** (exige o ID real) ou **não encontrada** (exige
que o ID fique vazio). A observação precisa de pelo menos 12 caracteres e cada
tentativa só pode ser conferida uma vez.

A conferência grava em `meta_publication_reconciliations` e move a fila, mas
**não** muda o status `unknown` da tentativa: o relatório continua contando
apenas o que a API confirmou. A tela avisa isso em texto.

## Mídia pública assinada

A Meta precisa **baixar** a imagem de um endereço público. A rota
`/meta/media/{arquivo}` é pública de propósito — quem pede é o servidor da Meta,
não uma pessoa logada. A proteção é:

- HMAC-SHA256 sobre `arquivo|expira`, com a `SECRET_KEY`;
- prazo de 10 minutos (a URL é gerada segundos antes do envio);
- só PNG/JPG da pasta de uploads, sem travessia de caminho;
- `Cache-Control: public, max-age=300` e `X-Robots-Tag: noindex, nofollow`.

Assinatura trocada, prazo vencido, arquivo de outro nome ou arquivo inexistente
respondem **404** em texto puro.

## Tabelas (esquema 6)

| Tabela | Papel |
| --- | --- |
| `scheduled_publications` | A fila interna (UTC + fuso, único por ciclo/rede ativo) |
| `schedule_events` | Histórico de criação, reagendamento e cancelamento |
| `meta_page_connections` | Página conectada, token cifrado e prazo informado |
| `meta_connection_permissions` | As cinco permissões por conexão |
| `meta_connection_checks` | Testes de leitura por conexão |
| `meta_graph_connectivity_checks` | Handshake TLS com o Graph |
| `meta_public_media_checks` | Conferências da origem pública de mídia |
| `meta_token_expiry_notices` | Avisos de prazo já emitidos (um por prazo) |
| `meta_publication_attempts` | Tentativas e status final |
| `meta_publication_reconciliations` | Conferências manuais (uma por tentativa) |

## Como validar depois de configurar

1. `/meta` → as etapas devem ficar verdes na ordem, sem atalhos.
2. **Teste de leitura** em cada Página → "leitura confirmada" (e o aviso de que
   isso não testa publicação).
3. **Handshake TLS** → confirma a saída do servidor para `graph.facebook.com`.
4. `/schedule` → agende um conteúdo aprovado para **5 minutos à frente**, em
   Facebook, com uma arte exportada.
5. Espere o horário e clique em **Publicar via Meta** → a tela precisa voltar
   com "A Meta confirmou a publicação" e o ID.
6. `/meta` → "Publicação real: Confirmada pela API".

Se qualquer etapa falhar, a mensagem diz o que aconteceu — e nada é marcado como
publicado sem ID da Meta.

## Rotina de diagnóstico

| Sintoma | Onde olhar |
| --- | --- |
| "Configure no .env: …" | Falta chave; preencha e recarregue |
| "callback não corresponde" | `META_REDIRECT_URI` sem a subpasta ou com outro host |
| "Nenhuma Página encontrada" | App sem `pages_show_list` aprovado, ou usuário sem Página |
| "A imagem precisa ter uma URL HTTPS" | `META_PUBLIC_BASE_URL`/`SOCIALMEDIA_PUBLIC_ORIGIN` ausente ou HTTP |
| "A extensão cURL do PHP não está ativa" | Habilite `curl` no cPanel (Select PHP Version) |
| "incerto" | Confira a conta na Meta **antes** de qualquer nova tentativa |
