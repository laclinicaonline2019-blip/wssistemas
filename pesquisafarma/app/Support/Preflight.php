<?php

namespace App\Support;

use App\Models\City;
use App\Models\Pharmacy;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Verificação de prontidão para produção. Cada item: [nível ok|aviso|erro, título, detalhe]. */
class Preflight
{
    /** @return array<int, array{0:string,1:string,2:string}> */
    public static function run(): array
    {
        $r = [];
        $add = function (string $level, string $title, string $detail = '') use (&$r) {
            $r[] = [$level, $title, $detail];
        };
        $prod = app()->environment('production');

        $add($prod ? 'ok' : 'erro', 'APP_ENV=production', 'atual: '.app()->environment());
        $add(config('app.debug') ? 'erro' : 'ok', 'APP_DEBUG desligado', config('app.debug') ? 'nunca deixe ligado em produção (mostra dados internos)' : '');
        $add(config('app.key') ? 'ok' : 'erro', 'APP_KEY gerada');
        $add(str_starts_with((string) config('app.url'), 'https://') ? 'ok' : 'erro', 'APP_URL com https://', (string) config('app.url'));
        $add(config('session.secure') ? 'ok' : 'erro', 'SESSION_SECURE_COOKIE=true', 'cookie de sessão só em HTTPS');
        $add(config('session.encrypt') ? 'ok' : 'aviso', 'SESSION_ENCRYPT=true');
        $add(config('database.default') === 'sqlite' ? 'aviso' : 'ok', 'Banco MySQL/MariaDB ou PostgreSQL', 'atual: '.config('database.default'));
        try {
            DB::select('select 1');
            $add('ok', 'Conexão com o banco');
        } catch (\Throwable $e) {
            $add('erro', 'Conexão com o banco', $e->getMessage());
        }
        $add(is_writable(storage_path('app')) ? 'ok' : 'erro', 'Pasta storage/ com permissão de escrita');

        $driver = (string) config('pesquisafarma.whatsapp_driver');
        $add($driver === 'mock' ? 'erro' : 'ok', 'WhatsApp real configurado', 'WHATSAPP_DRIVER='.$driver);
        $creds = match ($driver) {
            'zapi' => config('pesquisafarma.zapi.instance') && config('pesquisafarma.zapi.token') && config('pesquisafarma.zapi.client_token'),
            'evolution' => config('pesquisafarma.evolution.url') && config('pesquisafarma.evolution.instance') && config('pesquisafarma.evolution.api_key'),
            'meta' => config('pesquisafarma.meta.phone_number_id') && config('pesquisafarma.meta.access_token') && config('pesquisafarma.meta.verify_token') && config('pesquisafarma.meta.app_secret'),
            default => false,
        };
        $add($creds ? 'ok' : 'erro', 'Credenciais do provedor de WhatsApp', $driver === 'meta' ? 'inclui META_APP_SECRET (assinatura)' : '');
        $add(strlen((string) config('pesquisafarma.webhook_secret')) >= 24 && config('pesquisafarma.webhook_secret') !== 'troque-este-segredo' ? 'ok' : 'erro',
            'WHATSAPP_WEBHOOK_SECRET forte (24+ caracteres)', 'gere com: php -r "echo bin2hex(random_bytes(20));"');
        $add(filled(config('pesquisafarma.anthropic.api_key')) ? 'ok' : 'aviso', 'Chave da IA (ANTHROPIC_API_KEY)', 'sem ela, itens só por texto e notas digitadas na conferência');

        $beat = Cache::get('pf:cron');
        $add($beat && now()->diffInMinutes($beat) < 5 ? 'ok' : 'erro', 'Agendador rodando (cron schedule:run a cada minuto)', $beat ? 'último: '.$beat : 'nunca rodou');

        $admins = User::query()->where('role', 'admin')->where('active', true)->get();
        $add($admins->contains(fn ($u) => $u->email === 'admin@pesquisafarma.local') ? 'erro' : 'ok', 'Sem administrador padrão/demonstração', 'crie o seu com pf:admin e desative o padrão');
        $add($admins->every(fn ($u) => $u->two_factor_confirmed_at) ? 'ok' : 'aviso', 'Administradores com verificação em duas etapas');
        $add(Setting::get('require_2fa_admin') === '1' ? 'ok' : 'aviso', 'Verificação em duas etapas obrigatória para administradores');
        $add(User::query()->where('email', 'like', '%@demo.local')->where('active', true)->exists() ? 'erro' : 'ok', 'Sem usuários de demonstração ativos');
        $add(Pharmacy::query()->where('trade_name', 'like', '%(fictícia)%')->orWhere('legal_name', 'like', '%(fictícia)%')->exists() ? 'erro' : 'ok', 'Sem farmácias fictícias (dados de demonstração)');
        $add(City::query()->where('active', true)->exists() ? 'ok' : 'erro', 'Cidades cadastradas');
        $active = Pharmacy::query()->where('active', true)->get();
        $add($active->isNotEmpty() ? 'ok' : 'erro', 'Farmácias credenciadas ativas', $active->count().' ativa(s)');
        $noCoord = $active->whereNull('lat')->count();
        $add($noCoord ? 'aviso' : 'ok', 'Farmácias com latitude/longitude', $noCoord ? "{$noCoord} sem coordenadas (distância aproximada pelo bairro)" : '');
        $noWa = $active->filter(fn ($p) => ! $p->whatsapp && ! $p->phone)->count();
        $add($noWa ? 'erro' : 'ok', 'Farmácias com WhatsApp para receber cotações', $noWa ? "{$noWa} sem WhatsApp" : '');
        $noCnpj = $active->whereNull('cnpj')->count();
        $add($noCnpj ? 'aviso' : 'ok', 'Farmácias com CNPJ (reconhece a nota fiscal)', $noCnpj ? "{$noCnpj} sem CNPJ" : '');

        return $r;
    }
}
