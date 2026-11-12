<?php

namespace App\Http\Controllers;

use App\Core\Security\PasswordRules;
use App\Services\Installer;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Instalador web (HostGator sem SSH): grava o .env, cria as tabelas, os planos e o proprietário. Trava após concluir. */
class InstallController extends Controller
{
    public function show(Installer $installer): View|Response
    {
        if ($installer->installed()) {
            return redirect('/');
        }

        return view('install.show', ['checks' => $installer->requirements()]);
    }

    public function run(Request $request, Installer $installer): View|Response
    {
        if ($installer->installed()) {
            return redirect('/');
        }
        $d = $request->validate([
            'app_url' => ['required', 'url'], 'db_host' => ['required', 'string'], 'db_port' => ['required', 'integer'], 'db_database' => ['required', 'string'],
            'db_username' => ['required', 'string'], 'db_password' => ['nullable', 'string'], 'admin_name' => ['required', 'string', 'max:160'],
            'admin_email' => ['required', 'email'], 'admin_password' => ['required', 'confirmed', PasswordRules::default()],
            'stage' => ['required', 'in:production,homologation'],
        ]);
        $result = $installer->install($d);

        return view('install.done', $result);
    }
}
