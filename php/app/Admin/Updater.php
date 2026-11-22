<?php

declare(strict_types=1);

namespace Smia\Admin;

use Smia\Database\Database;
use Smia\Installer\Installer;
use Smia\Support\Config;

/**
 * Atualização do sistema pelo painel do dono.
 *
 * O problema que isto resolve: hoje, para subir de versão, é preciso entrar no
 * cPanel, enviar arquivos por FTP e torcer para nada ficar pela metade. Aqui o
 * dono da instalação manda atualizar, e o próprio sistema:
 *
 *   1. descobre a versão publicada (GitHub ou um zip enviado por upload);
 *   2. **confere a versão dentro do pacote antes de tocar em qualquer arquivo**;
 *   3. copia o `app/` e o `public/` atuais para `data/backups/`;
 *   4. faz um backup do banco;
 *   5. só então substitui `app/` e `public/`.
 *
 * Três coisas nunca são tocadas: `data/` (banco, imagens, backups), `.env`
 * (segredos da instalação) e a raiz fora de `app/` e `public/`. Um pacote que
 * tente escrever fora dessas duas pastas é recusado inteiro — nada é aplicado
 * pela metade, porque a extração acontece numa pasta temporária e só depois da
 * validação o conteúdo é movido.
 *
 * Também existe a volta: `restaurar()` devolve a cópia mais recente. É o botão
 * que faz alguém se sentir seguro para clicar em "atualizar" na sexta-feira.
 */
final class Updater
{
    /** Repositório oficial de onde saem as releases do pacote PHP. */
    public const REPOSITORIO = 'EdijalmeMoura/SOCIALMEDIA-IA';

    /** Prefixo das tags de versão do pacote PHP (o projeto tem mais de uma linha). */
    public const PREFIXO_TAG = 'php-v';

    /** Pastas que a atualização substitui — e só elas. */
    public const PASTAS_ALVO = ['app', 'public'];

    /** Limite do pacote: o zip oficial tem ~350 KB; 25 MB é folga para não abusar da memória. */
    public const TAMANHO_MAXIMO = 26_214_400;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Installer $installer,
    ) {
    }

    // ------------------------------------------------------------------
    // Onde ficam as coisas
    // ------------------------------------------------------------------

    public function pastaBackups(): string
    {
        return $this->config->dataDir . '/backups';
    }

    public function pastaTemporaria(): string
    {
        return $this->config->dataDir . '/update-tmp';
    }

    /** O que esta instalação está rodando agora. */
    public function versaoAtual(): string
    {
        return Config::VERSAO;
    }

    // ------------------------------------------------------------------
    // Descoberta da versão publicada
    // ------------------------------------------------------------------

    /**
     * Consulta a última release do pacote PHP no GitHub.
     *
     * Devolve sempre um array com `ok`. Quando `ok` é falso, `erro` diz o que
     * aconteceu em português e `dica` diz o que fazer — inclusive "sem saída
     * para a internet, use o envio do zip", que é o caso de muita hospedagem.
     *
     * @return array{ok:bool,versao:?string,url:?string,tamanho:?int,notas:string,erro:string,dica:string,origem:string}
     */
    public function consultarGitHub(): array
    {
        $vazio = [
            'ok' => false, 'versao' => null, 'url' => null, 'tamanho' => null,
            'notas' => '', 'erro' => '', 'dica' => '', 'origem' => 'github',
        ];

        if (!function_exists('curl_init')) {
            return $this->falha($vazio, 'A extensão cURL do PHP não está ativa neste servidor.', 'Peça à hospedagem para ativar a extensão curl — ou baixe o zip no seu computador e envie pelo campo de upload abaixo.');
        }

        $url = 'https://api.github.com/repos/' . self::REPOSITORIO . '/releases/latest';
        [$corpo, $status, $erro] = $this->httpGet($url);

        if ($status === 0) {
            return $this->falha($vazio, 'Não consegui falar com o GitHub' . ($erro !== '' ? ': ' . $erro : '.'), 'O servidor provavelmente não tem saída para a internet. Baixe o zip da versão no seu computador (na página de releases) e envie pelo campo de upload — o resultado é o mesmo.');
        }
        if ($status === 404) {
            return $this->falha($vazio, 'O repositório ' . self::REPOSITORIO . ' não foi encontrado ou não é público.', 'Use o envio do zip pelo painel.');
        }
        if ($status === 403 || $status === 429) {
            return $this->falha($vazio, 'O GitHub recusou a consulta agora (limite de acessos, HTTP ' . $status . ').', 'Espere alguns minutos e tente de novo, ou envie o zip pelo painel.');
        }
        if ($status !== 200) {
            return $this->falha($vazio, 'O GitHub respondeu HTTP ' . $status . '.', 'Tente de novo em instantes, ou envie o zip pelo painel.');
        }

        $json = json_decode($corpo, true);
        if (!is_array($json)) {
            return $this->falha($vazio, 'A resposta do GitHub não foi entendida.', 'Envie o zip pelo painel.');
        }

        // O projeto publica em tags `php-vX.Y`: só essas interessam aqui.
        $tag = (string) ($json['tag_name'] ?? '');
        if (!str_starts_with($tag, self::PREFIXO_TAG)) {
            return $this->falha($vazio, 'A release mais recente (' . ($tag !== '' ? $tag : 'sem tag') . ') não é do pacote PHP.', 'Abra a página de releases e baixe a versão `' . self::PREFIXO_TAG . '` mais nova, depois envie o zip pelo painel.');
        }
        $versao = substr($tag, strlen(self::PREFIXO_TAG));

        // O pacote é um arquivo dentro do próprio repositório (as releases não
        // recebem asset binário deste ambiente), então o download vem do
        // conteúdo da tag — o mesmo arquivo listado na tabela do README.
        $caminho = 'php/downloads/SOCIALMEDIA-IA-php-v' . $versao . '.zip';
        $urlPacote = 'https://raw.githubusercontent.com/' . self::REPOSITORIO . '/' . $tag . '/' . $caminho;

        return [
            'ok' => true,
            'versao' => $versao,
            'url' => $urlPacote,
            'tamanho' => null,
            'notas' => trim((string) ($json['body'] ?? '')),
            'erro' => '',
            'dica' => '',
            'origem' => 'github',
        ];
    }

    /**
     * Baixa o pacote de uma URL e guarda na pasta temporária.
     *
     * @return array{ok:bool,caminho:string,erro:string}
     */
    public function baixar(string $url): array
    {
        $destino = $this->pastaTemporaria() . '/pacote-' . bin2hex(random_bytes(4)) . '.zip';
        $this->garantirPasta($this->pastaTemporaria());

        $fp = @fopen($destino, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'caminho' => '', 'erro' => 'Não consegui criar o arquivo temporário em ' . $this->pastaTemporaria() . '. Confira a permissão da pasta data/.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'socialmedia-ia-updater/' . Config::VERSAO,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status !== 200) {
            @unlink($destino);

            return [
                'ok' => false,
                'caminho' => '',
                'erro' => $status !== 0
                    ? 'O download respondeu HTTP ' . $status . '.'
                    : 'O download falhou: ' . ($erro !== '' ? $erro : 'sem resposta') . '.',
            ];
        }

        $tamanho = (int) @filesize($destino);
        if ($tamanho === 0 || $tamanho > self::TAMANHO_MAXIMO) {
            @unlink($destino);

            return [
                'ok' => false,
                'caminho' => '',
                'erro' => 'O arquivo baixado tem ' . $this->legivel($tamanho) . ', fora do esperado. O pacote oficial tem menos de 1 MB.',
            ];
        }

        return ['ok' => true, 'caminho' => $destino, 'erro' => ''];
    }

    // ------------------------------------------------------------------
    // Leitura do pacote (sem tocar no sistema)
    // ------------------------------------------------------------------

    /**
     * Abre o zip, confere o que ele é e devolve o plano — nada é aplicado aqui.
     *
     * @return array{ok:bool,erro:string,dica:string,versao:?string,raiz:string,arquivos:int,bytes:int,manter:array<int,string>,ignorados:array<int,string>}
     */
    public function inspecionar(string $zip): array
    {
        $base = [
            'ok' => false, 'erro' => '', 'dica' => '', 'versao' => null,
            'raiz' => '', 'arquivos' => 0, 'bytes' => 0, 'manter' => [], 'ignorados' => [],
        ];

        if (!is_file($zip)) {
            return $this->falha($base, 'O arquivo do pacote não está mais na pasta temporária.', 'Envie o zip de novo.');
        }
        if (!class_exists('ZipArchive')) {
            return $this->falha($base, 'A extensão Zip do PHP não está ativa neste servidor.', 'Peça à hospedagem para ativar a extensão zip; sem ela o sistema não consegue abrir o pacote de atualização.');
        }

        $arquivo = new \ZipArchive();
        if ($arquivo->open($zip) !== true) {
            return $this->falha($base, 'O arquivo enviado não é um zip válido.', 'Baixe de novo o pacote oficial e envie sem renomear.');
        }

        $arquivos = [];
        $bytes = 0;
        for ($i = 0; $i < $arquivo->numFiles; $i++) {
            $stat = $arquivo->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $nome = (string) $stat['name'];
            if (str_ends_with($nome, '/')) {
                continue;
            }
            $arquivos[$nome] = (int) $stat['size'];
            $bytes += (int) $stat['size'];
        }
        $arquivo->close();

        if ($arquivos === []) {
            return $this->falha($base, 'O zip está vazio.', 'Envie o pacote oficial.');
        }

        // Segurança primeiro: nada pode escapar para fora de app/ e public/. Um
        // zip malicioso com "../" ou caminho absoluto é recusado INTEIRO — não
        // adianta aplicar "a parte boa". Isto roda ANTES de decidir a raiz,
        // senão um caminho como "../app/Application.php" passaria como se o
        // pacote tivesse a aplicação.
        $suspeitos = [];
        foreach (array_keys($arquivos) as $nome) {
            if (str_starts_with($nome, '/') || str_contains($nome, '..') || str_contains($nome, '\\')) {
                $suspeitos[] = $nome;
            }
        }
        if ($suspeitos !== []) {
            return $this->falha($base, 'O pacote tem ' . count($suspeitos) . ' caminho(s) suspeito(s), como "' . $suspeitos[0] . '".', 'Não aplique este arquivo. Baixe o pacote oficial na página de releases.');
        }

        // A raiz do pacote é a pasta que envolve app/ e public/. Aceita tanto
        // "SOCIALMEDIA-IA-php-v1.17/app/..." quanto um zip sem pasta raiz.
        $raiz = null;
        foreach (array_keys($arquivos) as $nome) {
            if (preg_match('#^(.*?)app/Application\.php$#', $nome, $m) === 1) {
                $raiz = $m[1];
                break;
            }
        }
        if ($raiz === null) {
            return $this->falha($base, 'O zip não tem `app/Application.php` — não parece ser o pacote do sistema.', 'Use o arquivo SOCIALMEDIA-IA-php-vX.Y.zip, da página de releases.');
        }

        // Classificação: só app/ e public/ são aplicados. `data/`, `.env` e o
        // resto ficam de fora de propósito — o que é da instalação (banco,
        // imagens, segredos) nunca vem do pacote.
        $manter = [];
        $ignorados = [];
        foreach (array_keys($arquivos) as $nome) {
            $relativo = $raiz !== '' ? substr($nome, strlen($raiz)) : $nome;
            $primeira = explode('/', $relativo)[0];
            if (in_array($primeira, self::PASTAS_ALVO, true)) {
                $manter[] = $relativo;
            } else {
                $ignorados[] = $relativo;
            }
        }
        if ($manter === []) {
            return $this->falha($base, 'O zip não tem as pastas app/ e public/.', 'Use o pacote oficial.');
        }

        // A versão vem de dentro do pacote, não do nome do arquivo: um zip
        // renomeado não deve conseguir se passar por versão mais nova.
        $versao = $this->versaoDoZip($zip, $raiz . 'app/Support/Config.php');
        if ($versao === null) {
            return $this->falha($base, 'Não consegui ler a versão dentro do pacote (app/Support/Config.php).', 'O zip pode estar corrompido. Baixe de novo e envie outra vez.');
        }

        return [
            'ok' => true,
            'erro' => '',
            'dica' => '',
            'versao' => $versao,
            'raiz' => (string) $raiz,
            'arquivos' => count($manter),
            'bytes' => $bytes,
            'manter' => $manter,
            'ignorados' => array_slice($ignorados, 0, 12),
        ];
    }

    /** Compara duas versões "1.17" no estilo do PHP. */
    public function comparar(string $nova, string $atual): int
    {
        return version_compare($nova, $atual);
    }

    // ------------------------------------------------------------------
    // Aplicação (com cópia de segurança antes)
    // ------------------------------------------------------------------

    /**
     * Aplica o pacote já inspecionado.
     *
     * Ordem deliberada: extrai para uma pasta temporária → copia o app atual
     * para o backup → backup do banco → troca app/ e public/. Se a extração
     * falhar, nada foi tocado. Se a troca falhar no meio, `restaurar()` volta
     * a cópia — e a mensagem diz o nome dela.
     *
     * @return array{ok:bool,erro:string,dica:string,versao:?string,backup:?string,copiados:int}
     */
    public function aplicar(string $zip, string $versaoEsperada): array
    {
        $base = ['ok' => false, 'erro' => '', 'dica' => '', 'versao' => null, 'backup' => null, 'copiados' => 0];

        $plano = $this->inspecionar($zip);
        if ($plano['ok'] !== true) {
            return $this->falha($base, $plano['erro'], $plano['dica']);
        }
        if ((string) $plano['versao'] !== $versaoEsperada) {
            return $this->falha($base, 'O pacote é da versão ' . (string) $plano['versao'] . ', mas você confirmou a ' . $versaoEsperada . '.', 'Confirme de novo — se o zip mudou no meio do caminho, envie outra vez.');
        }

        $estagio = $this->pastaTemporaria() . '/estagio-' . bin2hex(random_bytes(4));
        if (!$this->garantirPasta($estagio)) {
            return $this->falha($base, 'Não consegui criar a pasta temporária em ' . $this->pastaTemporaria() . '.', 'Confira a permissão da pasta data/ (755 ou 775) e tente de novo.');
        }

        try {
            if (!$this->extrair($zip, $estagio, $plano['raiz'])) {
                return $this->falha($base, 'Falha ao extrair o pacote.', 'O zip pode estar corrompido. Nada foi alterado no sistema.');
            }

            // 1) Cópia do que está no ar hoje. Sem isso não há volta.
            $backup = $this->copiarVersaoAtual();
            if ($backup['ok'] !== true) {
                return $this->falha($base, 'Não consegui guardar a cópia da versão atual, então não vou atualizar: ' . $backup['erro'], 'Confira a permissão de data/. Atualizar sem cópia deixaria a volta impossível.');
            }

            // 2) Backup do banco: a migração roda na próxima abertura e, se
            //    algo der errado, a versão anterior volta com os dados juntos.
            // O banco vai junto com a cópia: a versão anterior volta com os
            // dados do momento da atualização, não com um banco já migrado.
            $relatorio = new SystemReport($this->config, $this->db, $this->installer);
            $relatorio->copiarBanco($backup['caminho'] . '/banco.sqlite');

            // 3) Troca de app/ e public/.
            $copiados = 0;
            foreach (self::PASTAS_ALVO as $pasta) {
                $origem = $estagio . '/' . $pasta;
                if (!is_dir($origem)) {
                    continue;
                }
                $destino = $this->config->root . '/' . $pasta;
                $resultado = $this->substituirPasta($origem, $destino);
                if ($resultado !== true) {
                    // Volta imediatamente a cópia que acabou de ser feita.
                    $this->restaurarDe($backup['caminho']);

                    $falhou = $this->falha($base, 'Falha ao trocar a pasta ' . $pasta . ': ' . (string) $resultado, 'Restaurei a versão anterior automaticamente. Se algo parecer estranho, use "Restaurar" com a cópia ' . basename($backup['caminho']) . '.');
                    $falhou['backup'] = $backup['caminho'];

                    return $falhou;
                }
                $copiados += $this->contarArquivos($destino);
            }

            return [
                'ok' => true,
                'erro' => '',
                'dica' => '',
                'versao' => (string) $plano['versao'],
                'backup' => $backup['caminho'],
                'copiados' => $copiados,
            ];
        } finally {
            $this->apagarPasta($estagio);
        }
    }

    /**
     * Volta para a cópia mais recente (ou para uma pasta de backup específica).
     *
     * @return array{ok:bool,erro:string,dica:string,versao:?string,de:?string}
     */
    public function restaurar(?string $pasta = null): array
    {
        $base = ['ok' => false, 'erro' => '', 'dica' => '', 'versao' => null, 'de' => null];

        $pasta ??= $this->ultimoBackup();
        if ($pasta === null) {
            return $this->falha($base, 'Não existe cópia guardada para restaurar.', 'As cópias são criadas automaticamente na primeira atualização pelo painel.');
        }

        if (!is_dir($pasta . '/app')) {
            return $this->falha($base, 'A cópia ' . basename($pasta) . ' não tem a pasta app/.', 'Escolha outra cópia na lista.');
        }

        $resultado = $this->restaurarDe($pasta);
        if ($resultado !== true) {
            return $this->falha($base, 'Falha ao restaurar: ' . (string) $resultado, 'Tente de novo; se persistir, restaure manualmente pelo cPanel.');
        }

        return [
            'ok' => true, 'erro' => '', 'dica' => '',
            'versao' => $this->versaoEm($pasta), 'de' => $pasta,
        ];
    }

    /**
     * Cópias guardadas, da mais nova para a mais antiga.
     *
     * @return array<int,array{pasta:string,nome:string,versao:string,quando:int,banco:bool,bytes:int}>
     */
    public function backups(): array
    {
        $lista = [];
        foreach (glob($this->pastaBackups() . '/*', GLOB_ONLYDIR) ?: [] as $pasta) {
            $lista[] = [
                'pasta' => $pasta,
                'nome' => basename($pasta),
                'versao' => $this->versaoEm($pasta) ?? '—',
                'quando' => (int) @filemtime($pasta),
                'banco' => is_file($pasta . '/banco.sqlite'),
                'bytes' => (int) @filesize($pasta . '/banco.sqlite'),
            ];
        }
        usort($lista, static fn (array $a, array $b): int => $b['quando'] <=> $a['quando']);

        return $lista;
    }

    public function ultimoBackup(): ?string
    {
        $lista = $this->backups();

        return $lista === [] ? null : $lista[0]['pasta'];
    }

    /** Apaga cópias antigas, mantendo as N mais recentes. */
    public function limparBackups(int $manter = 5): int
    {
        $removidas = 0;
        foreach (array_slice($this->backups(), $manter) as $copia) {
            if ($this->apagarPasta($copia['pasta'])) {
                $removidas++;
            }
        }

        return $removidas;
    }

    // ------------------------------------------------------------------
    // Interior
    // ------------------------------------------------------------------

    /**
     * Resposta de falha padronizada: mantém as chaves do array base e troca
     * erro/dica. (Usar `$base + [...]` aqui apagaria a mensagem: o operador
     * `+` preserva o valor da esquerda, e o base já tem erro vazio.)
     *
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function falha(array $base, string $erro, string $dica = ''): array
    {
        $base['ok'] = false;
        $base['erro'] = $erro;
        $base['dica'] = $dica;

        return $base;
    }

    /** @return array{ok:bool,caminho:string,erro:string} */
    private function copiarVersaoAtual(): array
    {
        $nome = 'app-' . date('Ymd-His') . '-v' . $this->versaoAtual();
        $destino = $this->pastaBackups() . '/' . $nome;

        if (!$this->garantirPasta($destino)) {
            return ['ok' => false, 'caminho' => '', 'erro' => 'não consegui criar ' . $destino];
        }

        foreach (self::PASTAS_ALVO as $pasta) {
            $origem = $this->config->root . '/' . $pasta;
            if (!is_dir($origem)) {
                continue;
            }
            $erro = $this->copiarArvore($origem, $destino . '/' . $pasta);
            if ($erro !== true) {
                return ['ok' => false, 'caminho' => '', 'erro' => $pasta . ': ' . (string) $erro];
            }
        }

        return ['ok' => true, 'caminho' => $destino, 'erro' => ''];
    }

    /** @return true|string */
    private function restaurarDe(string $copia)
    {
        foreach (self::PASTAS_ALVO as $pasta) {
            $origem = $copia . '/' . $pasta;
            if (!is_dir($origem)) {
                continue;
            }
            $resultado = $this->substituirPasta($origem, $this->config->root . '/' . $pasta);
            if ($resultado !== true) {
                return $pasta . ': ' . (string) $resultado;
            }
        }

        return true;
    }

    /**
     * Troca uma pasta pelo conteúdo de outra, sem apagar antes de ter o novo.
     *
     * @return true|string
     */
    private function substituirPasta(string $origem, string $destino)
    {
        $novo = $destino . '.novo-' . bin2hex(random_bytes(3));
        $velho = $destino . '.velho-' . bin2hex(random_bytes(3));

        $erro = $this->copiarArvore($origem, $novo);
        if ($erro !== true) {
            $this->apagarPasta($novo);

            return 'não consegui preparar a pasta nova: ' . (string) $erro;
        }

        if (is_dir($destino) && !@rename($destino, $velho)) {
            $this->apagarPasta($novo);

            return 'não consegui mover a pasta atual (permissão?)';
        }
        if (!@rename($novo, $destino)) {
            // Devolve a pasta antiga ao lugar antes de desistir.
            if (is_dir($velho)) {
                @rename($velho, $destino);
            }
            $this->apagarPasta($novo);

            return 'não consegui mover a pasta nova para o lugar';
        }
        $this->apagarPasta($velho);

        return true;
    }

    /** @return true|string */
    private function copiarArvore(string $origem, string $destino)
    {
        if (!is_dir($destino) && !@mkdir($destino, 0o755, true) && !is_dir($destino)) {
            return 'não consegui criar ' . $destino;
        }
        $itens = @scandir($origem);
        if ($itens === false) {
            return 'não consegui ler ' . $origem;
        }
        foreach ($itens as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $de = $origem . '/' . $item;
            $para = $destino . '/' . $item;
            if (is_dir($de)) {
                $erro = $this->copiarArvore($de, $para);
                if ($erro !== true) {
                    return $erro;
                }
                continue;
            }
            if (!@copy($de, $para)) {
                return 'não consegui copiar ' . $de;
            }
        }

        return true;
    }

    private function extrair(string $zip, string $destino, string $raiz): bool
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        $arquivo = new \ZipArchive();
        if ($arquivo->open($zip) !== true) {
            return false;
        }
        try {
            for ($i = 0; $i < $arquivo->numFiles; $i++) {
                $stat = $arquivo->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $nome = (string) $stat['name'];
                $relativo = $raiz !== '' ? substr($nome, strlen($raiz)) : $nome;
                if ($relativo === '' || str_ends_with($relativo, '/') || str_contains($relativo, '..') || str_starts_with($relativo, '/')) {
                    continue;
                }
                $primeira = explode('/', $relativo)[0];
                if (!in_array($primeira, self::PASTAS_ALVO, true)) {
                    continue;
                }
                $alvo = $destino . '/' . $relativo;
                $pasta = dirname($alvo);
                if (!is_dir($pasta) && !@mkdir($pasta, 0o755, true) && !is_dir($pasta)) {
                    return false;
                }
                if (!$arquivo->extractTo($destino, $nome)) {
                    return false;
                }
                // extractTo devolve o caminho completo dentro de $destino;
                // quando o zip tem pasta raiz, o arquivo cai num nível extra.
                $extraido = $destino . '/' . $nome;
                if ($nome !== $relativo && is_file($extraido) && !is_file($alvo)) {
                    if (!@rename($extraido, $alvo)) {
                        return false;
                    }
                }
            }
        } finally {
            $arquivo->close();
        }

        return true;
    }

    private function versaoDoZip(string $zip, string $caminhoInterno): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $arquivo = new \ZipArchive();
        if ($arquivo->open($zip) !== true) {
            return null;
        }
        $conteudo = $arquivo->getFromName($caminhoInterno);
        $arquivo->close();
        if (!is_string($conteudo) || $conteudo === '') {
            return null;
        }

        return preg_match('/VERSAO\s*=\s*[\'"]([0-9]+\.[0-9]+(?:\.[0-9]+)?)[\'"]/', $conteudo, $m) === 1 ? $m[1] : null;
    }

    private function versaoEm(string $pasta): ?string
    {
        $config = $pasta . '/app/Support/Config.php';
        if (!is_file($config)) {
            return null;
        }
        $conteudo = (string) @file_get_contents($config);

        return preg_match('/VERSAO\s*=\s*[\'"]([0-9]+\.[0-9]+(?:\.[0-9]+)?)[\'"]/', $conteudo, $m) === 1 ? $m[1] : null;
    }

    private function contarArquivos(string $pasta): int
    {
        $total = 0;
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterador as $arquivo) {
            if ($arquivo->isFile()) {
                $total++;
            }
        }

        return $total;
    }

    private function apagarPasta(string $pasta): bool
    {
        if (!is_dir($pasta)) {
            return false;
        }
        $itens = @scandir($pasta) ?: [];
        foreach ($itens as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $caminho = $pasta . '/' . $item;
            if (is_dir($caminho)) {
                $this->apagarPasta($caminho);
            } else {
                @unlink($caminho);
            }
        }

        return @rmdir($pasta);
    }

    private function garantirPasta(string $pasta): bool
    {
        if (is_dir($pasta)) {
            return is_writable($pasta);
        }

        return @mkdir($pasta, 0o755, true) || is_dir($pasta);
    }

    /** @return array{0:string,1:int,2:string} corpo, status, erro */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'socialmedia-ia-updater/' . Config::VERSAO,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $corpo = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);
        curl_close($ch);

        return [is_string($corpo) ? $corpo : '', $status, $erro];
    }

    private function legivel(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' bytes';
    }
}
