# Roteiro de validação ao vivo da integração Meta

Este roteiro deve ser executado no deploy público que hospedará a aplicação, não nos testes unitários. Os testes do repositório usam mocks e não comprovam OAuth, permissões ou publicação reais.

## 1. Preparar o servidor e o App Meta

- Servir a aplicação por HTTPS, com `SECRET_KEY` forte e persistente e `COOKIE_SECURE=1`.
- No gerenciador de segredos do servidor, configurar `META_APP_ID`, `META_APP_SECRET`, `META_GRAPH_VERSION`, `META_REDIRECT_URI` e `META_TOKEN_ENCRYPTION_KEY`. Não inserir os valores no Git, em tickets ou no chat.
- `META_REDIRECT_URI` deve ser a URL pública exata da rota `/integrations/meta/callback`, cadastrada também nas configurações do App.
- Para publicação de imagens, configurar `META_PUBLIC_BASE_URL` como a origem HTTPS pública da aplicação, sem caminho, barra final, query ou fragmento. A Meta precisa conseguir acessar a URL temporária assinada da imagem.
- Confirmar que o servidor permite conexões HTTPS de saída aos endpoints Facebook/Graph da Meta e que o App está na versão Graph configurada.
- Revisar no painel Meta o login OAuth, as permissões pedidas e o acesso/revisão necessários para os usuários e ativos reais. A indicação `granted` na aplicação registra consentimento OAuth; por si só, não garante App Review, acesso avançado, função na Página ou direito de publicação.

## 2. Conferir OAuth e ativos

1. Entrar como `owner` ou `admin` e abrir **Conexões Meta**.
2. Usar **Conectar com Facebook** e concluir a autorização na Meta. O callback deve retornar à aplicação sem erro e listar as Páginas autorizadas.
3. Conferir os estados de `pages_show_list`, `pages_manage_posts`, `instagram_basic` e `instagram_content_publish`. `recusada` bloqueia a tentativa correspondente; `não confirmada` não significa que a permissão esteja disponível.
4. Conferir a Página e, se aplicável, a conta profissional do Instagram vinculada. O botão **Testar conexão** somente confirma leitura da Página pelo Graph; ele não publica e não prova permissões de escrita.
5. Nunca copiar tokens ou segredos para o navegador, logs, capturas de tela ou mensagens.

## 3. Fazer um teste de publicação controlado

- Usar primeiro um conteúdo de teste de baixo risco em uma Página/conta controlada pela equipe. Não usar conteúdo de cliente sem autorização.
- Preparar a aprovação normal do portal do cliente e um horário que já tenha vencido; a aplicação só oferece o envio manual para conteúdo aprovado e vencido.
- Para Facebook, iniciar com texto. Para foto no Facebook ou imagem no Instagram, exportar a arte e confirmar que `META_PUBLIC_BASE_URL` é alcançável publicamente. Instagram exige imagem exportada e conta profissional vinculada.
- Selecionar a Página/conta correta e clicar explicitamente em **Publicar via Meta**. Esta ação envia o conteúdo real; revisar texto, conta e arte antes de confirmar.
- Conferir o post diretamente na Meta e comparar o ID remoto exibido pela aplicação. Registrar data, rede, conta de teste e resultado para a validação do deploy.
- Se o resultado ficar **incerto**, não clicar para publicar de novo. Conferir a conta Meta primeiro; a reconciliação manual exige `owner`/`admin` e anotação, e uma publicação confirmada manualmente permanece separada das confirmações recebidas pela API.
- Remover ou arquivar o conteúdo de teste diretamente na Meta depois da validação, se apropriado.

## Critérios para considerar o teste ao vivo aprovado

- OAuth retorna à URL pública correta e salva a Página sem expor tokens.
- O teste de leitura Graph confirma a Página esperada.
- A tentativa manual recebe resposta inequívoca da Meta, grava um ID remoto e o post aparece na conta selecionada.
- Se houver imagem, a Meta consegue buscá-la pela URL HTTPS temporária.
- O resultado é registrado com rede, conta e horário; não há envio automático em background.

Uma falha em qualquer critério deixa a integração **não validada ao vivo**. Guardar mensagens de erro sem segredos e verificar permissões e logs do servidor antes de tentar novamente. Em especial, tratar timeout ou resposta ambígua como possível publicação, não como falha segura.
