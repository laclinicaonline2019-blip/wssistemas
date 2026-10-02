<?php

namespace App\Modules\Insurance\Tiss;

use App\Core\Support\BusinessRuleViolation;
use App\Modules\Insurance\Models\Batch;
use App\Modules\Insurance\Models\Guide;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;

/**
 * Mensagem TISS de envio de lote de guias (ENVIO_LOTE_GUIAS), versão 4.01.00.
 *
 * - Estrutura conforme os schemas oficiais da ANS em resources/tiss/4.01.00 (tissV4_01_00.xsd);
 *   o XML é VALIDADO contra o XSD antes de fechar o lote — XML inválido não é gerado.
 * - Epílogo: hash MD5 da concatenação dos valores de todos os elementos da mensagem
 *   (sem tags/atributos), em ISO-8859-1, como define o componente de comunicação do TISS.
 * - Envio: o arquivo é baixado e enviado no portal da operadora (webservice TISS
 *   específico de cada operadora não está implementado).
 */
class TissMessageBuilder
{
    public const NS = 'http://www.ans.gov.br/padroes/tiss/schemas';

    /** UF → código IBGE (dm_UF). */
    private const UF = [
        'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17', 'MA' => '21', 'PI' => '22',
        'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27', 'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32',
        'RJ' => '33', 'SP' => '35', 'PR' => '41', 'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
    ];

    private DOMDocument $doc;

    /** @return array{xml: string, hash: string} */
    public function build(Batch $batch, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('America/Sao_Paulo');
        $batch->loadMissing(['insurer', 'branch', 'guides.items', 'guides.doctor', 'guides.authorization']);
        $insurer = $batch->insurer;
        $provider = $this->provider($batch);

        $this->doc = new DOMDocument('1.0', 'ISO-8859-1');
        $this->doc->formatOutput = true;
        $root = $this->doc->createElementNS(self::NS, 'ans:mensagemTISS');
        $this->doc->appendChild($root);

        $head = $this->el($root, 'cabecalho');
        $ident = $this->el($head, 'identificacaoTransacao');
        $this->el($ident, 'tipoTransacao', 'ENVIO_LOTE_GUIAS');
        $this->el($ident, 'sequencialTransacao', $batch->number);
        $this->el($ident, 'dataRegistroTransacao', $now->toDateString());
        $this->el($ident, 'horaRegistroTransacao', $now->format('H:i:s'));
        $this->el($this->el($this->el($head, 'origem'), 'identificacaoPrestador'), $provider['origin_tag'], $provider['value']);
        $this->el($this->el($head, 'destino'), 'registroANS', $insurer->ans_registry);
        $this->el($head, 'Padrao', $insurer->tiss_version);

        $lote = $this->el($this->el($root, 'prestadorParaOperadora'), 'loteGuias');
        $this->el($lote, 'numeroLote', $batch->number);
        $guias = $this->el($lote, 'guiasTISS');

        foreach ($batch->guides as $guide) {
            $batch->guide_type === 'consulta' ? $this->consulta($guias, $batch, $guide, $provider) : $this->spSadt($guias, $batch, $guide, $provider);
        }

        $hashParent = $this->el($root, 'epilogo');
        $hash = md5(mb_convert_encoding($this->concatValues($root), 'ISO-8859-1', 'UTF-8'));
        $this->el($hashParent, 'hash', $hash);

        return ['xml' => $this->doc->saveXML(), 'hash' => $hash];
    }

    /**
     * Valida contra o XSD oficial. Retorna a lista de erros (vazia = válido).
     *
     * @return list<string>
     */
    public function validate(string $xml, string $version = '4.01.00'): array
    {
        $schema = resource_path("tiss/{$version}/tissV".str_replace('.', '_', $version).'.xsd');

        if (! is_file($schema)) {
            return ["Schema TISS {$version} não encontrado em resources/tiss/{$version}."];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = new DOMDocument;
            $doc->loadXML($xml, LIBXML_NONET);
            $doc->schemaValidate($schema, LIBXML_NONET);

            return array_map(fn ($e) => trim($e->message).' (linha '.$e->line.')', libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    // ------------------------------------------------------------------ guias

    private function consulta(DOMElement $parent, Batch $batch, Guide $guide, array $provider): void
    {
        $g = $this->el($parent, 'guiaConsulta');
        $cab = $this->el($g, 'cabecalhoConsulta');
        $this->el($cab, 'registroANS', $batch->insurer->ans_registry);
        $this->el($cab, 'numeroGuiaPrestador', $guide->number);
        $this->opt($g, 'numeroGuiaOperadora', $guide->operator_guide_number ?: $guide->authorization?->operator_guide_number);
        $this->beneficiario($g, $guide);

        $exec = $this->el($g, 'contratadoExecutante');
        $this->el($exec, $provider['contracted_tag'], $provider['value']);
        $this->el($exec, 'CNES', $this->cnes($batch));
        $this->profissional($g, 'profissionalExecutante', $guide);
        $this->el($g, 'indicacaoAcidente', $guide->accident_indicator);

        $at = $this->el($g, 'dadosAtendimento');
        $this->el($at, 'regimeAtendimento', '01');
        $this->el($at, 'dataAtendimento', $guide->attendance_date->toDateString());
        $this->el($at, 'tipoConsulta', $guide->consultation_type);
        $item = $guide->items->first();
        $proc = $this->el($at, 'procedimento');
        $this->el($proc, 'codigoTabela', $item->table_code);
        $this->el($proc, 'codigoProcedimento', $item->code);
        $this->el($proc, 'valorProcedimento', $this->money($item->total_cents));
        $this->opt($g, 'observacao', $guide->observation);
    }

    private function spSadt(DOMElement $parent, Batch $batch, Guide $guide, array $provider): void
    {
        $g = $this->el($parent, 'guiaSP-SADT');
        $cab = $this->el($g, 'cabecalhoGuia');
        $this->el($cab, 'registroANS', $batch->insurer->ans_registry);
        $this->el($cab, 'numeroGuiaPrestador', $guide->number);

        if ($auth = $guide->authorization) {
            $da = $this->el($g, 'dadosAutorizacao');
            $this->opt($da, 'numeroGuiaOperadora', $guide->operator_guide_number ?: $auth->operator_guide_number);
            $this->el($da, 'dataAutorizacao', ($auth->authorized_on ?? $guide->attendance_date)->toDateString());
            $this->opt($da, 'senha', $auth->password);
            $this->opt($da, 'dataValidadeSenha', $auth->valid_until?->toDateString());
        }

        $this->beneficiario($g, $guide);

        // Solicitante = a própria clínica/médico (pedido interno). Pedido externo: informar na observação.
        $sol = $this->el($g, 'dadosSolicitante');
        $this->el($this->el($sol, 'contratadoSolicitante'), $provider['contracted_tag'], $provider['value']);
        $this->el($sol, 'nomeContratadoSolicitante', mb_substr($this->providerName($batch), 0, 70));
        $this->profissional($sol, 'profissionalSolicitante', $guide);

        $req = $this->el($g, 'dadosSolicitacao');
        $this->el($req, 'dataSolicitacao', $guide->attendance_date->toDateString());
        $this->el($req, 'caraterAtendimento', $guide->character);
        $this->opt($req, 'indicacaoClinica', $guide->clinical_indication);

        $ex = $this->el($g, 'dadosExecutante');
        $this->el($this->el($ex, 'contratadoExecutante'), $provider['contracted_tag'], $provider['value']);
        $this->el($ex, 'CNES', $this->cnes($batch));

        $at = $this->el($g, 'dadosAtendimento');
        $this->el($at, 'tipoAtendimento', $guide->attendance_type);
        $this->el($at, 'indicacaoAcidente', $guide->accident_indicator);
        if ($guide->attendance_type === '04') {
            $this->el($at, 'tipoConsulta', $guide->consultation_type);
        }
        $this->el($at, 'regimeAtendimento', '01');

        $procs = $this->el($g, 'procedimentosExecutados');
        foreach ($guide->items->values() as $i => $item) {
            $pe = $this->el($procs, 'procedimentoExecutado');
            $this->el($pe, 'sequencialItem', (string) ($i + 1));
            $this->el($pe, 'dataExecucao', $item->execution_date->toDateString());
            $p = $this->el($pe, 'procedimento');
            $this->el($p, 'codigoTabela', $item->table_code);
            $this->el($p, 'codigoProcedimento', $item->code);
            $this->el($p, 'descricaoProcedimento', mb_substr($item->description, 0, 150));
            $this->el($pe, 'quantidadeExecutada', (string) $item->quantity);
            $this->el($pe, 'reducaoAcrescimo', '1.00');
            $this->el($pe, 'valorUnitario', $this->money($item->unit_cents));
            $this->el($pe, 'valorTotal', $this->money($item->total_cents));
        }

        $this->opt($g, 'observacao', $guide->observation);
        $tot = $this->el($g, 'valorTotal');
        $this->el($tot, 'valorProcedimentos', $this->money($guide->total_cents));
        $this->el($tot, 'valorTotalGeral', $this->money($guide->total_cents));
    }

    private function beneficiario(DOMElement $g, Guide $guide): void
    {
        $b = $this->el($g, 'dadosBeneficiario');
        $this->el($b, 'numeroCarteira', $guide->card_number);
        $this->el($b, 'atendimentoRN', 'N');
    }

    private function profissional(DOMElement $parent, string $tag, Guide $guide): void
    {
        $doctor = $guide->doctor;
        $p = $this->el($parent, $tag);
        $this->el($p, 'nomeProfissional', mb_substr($doctor->name, 0, 70));
        $this->el($p, 'conselhoProfissional', '06'); // CRM
        $this->el($p, 'numeroConselhoProfissional', preg_replace('/\D/', '', $doctor->crm));
        $this->el($p, 'UF', self::UF[strtoupper($doctor->crm_state)] ?? '98');
        $this->el($p, 'CBOS', $guide->cbo_code);
    }

    // ------------------------------------------------------------------ apoio

    /** Identificação da clínica na operadora: código do prestador (preferido) ou CNPJ. */
    private function provider(Batch $batch): array
    {
        if ($code = $batch->insurer->provider_code) {
            return ['origin_tag' => 'codigoPrestadorNaOperadora', 'contracted_tag' => 'codigoPrestadorNaOperadora', 'value' => $code];
        }

        $cnpj = $batch->branch->document ?: DB::table('companies')->where('id', $batch->company_id)->value('document');

        if (! $cnpj || strlen((string) $cnpj) !== 14) {
            throw new BusinessRuleViolation('Informe o código do prestador na operadora (cadastro do convênio) ou o CNPJ da unidade.', 'tiss_provider_missing');
        }

        return ['origin_tag' => 'CNPJ', 'contracted_tag' => 'cnpjContratado', 'value' => $cnpj];
    }

    private function providerName(Batch $batch): string
    {
        return (string) (DB::table('companies')->where('id', $batch->company_id)->value('legal_name') ?: $batch->branch->name);
    }

    /** Sem CNES cadastrado, o TISS orienta preencher 9999999. */
    private function cnes(Batch $batch): string
    {
        return preg_match('/^\d{7}$/', (string) $batch->branch->cnes) ? $batch->branch->cnes : '9999999';
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function el(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $node = $this->doc->createElementNS(self::NS, 'ans:'.$name);
        if ($value !== null) {
            $node->appendChild($this->doc->createTextNode($value));
        }
        $parent->appendChild($node);

        return $node;
    }

    private function opt(DOMElement $parent, string $name, ?string $value): void
    {
        if ($value !== null && trim($value) !== '') {
            $this->el($parent, $name, trim($value));
        }
    }

    private function concatValues(DOMElement $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= trim($child->nodeValue);
            } elseif ($child instanceof DOMElement) {
                $out .= $this->concatValues($child);
            }
        }

        return $out;
    }
}
