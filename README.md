# SocialMedia IA

Núcleo inicial do SaaS para agências de social media. A entrega implementa **exclusivamente a Etapa 01**: autenticação, workspaces isolados, clientes, identidade visual, arquivos de referência e permissões de equipe.

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

Os testes criam banco e arquivos temporários e cobrem registro, autenticação/CSRF, criação/edição/exclusão de clientes, identidade de marca, uploads, isolamento entre agências e permissões.

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

## Limites desta entrega

O planejamento com IA, geração de artes, feed, aprovação, agendamento, integrações sociais/pagamentos, relatórios e billing não foram iniciados: fazem parte das próximas etapas e não são apresentados como integrações funcionais nesta versão. SQLite atende ao núcleo local; para escala de produção multi-instância, migrar para PostgreSQL e armazenamento de arquivos externo.
