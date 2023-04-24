<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ambientes extends Command
{
    protected $signature = 'sighub:ambientes {opcao?} {projeto?} {acao?}';
    protected $description = 'Command description';
    private array $ambientes;

    public function handle()
    {
        $this->ambientes = self::getAmbientes();

        $opcao = $this->argument('opcao') ?? 'listarAmbientes';

        if (method_exists(self::class, $opcao)) {
            $this->$opcao();
            return;
        }

        $this->error('comando não encontrado');
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

    private function novoAmbiente()
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

    private static function getAmbientes()
    {
        $ambientes = file_get_contents(public_path('ambientes.json'));
        return json_decode($ambientes, true);
    }
}
