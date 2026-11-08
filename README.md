# SocialMedia IA

Núcleo incremental do SaaS para agências de social media. As **Etapas 01 a 04** estão implementadas: workspaces isolados, clientes e identidade visual, planejamento editorial com IA, estúdio de criação e preview de feed.

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

Os testes criam banco e arquivos temporários e cobrem autenticação/CSRF, clientes, identidade de marca, isolamento multi-tenant, permissões, planejamento editorial, chamadas de API controladas, editor, versões, uploads e exportação.

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

## Limites desta entrega

Feed preview, aprovação do cliente, publicação/agendamento em redes sociais, integrações Meta/pagamentos, relatórios e billing ainda não foram implementados. Encaminhar um planejamento para produção apenas altera o estado interno e **não** publica conteúdo. SQLite atende ao núcleo local; para escala de produção multi-instância, migrar para PostgreSQL e armazenamento de arquivos externo.
