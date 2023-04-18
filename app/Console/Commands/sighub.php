<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class sighub extends Command
{
    protected $signature = 'sighub {acao} {arg1?} {arg2?} {arg3?}';
    protected $description = 'Command description';
    private array $ambientes;

    private static string $diretorioLocal = 'C:/SigHub/';

    private $sftp;

    public function handle()
    {
        $this->ambientes = self::getAmbientes();

        $opcao = $this->argument('acao');
        if (method_exists(self::class, $opcao)) {
            $this->$opcao();
            return;
        }
        $this->error('comando não encontrado');
    }

    private function clone()
    {
        $diretorio = $this->argument('arg2');
        $ambiente = $this->argument('arg1');

        $nomesAmbientes = Arr::pluck($this->ambientes, 'nome');
        $indiceAmbienteSelecionado = array_keys($nomesAmbientes, $ambiente);

        if (!count($indiceAmbienteSelecionado)) {
            $this->error("Ambiente '{$ambiente}' não encontrado");
            return;
        }
        $ambiente = $this->ambientes[$indiceAmbienteSelecionado[0]];

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
            if(file_put_contents($novoDiretorioLocal, $this->sftp->get($novoDiretorioExterno)) === false) {
                $this->error("Falha ao copiar conteúdo");
            }

            $progresso->advance();
        }
        $progresso->finish();

    }

    private function serve()
    {
        $diretorio = $this->argument('arg1');
        $porta = $this->argument('arg2') ?? '8000';
        $host = $this->argument('arg3') ?? 'localhost';

        $diretorio = self::$diretorioLocal . $diretorio;

        if(!is_dir($diretorio))
        {
            $this->error("diretório {$diretorio} não encontrado");
            return;
        }

        $this->info("Diretório hospedado em: http://{$host}:{$porta}");

        $process = new Process(["php", "-S", "{$host}:{$porta}"]);
        $process->setWorkingDirectory($diretorio);
        $process->setTimeout(0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
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
}
