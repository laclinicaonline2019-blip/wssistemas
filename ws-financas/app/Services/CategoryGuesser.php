<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Categoria pela descrição (mesmas palavras-chave do painel original + categorias empresariais). */
final class CategoryGuesser
{
    private const KW = [
        ['Impostos e taxas', ['das', 'simples nacional', 'imposto', 'iss', 'icms', 'inss', 'fgts', 'darf', 'taxa', 'alvara', 'irpj', 'csll', 'pis', 'cofins']],
        ['Folha de pagamento', ['salario do', 'salario da', 'salarios', 'folha', 'funcionario', 'funcionaria', 'pro labore', 'prolabore', '13o', 'ferias', 'vale transporte', 'vale refeicao']],
        ['Fornecedores', ['fornecedor', 'fornecedores', 'mercadoria', 'estoque', 'materia prima', 'insumo', 'insumos', 'atacado']],
        ['Aluguel comercial', ['aluguel da loja', 'aluguel do escritorio', 'aluguel da sala', 'aluguel comercial', 'aluguel da empresa']],
        ['Marketing', ['marketing', 'anuncio', 'anuncios', 'google ads', 'facebook ads', 'instagram', 'panfleto', 'propaganda']],
        ['Tarifas bancárias', ['tarifa', 'tarifas', 'tarifa bancaria', 'maquininha', 'taxa do cartao', 'iof']],
        ['Serviços e manutenção', ['contador', 'contabilidade', 'manutencao', 'conserto', 'limpeza', 'sistema', 'software', 'honorarios']],
        ['Compras', ['mercado livre', 'shopee', 'amazon', 'shein', 'roupa', 'loja', 'presente', 'magalu']],
        ['Dívidas', ['cartao', 'fatura', 'emprestimo', 'parcela', 'acordo', 'cheque especial', 'consignado', 'nubank', 'financiamento', 'divida', 'serasa']],
        ['Moradia', ['aluguel', 'condominio', 'iptu', 'prestacao da casa']],
        ['Contas da casa', ['luz', 'energia', 'neoenergia', 'celpe', 'cemig', 'enel', 'light', 'copel', 'agua', 'compesa', 'sabesp', 'internet', 'gas', 'telefone', 'celular', 'tim', 'vivo', 'claro', 'oi']],
        ['Combustível', ['combustivel', 'gasolina', 'etanol', 'alcool', 'diesel', 'gnv', 'posto', 'abastecimento', 'abasteci', 'abastecer']],
        ['Refeições', ['almoco', 'jantar', 'janta', 'refeicao', 'refeicoes', 'marmita', 'marmitex', 'self service', 'cafe da manha', 'quentinha', 'prato feito']],
        ['Delivery e lanches', ['ifood', 'delivery', 'lanche', 'pizza', 'hamburguer', 'restaurante', 'rappi', 'acai', 'sorvete']],
        ['Alimentação', ['mercado', 'supermercado', 'feira', 'padaria', 'acougue', 'hortifruti', 'atacarejo', 'atacadao', 'assai']],
        ['Transporte', ['uber', '99', 'onibus', 'passagem', 'estacionamento', 'ipva', 'oficina', 'carro', 'moto', 'metro', 'pedagio']],
        ['Saúde', ['farmacia', 'remedio', 'plano de saude', 'medico', 'consulta', 'dentista', 'exame']],
        ['Educação', ['escola', 'faculdade', 'curso', 'material escolar', 'mensalidade']],
        ['Assinaturas', ['netflix', 'spotify', 'prime', 'disney', 'globoplay', 'max', 'youtube', 'assinatura', 'deezer', 'icloud']],
        ['Lazer', ['cinema', 'bar', 'festa', 'viagem', 'show', 'academia', 'praia', 'passeio']],
    ];

    private const INCOME = [
        ['Salário', ['salario', 'pagamento do mes', 'holerite']],
        ['Vendas', ['venda', 'vendas', 'vendi', 'pedido']],
        ['Recebimento de clientes', ['cliente', 'clientes', 'boleto recebido', 'pix recebido', 'nota fiscal']],
        ['Serviços prestados', ['servico', 'servicos', 'freela', 'freelance', 'consultoria', 'projeto']],
        ['Rendimentos', ['rendimento', 'rendimentos', 'juros', 'dividendos', 'aplicacao', 'cdb', 'poupanca']],
    ];

    /** Emoji usado nas respostas do WhatsApp. */
    public const EMOJI = [
        'Combustível' => '⛽', 'Refeições' => '🍽️', 'Alimentação' => '🛒', 'Delivery e lanches' => '🍔', 'Transporte' => '🚗', 'Moradia' => '🏠',
        'Contas da casa' => '💡', 'Saúde' => '💊', 'Educação' => '📚', 'Assinaturas' => '📺', 'Lazer' => '🎉', 'Compras' => '🛍️', 'Dívidas' => '💳',
        'Fornecedores' => '📦', 'Folha de pagamento' => '👥', 'Impostos e taxas' => '🧾', 'Aluguel comercial' => '🏢', 'Serviços e manutenção' => '🔧',
        'Marketing' => '📣', 'Tarifas bancárias' => '🏦', 'Outros' => '📌',
        'Salário' => '💼', 'Vendas' => '🛒', 'Serviços prestados' => '🧑‍💻', 'Recebimento de clientes' => '🤝', 'Rendimentos' => '📈', 'Outras entradas' => '💵',
    ];

    public static function emoji(?string $category): string
    {
        return self::EMOJI[$category ?? ''] ?? '📌';
    }

    /** Categoria pelo nome (ex.: "combustível", "contas da casa") ou por palavra-chave; null se não reconhecer. */
    public static function find(string $text, string $type = 'despesa'): ?string
    {
        $n = trim(preg_replace('/\s+/', ' ', self::norm($text)) ?? '');
        if ($n === '') {
            return null;
        }
        foreach ((array) config('wsf.categories.'.$type, []) as $cat) {
            if (trim(self::norm($cat)) === $n) {
                return $cat;
            }
        }

        return self::match($text, $type === 'receita' ? self::INCOME : self::KW);
    }

    public static function norm(string $s): string
    {
        return ' '.preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower(Str::ascii($s))).' ';
    }

    public static function guess(string $desc): string
    {
        return self::match($desc, self::KW) ?? 'Outros';
    }

    public static function guessIncome(string $desc): string
    {
        return self::match($desc, self::INCOME) ?? 'Outras entradas';
    }

    private static function match(string $desc, array $table): ?string
    {
        $n = preg_replace('/\s+/', ' ', self::norm($desc));
        foreach ($table as [$cat, $keys]) {
            foreach ($keys as $k) {
                if (str_contains($n, ' '.$k.' ') || str_contains($n, ' '.$k.'s ')) {
                    return $cat;
                }
            }
        }

        return null;
    }
}
