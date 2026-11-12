<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    protected $fillable = ['account_id', 'period', 'whatsapp_in', 'whatsapp_out', 'ai_calls', 'ai_input_tokens', 'ai_output_tokens'];

    public static function bump(string $accountId, array $increments): void
    {
        $period = now()->format('Y-m');
        static::query()->firstOrCreate(['account_id' => $accountId, 'period' => $period]);
        static::query()->where(['account_id' => $accountId, 'period' => $period])->incrementEach(array_map('intval', $increments));
    }

    public static function current(string $accountId): self
    {
        return static::query()->firstOrNew(['account_id' => $accountId, 'period' => now()->format('Y-m')], ['whatsapp_in' => 0, 'whatsapp_out' => 0, 'ai_calls' => 0, 'ai_input_tokens' => 0, 'ai_output_tokens' => 0]);
    }
}
