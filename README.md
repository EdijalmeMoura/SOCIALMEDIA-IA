# SocialMedia IA

Núcleo incremental do SaaS para agências de social media. As **Etapas 01 a 07** estão implementadas: workspaces isolados, clientes e identidade de marca, planejamento editorial com IA, estúdio de criação, preview de feed, aprovação do cliente, agenda interna e relatórios operacionais.

## Requisitos

- Python 3.11+
- Dependências em `requirements.txt`

## Rodar localmente

```bash
python -m venv .venv
source .venv/bin/activate  # Windows: .venv\Scripts\activate
pip install -r requirements.txt
SECRET_KEY="substitua-por-uma-chave-aleatoria-longa" python app.py
```

Abra `http://localhost:5000`. O primeiro acesso começa pela criação de uma conta e agência. O banco SQLite e os uploads ficam em `instance/` (ignorado pelo Git). Para persistência em outro local, configure `SOCIALMEDIA_DB` e `SOCIALMEDIA_DATA`. O servidor usa a porta definida em `PORT` (padrão 5000) e escuta em `0.0.0.0` para permitir preview em sandbox.

### Configuração para produção

- **Obrigatório:** configurar um `SECRET_KEY` aleatório, forte e persistente.
- Servir atrás de HTTPS e configurar `COOKIE_SECURE=1`.
- Usar servidor WSGI de produção (ex.: Gunicorn), proxy reverso e armazenamento persistente/backup para banco e arquivos.
- O limitador simples de tentativas de login está em memória e é voltado a uma instância. Em produção com múltiplos processos/instâncias, substituir por rate limiting compartilhado (Redis ou equivalente).
- A criação de colaborador define uma senha temporária manualmente. Não há envio de convite/e-mail nem recuperação de senha nesta etapa.

## Testes

```bash
python -m unittest discover -s tests -v
```

Os testes criam banco e arquivos temporários e cobrem autenticação/CSRF, clientes, identidade de marca, isolamento multi-tenant, permissões, planejamento editorial, chamadas de API controladas, editor, versões, uploads/exportação, aprovações, notificações internas, agenda com fuso/auditoria, relatórios CSV e o protocolo OAuth/Graph Meta via mocks.

## Etapa 01 implementada

- Criação de conta e agência; senha armazenada com hash PBKDF2 do Werkzeug.
- Sessão assinada, proteção CSRF, cabeçalhos básicos e limitação de tentativas de login por endereço de origem.
- Isolamento por `company_id` em consultas de clientes, equipe e arquivos.
- Papéis `owner`, `admin`, `editor` e `viewer`, com gerenciamento de colaboradores e verificação no servidor.
- Cadastro e edição dos dados do negócio, redes, objetivos comerciais e público.
- Perfil individual com cores, tipografia, estilo, tom, slogan, elementos gráficos e manual da marca.
- Upload de logotipo e referências PNG/JPG/WEBP (máximo 8 MB), com verificação do tipo pelo conteúdo e acesso condicionado ao tenant.
- Exclusão de clientes e limpeza de arquivos relacionados.
- Dashboard inicial responsivo e busca no portfólio.

## Etapa 02 implementada

- Planejamento editorial de 7, 15 ou 30 dias, escolhendo cliente, objetivo, redes, quantidade de publicações e contexto de campanha.
- Inclusão automática do perfil do negócio, produtos, público, objetivos e identidade visual do cliente no contexto da geração.
- Integração real com OpenAI Chat Completions via `OPENAI_API_KEY`; `OPENAI_MODEL` é opcional e usa `gpt-4o-mini` por padrão. Sem chave, a geração fica desabilitada e não cria resultados de demonstração.
- Validação de resposta: quantidade solicitada, redes selecionadas, período e campos editoriais essenciais. O plano e os posts só são persistidos após resposta válida do provedor.
- Calendário editorial visual por semana, com temas, datas e posts detalhados; planos e publicações ficam isolados por agência e cliente.
- Edição de posts e datas dentro do período, duplicação, exclusão, regeneração via API e encaminhamento para o fluxo de produção. Esse estado não publica em redes sociais.
- Auditoria de gerações, modelo e uso de tokens, sem armazenar a chave de API; limite local de 12 chamadas de IA por usuário a cada minuto.

Para habilitar: inicie o servidor com `OPENAI_API_KEY` configurada no ambiente. A chave é lida somente no servidor e enviada ao endpoint oficial do provedor; não a coloque no navegador nem no Git. A chamada pode gerar custos na conta vinculada à chave. Os testes simulam somente a resposta HTTP para não fazer chamadas pagas.

## Etapa 03 implementada

- Estúdio por cliente para gerar título, subtítulo, legenda, hashtags, CTA, briefing, sugestão de imagem e composição com base no assunto e na identidade visual.
- Formatos quadrado (1080×1080), vertical/carrossel/anúncio (1080×1350), Stories (1080×1920), banner (1200×628) e capa (1640×624).
- Geração de imagens reais via OpenAI Images API (`OPENAI_IMAGE_MODEL`, padrão `gpt-image-1`); sem chave, não há imagem simulada. Saídas são recortadas com Pillow para o tamanho exato da prancheta, sem esticar a imagem.
- Editor de prancheta no navegador: edição de textos, cores, tamanho, movimentação/redimensionamento dos elementos, logo do cliente, substituição da imagem e exportação PNG/JPG.
- Salvamento de conteúdo e layout, histórico de versões com restauração e criação de variações via API.
- Arquivos privados e isolados por agência; geração registrada em `ai_generations`; limite local de 12 chamadas de IA por usuário por minuto.

Texto usa `OPENAI_API_KEY` e `OPENAI_MODEL` (padrão `gpt-4o-mini`). Imagens usam a mesma chave e `OPENAI_IMAGE_MODEL` (padrão `gpt-image-1`). Os dados da marca/assunto necessários à criação e o prompt da imagem são enviados ao provedor configurado. A rede do servidor deve permitir acesso a `api.openai.com`. O arquivo final só existe após exportação no editor.

## Etapa 04 implementada

- Feed preview individual por cliente, com grade de três colunas, identidade visual e seleção entre os clientes da agência.
- Inclusão de artes exportadas/rascunhos do estúdio e ideias do planejamento; reordenação por arrastar e salvar ordem no banco.
- Pré-visualização individual de legenda, rede, briefing e data recomendada; remoção do feed não exclui a arte ou post original.
- Isolamento multi-tenant e controle de permissões para reorganizar e administrar a prévia.
- Conteúdos sem arte final são marcados como rascunho/ideia sem arte. Datas são recomendações; nenhum status simula publicação ou agendamento.

## Etapa 05 implementada

- Contas de portal separadas das contas da agência; cada acesso de cliente fica vinculado a uma única marca e tem hash de senha e ativação/desativação pelo workspace.
- Envio de artes exportadas ou publicações planejadas para revisão; a solicitação armazena um snapshot imutável do conteúdo, abre ciclo numerado e bloqueia duplicidade enquanto estiver pendente.
- Portal privado para o cliente revisar, aprovar, rejeitar ou pedir alterações com comentário e referência anexada (PNG/JPG/WEBP até 8 MB).
- Agência e cliente visualizam comentários, referências e histórico de status por ciclo. Solicitações de alteração permitem um novo ciclo preservando o anterior.
- Nenhuma aprovação publica conteúdo. Contas são criadas manualmente e a agência compartilha a senha temporária por canal seguro; não há envio de convites ou notificações por e-mail nesta entrega.

## Etapa 06 implementada

- Fila interna de horários para conteúdo aprovado pelo cliente, com rede e fuso horário IANA; horário convertido e salvo em UTC.
- Somente ciclos aprovados podem ser agendados. Publicações editoriais mantêm a rede que foi aprovada; artes aprovadas podem ser organizadas para as redes suportadas no planejamento.
- Reagendamento e cancelamento preservam eventos de auditoria. Um conteúdo não pode ter dois horários ativos para a mesma rede.
- A agenda não dispara jobs em segundo plano nem publica no horário automaticamente. A ação manual de publicação via Meta foi adicionada posteriormente e está descrita na Etapa 08.

## Etapa 07 implementada

- Relatórios filtráveis por período (até 366 dias) e cliente, com dados isolados por agência.
- Resumo de planejamento, artes criadas/exportadas, aprovações e horários internos, além de quebras por rede e status.
- Exportação CSV com proteção contra fórmulas maliciosas em planilhas; datas de registro são identificadas como UTC.
- Os números são exclusivamente operacionais. Não inferem alcance, impressões, curtidas, cliques ou desempenho de redes sociais desconectadas.

## Limites desta entrega

Publicação automática em redes sociais, métricas de desempenho externo e billing ainda não foram implementados. As ações manuais da Meta descritas abaixo não foram validadas ao vivo neste sandbox; integração e publicação reais dependem de configuração e teste no deploy. Encaminhar um planejamento para produção altera apenas o estado interno. SQLite atende ao núcleo local; para escala de produção multi-instância, migrar para PostgreSQL e armazenamento de arquivos externo.

## Etapa 08 em andamento — fundação Meta e notificações internas

- Notificações dentro do produto para pedidos de revisão, respostas e comentários; destinatários e leitura ficam isolados por agência/conta de portal. Nenhum e-mail é enviado.
- OAuth Meta para descobrir Páginas do Facebook (incluindo paginação/cursors) e contas profissionais do Instagram vinculadas; tokens de Página criptografados com Fernet. Permissões solicitadas são registradas como autorizadas, recusadas ou não confirmadas; escopos explicitamente recusados bloqueiam o início da publicação. Estado não confirmado não garante que a Meta aceitará a ação. Chamadas Graph assinadas com `appsecret_proof`; o teste da tela confirma somente leitura da Página, não permissão de publicação. A plataforma também possui ações manuais via Graph API para publicar um horário vencido e aprovado: feed de texto/foto do Facebook e imagem no Instagram.
- Não existe publicação automática em background. Instagram requer arte exportada e URL pública HTTPS; Meta ainda pode exigir revisão e permissões avançadas.
- Se a resposta da Meta for incerta, owner/admin pode registrar a conferência após verificar diretamente a conta: publicação encontrada mantém a tentativa bloqueada e fora da métrica confirmada pela API; ausência confirmada libera uma nova tentativa e fica auditada.
- O sandbox desta execução não tem saída para os servidores Meta. Testes cobrem protocolo com respostas simuladas, mas autorização e publicação reais **não foram verificadas**; nenhum post foi enviado.
- Antes de conectar, configure `META_APP_ID`, `META_APP_SECRET`, `META_GRAPH_VERSION`, `META_REDIRECT_URI` (exatamente a URL pública `/integrations/meta/callback`) e `META_TOKEN_ENCRYPTION_KEY` no gerenciador de segredos do servidor. Para imagens, configure `META_PUBLIC_BASE_URL` com a origem HTTPS pública que a Meta possa buscar. Gere a chave de criptografia com `.venv/bin/python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"`. Não cole nem versione esses segredos. Configure a mesma chave de criptografia de forma persistente; perder/trocar a chave impede a leitura dos tokens já armazenados.
