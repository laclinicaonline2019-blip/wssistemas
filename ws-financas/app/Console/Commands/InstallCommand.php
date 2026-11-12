<?php

namespace App\Console\Commands;

use App\Core\Security\PasswordRules;
use App\Services\Installer;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'wsf:install {--name=} {--email=} {--password=}';

    protected $description = 'Instala o WS Finanças: migrações, planos e usuário proprietário da plataforma';

    public function handle(Installer $installer): int
    {
        $name = $this->option('name') ?: $this->ask('Nome do proprietário', 'Proprietário');
        $email = $this->option('email') ?: $this->ask('E-mail do proprietário');
        $password = $this->option('password') ?: $this->secret('Senha (mín. 10, maiúsc., minúsc., número e símbolo)');
        $v = validator(['email' => $email, 'password' => $password], ['email' => 'required|email', 'password' => ['required', PasswordRules::default()]]);
        if ($v->fails()) {
            $this->error(implode(' ', $v->errors()->all()));

            return self::FAILURE;
        }
        // segredos próprios da instalação (gerados uma única vez)
        $env = base_path('.env');
        if (is_writable($env)) {
            $content = (string) file_get_contents($env);
            foreach (['AUDIT_HMAC_KEY' => bin2hex(random_bytes(32)), 'BACKUP_PASSWORD' => bin2hex(random_bytes(24)), 'WHATSAPP_VERIFY_TOKEN' => bin2hex(random_bytes(16)), 'ASAAS_WEBHOOK_TOKEN' => bin2hex(random_bytes(24))] as $k => $v) {
                if (preg_match('/^'.$k.'=\S+/m', $content)) {
                    continue;
                }
                $content = preg_match('/^'.$k.'=.*$/m', $content) ? preg_replace('/^'.$k.'=.*$/m', $k.'='.$v, $content) : $content."\n".$k.'='.$v;
                config([$k === 'AUDIT_HMAC_KEY' ? 'wsf.security.audit_hmac_key' : ($k === 'BACKUP_PASSWORD' ? 'wsf.backup.password' : 'app.'.strtolower($k)) => $v]);
            }
            file_put_contents($env, $content);
        }
        $installer->finish($name, $email, $password);
        $this->info('Instalado. Entre em /entrar com '.$email.' e ative a verificação em duas etapas.');

        return self::SUCCESS;
    }
}
