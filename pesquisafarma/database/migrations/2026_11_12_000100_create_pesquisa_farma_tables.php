<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estrutura completa do Pesquisa Farma: bairros, farmácias (regras de comissão e entrega),
 * clientes, WhatsApp, cotações, respostas das farmácias, pedidos, notas fiscais, comissões,
 * promoções, fidelidade (pontos/brindes), premiação e registro de atividades dos televendas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('neighborhoods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->index();
            $t->string('city')->default('');
            $t->string('uf', 2)->default('');
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->timestamps();
            $t->unique(['slug', 'city']);
        });

        Schema::create('pharmacies', function (Blueprint $t) {
            $t->id();
            $t->string('trade_name');
            $t->string('legal_name')->nullable();
            $t->string('cnpj', 18)->nullable()->unique();
            $t->string('phone', 20)->nullable();
            $t->string('whatsapp', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('responsible')->nullable();
            $t->string('cep', 9)->nullable();
            $t->string('address')->nullable();
            $t->string('number', 20)->nullable();
            $t->foreignId('neighborhood_id')->nullable()->constrained();
            $t->string('city')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            // Regra comercial
            $t->decimal('commission_percent', 5, 2)->default(0);
            $t->string('commission_cycle', 10)->default('mensal'); // semanal | mensal
            // Regras de entrega
            $t->boolean('delivers')->default(true);
            $t->decimal('delivery_radius_km', 6, 2)->default(5);
            $t->decimal('delivery_base_fee', 8, 2)->default(0);
            $t->decimal('delivery_fee_per_km', 8, 2)->default(0);
            $t->decimal('free_delivery_above', 10, 2)->nullable();
            $t->unsignedInteger('delivery_eta_min')->default(60);
            $t->string('opening_hours')->nullable();
            $t->string('payment_methods')->nullable();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('pharmacy_delivery_zones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $t->foreignId('neighborhood_id')->constrained()->cascadeOnDelete();
            $t->decimal('fee', 8, 2)->default(0);
            $t->unsignedInteger('eta_min')->nullable();
            $t->unique(['pharmacy_id', 'neighborhood_id']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->default('televendas')->after('email'); // admin | televendas | farmacia
            $t->foreignId('pharmacy_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $t->string('phone', 20)->nullable()->after('pharmacy_id');
            $t->boolean('active')->default(true)->after('phone');
            $t->timestamp('last_login_at')->nullable();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('phone', 20)->unique();
            $t->string('cpf', 14)->nullable()->unique();
            $t->string('email')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('cep', 9)->nullable();
            $t->string('address')->nullable();
            $t->string('number', 20)->nullable();
            $t->string('complement')->nullable();
            $t->foreignId('neighborhood_id')->nullable()->constrained();
            $t->string('city')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->boolean('loyalty_enabled')->default(true);
            $t->integer('points_balance')->default(0);
            $t->boolean('accepts_marketing')->default(false);
            $t->timestamp('lgpd_consent_at')->nullable();
            $t->string('bot_state', 30)->nullable(); // etapa do cadastro / aguardando avaliação
            $t->json('bot_data')->nullable();
            $t->string('origin', 20)->default('whatsapp');
            $t->text('notes')->nullable();
            $t->string('portal_code')->nullable();
            $t->timestamp('portal_code_expires_at')->nullable();
            $t->timestamp('last_contact_at')->nullable();
            $t->timestamps();
        });

        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            // recebida → enviada → respondida → apresentada → pedido → finalizada | perdida | cancelada
            $t->string('status', 20)->default('recebida')->index();
            $t->string('channel', 20)->default('whatsapp'); // whatsapp | telefone | portal | balcao
            $t->text('raw_text')->nullable();
            $t->string('attachment_path')->nullable();
            $t->string('attachment_mime')->nullable();
            $t->boolean('ai_extracted')->default(false);
            $t->string('scope', 10)->default('proximas'); // proximas | ampliada
            $t->decimal('radius_km', 6, 2)->nullable();
            $t->string('fulfillment', 10)->default('entrega'); // entrega | retirada
            $t->foreignId('neighborhood_id')->nullable()->constrained();
            $t->string('delivery_address')->nullable();
            $t->timestamp('requested_at')->nullable();
            $t->timestamp('attended_at')->nullable();
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('response_deadline_at')->nullable();
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('presented_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedTinyInteger('feedback_rating')->nullable();
            $t->text('feedback_comment')->nullable();
            $t->timestamp('feedback_at')->nullable();
            $t->string('lost_reason')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('quote_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->string('presentation')->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->string('preference', 12)->default('qualquer'); // qualquer | referencia | generico | similar
            $t->string('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('quote_pharmacies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pharmacy_id')->constrained();
            $t->string('token', 64)->unique();
            $t->decimal('distance_km', 8, 2)->nullable();
            $t->boolean('within_radius')->default(true);
            // pendente | visualizada | respondida | recusada | expirada
            $t->string('status', 12)->default('pendente')->index();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('delivers')->default(false);
            $t->decimal('delivery_fee', 8, 2)->nullable();
            $t->unsignedInteger('delivery_eta_min')->nullable();
            $t->string('payment_methods')->nullable();
            $t->text('notes')->nullable();
            $t->decimal('subtotal', 10, 2)->nullable();
            $t->decimal('total', 10, 2)->nullable();
            $t->unsignedInteger('items_available')->default(0);
            $t->unsignedInteger('rank')->nullable();
            $t->timestamps();
            $t->unique(['quote_id', 'pharmacy_id']);
        });

        Schema::create('quote_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_pharmacy_id')->constrained()->cascadeOnDelete();
            $t->foreignId('quote_item_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12); // referencia (ético) | generico | similar
            $t->string('product_name')->nullable();
            $t->string('laboratory')->nullable();
            $t->decimal('unit_price', 10, 2);
            $t->boolean('available')->default(true);
            $t->timestamps();
        });

        Schema::create('quote_suggestions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_pharmacy_id')->constrained()->cascadeOnDelete();
            $t->string('product_name');
            $t->string('description')->nullable();
            $t->decimal('price', 10, 2);
            $t->decimal('original_price', 10, 2)->nullable();
            $t->boolean('accepted')->default(false);
            $t->timestamps();
        });

        Schema::create('commission_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pharmacy_id')->constrained();
            $t->string('cycle', 10);
            $t->date('period_start');
            $t->date('period_end');
            $t->unsignedInteger('sales_count')->default(0);
            $t->decimal('base_total', 12, 2)->default(0);
            $t->decimal('commission_amount', 12, 2)->default(0);
            $t->string('status', 10)->default('aberto'); // aberto | emitido | pago | cancelado
            $t->timestamp('paid_at')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['pharmacy_id', 'period_start']);
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('pharmacy_id')->constrained();
            $t->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 12)->default('confirmado'); // confirmado | entregue | cancelado
            $t->string('fulfillment', 10)->default('entrega');
            $t->decimal('subtotal', 10, 2)->default(0);
            $t->decimal('delivery_fee', 8, 2)->default(0);
            $t->decimal('total', 10, 2)->default(0);
            $t->decimal('receipt_total', 10, 2)->nullable(); // total da nota fiscal conferida
            $t->decimal('commission_percent', 5, 2)->default(0);
            $t->decimal('commission_base', 10, 2)->default(0);
            $t->decimal('commission_amount', 10, 2)->default(0);
            $t->foreignId('commission_statement_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('quote_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->string('type', 12)->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->decimal('unit_price', 10, 2);
            $t->decimal('total', 10, 2);
            $t->boolean('is_suggestion')->default(false);
        });

        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('pharmacy_id')->nullable()->constrained();
            $t->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('file_path')->nullable();
            $t->string('mime')->nullable();
            $t->string('source', 12)->default('whatsapp'); // whatsapp | portal | painel
            $t->string('status', 12)->default('pendente'); // pendente | conferida | rejeitada
            $t->string('issuer_cnpj', 18)->nullable();
            $t->string('issuer_name')->nullable();
            $t->string('nf_number')->nullable();
            $t->string('nf_key', 60)->nullable();
            $t->date('purchase_date')->nullable();
            $t->decimal('total', 10, 2)->nullable();
            $t->json('ai_data')->nullable();
            $t->decimal('commission_percent', 5, 2)->default(0);
            $t->decimal('commission_amount', 10, 2)->default(0);
            $t->foreignId('commission_statement_id')->nullable()->constrained()->nullOnDelete();
            $t->integer('points_awarded')->default(0);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('reject_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('receipt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->decimal('quantity', 8, 2)->default(1);
            $t->decimal('unit_price', 10, 2)->default(0);
            $t->decimal('total', 10, 2)->default(0);
            $t->foreignId('quote_item_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('quoted_unit_price', 10, 2)->nullable();
        });

        Schema::create('wa_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('pharmacy_id')->nullable()->constrained()->nullOnDelete();
            $t->string('phone', 20)->index();
            $t->string('direction', 3); // in | out
            $t->string('type', 10)->default('text'); // text | image | document | audio
            $t->text('body')->nullable();
            $t->string('media_path')->nullable();
            $t->string('mime')->nullable();
            $t->string('provider_id')->nullable()->index();
            $t->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('ok'); // ok | falhou
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pharmacy_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('product_name')->nullable();
            $t->decimal('price', 10, 2)->nullable();
            $t->decimal('original_price', 10, 2)->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();
            $t->string('image_path')->nullable();
            $t->string('status', 12)->default('pendente'); // pendente | aprovada | recusada | divulgada
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('broadcast_at')->nullable();
            $t->unsignedInteger('broadcast_count')->default(0);
            $t->timestamps();
        });

        Schema::create('loyalty_rules', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type', 12); // por_valor | por_produto | por_farmacia
            $t->decimal('points_per_real', 8, 2)->default(0);
            $t->string('keyword')->nullable();
            $t->integer('bonus_points')->default(0);
            $t->decimal('multiplier', 5, 2)->default(1);
            $t->foreignId('pharmacy_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('min_purchase', 10, 2)->default(0);
            $t->boolean('active')->default(true);
            $t->date('valid_until')->nullable();
            $t->timestamps();
        });

        Schema::create('rewards', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->integer('points_cost');
            $t->integer('stock')->nullable();
            $t->unsignedInteger('min_purchases')->default(0); // nº mínimo de compras conferidas
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('reward_id')->constrained();
            $t->integer('points');
            $t->string('status', 12)->default('solicitado'); // solicitado | entregue | cancelado
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });

        Schema::create('points_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained();
            $t->integer('points');
            $t->string('reason');
            $t->foreignId('receipt_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('redemption_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('prize_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('month', 7); // AAAA-MM
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('rule', 12)->default('sorteio'); // sorteio | ranking
            $t->decimal('min_total', 10, 2)->default(0);
            $t->foreignId('winner_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->timestamp('drawn_at')->nullable();
            $t->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 60)->index();
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('description');
            $t->json('meta')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('ai_requests', function (Blueprint $t) {
            $t->id();
            $t->string('purpose', 30);
            $t->string('model')->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('latency_ms')->default(0);
            $t->string('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ai_requests', 'activity_logs', 'prize_campaigns', 'points_ledger', 'redemptions', 'rewards', 'loyalty_rules', 'promotions',
            'wa_messages', 'receipt_items', 'receipts', 'order_items', 'orders', 'commission_statements', 'quote_suggestions', 'quote_offers',
            'quote_pharmacies', 'quote_items', 'quotes', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('pharmacy_id');
            $t->dropColumn(['role', 'phone', 'active', 'last_login_at']);
        });
        foreach (['pharmacy_delivery_zones', 'pharmacies', 'neighborhoods', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
