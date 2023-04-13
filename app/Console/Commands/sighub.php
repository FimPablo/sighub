<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;

class sighub extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sighub';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $host = '172.16.20.111';
        $username = 'pfim';
        $keyfile = public_path('pfim_etherium_dev');
        $keyfile_password = '@8dwLF';

        $key = new RSA();
        $key->setPassword($keyfile_password);
        $key->loadKey(file_get_contents($keyfile));

        $sftp = new SFTP($host);
        if (!$sftp->login($username, $key)) {
            exit('Login failed');
        }

        $file_contents = $sftp->get('/var/www/sigiss/arquivosComuns/utils/Formatacoes.php');

        file_put_contents(public_path('teste.php'), $file_contents);

        $this->error('conectado');
    }
}
