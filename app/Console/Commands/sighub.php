<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use ZipArchive;

class sighub extends Command
{
    protected $signature = 'sighub {acao?} {arg1?} {arg2?} {arg3?}';
    protected $description = 'Command description';
    private array $ambientes;

    private static string $diretorioLocal = 'C:/SigHub/arquivos/';
    private static string $diretorioBackup = 'C:/SigHub/commits/';

    private $sftp;

    public function handle()
    {
        $this->ambientes = self::getAmbientes();

        $opcao = $this->argument('acao');

        if ($opcao == '') {
            $this->apresentacao();
            return;
        }

        if (method_exists(self::class, $opcao)) {
            $this->$opcao();
            return;
        }
        $this->error('comando não encontrado');
    }

    private function ambientes()
    {
        $opcao = $this->argument('arg1') ?? 'listarAmbientes';

        if (method_exists(self::class, $opcao)) {
            $this->$opcao();
            return;
        }
    }

    private function listarAmbientes()
    {
        if (!count($this->ambientes)) {
            $this->error('Nenhum ambiente configurado, utilize o  comando sighub:ambietes novo');
            return;
        }

        echo " Ambientes:\n";
        foreach ($this->ambientes as $ambiente) {
            $this->info("  {$ambiente['nome']}");
            echo "   host: {$ambiente['host']} \n";
            echo "   usuário: {$ambiente['user']} \n";
            echo "   chave: {$ambiente['keyFile']} \n";
            echo "   usuário: {$ambiente['raiz']} \n\n";
        }
    }

    private function novo()
    {
        $ambientes = [];
        $novoAmbiente = 'N';

        do {
            $nomeAmbiente = $this->ask('informe um nome para o ambiente');
            $hostAmbiente = $this->ask('informe o host do ambiente');
            $usenameAmbiente = $this->ask('informe o username do seu acesso ao servidor');
            $keyFilePath = $this->ask('informe o caminho para o arquivo chave do servidor');
            $raizAmbiente = $this->ask('informe o caminho da raiz do ambiente');
            $senhaAmbiente = $this->secret('informe a senha do ambiente');

            $ambientes[] = [
                'nome' => $nomeAmbiente,
                'host' => $hostAmbiente,
                'user' => $usenameAmbiente,
                'keyFile' => $keyFilePath,
                'password' => $senhaAmbiente,
                'raiz' => $raizAmbiente
            ];

            $novoAmbiente = mb_strtoupper($this->ask('Deseja adicionar mais um ambiente? [s/n]'));
        } while ($novoAmbiente == 'S');

        $this->ambientes = array_merge($this->ambientes, $ambientes);

        file_put_contents(public_path('ambientes.json'), json_encode($this->ambientes));
    }

    private function clone()
    {
        $diretorio = $this->argument('arg2');
        $ambiente = $this->selecionaAmbiente($this->argument('arg1'));

        if ($ambiente === false) {
            return;
        }

        $this->sftp = self::conectarServidor($ambiente);

        if ($this->sftp === false) {
            $this->error("Falha ao se conectar com o servidor, verifique as credenciais informadas no ambiente.");
        }

        $diretorioExterno = $ambiente['raiz'] . $diretorio;

        $resposta = $this->ask("Tem certeza que deseja clonar todos os arquivos do diretorio $diretorioExterno, bem como suas subpastas? [s/n]");

        if (mb_strtoupper($resposta) == 'N') {
            return;
        }

        if (mb_strtoupper($resposta) != 'S') {
            $this->error("qual parte do [s/n] vc não entendeu??????????????????????????");
            return;
        }

        $this->info("Calculando árvode de diretórios");
        $estruturaDiretorios = $this->sftp->nlist($diretorioExterno, true);

        unset($estruturaDiretorios[array_search('.', $estruturaDiretorios)]);
        unset($estruturaDiretorios[array_search('..', $estruturaDiretorios)]);

        self::removerIgnore($estruturaDiretorios);

        $this->info("Baixando arquivos...");

        $progresso = $this->output->createProgressBar(count($estruturaDiretorios));
        $progresso->start();

        foreach ($estruturaDiretorios as $dir) {
            $novoDiretorioExterno = $ambiente['raiz'] . $diretorio . '/' . $dir;
            $novoDiretorioLocal = self::$diretorioLocal . $diretorio . '/' . $dir;

            self::criarDiretorioLocal($novoDiretorioLocal);
            if (file_put_contents($novoDiretorioLocal, $this->sftp->get($novoDiretorioExterno)) === false) {
                $this->error("Falha ao copiar conteúdo");
            }

            $progresso->advance();
        }
        $progresso->finish();

        $this->info("Diretório clonado com sucesso!");
    }

    private function pull()
    {
        $diretorio = $this->argument('arg2');
        $ambiente = $this->selecionaAmbiente($this->argument('arg1'));

        if ($ambiente === false) {
            return;
        }

        $diretorioLocal = self::$diretorioLocal . $diretorio;
        $diretorioRemoto = $ambiente['raiz'] . $diretorio;

        if (!is_dir($diretorioLocal)) {
            $this->clone();
            return;
        }

        $this->sftp = self::conectarServidor($ambiente);
        if ($this->sftp === false) {
            $this->error("Falha ao se conectar com o servidor, verifique as credenciais informadas no ambiente.");
        }

        $listaDiretoriosRemotos = $this->listarDiretoriosRemmotos($diretorioRemoto);

        $diretoriosABaixar = [];

        $this->info('Calculando lista de diretorios atualizados');
        $progresso = $this->output->createProgressBar(count($listaDiretoriosRemotos));
        $progresso->start();

        foreach ($listaDiretoriosRemotos as $k => $arquivo) {
            $progresso->advance();

            $caminhoRemoto = $diretorioRemoto . '/' . $arquivo;
            $caminhoLocal = $diretorioLocal . '/' . $arquivo;

            $infoArquivoLocal = stat($caminhoLocal);

            if($infoArquivoLocal['size'] > 38241000)
            {
                $this->error("\nArquivo {$arquivo} é muito grande, não pode ser comparado nem será baixado");
                continue;
            }

            $conteudoArquivoLocal = file_get_contents($caminhoLocal);

            if ($conteudoArquivoLocal === false) {
                $diretoriosABaixar[] = $diretorio.'/'.$arquivo;
                continue;
            }

            $infoArquivoRemoto = $this->sftp->stat($caminhoRemoto);
            $conteudoArquivoRemoto = $this->sftp->get($caminhoRemoto);

            if ($conteudoArquivoLocal == $conteudoArquivoRemoto) {
                continue;
            }

            if ($infoArquivoRemoto['mtime'] > $infoArquivoLocal['mtime']) {
                $diretoriosABaixar[] = $diretorio.'/'.$arquivo;
            }
        }

        $progresso->finish();
        $this->info("\nLista gerada.");

        if (!count($diretoriosABaixar)) {
            $this->info("\nO diretório local encontra-se atualizado, nenhuma ação será tomda.");
            return;
        }

        $this->info("Os arquivos : ");
        foreach ($diretoriosABaixar as $key => $value) {
            $this->info(" {$value};");
        }
        $resposta = $this->ask("\nForam alterados posteriormente no servidor, deseja baixar esses arquivos? [s/n]");

        if (mb_strtoupper($resposta) == 'N') {
            return;
        }

        if (mb_strtoupper($resposta) != 'S') {
            $this->error("qual parte do [s/n] vc não entendeu??????????????????????????");
            return;
        }

        $this->baixarListaDiretorios($diretoriosABaixar, $ambiente);
    }

    private function commit()
    {
        $diretorio = $this->argument('arg2');
        $ambiente = $this->selecionaAmbiente($this->argument('arg1'));

        if ($ambiente === false) {
            return;
        }

        $diretorioLocal = self::$diretorioLocal . $diretorio;
        $diretorioRemoto = $ambiente['raiz'] . $diretorio;

        $this->sftp = self::conectarServidor($ambiente);
        if ($this->sftp === false) {
            $this->error("Falha ao se conectar com o servidor, verifique as credenciais informadas no ambiente.");
        }

        $listaDiretoriosLocais = $this->listaDiretoriosLocais($diretorioLocal);

        $diretoriosASubir = [];

        $this->info('Calculando lista de diretorios atualizados');
        $progresso = $this->output->createProgressBar(count($listaDiretoriosLocais));
        $progresso->start();

        foreach ($listaDiretoriosLocais as $k => $arquivo) {
            $progresso->advance();
            $caminhoRemoto = $diretorioRemoto . '/' . $arquivo;
            $caminhoLocal = $diretorioLocal . '/' . $arquivo;

            $infoArquivoRemoto = $this->sftp->stat($caminhoRemoto);
            $conteudoArquivoRemoto = $this->sftp->get($caminhoRemoto);

            if ($conteudoArquivoRemoto === false) {
                $diretoriosASubir[] = $diretorio.'/'.$arquivo;
                continue;
            }

            $infoArquivoLocal = stat($caminhoLocal);
            $conteudoArquivoLocal = file_get_contents($caminhoLocal);

            if ($conteudoArquivoLocal == $conteudoArquivoRemoto) {
                continue;
            }

            if ($infoArquivoLocal['mtime'] > $infoArquivoRemoto['mtime']) {
                $diretoriosASubir[] = $diretorio.'/'.$arquivo;
            }
        }

        $progresso->finish();
        $this->info("\nLista gerada.");

        if (!count($diretoriosASubir)) {
            $this->info("O diretório remoto encontra-se atualizado, nenhuma ação será tomda.");
            return;
        }

        $this->info("Os arquivos : ");
        foreach ($diretoriosASubir as $k => $value) {
            $this->info(" {$value};");
        }
        $resposta = $this->ask("Foram alterados, deseja enviar esses arquivos? [s/n]");

        if (mb_strtoupper($resposta) == 'N') {
            return;
        }

        if (mb_strtoupper($resposta) != 'S') {
            $this->error("qual parte do [s/n] vc não entendeu??????????????????????????");
            return;
        }

        $this->criarBackupLocal($diretoriosASubir, $ambiente);

        $this->subirArquivos($diretoriosASubir, $ambiente);
    }

    private function serve()
    {
        $diretorio = $this->argument('arg1');
        $porta = $this->argument('arg2') ?? '8000';
        $host = $this->argument('arg3') ?? 'localhost';

        $diretorio = self::$diretorioLocal . $diretorio;

        if (!is_dir($diretorio)) {
            $this->error("diretório {$diretorio} não encontrado");
            return;
        }

        $this->info("$diretorio");
        $this->info("Diretório hospedado em: http://{$host}:{$porta}");

        $process = new Process(["php", "-S", "{$host}:{$porta}"]);
        $process->setWorkingDirectory($diretorio);
        $process->setTimeout(0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function ignore()
    {
        $diretorio = $this->argument('arg2');
        $ambiente = $this->selecionaAmbiente($this->argument('arg1'));
    }

    private function criarBackupLocal($diretorios, $ambiente)
    {
        $zipFilename = self::$diretorioBackup . "COMMIT_{$ambiente['nome']}_" . Date("d-m-Y-H-i-s") . ".zip";

        self::criarDiretorioLocal($zipFilename);

        $zip = new ZipArchive();
        $zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $this->info("Criando backup dos arquivos...");

        $progresso = $this->output->createProgressBar(count($diretorios));
        $progresso->start();

        foreach ($diretorios as $dir) {
            $novoDiretorioExterno = $ambiente['raiz'] . $dir;

            $conteudo = $this->sftp->get($novoDiretorioExterno);

            if ($conteudo === false) {
                $this->error("Falha ao copiar conteúdo");
                return false;
            }

            $zip->addFromString($dir, $conteudo);

            $progresso->advance();
        }
        $progresso->finish();

        echo "\n";
        $this->info("Arquivos salvos com sucesso!");
    }

    private function subirArquivos($diretorios, $ambiente)
    {
        $this->info("Enviando arquivos...");

        $progresso = $this->output->createProgressBar(count($diretorios));
        $progresso->start();

        foreach ($diretorios as $dir) {
            $diretorioExterno = $ambiente['raiz'] . $dir;
            $diretorioLocal = self::$diretorioLocal . $dir;

            if ($this->sftp->put($diretorioExterno, file_get_contents($diretorioLocal)) === false) {
                $this->error("Falha ao enviar conteúdo");
                return;
            }

            $progresso->advance();
        }
        $progresso->finish();

        echo "\n";
        $this->info("Arquivos enviados com sucesso!");
    }

    private function comparaDiretorios($ambiente, $diretorio)
    {
        $diretorioExterno = $ambiente['raiz'] . $diretorio;

        $informacoesDiretorios = [
            'remoto' => [],
            'local' => []
        ];

        $this->info("Buscando informações dos diretórios remotos...");
        $listaDiretoriosRemotos = $this->listarDiretoriosRemmotos($diretorioExterno);

        $progresso = $this->output->createProgressBar(count($listaDiretoriosRemotos));
        $progresso->start();

        foreach ($listaDiretoriosRemotos as $key => $dir) {
            $fileInfo = $this->sftp->stat($diretorioExterno . '/' . $dir);
            $dirIndex = str_replace($ambiente['raiz'], '', $diretorioExterno . '/' . $dir);

            $informacoesDiretorios['remoto'][$dirIndex] = ['mtime' => $fileInfo['mtime'], 'size' => $fileInfo['size']];
            $progresso->advance();
        }

        $progresso->finish();
        echo "\n";
        $this->info("Busca concluída");

        $dirPath = self::$diretorioLocal . $diretorio;

        $this->info("Buscando informações dos diretórios locais...");

        $listaDiretoriosLocais = $this->listaDiretoriosLocais($dirPath);

        $this->info("Busca concluída");

        $this->info("Comparando arquivos...");

        foreach ($informacoesDiretorios['local'] as $dir => $info) {
            if (!isset($informacoesDiretorios['remoto'][$dir])) {
                continue;
            }

            $iguais = $informacoesDiretorios['remoto'][$dir]['mtime'] == $informacoesDiretorios['local'][$dir]['mtime'];

            if ($iguais) {
                unset($informacoesDiretorios['remoto'][$dir]);
                unset($informacoesDiretorios['local'][$dir]);
                continue;
            }

            $menosRecente = $informacoesDiretorios['remoto'][$dir]['mtime'] < $info['mtime'] ? 'remoto' : 'local';

            unset($informacoesDiretorios[$menosRecente][$dir]);
        }

        foreach ($informacoesDiretorios['remoto'] as $dir => $info) {
            if (!isset($informacoesDiretorios['local'][$dir])) {
                continue;
            }

            $iguais = $informacoesDiretorios['remoto'][$dir]['mtime'] == $informacoesDiretorios['local'][$dir]['mtime'];
            if ($iguais) {
                unset($informacoesDiretorios['remoto'][$dir]);
                unset($informacoesDiretorios['local'][$dir]);
                continue;
            }

            $menosRecente = $informacoesDiretorios['local'][$dir]['mtime'] > $info['mtime'] ? 'local' : 'remoto';

            unset($informacoesDiretorios[$menosRecente][$dir]);
        }

        $listaRemoto = [];
        foreach ($informacoesDiretorios['remoto'] as $dir => $info) {
            $listaRemoto[] = $dir;
        }
        $informacoesDiretorios['remoto'] = $listaRemoto;

        $listaRemoto = [];
        foreach ($informacoesDiretorios['local'] as $dir => $info) {
            $listaRemoto[] = $dir;
        }
        $informacoesDiretorios['local'] = $listaRemoto;

        $this->info("Arquivos comparados");

        return $informacoesDiretorios;
    }

    private function baixarListaDiretorios(array $diretorios, $ambiente)
    {
        $this->info("Baixando arquivos...");

        $progresso = $this->output->createProgressBar(count($diretorios));
        $progresso->start();

        foreach ($diretorios as $dir) {
            $novoDiretorioExterno = $ambiente['raiz'] . $dir;
            $novoDiretorioLocal = self::$diretorioLocal . $dir;

            self::criarDiretorioLocal($novoDiretorioLocal);
            if (file_put_contents($novoDiretorioLocal, $this->sftp->get($novoDiretorioExterno)) === false) {
                $this->error("Falha ao copiar conteúdo");
            }

            $progresso->advance();
        }
        $progresso->finish();

        echo "\n";
        $this->info("Arquivos baixados com sucesso!");
    }

    private function selecionaAmbiente(string $nomeAmbiente)
    {
        $nomesAmbientes = Arr::pluck($this->ambientes, 'nome');
        $indiceAmbienteSelecionado = array_keys($nomesAmbientes, $nomeAmbiente);

        if (!count($indiceAmbienteSelecionado)) {
            $this->error("Ambiente '{$nomeAmbiente}' não encontrado");
            return false;
        }
        return $this->ambientes[$indiceAmbienteSelecionado[0]];
    }

    private static function getAmbientes()
    {
        $projetos = file_get_contents(public_path('ambientes.json'));
        return json_decode($projetos, true);
    }

    private static function conectarServidor($ambiente)
    {
        echo "Conectando ao servidor {$ambiente['host']}\n";

        $key = new RSA();
        $key->setPassword($ambiente['password']);
        $key->loadKey(file_get_contents($ambiente['keyFile']));

        $sftp = new SFTP($ambiente['host']);

        if (!$sftp->login($ambiente['user'], $key)) {
            return false;
        }

        return $sftp;
    }

    private static function criarDiretorioLocal($diretorio)
    {
        $diretoriosArray = explode('/', $diretorio);
        array_pop($diretoriosArray);
        $diretorio = implode('/', $diretoriosArray);

        if (!is_dir($diretorio)) {
            mkdir($diretorio, 0777, true);
        }
    }

    private static function removerIgnore(&$estruturaDiretorios)
    {
        $results = array_filter($estruturaDiretorios, function ($value) {
            return Str::contains($value, '.ignore');
        });

        foreach ($results as $key => $value) {
            $serachString = str_replace(".ignore", '', $value);

            foreach ($estruturaDiretorios as $chave => $dir) {
                if (Str::contains($dir, $serachString)) {
                    unset($estruturaDiretorios[$chave]);
                }
            }
        }
    }

    private function listarDiretoriosRemmotos($diretorioExterno)
    {
        $estruturaDiretorios = $this->sftp->nlist($diretorioExterno, true);
        unset($estruturaDiretorios[array_search('.', $estruturaDiretorios)]);
        unset($estruturaDiretorios[array_search('..', $estruturaDiretorios)]);
        self::removerIgnore($estruturaDiretorios);

        return $estruturaDiretorios;
    }

    private function listaDiretoriosLocais($dirPath)
    {
        $dirIterator = new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS);
        $dirIterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);

        $listaLocal = [];

        foreach ($dirIterator as $filePath => $fileInfo) {
            $filePath = str_replace('\\', '/', $filePath);

            if ($fileInfo->isFile()) {
                $filePath = str_replace($dirPath.'/', '', $filePath);
                $listaLocal[] = $filePath;
            }
        }

        return $listaLocal;
    }

    private function apresentacao()
    {
        $this->info("Esse é o sighub, um projeto independente desenvolvido por Pablo Fim, como uma tentativa de sanar a dor da dinamica de trabalho com FTP no ISS legado da SIGCORP.");
        $this->info("comandos aceitos:");
        $this->info("sighub ambientes");
        $this->info(" -> lista os ambinetes salvos no Sighub. Os ambientes são uma abstração dos servidores de arquivos que possuem o código fonte do legado");
        $this->info("\nsighub ambientes novo");
        $this->info(" -> abre as opções para definir novos ambinetes para o Sighub");
        $this->info("\nsighub clone 'ambiente' 'diretorio'");
        $this->info(" -> baixa para o ambiente local todos os arquivos do ambiente remoto (com excessão dos diretorios que possuam um arquivo .ignore)");
        $this->info(" -->'ambiente' : nome do ambiente previamente cadastrado");
        $this->info(" -->'diretorio' : caminho do diretorio que deseja colnar, apartir do diretorio raiz cadastrado");
        $this->info("\nsighub pull 'ambinete' 'diretorio'");
        $this->info(" ->compara os arquivos remotos e locais, baixa apenas dos arquivos mais recentes");
        $this->info(" -->'ambiente' : nome do ambiente previamente cadastrado");
        $this->info(" -->'diretorio' : caminho do diretorio que deseja atualizar, apartir do diretorio raiz cadastrado");
        $this->info("\nsighub commit 'ambinete' 'diretorio'");
        $this->info(" ->compara os arquivos remotos e locais, faz upload apenas dos arquivos mais recentes, slavando uma cópia dos arquivos sobreescritos no diretorio remoto");
        $this->info(" -->'ambiente' : nome do ambiente previamente cadastrado");
        $this->info(" -->'diretorio' : caminho do diretorio que deseja atualizar, apartir do diretorio raiz cadastrado");
        $this->info("\nsighub serve 'diretorio' 'porta?' 'host?'");
        $this->info(" ->inicia um servidor PHP no diretorio local informado");
        $this->info(" -->'diretorio' : caminho do diretorio que deseja servir");
        $this->info(" -->'porta?' : OPCIONAL, padrão 8000, porta onde o servidor servirá o diretório");
        $this->info(" -->'host?' : OPCIONAL, padrão localhost, host onde o servidor servirá o diretório");
    }
}
