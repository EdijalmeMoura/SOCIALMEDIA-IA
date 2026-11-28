<?php

declare(strict_types=1);

namespace Smia\Controllers;

use Smia\Admin\SettingsRepository;
use Smia\Approvals\ApprovalError;
use Smia\Approvals\ApprovalRepository;
use Smia\Approvals\PortalRepository;
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
        private readonly ?SettingsRepository $ajustes = null,
        // Aprovação e reescrita são opcionais no construtor: um teste que só
        // edita a prancheta não deve ser obrigado a montar os dois repositórios.
        private readonly ?ApprovalRepository $aprovacoes = null,
        private readonly ?PortalRepository $portal = null,
        private readonly ?StudioGenerator $geradorTexto = null,
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
            'q' => trim((string) ($req->input('q') ?? '')),
            'ordenar' => (string) ($req->input('ordenar') ?? 'recentes'),
        ];
        $porPagina = 24;
        $total = $this->repo->contarArtesFiltradas($empresa, $filtros);
        // Página fora do intervalo cai na última existente: /studio?pagina=99
        // mostrando "nenhuma arte" faria a pessoa achar que perdeu tudo.
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($paginas, max(1, (int) ($req->input('pagina') ?? 1)));

        return $this->render('studio/index', $req, $this->dadosCriacao($usuario, $filtros) + [
            'artes' => $this->repo->artes($empresa, $porPagina, $filtros + ['pagina' => $pagina]),
            'total_artes' => $total,
            'contagens' => $this->repo->contarPorStatus($empresa),
            'pagina' => $pagina,
            'paginas' => $paginas,
            'por_pagina' => $porPagina,
            'form' => $this->formComPreferencias($empresa),
            // O que foi digitado volta na tela de erro em vez de sumir.
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
            'filtros' => $filtros + ['client_id' => 0, 'format_key' => '', 'status' => '', 'q' => '', 'ordenar' => 'recentes'],
            'filtros_ativos' => (int) ($filtros['client_id'] ?? 0) > 0
                || (string) ($filtros['format_key'] ?? '') !== ''
                || (string) ($filtros['status'] ?? '') !== ''
                || trim((string) ($filtros['q'] ?? '')) !== '',
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
        $pedido = $this->pedidoDaCriacao($req, $empresa);

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

        // Hashtags/assinatura da agência entram antes do layout: a arte já
        // nasce com o texto completo (o saving usa o content_json).
        $resultado['content'] = $this->completarLegenda($resultado['content'], $empresa);

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
     * Formulário de criação já preenchido com o padrão da agência.
     *
     * A pessoa pode trocar em cada arte; isto só evita repetir "Instagram,
     * tom direto" em toda criação nova.
     *
     * @return array<string,string>
     */
    private function formComPreferencias(int $companyId): array
    {
        if ($this->ajustes === null) {
            return [];
        }
        $prefs = $this->ajustes->preferencias($companyId, StudioGenerator::REDES, StudioGenerator::TONS);

        return [
            'network' => $prefs['rede'],
            'tone' => $prefs['tom'],
        ];
    }

    /**
     * Hashtags e assinatura que a agência configurou (Configurações →
     * Preferências).
     *
     * Ficaram guardadas desde a v1.17 e nunca chegavam à arte: a tela prometia
     * "entram na legenda das artes novas" e o texto era gravado sem elas.
     *
     * @return array{tags:string,assinatura:string}
     */
    private function padroesDeLegenda(int $companyId): array
    {
        if ($this->ajustes === null || $companyId <= 0) {
            return ['tags' => '', 'assinatura' => ''];
        }
        $prefs = $this->ajustes->preferencias($companyId, StudioGenerator::REDES, StudioGenerator::TONS);

        return ['tags' => (string) $prefs['tags'], 'assinatura' => (string) $prefs['assinatura']];
    }

    /**
     * Acrescenta hashtags e assinatura padrão ao texto gerado.
     *
     * Não sobrescreve o que a IA (ou o rascunho local) já escreveu: só completa
     * o que está vazio e evita repetir a mesma tag. O que a agência cadastrou
     * é a "letra miúda" dela — e agora aparece.
     *
     * @param array<string,string> $conteudo
     * @return array<string,string>
     */
    private function completarLegenda(array $conteudo, int $companyId): array
    {
        $padroes = $this->padroesDeLegenda($companyId);

        if ($padroes['tags'] !== '' && trim((string) ($conteudo['hashtags'] ?? '')) === '') {
            $tags = [];
            foreach (preg_split('/[\s,]+/', $padroes['tags']) ?: [] as $tag) {
                $tag = trim($tag);
                if ($tag === '') {
                    continue;
                }
                $tags[] = $tag[0] === '#' ? $tag : '#' . $tag;
            }
            $conteudo['hashtags'] = implode(' ', array_slice(array_unique($tags), 0, 12));
        }

        if ($padroes['assinatura'] !== '') {
            $legenda = (string) ($conteudo['caption'] ?? '');
            if (!str_contains($legenda, $padroes['assinatura'])) {
                $conteudo['caption'] = Layout::textoCurto($legenda . "\n\n" . $padroes['assinatura'], 5000);
            }
        }

        return $conteudo;
    }

    /**
     * Lê as escolhas da criação e devolve tudo já dentro do contrato.
     *
     * Campo desconhecido não derruba a criação: vira o padrão. O que importa
     * é nunca gravar texto solto vindo do formulário.
     *
     * @return array{network:string,objective:string,tone:string,reference:string,com_imagem:bool}
     */
    private function pedidoDaCriacao(Request $req, int $companyId = 0): array
    {
        // Sem escolha explícita no formulário, valem as preferências da
        // agência (Configurações → Preferências); sem elas, o padrão do
        // produto. É o que faz a preferência deixar de ser enfeite.
        $padrao = $companyId > 0 && $this->ajustes !== null
            ? $this->ajustes->preferencias($companyId, StudioGenerator::REDES, StudioGenerator::TONS)
            : ['rede' => 'instagram', 'tom' => 'marca'];

        $rede = (string) ($req->input('network') ?? '');
        if (!isset(StudioGenerator::REDES[$rede])) {
            $rede = $padrao['rede'];
        }
        $tom = (string) ($req->input('tone') ?? '');
        if (!isset(StudioGenerator::TONS[$tom])) {
            $tom = $padrao['tom'];
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

        $dados = $this->dadosEditor($arte, $usuario);

        return $this->render('studio/editor', $req, $dados + [
            'aviso' => $aviso,
            'erro' => $req->input('erro'),
            // "Desfazer este salvamento" só faz sentido no instante seguinte ao
            // salvamento — depois disso a lista de versões já conta a história.
            'pode_desfazer' => (string) ($req->input('ok') ?? '') === 'saved' && $dados['salvamento_recente'],
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
        // No salvamento vale a empresa da arte (o $empresa não existe fora do index).
        $pedido = $this->pedidoDaCriacao($req, (int) $arte['company_id']);
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

        // Salvou: o rascunho da sessão já cumpriu o papel e não deve mais
        // aparecer como "alterações não salvas" na próxima abertura.
        $rascunhos = $this->session->get('studio_rascunhos');
        if (is_array($rascunhos) && isset($rascunhos[(int) $arte['id']])) {
            unset($rascunhos[(int) $arte['id']]);
            $this->session->put('studio_rascunhos', $rascunhos);
        }

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
                // Nenhum arquivo escolhido: dizer isso é melhor que recarregar
                // a tela como se nada tivesse sido pedido.
                return $this->voltarComErro($arte, 'Escolha um arquivo de imagem para enviar.');
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

    /**
     * Envia a arte exportada para a aprovação do cliente.
     *
     * Mesmo caminho da tela de Aprovações: exige `exported` + `export_path`
     * (é o arquivo que o cliente vê) e avisa quem tem acesso ao portal. Sem
     * esse aviso, o pedido ficaria esperando numa tela que ninguém abre.
     */
    public function sendToApproval(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario) || $this->aprovacoes === null) {
            return $this->semPermissao($req);
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return $this->voltarComErro($arte, 'Sessão expirada. Recarregue a página e envie novamente.');
        }

        $empresa = (int) $arte['company_id'];
        $fonte = $this->aprovacoes->fonte('creative', (int) $arte['id'], (int) $arte['client_id'], $empresa);
        if ($fonte === null) {
            // A arte foi editada depois de exportada: o arquivo antigo não vale.
            return $this->voltarComErro($arte, 'Exporte a arte antes de enviar para aprovação.');
        }

        try {
            $id = $this->aprovacoes->enviar(
                $empresa,
                (int) $arte['client_id'],
                (int) $usuario['id'],
                'creative',
                (int) $arte['id'],
                ApprovalRepository::snapshot('creative', $fonte),
                Layout::textoCurto($req->input('message') ?? '', 500),
            );
        } catch (ApprovalError $erro) {
            return $this->voltarComErro($arte, $erro->getMessage());
        }

        if ($this->portal !== null) {
            $aprovacao = $this->aprovacoes->aprovacao($id, $empresa);
            $this->portal->avisarCliente(
                $empresa,
                (int) $arte['client_id'],
                'Novo conteúdo para revisão',
                (string) ($aprovacao['title'] ?? $arte['subject']),
                '/portal/approvals/' . $id,
            );
        }
        $this->aprovacoes->auditoria($empresa, (int) $usuario['id'], 'approval.sent', 'creative:' . $arte['id'] . ' -> ' . $id);

        return Response::redirect($this->config->url('/approvals/' . $id . '?sent=1'));
    }

    /**
     * Reescreve só uma parte do texto, mantendo o resto do conteúdo.
     *
     * "Gerar outra versão" refazia tudo e apagava o que já estava bom; aqui o
     * usuário escolhe legenda (texto + hashtags) ou só as hashtags.
     */
    public function rewrite(Request $req, array $params): Response
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
            return $this->voltarComErro($arte, 'Sessão expirada. Recarregue a página e tente novamente.');
        }

        $alvo = (string) ($req->input('target') ?? '');
        if (!in_array($alvo, ['caption', 'hashtags'], true)) {
            return $this->voltarComErro($arte, 'Escolha o que reescrever: legenda ou hashtags.');
        }
        $gerador = $this->geradorTexto ?? $this->gerador;
        if (!$gerador->iaConfigurada()) {
            return $this->voltarComErro($arte, 'A reescrita pede a IA configurada. Em Ajustes, informe a chave do provedor.');
        }

        $conteudo = json_decode((string) $arte['content_json'], true);
        $conteudo = is_array($conteudo) ? $conteudo : [];
        $atual = [
            'title' => (string) ($conteudo['title'] ?? ''),
            'caption' => (string) ($conteudo['caption'] ?? ''),
            'hashtags' => (string) ($conteudo['hashtags'] ?? ''),
        ];

        try {
            $novo = $gerador->reescrever(
                $alvo,
                $atual,
                [
                    'subject' => (string) $arte['subject'],
                    'network' => (string) $arte['network'],
                    'objective' => (string) $arte['objective'],
                    'tone' => (string) $arte['tone'],
                    'format' => Layout::rotulo((string) $arte['format_key']),
                    'client' => (string) (($arte['trade_name'] ?? '') ?: $arte['company_name']),
                    'segment' => (string) $arte['segment'],
                ],
                (string) ($req->input('instruction') ?? ''),
            );
        } catch (StudioError $erro) {
            return $this->voltarComErro($arte, $erro->getMessage());
        }

        foreach ($novo as $campo => $valor) {
            if (array_key_exists($campo, $conteudo) || in_array($campo, ['caption', 'hashtags'], true)) {
                $conteudo[$campo] = $valor;
            }
        }

        $this->repo->atualizar((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id'], [
            'content_json' => json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'export_path' => null,
            'status' => 'draft',
        ], $alvo === 'caption' ? 'Reescrita da legenda' : 'Reescrita das hashtags');

        return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?ok=' . ($alvo === 'caption' ? 'recaption' : 'rehashtags')));
    }

    /**
     * Desfaz o último salvamento manual, voltando ao estado anterior.
     *
     * É o "Ctrl+Z" do salvamento: a lista de versões já existia, mas descobrir
     * qual delas é a anterior exigia abrir a aba. O botão só aparece em
     * `?ok=saved`, logo depois do salvamento que o criou.
     */
    public function undoLastSave(Request $req, array $params): Response
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
            return $this->voltarComErro($arte, 'Sessão expirada. Recarregue a página e tente novamente.');
        }

        $versao = $this->repo->estadoAnteriorAoSalvamento((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id']);
        if ($versao === null) {
            return $this->voltarComErro($arte, 'Não há salvamento recente para desfazer.');
        }
        $this->repo->restaurar((int) $arte['id'], (int) $arte['company_id'], (int) $usuario['id'], $versao);

        return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?ok=archived'));
    }

    /**
     * Guarda o rascunho do editor na sessão (chamado pelo JS ao digitar).
     *
     * Sair da tela, trocar de aba ou o navegador fechar deixava o texto
     * digitado só na memória da página. O banco continua sendo a verdade: isto
     * é um bilhete de "onde você parou", por usuário e por arte.
     */
    public function draft(Request $req, array $params): Response
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
            return Response::json(['ok' => false, 'erro' => 'Sessão expirada.'], 419);
        }

        $campos = [];
        foreach (['title', 'subtitle', 'cta', 'caption', 'hashtags', 'alt_text'] as $campo) {
            $campos[$campo] = Layout::textoCurto($req->input($campo) ?? '', 5000);
        }
        $rascunhos = $this->session->get('studio_rascunhos');
        $rascunhos = is_array($rascunhos) ? $rascunhos : [];
        $rascunhos[(int) $arte['id']] = ['em' => gmdate('Y-m-d H:i:s'), 'campos' => $campos];
        // Só os cinco mais recentes: a sessão não é banco de dados.
        if (count($rascunhos) > 5) {
            $rascunhos = array_slice($rascunhos, -5, null, true);
        }
        $this->session->put('studio_rascunhos', $rascunhos);

        return Response::json(['ok' => true]);
    }

    /** Descarta o rascunho (ao salvar ou quando o usuário recusa recuperá-lo). */
    public function descartarDraft(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        if ($this->session->csrfValid($req->input('csrf_token'))) {
            $rascunhos = $this->session->get('studio_rascunhos');
            if (is_array($rascunhos)) {
                unset($rascunhos[(int) $arte['id']]);
                $this->session->put('studio_rascunhos', $rascunhos);
            }
        }

        return Response::redirect($this->config->url('/studio/posts/' . $arte['id'] . '?rascunho=descartado'));
    }

    /**
     * Devolve o rascunho guardado para o botão "Recuperar texto".
     *
     * 404 quando não há rascunho para esta arte (foi salvo, descartado ou a
     * sessão é outra): o editor então não tem o que recuperar.
     */
    public function preview(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $arte = $this->arteDoUsuario($req, $usuario, $params);
        if ($arte instanceof Response) {
            return $arte;
        }
        $rascunhos = $this->session->get('studio_rascunhos');
        $rascunho = is_array($rascunhos) ? ($rascunhos[(int) $arte['id']] ?? null) : null;
        if (!is_array($rascunho)) {
            return Response::json(['ok' => false], 404);
        }

        return Response::json([
            'ok' => true,
            'em' => (string) ($rascunho['em'] ?? ''),
            'campos' => (array) ($rascunho['campos'] ?? []),
        ]);
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

    /**
     * Ações em lote na galeria (v1.23).
     *
     * Arquiva/desarquiva e exclui várias artes de uma vez, sempre restritas à
     * empresa do usuário. Uma arte de outro workspace na lista é ignorada — não
     * derruba as outras e não revela que existe.
     */
    public function bulk(Request $req, array $params): Response
    {
        $usuario = $this->precisaLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!$this->podeEditar($usuario)) {
            return $this->semPermissao($req);
        }
        $empresa = (int) $usuario['company_id'];
        $voltar = $this->config->url('/studio');
        if (!$this->session->csrfValid($req->input('csrf_token'))) {
            return Response::redirect($voltar . '?erro=' . rawurlencode('Sessão expirada. Selecione as artes de novo.'));
        }

        $acao = (string) ($req->input('acao') ?? '');
        if (!in_array($acao, ['exportar', 'rascunho', 'excluir'], true)) {
            return Response::redirect($voltar . '?erro=' . rawurlencode('Escolha o que fazer com as artes selecionadas.'));
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($req->post['ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return Response::redirect($voltar . '?erro=' . rawurlencode('Selecione pelo menos uma arte.'));
        }

        $feitas = 0;
        $arquivos = [];
        foreach ($ids as $id) {
            $arte = $this->repo->arte($id, $empresa);
            if ($arte === null) {
                continue;
            }
            if ($acao === 'excluir') {
                $arquivos = array_merge($arquivos, $this->repo->arquivos($id, $empresa));
                $this->repo->remover($id, $empresa);
            } else {
                $this->repo->atualizar($id, $empresa, (int) $usuario['id'], [
                    'status' => $acao === 'exportar' ? 'exported' : 'draft',
                ], $acao === 'exportar' ? 'Marcada como publicada' : 'Voltou para rascunho');
            }
            $feitas++;
        }

        foreach ($arquivos as $arquivo) {
            $this->upload->delete($arquivo);
        }
        if ($feitas > 0) {
            $rotulo = match ($acao) {
                'excluir' => 'Artes excluídas',
                'exportar' => 'Artes marcadas como publicadas',
                default => 'Artes devolvidas para rascunho',
            };
            $this->repo->auditoria($empresa, (int) $usuario['id'], 'studio.bulk_' . $acao, $rotulo . ': ' . $feitas . ' (' . implode(', ', $ids) . ')');
        }

        // A mensagem é montada a partir de chaves conhecidas (nunca de texto
        // na URL): o aviso da tela não vira eco de parâmetro.
        return Response::redirect($voltar . '?ok=lote&acao=' . $acao . '&n=' . $feitas);
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
            // Envio para aprovação: a arte precisa estar exportada, e o cliente
            // precisa ter alguém com acesso ao portal — senão o envio some numa
            // caixa que ninguém abre. As duas condições são ditas na tela.
            'pode_enviar' => $this->podeEditar($usuario) && $this->aprovacoes !== null,
            'exportada' => (string) $arte['export_path'] !== '',
            'portal_ativo' => $this->portalDoCliente((int) $arte['company_id'], (int) $arte['client_id']),
            'aprovacao_pendente' => $this->aprovacaoPendente((int) $arte['id'], (int) $empresa),
            // "Desfazer este salvamento": só depois de salvar e só do próprio autor.
            'salvamento_recente' => $this->repo->ultimoSalvamento((int) $arte['id'], (int) $empresa, (int) $usuario['id']) !== null,
            // Rascunho guardado na sessão (v1.23): o editor oferece recuperar
            // em vez de aplicar sozinho — o banco continua sendo a verdade.
            'rascunho' => $this->rascunhoDaArte((int) $arte['id']),
        ];
    }

    /**
     * Rascunho de edição desta arte para este usuário, se houver.
     *
     * @return array{em:string,campos:array<string,string>}|null
     */
    private function rascunhoDaArte(int $creativeId): ?array
    {
        $rascunhos = $this->session->get('studio_rascunhos');
        $rascunho = is_array($rascunhos) ? ($rascunhos[$creativeId] ?? null) : null;

        return is_array($rascunho) ? $rascunho : null;
    }

    /** Quantos acessos ativos ao portal este cliente tem (0 = ninguém vê). */
    private function portalDoCliente(int $empresa, int $cliente): int
    {
        if ($this->portal === null) {
            return 0;
        }

        return count(array_filter(
            $this->portal->contas($empresa),
            static fn (array $conta): bool => (int) $conta['client_id'] === $cliente && (int) ($conta['is_active'] ?? 1) === 1
        ));
    }

    /**
     * Revisão aguardando resposta deste conteúdo.
     *
     * Sem isso o editor oferecia "Enviar para aprovação" para uma arte que já
     * estava na fila, e o usuário descobria o duplicado por mensagem de erro.
     */
    private function aprovacaoPendente(int $creativeId, int $empresa): ?array
    {
        if ($this->aprovacoes === null) {
            return null;
        }
        $linha = $this->aprovacoes->pendenteDoCriativo($creativeId, $empresa);

        return $linha === null ? null : [
            'id' => (int) $linha['id'],
            'enviado_em' => (string) ($linha['submitted_at'] ?? ''),
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
        $ok = (string) ($req->input('ok') ?? '');
        if ($ok === 'lote') {
            $n = (int) ($req->input('n') ?? 0);
            if ($n <= 0) {
                return 'Nenhuma arte foi alterada — confira a seleção.';
            }

            return match ((string) ($req->input('acao') ?? '')) {
                'excluir' => $n . ' ' . ($n === 1 ? 'arte excluída' : 'artes excluídas') . '.',
                'exportar' => $n . ' ' . ($n === 1 ? 'arte marcada como publicada' : 'artes marcadas como publicadas') . '.',
                default => $n . ' ' . ($n === 1 ? 'arte devolvida para rascunho' : 'artes devolvidas para rascunho') . '.',
            };
        }

        return match ($ok) {
            'saved' => 'Edição salva como uma nova versão.',
            'image' => 'Imagem de fundo atualizada.',
            'upload' => 'Imagem enviada e ajustada ao formato.',
            'restored' => 'Versão restaurada.',
            'created' => 'Conteúdo gerado. Edite os elementos, escolha a imagem e exporte a arte.',
            'duplicated' => 'Cópia criada. Ela é independente da arte original.',
            'imagem' => 'Imagem de capa gerada na criação.',
            'deleted' => 'Criação excluída.',
            'recaption' => 'Legenda reescrita. O resto do conteúdo ficou como estava.',
            'rehashtags' => 'Hashtags reescritas. Confira antes de exportar.',
            'archived' => 'Salvamento desfeito: a versão anterior foi restaurada.',
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

        // A tela de erro é a mesma galeria, com o formulário preenchido: ela
        // também precisa das contagens e da paginação, senão o topo da galeria
        // aparece sem números.
        $total = $this->repo->contarArtesFiltradas($empresa);
        $contagens = $this->repo->contarPorStatus($empresa);

        return $this->render('studio/index', $req, $this->dadosCriacao($usuario ?? []) + [
            'artes' => $this->repo->artes($empresa, 24),
            'total_artes' => $total,
            'contagens' => $contagens,
            'pagina' => 1,
            'paginas' => max(1, (int) ceil($total / 24)),
            'por_pagina' => 24,
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
