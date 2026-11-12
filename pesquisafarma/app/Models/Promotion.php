<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pendente', 'broadcast_count' => 0];

    protected $casts = ['price' => 'float', 'original_price' => 'float', 'valid_from' => 'date', 'valid_until' => 'date', 'broadcast_at' => 'datetime'];

    public const STATUSES = ['pendente' => 'Aguardando aprovação', 'aprovada' => 'Aprovada', 'recusada' => 'Recusada', 'divulgada' => 'Divulgada'];

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function isCurrent(): bool
    {
        return (! $this->valid_from || $this->valid_from->lte(today())) && (! $this->valid_until || $this->valid_until->gte(today()));
    }

    public function whatsappText(): string
    {
        $lines = ['🔥 *'.$this->title.'*'];
        if ($this->product_name) {
            $lines[] = $this->product_name;
        }
        if ($this->price) {
            $lines[] = ($this->original_price ? '~De '.money($this->original_price).'~ ' : '').'*Por '.money($this->price).'*';
        }
        if ($this->description) {
            $lines[] = $this->description;
        }
        if ($this->pharmacy) {
            $lines[] = '📍 '.$this->pharmacy->trade_name.($this->pharmacy->neighborhood ? ' — '.$this->pharmacy->neighborhood->name : '');
        }
        if ($this->valid_until) {
            $lines[] = 'Válido até '.$this->valid_until->format('d/m/Y').'.';
        }
        $lines[] = "\nQuer aproveitar? Responda esta mensagem! 💊";
        $lines[] = '_Para não receber ofertas, responda SAIR._';

        return implode("\n", $lines);
    }
}
