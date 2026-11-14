# Instalar no cPanel (o 403 do anexo)

O `403 Forbidden` do LiteSpeed/Apache significa que **o Python ainda não está
atendendo esse endereço**: a pasta não tem índice e nenhuma aplicação
configurada. A aplicação precisa rodar atrás do servidor web; só subir os
arquivos não basta.

Endereço do exemplo: `https://projetoredesnordeste.com.br/edijalmemoura/socialai/`
→ pasta pública `~/public_html/edijalmemoura/socialai`

Resultado esperado nos dois caminhos: abrir esse endereço mostra a **tela de
instalação** (`Configurar instância · SocialMedia IA`). A raiz redireciona para
`…/edijalmemoura/socialai/install`.

---

## Opção A — "Setup Python App" (recomendado)

1. **cPanel → Setup Python App → Create Application**
   - Python version: **3.11** ou 3.12
   - Application root: `socialai` (fora de `public_html`, ex.: `~/socialai`)
   - Application URL: `projetoredesnordeste.com.br/edijalmemoura/socialai`
   - Application startup file: `passenger_wsgi.py`
2. Envie o projeto para `~/socialai` (Git, gerenciador de arquivos ou `scp`),
   incluindo `app.py`, `installer.py`, `meta_integration.py`,
   `component_map.py`, `requirements.txt`, `templates/`, `static/`.
   Use o `passenger_wsgi.py` de `deploy/cpanel/` (copie para a raiz da
   aplicação).
3. No terminal do cPanel (ou "Run Pip Install"):
   ```sh
   source /home/USUARIO/virtualenv/socialai/3.11/bin/activate
   cd /home/USUARIO/socialai
   pip install -r requirements.txt
   ```
4. **Restart** a aplicação no painel.
5. Abra `https://projetoredesnordeste.com.br/edijalmemoura/socialai/install`
   → a tela de instalação deve aparecer. Conclua o assistente (agência,
   proprietário, senha e a **URL pública com a subpasta**).
6. Depois de instalar, em **Setup Python App → Environment variables**:
   - `SOCIALMEDIA_INSTALL_TOKEN` (antes de divulgar o link)
   - `OPENAI_API_KEY` / `META_*` se for usar
   - `COOKIE_SECURE=1` (HTTPS do cPanel já entrega TLS)

O `passenger_wsgi.py` define `SOCIALMEDIA_BASE_PATH=/edijalmemoura/socialai`
como reserva (mude com `SOCIALMEDIA_SUBPATH`) e grava os dados em
`~/socialai/dados/` (SQLite + uploads + `installer.env`). Faça backup dessa
pasta.

---

## Opção B — CGI puro (sem "Setup Python App")

Quando a hospedagem não oferece Passenger. É mais lento (um processo por
requisição) — serve para subir e configurar; depois migre para a Opção A ou
para um VPS (`docs/deploy-single-instance.md`).

1. Envie o projeto **dentro** de
   `~/public_html/edijalmemoura/socialai/`.
2. Copie para essa mesma pasta os dois arquivos de `deploy/cpanel/`:
   - `.htaccess` (habilita `+ExecCGI`, `DirectoryIndex app.cgi` e reescreve
     `/install`, `/static/...` para `app.cgi/<caminho>`)
   - `app.cgi`
3. Instale as dependências no interpretador usado pelo cPanel
   (**Setup Python App → Add packages**: Flask, Pillow, cryptography, waitress)
   **ou** use um virtualenv e ajuste a 1ª linha do `app.cgi` para
   `#!/home/USUARIO/virtualenv/socialai/3.11/bin/python`.
4. Permissões: `chmod 755 app.cgi` e `755` nas pastas.
5. Abra `https://projetoredesnordeste.com.br/edijalmemoura/socialai/` → a raiz
   redireciona para `/install` e a tela aparece.

> O `.htaccess` **não** bloqueia `app.cgi` (isso reproduziria o 403). Ele
> bloqueia apenas `*.py`, `.env`, `dados/`, `tests/`, `docs/`, `deploy/`,
> `*.db`, `*.log`, `*.md`. Se o `.htaccess` do diretório pai tiver
> `Options -ExecCGI` ou `RemoveHandler .cgi`, é preciso liberar nesta pasta
> (algumas hospedagens exigem abrir um ticket pedindo CGI habilitado).

---

## Se continuar 403

| Verificação | Como conferir |
| --- | --- |
| O `.htaccess` foi enviado? | gerenciador de arquivos → mostrar ocultos |
| `Options +ExecCGI` vale nesta pasta? | `.htaccess` do diretório pai / ticket na hospedagem |
| Módulo CGI ativo? | cPanel → "Select PHP Version" / suporte da hospedagem |
| Permissão do `app.cgi` | precisa ser `0755` (executável) |
| Aplicação Python criada? | Setup Python App lista a URL mapeada |
| Pasta errada | `~/public_html/edijalmemoura/socialai` (não `~/socialai`) |
| Log de erro | cPanel → "Errors" / `~/logs/` (o CGI escreve o traceback lá) |

Se aparecer **500**, o Python executou e falhou: veja o log de erro. Causas
comuns: dependências não instaladas (`ModuleNotFoundError: flask`) ou caminho
do interpretador na 1ª linha do `app.cgi`. Se aparecer **404** apenas em
`/install`, o rewrite do `.htaccess` não está ativo: confira `mod_rewrite` e
`RewriteBase`.

---

## Subpasta: como a aplicação trata isso

O servidor entrega o caminho virtual em `SCRIPT_NAME`; `app.py` reconhece
`SOCIALMEDIA_BASE_PATH` (ou o prefixo repassado via `X-Forwarded-Prefix`) e
monta **todas** as URLs sob `/edijalmemoura/socialai/`. O `app.cgi`
reconstrói esse prefixo quando o CGI recebe `SCRIPT_NAME` terminando em
`/app.cgi` — inclusive quando o Apache junta o caminho (`…/app.cgi/install`)
ou quando o `PATH_INFO` vem vazio.

No assistente, informe a URL pública **com a subpasta**:

```
https://projetoredesnordeste.com.br/edijalmemoura/socialai
```

Assim o callback OAuth fica correto:
`…/edijalmemoura/socialai/integrations/meta/callback`, e
`META_PUBLIC_BASE_URL` (mídia assinada) também aponta para a pasta.

Cenários cobertos por `tests/test_subpath.py`: raiz com e sem `PATH_INFO`,
links e formulários prefixados, cookie de sessão/CSRF no caminho da
subpasta, fluxo completo `/install → review → run → done`, login e dashboard,
callback Meta e execução real do `app.cgi` nos três formatos de ambiente CGI.
