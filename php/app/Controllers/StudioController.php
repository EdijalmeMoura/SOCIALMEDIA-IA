<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Auth\Authenticator;
use Smia\Http\Request;
use Smia\Http\Response;
use Smia\Studio\Layout;
use Smia\Studio\StudioError;
use Smia\Studio\StudioGenerator;
use Smia\Studio\StudioRepository;
use Smia\Support\Config;
use Smia\Support\Modules;
use Smia\Support\Session;
use Smia\Support\Upload;
use Smia\Support\View;

/**
 * Estúdio: arte pronta a partir de um assunto.
 *
 * Fluxo: gerar o texto (IA ou rascunho local) → escolher a imagem de fundo
 * (IA ou upload) → ajustar no editor → exportar o PNG/JPG final.
 *
 * Cada passo que mexe na arte grava uma versão: nada de trabalho perdido.
 */
final class StudioController
{
    private const PAPEIS_EDICAO = ['owner', 'admin', 'editor'];
    private const ROTULOS = [
        'title' => 'Título',
        'subtitle' => 'Subtítulo',
        'caption' => 'Legenda',
        'hashtags' => 'Hashtags',
        'cta' => 'Chamada para ação',
        'visual_brief' => 'Briefing visual',
        'image_suggestion' => 'Sugestão de imagem',
        'composition' => 'Composição',
        'alt_text' => 'Descrição da imagem',
    ];

    /**
     * Limite de legenda por rede, para o contador da tela.
     *
     * Vem do StudioGenerator para a tela e o prompt usarem o mesmo número —
     * dois limites diferentes seriam pior do que nenhum.
     *
     * @return array<string,array{label:string,limite:int,tags:int}>
     */
    public static function redes(): array
    {
        return StudioGenerator::REDES;
    }

    public function __construct(
        private readonly Config $config,
        private readonly StudioRepository $repo,
        private readonly StudioGenerator $gerador,
        private readonly Upload $upload,
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $empresa = (int) $usuario['company_id'];
        $filtros = [
            'client_id' => (int) ($req->input('cliente') ?? 0),
            'format_key' => (string) ($req->input('formato') ?? ''),
            'status' => (string) ($req->input('status') ?? ''),
        ];

        return $this->render('studio/index', $req, $this->dadosCriacao($usuario, $filtros) + [
            'artes' => $this->repo->artes($empresa, 40, $filtros),
            'form' => [],
            'erro' => $req->input('erro'),
            'aviso' => $this->avisoDaQuery($req),
        ]);
    }

    /**
     * Tudo o que o formulário de criação precisa para se redesenhar.
     *
     * Usado também na tela de erro: quando a validação falha, o formulário
     * volta preenchido em vez de virar uma página só com a mensagem.
     *
     * @param array<string,mixed> $usuario
     * @param array{client_id?:int,format_key?:string,status?:string} $filtros
     * @return array<string,mixed>
     */
    private function dadosCriacao(array $usuario, array $filtros = []): array
    {
        return [
            'clientes' => $this->repo->clientesAtivos((int) $usuario['company_id']),
            'formatos' => Layout::FORMATOS,
            'redes' => StudioGenerator::REDES,
            'tons' => StudioGenerator::TONS,
            'ia_configurada' => $this->gerador->iaConfigurada(),
            'modelo_imagem' => $this->gerador->modeloImagem(),
            'pode_editar' => $this->podeEditar($usuario),
            'filtros' => $filtros + ['client_id' => 0, 'format_key' => '', 'status' => ''],
            'filtros_ativos' => (int) ($filtros['client_id'] ?? 0) > 0
                || (string) ($filtros['format_key'] ?? '') !== ''
                || (string) ($filtros['status'] ?? '') !== '',
        ];
    }

    public function generate(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->telaErro($req, 'Sessão expirada. Revise os dados e envie novamente.', $req->post, 400);
        }

        $empresa = (int) $usuario['company_id'];
        $assunto = Layout::textoCurto($req->input('subject') ?? '', 300);
        $formato = (string) ($req->input('format_key') ?? '');
        $clientId = (int) ($req->input('client_id') ?? 0);
        $pedido = $this->pedidoDaCriacao($req);

        $erros = [];
        if ($clientId <= 0) {
            $erros[] = 'Selecione um cliente.';
        }
        if (Layout::formato($formato) === null) {
            $erros[] = 'Escolha um formato de arte.';
        }
        if (mb_strlen($assunto) < 3) {
            $erros[] = 'Informe um assunto com pelo menos 3 caracteres.';
        }
        if ($erros !== []) {
            return $this->telaErro($req, implode(' ', $erros), $req->post, 400);
        }

        $cliente = null;
        foreach ($this->repo->clientesAtivos($empresa) as $candidato) {
            if ((int) $candidato['id'] === $clientId) {
                $cliente = $candidato;
                break;
            }
        }
        if ($cliente === null) {
            return $this->telaErro($req, 'Cliente não encontrado ou inativo neste workspace.', $req->post, 400);
        }

        try {
            $resultado = $this->gerador->gerarConteudo($cliente, [
                'assunto' => $assunto,
                'formato' => $formato,
                'rede' => $pedido['network'],
                'objetivo' => $pedido['objective'],
                'tom' => $pedido['tone'],
                'referencia' => $pedido['reference'],
            ]);
        } catch (StudioError $erro) {
            return $this->telaErro($req, $erro->getMessage(), $req->post, $this->gerador->iaConfigurada() ? 502 : 503);
        }

        $medida = Layout::formato($formato);
        $marca = trim((string) ($cliente['trade_name'] ?? '')) ?: (string) $cliente['company_name'];
        $corFundo = Layout::corValida((string) ($cliente['primary_color'] ?? ''))
            ? strtolower((string) $cliente['primary_color'])
            : '#3158d8';
        $layout = Layout::padrao($resultado['content'], $medida['width'], $medida['height'], $corFundo, $marca);

        try {
            $id = $this->repo->criar(
                $empresa,
                $clientId,
                (int) $usuario['id'],
                $assunto,
                $formato,
                $medida['width'],
                $medida['height'],
                $resultado['content'],
                $layout,
                $corFundo,
                $this->promptImagem($resultado['content']),
                $resultado['model'],
                $resultado['input_tokens'],
                $resultado['output_tokens'],
                'Texto e layout gerados',
                $pedido,
            );
        } catch (\PDOException $erro) {
            error_log('[SMIA] estúdio: ' . $erro->getMessage());

            return $this->telaErro($req, 'Não foi possível salvar a arte. Tente novamente.', $req->post, 500);
        }

        $this->repo->auditoria($empresa, (int) $usuario['id'], 'studio.created', $marca . ' · ' . $assunto . ' (' . $resultado['modo'] . ')');

        // "Já com imagem": o usuário pediu o pacote completo na criação. A
        // imagem é gerada depois de a arte existir; se falhar (sem chave,
        // provedor fora do ar), a arte continua salva e o editor explica —
        // perder o texto por causa do fundo seria pior.
        if ($pedido['com_imagem'] && $this->gerador->iaConfigurada()) {
            $comImagem = $this->gerarImagemInicial($id, $empresa, $clientId, (int) $usuario['id'], $formato, $medida, $resultado['content']);
            if ($comImagem === null) {
                return Response::redirect($this->config->url('/studio/posts/' . $id . '?created=1&imagem=erro'));
            }

            return Response::redirect($this->config->url('/studio/posts/' . $id . '?created=1&imagem=ok'));
        }

        return Response::redirect($this->config->url('/studio/posts/' . $id . '?created=1&imagem=pedida'));
    }

    /**
     * Lê as escolhas da criação e devolve tudo já dentro do contrato.
     *
     * Campo desconhecido não derruba a criação: vira o padrão. O que importa
     * é nunca gravar texto solto vindo do formulário.
     *
     * @return array{network:string,objective:string,tone:string,reference:string,com_imagem:bool}
     */
    private function pedidoDaCriacao(Request $req): array
    {
        $rede = (string) ($req->input('network') ?? 'generico');
        if (!isset(StudioGenerator::REDES[$rede])) {
            $rede = 'generico';
        }
        $tom = (string) ($req->input('tone') ?? 'marca');
        if (!isset(StudioGenerator::TONS[$tom])) {
            $tom = 'marca';
        }

        return [
            'network' => $rede,
            'objective' => Layout::textoCurto($req->input('objective') ?? '', 300),
            'tone' => $tom,
            'reference' => Layout::textoCurto($req->input('reference') ?? '', 600),
            'com_imagem' => (string) ($req->input('with_image') ?? 'depois') === 'agora',
        ];
    }

    /**
     * Gera a imagem de capa na própria criação.
     *
     * Devolve o nome do arquivo, ou null quando a imagem não saiu (a arte
     * continua válida; o editor mostra o motivo ao abrir).
     *
     * @param array<string,string> $conteudo
     * @param array{label:string,width:int,height:int,image_size:string} $medida
     */
    private function gerarImagemInicial(int $id, int $empresa, int $clientId, int $usuarioId, string $formato, array $medida, array $conteudo): ?string
    {
        $prompt = $this->promptImagem($conteudo);
        try {
            $imagem = $this->gerador->gerarImagem(
                $prompt . ' Sem texto, letras, logotipos ou marca d\'água. Composição organizada com áreas seguras.',
                $formato
            );
            $nome = $this->salvarBytesImagem($imagem['bytes'], $id, $medida['width'], $medida['height']);
            $this->repo->registrarConsumo($empresa, $clientId, $usuarioId, 'studio_image', $imagem['model'], $imagem['input_tokens'], $imagem['output_tokens']);
        } catch (StudioError | \Throwable $erro) {
            error_log('[SMIA] estúdio: imagem inicial não saiu: ' . $erro->getMessage());

            return null;
        }

        $this->repo->atualizar($id, $empresa, $usuarioId, [
            'image_path' => $nome,
            'image_prompt' => $prompt,
        ], 'Imagem gerada na criação');

        return $nome;
    }

    public function editor(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }

        $imagem = (string) ($req->input('imagem') ?? '');
        $aviso = match ($imagem) {
            'ok' => 'Arte criada com a imagem de capa gerada por IA.',
            'erro' => 'A arte foi criada, mas a imagem de capa não saiu. Você pode gerar de novo ou enviar a sua no painel da direita.',
            'pedida' => 'Arte criada. Falta a imagem: gere com IA ou envie a sua no painel da direita.',
            default => $this->avisoDaQuery($req),
        };

        return $this->render('studio/editor', $req, $this->dadosEditor($arte, $usuario) + [
            'aviso' => $aviso,
            'erro' => $req->input('erro'),
        ]);
    }

    public function save(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?erro=' . rawurlencode('Sessão expirada. Salve novamente.')));
        }

        $conteudo = [];
        foreach (Layout::CAMPOS as $campo) {
            $conteudo[$campo] = Layout::textoCurto($req->input($campo) ?? '', 5000);
        }
        foreach (['title', 'caption', 'visual_brief', 'image_suggestion'] as $essencial) {
            if ($conteudo[$essencial] === '') {
                return $this->voltarComErro($arte, 'Os campos ' . implode(', ', array_map(
                    static fn (string $c): string => self::ROTULOS[$c] ?? $c,
                    array_filter(['title', 'caption', 'visual_brief', 'image_suggestion'], static fn (string $c): bool => $conteudo[$c] === '')
                )) . ' são obrigatórios.');
            }
        }

        $corFundo = (string) ($req->input('background_color') ?? '');
        if (!Layout::corValida($corFundo)) {
            $corFundo = Layout::corValida((string) $arte['background_color']) ? (string) $arte['background_color'] : '#3158d8';
        }

        try {
            $layout = Layout::validar((string) ($req->input('layout_json') ?? ''), (int) $arte['canvas_width'], (int) $arte['canvas_height']);
        } catch (StudioError $erro) {
            return $this->voltarComErro($arte, $erro->getMessage());
        }
        // O texto dos campos é a fonte da verdade: o layout recebe de volta o
        // que o usuário digitou (o navegador pode ter enviado valores antigos).
        $textos = ['title' => $conteudo['title'], 'subtitle' => $conteudo['subtitle'], 'cta' => $conteudo['cta']];
        foreach ($layout['elements'] as $indice => $elemento) {
            if (isset($textos[$elemento['id']])) {
                $layout['elements'][$indice]['text'] = $textos[$elemento['id']];
            }
        }

        $prompt = Layout::textoCurto($req->input('image_prompt') ?? '', 4000);
        $pedido = $this->pedidoDaCriacao($req);
        $this->repo->atualizar((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id'], [
            'network' => $pedido['network'],
            'objective' => $pedido['objective'],
            'tone' => $pedido['tone'],
            'reference' => $pedido['reference'],
            'content_json' => json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'layout_json' => json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'background_color' => $corFundo,
            'image_prompt' => $prompt !== '' ? $prompt : (string) $arte['image_prompt'],
            'export_path' => null,
            'status' => 'draft',
        ], 'Edição manual');

        return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?ok=saved'));
    }

    public function generateImage(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarComErro($arte, 'Sessão expirada. Tente novamente.');
        }

        $prompt = Layout::textoCurto($req->input('image_prompt') ?? '', 4000) ?: (string) $arte['image_prompt'];
        if (mb_strlen($prompt) < 10) {
            return $this->voltarComErro($arte, 'Descreva a imagem com pelo menos 10 caracteres.');
        }
        if (!$this->gerador->iaConfigurada()) {
            return $this->voltarComErro($arte, 'Configure OPENAI_API_KEY para gerar imagens. Você pode enviar a sua própria imagem.');
        }

        try {
            $imagem = $this->gerador->gerarImagem(
                $prompt . ' Sem texto, letras, logotipos ou marca d\'água. Composição organizada com áreas seguras.',
                (string) $arte['format_key']
            );
            $nome = $this->salvarBytesImagem($imagem['bytes'], (int) $arte['id'], (int) $arte['canvas_width'], (int) $arte['canvas_height']);
        } catch (StudioError $erro) {
            return $this->voltarComErro($arte, $erro->getMessage());
        }

        $this->repo->atualizar((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id'], [
            'image_path' => $nome,
            'image_prompt' => $prompt,
            'export_path' => null,
            'status' => 'draft',
        ], 'Imagem gerada por IA');
        $this->repo->registrarConsumo((int) $arte['company_id'], (int) $arte['client_id'], (int) $usuario['id'], 'studio_image', $imagem['model'], $imagem['input_tokens'], $imagem['output_tokens']);

        return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?ok=image'));
    }

    public function uploadImage(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarComErro($arte, 'Sessão expirada. Tente novamente.');
        }

        $temporario = null;
        try {
            $enviado = $this->upload->store($_FILES['file'] ?? [], (int) $arte['client_id'], 'studio');
            if ($enviado === null) {
                return Response::redirect($this->config->url('/studio/posts/' . $arte['id']));
            }
            $temporario = $enviado['filename'];
            $bytes = (string) file_get_contents($this->upload->diretorio() . '/' . $temporario);
            $nome = $this->salvarBytesImagem($bytes, (int) $arte['id'], (int) $arte['canvas_width'], (int) $arte['canvas_height']);
        } catch (\InvalidArgumentException $erro) {
            $this->upload->delete($temporario);

            return $this->voltarComErro($arte, $erro->getMessage());
        } catch (StudioError $erro) {
            $this->upload->delete($temporario);

            return $this->voltarComErro($arte, $erro->getMessage());
        }
        $this->upload->delete($temporario);

        $this->repo->atualizar((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id'], [
            'image_path' => $nome,
            'export_path' => null,
            'status' => 'draft',
        ], 'Imagem substituída');

        return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?ok=upload'));
    }

    /** Recebe o PNG/JPG renderizado pelo navegador e marca a arte como exportada. */
    public function export(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::json(['error' => 'Sessão expirada. Recarregue a página e exporte novamente.'], 400)->withSecurityHeaders();
        }

        $temporario = null;
        try {
            $enviado = $this->upload->store($_FILES['file'] ?? [], (int) $arte['client_id'], 'export');
            if ($enviado === null) {
                return Response::json(['error' => 'Arquivo de exportação ausente.'], 400)->withSecurityHeaders();
            }
            $temporario = $enviado['filename'];
            $bytes = (string) file_get_contents($this->upload->diretorio() . '/' . $temporario);
            $extensao = str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? 'png' : 'jpg';
            $nome = 'studio-' . (int) $arte['id'] . '-export-' . bin2hex(random_bytes(10)) . '.' . $extensao;
            $destino = $this->upload->diretorio() . '/' . $nome;
            if (file_put_contents($destino, $bytes) === false) {
                throw new StudioError('Não foi possível salvar a arte exportada.');
            }
            @chmod($destino, 0o644);
        } catch (\InvalidArgumentException | StudioError $erro) {
            $this->upload->delete($temporario);

            return Response::json(['error' => $erro->getMessage() ?: 'Arquivo de exportação inválido.'], 400)->withSecurityHeaders();
        }
        $this->upload->delete($temporario);

        $this->repo->atualizar((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id'], [
            'export_path' => $nome,
            'status' => 'exported',
        ], 'Arte exportada');

        return Response::json([
            'url' => $this->config->url('/studio/files?file=' . rawurlencode($nome)),
            'download' => $this->config->url('/studio/posts/' . $arte['id'] . '/download'),
        ])->withSecurityHeaders();
    }

    public function download(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $arte = $this->repo->arte((int) ($params['id'] ?? 0), (int) $usuario['company_id']);
        $nome = (string) ($arte['export_path'] ?? '');
        if ($arte === null || $nome === '' || !Upload::isSafeName($nome)) {
            return Response::html('', 404);
        }
        $caminho = $this->upload->diretorio() . '/' . $nome;
        if (!is_file($caminho)) {
            return Response::html('', 404);
        }
        $extensao = strtolower((string) pathinfo($nome, PATHINFO_EXTENSION));

        return Response::file($caminho, Upload::mime($nome))
            ->withHeader('Content-Disposition', 'attachment; filename="socialmedia-ia-' . (int) $arte['id'] . '.' . $extensao . '"')
            ->withSecurityHeaders();
    }

    /** Serve imagem/exportação só para a empresa dona do arquivo. */
    public function file(Request $req): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $nome = (string) $req->input('file', '');
        if (!Upload::isSafeName($nome) || basename($nome) !== $nome) {
            return Response::html('', 404);
        }
        $caminho = $this->upload->diretorio() . '/' . $nome;
        if (!$this->repo->arquivoDaEmpresa($nome, (int) $usuario['company_id']) || !is_file($caminho)) {
            return Response::html('', 404);
        }

        return Response::file($caminho, Upload::mime($nome))->withSecurityHeaders();
    }

    public function variation(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarComErro($arte, 'Sessão expirada. Tente novamente.');
        }

        $nota = Layout::textoCurto($req->input('variation_note') ?? '', 400);
        $conteudoAtual = json_decode((string) $arte['content_json'], true) ?: [];
        $contexto = 'Crie uma versão criativa alternativa desta arte. ' . $nota
            . ' Não copie literalmente o texto anterior. Assunto: ' . $arte['subject']
            . '. Título anterior: ' . (string) ($conteudoAtual['title'] ?? '');

        $cliente = $this->repo->arte((int) $arte['id'], (int) $usuario['company_id']);
        try {
            $resultado = $this->gerador->gerarConteudo($cliente ?? [], [
                'assunto' => (string) $arte['subject'],
                'formato' => (string) $arte['format_key'],
                'contexto' => $contexto,
            ]);
        } catch (StudioError $erro) {
            return $this->voltarComErro($arte, $erro->getMessage());
        }

        $marca = trim((string) ($arte['trade_name'] ?? '')) ?: (string) ($arte['company_name'] ?? 'Marca');
        $cor = Layout::corValida((string) ($arte['primary_color'] ?? '')) ? strtolower((string) $arte['primary_color']) : '#3158d8';
        $layout = Layout::padrao($resultado['content'], (int) $arte['canvas_width'], (int) $arte['canvas_height'], $cor, $marca);

        $novoId = $this->repo->criar(
            (int) $arte['company_id'],
            (int) $arte['client_id'],
            (int) $usuario['id'],
            (string) $arte['subject'],
            (string) $arte['format_key'],
            (int) $arte['canvas_width'],
            (int) $arte['canvas_height'],
            $resultado['content'],
            $layout,
            $cor,
            $this->promptImagem($resultado['content']),
            $resultado['model'],
            0,
            0,
            'Variação gerada',
        );
        if ($resultado['modo'] === 'ia') {
            $this->repo->registrarConsumo((int) $arte['company_id'], (int) $arte['client_id'], (int) $usuario['id'], 'studio_variation', $resultado['model'], $resultado['input_tokens'], $resultado['output_tokens']);
        }

        return Response::redirect($this->config->url('/studio/posts/' . $novoId . '?created=1'));
    }

    public function restore(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarComErro($arte, 'Sessão expirada. Tente novamente.');
        }
        $versao = $this->repo->versao((int) ($params['version'] ?? 0), (int) $arte['id'], (int) $arte['company_id']);
        if ($versao === null) {
            return Response::html('', 404);
        }
        $this->repo->restaurar((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id'], $versao);

        return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?ok=restored'));
    }

    /** Cria uma cópia independente da arte (mesma composição, novo histórico). */
    public function duplicate(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $arte = $this->repo->arte((int) ($params['id'] ?? 0), (int) $usuario['company_id']);
        if ($arte === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::html('', 403);
        }

        $novo = $this->repo->duplicar((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id']);
        if ($novo === null) {
            return $this->naoEncontrado($req);
        }
        $this->repo->auditoria((int) $arte['company_id'], (int) $usuario['id'], 'studio.duplicated', (string) $arte['subject']);

        // O editor abre na cópia; voltar à galeria fica a um clique.
        return Response::redirect($this->config->url('/studio/posts/' . $novo . '?ok=duplicated'));
    }

    public function delete(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $arte = $this->repo->arte((int) ($params['id'] ?? 0), (int) $usuario['company_id']);
        if ($arte === null) {
            return $this->naoEncontrado($req);
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::html('', 403);
        }

        $arquivos = $this->repo->arquivos((int) $arte['id'], (int) $arte['company_id']);
        $this->repo->remover((int) $arte['id'], (int) $arte['company_id']);
        foreach ($arquivos as $arquivo) {
            $this->upload->delete($arquivo);
        }
        $this->repo->auditoria((int) $arte['company_id'], (int) $usuario['id'], 'studio.deleted', (string) $arte['subject']);

        return Response::redirect($this->config->url('/studio?ok=deleted'));
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function dadosEditor(array $arte, array $usuario): array
    {
        $empresa = (int) $arte['company_id'];
        $conteudo = json_decode((string) $arte['content_json'], true) ?: [];
        $layout = json_decode((string) $arte['layout_json'], true) ?: ['elements' => []];

        return [
            'arte' => $arte,
            'conteudo' => $conteudo,
            'layout' => $layout,
            'formatos' => Layout::FORMATOS,
            'rotulos' => self::ROTULOS,
            'versoes' => $this->repo->versoes((int) $arte['id'], $empresa),
            'redes' => StudioGenerator::REDES,
            'tons' => StudioGenerator::TONS,
            'limites' => Layout::LIMITES,
            'ia_configurada' => $this->gerador->iaConfigurada(),
            'modelo_imagem' => $this->gerador->modeloImagem(),
            'pode_editar' => $this->podeEditar($usuario),
            'imagem_url' => $arte['image_path'] ? $this->config->url('/studio/files?file=' . rawurlencode((string) $arte['image_path'])) : null,
            'logo_url' => $arte['logo_path'] ? $this->config->url('/clients/file?file=' . rawurlencode((string) $arte['logo_path'])) : null,
        ];
    }

    private function promptImagem(array $conteudo): string
    {
        $prompt = trim(
            (string) ($conteudo['image_suggestion'] ?? '') . ' '
            . (string) ($conteudo['visual_brief'] ?? '') . ' Estilo da marca. Composição '
            . (string) ($conteudo['composition'] ?? '')
        );

        return Layout::textoCurto(
            $prompt . ' Não incluir texto, letras, logotipos nem marcas d\'água. Deixe áreas de respiro para inserir textos posteriormente.',
            4000
        );
    }

    /**
     * Redimensiona para o formato e grava como PNG.
     *
     * GD já vem com o PHP do cPanel. Se a extensão faltar, a imagem original é
     * guardada sem corte e a tela continua funcionando (o navegador desenha no
     * tamanho certo do canvas).
     */
    private function salvarBytesImagem(string $bytes, int $creativeId, int $largura, int $altura): string
    {
        if (strlen($bytes) > Upload::TAMANHO_MAX) {
            throw new StudioError('A imagem passa do limite de 8 MB.');
        }
        $origem = @imagecreatefromstring($bytes);
        if ($origem === false) {
            throw new StudioError('O arquivo não contém uma imagem válida.');
        }
        $larguraOrigem = imagesx($origem);
        $alturaOrigem = imagesy($origem);
        if ($larguraOrigem * $alturaOrigem > 40_000_000) {
            imagedestroy($origem);

            throw new StudioError('A imagem é grande demais para ser processada com segurança.');
        }

        $destino = imagecreatetruecolor($largura, $altura);
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        // Enquadra como ImageOps.fit: cobre o canvas e corta o excesso, centralizado.
        $escala = max($largura / $larguraOrigem, $altura / $alturaOrigem);
        $novaLargura = (int) ceil($larguraOrigem * $escala);
        $novaAltura = (int) ceil($alturaOrigem * $escala);
        $x = (int) floor(($largura - $novaLargura) / 2);
        $y = (int) floor(($altura - $novaAltura) / 2);
        imagecopyresampled($destino, $origem, $x, $y, 0, 0, $novaLargura, $novaAltura, $larguraOrigem, $alturaOrigem);
        imagedestroy($origem);

        $nome = 'studio-' . $creativeId . '-' . bin2hex(random_bytes(12)) . '.png';
        $caminho = $this->upload->diretorio() . '/' . $nome;
        if (!imagepng($destino, $caminho, 7)) {
            imagedestroy($destino);

            throw new StudioError('Não foi possível salvar a imagem processada.');
        }
        imagedestroy($destino);
        @chmod($caminho, 0o644);

        return $nome;
    }

    /** @return array<string,mixed>|Response */
    private function arteDoUsuario(Request $req, array $usuario, array $params): array|Response
    {
        $id = (int) ($params['id'] ?? 0);
        $arte = $this->repo->arte($id, (int) $usuario['company_id']);

        return $arte ?? $this->naoEncontrado($req);
    }

    private function avisoDaQuery(Request $req): ?string
    {
        return match ((string) ($req->input('ok') ?? '')) {
            'saved' => 'Edição salva como uma nova versão.',
            'image' => 'Imagem de fundo atualizada.',
            'upload' => 'Imagem enviada e ajustada ao formato.',
            'restored' => 'Versão restaurada.',
            'created' => 'Conteúdo gerado. Edite os elementos, escolha a imagem e exporte a arte.',
            'duplicated' => 'Cópia criada. Ela é independente da arte original.',
            'imagem' => 'Imagem de capa gerada na criação.',
            'deleted' => 'Criação excluída.',
            default => null,
        };
    }

    private function voltarComErro(array $arte, string $mensagem): Response
    {
        return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?erro=' . rawurlencode($mensagem)));
    }

    /** @return array<string,mixed>|Response */
    private function precisaLogin(): array|Response
    {
        $usuario = $this->auth->user();
        if ($usuario === null) {
            return Response::redirect($this->config->url('/login'));
        }

        return $usuario;
    }

    private function podeEditar(array $usuario): bool
    {
        return in_array((string) $usuario['role'], self::PAPEIS_EDICAO, true);
    }

    private function semPermissao(Request $req, string $mensagem = 'Seu perfil não tem permissão para editar artes.'): Response
    {
        return $this->render('studio/forbidden', $req, ['mensagem' => $mensagem], 403);
    }

    private function naoEncontrado(Request $req): Response
    {
        return $this->render('studio/not_found', $req, [], 404);
    }

    /** Volta ao formulário mantendo o que foi digitado. */
    private function telaErro(Request $req, string $mensagem, array $form, int $status): Response
    {
        $usuario = $this->auth->user();
        $empresa = (int) ($usuario['company_id'] ?? 0);

        return $this->render('studio/index', $req, $this->dadosCriacao($usuario ?? [], []) + [
            'artes' => $this->repo->artes($empresa),
            'form' => $form,
            'erro' => $mensagem,
            'aviso' => null,
        ], $status);
    }

    private function render(string $view, Request $req, array $dados, int $status = 200): Response
    {
        return $this->view->render($view, $dados + [
            'user' => $this->auth->user(),
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'modules' => Modules::all(),
            'active' => 'studio',
            'current_path' => $req->path,
        ], $status)->withSecurityHeaders();
    }
}
