# Deploy de instância única

Este caminho Docker é para uma **instância única** com SQLite e armazenamento persistente. Não use réplicas/escala horizontal com este volume SQLite; para multi-instância, migre banco e arquivos para serviços compartilhados antes.

## Construir e iniciar

1. Construa a imagem na raiz do repositório:

   ```sh
   docker build -t socialmedia-ia:latest .
   ```

2. Crie valores fortes e persistentes no gerenciador de segredos do host. Gere `SECRET_KEY` com `python -c 'import secrets; print(secrets.token_urlsafe(48))'`. Para Meta, gere `META_TOKEN_ENCRYPTION_KEY` com o comando Fernet descrito no README. **Não gere novamente essas chaves a cada reinício**: trocar `SECRET_KEY` invalida sessões e links assinados; trocar a chave Fernet impede abrir tokens Meta existentes.

3. Publique somente atrás de um proxy HTTPS, configure o domínio/certificado no proxy e passe ao contêiner a mesma origem pública. Materialize as variáveis em `/run/secrets/socialmedia.env` com permissões restritas (por exemplo, `0600`), nunca no Git. Exemplo de execução:

   ```sh
   docker run -d --name socialmedia-ia \
     --restart unless-stopped \
     -p 127.0.0.1:5000:5000 \
     -v socialmedia-data:/data \
     --env-file /run/secrets/socialmedia.env \
     socialmedia-ia:latest
   ```

   `META_*` pode ficar ausente enquanto a integração Meta não for usada; `SECRET_KEY` e `COOKIE_SECURE=1` são exigidos pelo entrypoint de produção. Não exponha diretamente a porta HTTP do contêiner à internet. O proxy deve encaminhar para `127.0.0.1:5000`, servir HTTPS e repassar corretamente o host original.

4. Monte sempre o volume persistente `/data` e faça backup coordenado do SQLite e dos uploads. Use `scripts/backup-data.sh /caminho/seguro` para criar um arquivo `.tar.gz`: o script para temporariamente o serviço, arquiva o volume inteiro em ponto consistente e reinicia o serviço mesmo se o arquivamento falhar. É necessário Docker Compose e acesso à imagem auxiliar `alpine:3.21.3`. Trate os arquivos como dados sensíveis, armazene cópias fora do host e mantenha um cronograma de retenção; a pasta padrão `backups/` fica ignorada pelo Git. Faça um exercício de restauração em volume isolado antes de depender do backup. As chaves `SECRET_KEY` e `META_TOKEN_ENCRYPTION_KEY` devem ser armazenadas em backup seguro separado: o volume de dados sozinho não basta para recuperar sessões/links e tokens Meta criptografados. A imagem executa como usuário não root e inicia Waitress com quatro threads. O health check em `/health` confirma uma consulta SQLite e retorna HTTP 503 se o banco estiver indisponível.

Para restaurar um arquivo confiável, pare o serviço e extraia-o sobre o volume persistente (esta operação substitui os dados atuais; faça outro backup antes):

```sh
ARCHIVE=/caminho/seguro/socialmedia-ia-AAAAMMDDTHHMMSSZ.tar.gz
ARCHIVE_DIR=$(dirname "$ARCHIVE")
ARCHIVE_NAME=$(basename "$ARCHIVE")
docker compose -f compose.yaml stop web
docker run --rm \
  --mount type=volume,source=socialmedia-ia-data,target=/data \
  --mount "type=bind,source=$ARCHIVE_DIR,target=/backup,readonly" \
  -e ARCHIVE_NAME="$ARCHIVE_NAME" alpine:3.21.3 \
  sh -c 'find /data -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && tar -xzf "/backup/$ARCHIVE_NAME" -C /data'
docker compose -f compose.yaml start web
```

Se a extração falhar, mantenha o serviço parado e investigue antes de iniciar. Nunca restaure arquivos recebidos de fonte não confiável.

## Variante Docker Compose

`compose.yaml` aplica o mesmo modo de instância única, volume nomeado persistente, bind local em `127.0.0.1:5000`, remoção de capabilities e limite de logs. Antes de iniciar, o gerenciador de segredos deve materializar `/etc/socialmedia-ia/socialmedia.env` com `SECRET_KEY` e, quando for ativar a Meta, as variáveis `META_*`; proteja o arquivo (`0600`) e não o versione. Então:

```sh
docker compose -f compose.yaml up -d --build
docker compose -f compose.yaml ps
```

Configure o proxy HTTPS do host para encaminhar a `127.0.0.1:5000`. Não escale este serviço para réplicas usando SQLite.

## Depois do deploy

- Confirme `https://seu-dominio/health` e que o health check do contêiner está saudável.
- Configure o endereço de callback exato `https://seu-dominio/integrations/meta/callback` também no App Meta.
- Abra **Conexões Meta**. **Testar saída** comprova somente handshake TLS do servidor com `graph.facebook.com`; **Testar origem** comprova somente que o servidor buscou o JPEG assinado. Nenhum dos dois valida OAuth ou acesso da rede da Meta.
- Siga [o roteiro de validação ao vivo Meta](meta-live-validation.md) para consentimento real, permissões e publicações manuais controladas.

O Dockerfile não configura domínio, proxy TLS, DNS, firewall de saída, aprovação do App Meta nem segredos do provedor. Esses itens precisam ser configurados no ambiente de hospedagem.
