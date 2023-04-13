<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class projetos extends Command
{
    protected $signature = 'sighub:projetos {opcao?}';
    protected $description = 'Command description';
    private array $projetos;

    public function handle()
    {
        $this->projetos = self::getProjetos();

        $opcao = $this->argument('opcao') ?? 'listarProjetos';

        if(method_exists(self::class, $opcao))
        {
            $this->$opcao();
            return;
        }

        $this->error('comando não encontrado');
    }

    private function listarProjetos()
    {
        if(!count($this->projetos))
        {
            $this->error('Nenhum ambiente configurado, utilize o  comando sighub:ambietes novo');
            return;
        }

        foreach ($this->projetos as $nomeProjeto => $ambientes) {
            $this->info("Projeto {$nomeProjeto}:");

            foreach ($ambientes as $ambiente) {
                echo " Ambientes:\n";
                $this->info("  {$ambiente['nome']}");
                echo "   host: {$ambiente['host']} \n";
                echo "   usuário: {$ambiente['user']} \n";
                echo "   chave: {$ambiente['keyFile']} \n\n";
            }

        }
    }

    private function novo()
    {
        do {
            $nomeProjeto = $this->ask('Defina um nome para o projeto');

            if(isset($this->projetos[$nomeProjeto]))
            {
                $this->error('Já existe um projeto com esse nome, defina um nome diferente.');
            }

        } while (isset($this->projetos[$nomeProjeto]));


        $ambientes = [];
        $novoAmbiente = true;

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

            $novoAmbiente = false;

        } while ($novoAmbiente);

        $this->projetos[$nomeProjeto] = $ambientes;

        file_put_contents(public_path('projetos.json'), json_encode($this->projetos));
    }

    private static function getProjetos()
    {
        $projetos = file_get_contents(public_path('projetos.json'));
        return json_decode($projetos, true);
    }
}
