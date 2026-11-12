<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pós-venda automático: confirmação de entrega, indicação (0 a 10) e pedido da nota fiscal. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('followup_at')->nullable()->index();      // quando perguntar se entregou
            $t->unsignedTinyInteger('followup_count')->default(0);
            $t->timestamp('delivery_confirmed_at')->nullable();     // cliente confirmou que recebeu
            $t->string('delivery_issue', 500)->nullable();          // "ainda não chegou" / problema relatado
            $t->timestamp('delivery_issue_at')->nullable();
            $t->timestamp('delivery_issue_resolved_at')->nullable();
        });
        Schema::table('quotes', function (Blueprint $t) {
            $t->unsignedTinyInteger('nps_score')->nullable();       // 0 a 10: indicaria a outra pessoa?
            $t->timestamp('nps_at')->nullable();
            $t->timestamp('followup_at')->nullable()->index();      // cotação apresentada sem pedido: "conseguiu comprar?"
            $t->unsignedTinyInteger('followup_count')->default(0);
            $t->string('customer_reported', 12)->nullable();        // comprou | desistiu
            $t->timestamp('receipt_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['followup_at', 'followup_count', 'delivery_confirmed_at', 'delivery_issue', 'delivery_issue_at', 'delivery_issue_resolved_at']));
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn(['nps_score', 'nps_at', 'followup_at', 'followup_count', 'customer_reported', 'receipt_requested_at']));
    }
};
