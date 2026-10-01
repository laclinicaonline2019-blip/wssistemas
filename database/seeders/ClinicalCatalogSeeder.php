<?php

namespace Database\Seeders;

use App\Core\Tenancy\TenantContext;
use App\Modules\Clinical\Models\CidCode;
use App\Modules\Clinical\Models\Medication;
use Illuminate\Database\Seeder;

/**
 * Amostra mínima das bases clínicas (marcada is_sample) para o sistema funcionar
 * logo após a instalação. NÃO substitui a CID-10 oficial do DATASUS nem uma base
 * de medicamentos completa — importe-as em Plataforma → Bases clínicas.
 * Só insere quando as bases estão vazias.
 */
class ClinicalCatalogSeeder extends Seeder
{
    private const CID = [
        ['A09', 'Diarreia e gastroenterite de origem infecciosa presumível'],
        ['B34.9', 'Infecção viral não especificada'],
        ['E03.9', 'Hipotireoidismo não especificado'],
        ['E11.9', 'Diabetes mellitus não-insulino-dependente - sem complicações'],
        ['E66.9', 'Obesidade não especificada'],
        ['E78.5', 'Hiperlipidemia não especificada'],
        ['F32.9', 'Episódio depressivo não especificado'],
        ['F41.1', 'Ansiedade generalizada'],
        ['F41.9', 'Transtorno ansioso não especificado'],
        ['G43.9', 'Enxaqueca, sem especificação'],
        ['G44.2', 'Cefaléia tensional'],
        ['G47.0', 'Distúrbios do início e da manutenção do sono [insônias]'],
        ['H10.9', 'Conjuntivite não especificada'],
        ['H66.9', 'Otite média não especificada'],
        ['I10', 'Hipertensão essencial (primária)'],
        ['J00', 'Nasofaringite aguda [resfriado comum]'],
        ['J01.9', 'Sinusite aguda não especificada'],
        ['J02.9', 'Faringite aguda não especificada'],
        ['J03.9', 'Amigdalite aguda não especificada'],
        ['J06.9', 'Infecção aguda das vias aéreas superiores não especificada'],
        ['J11.1', 'Influenza com outras manifestações respiratórias, devida a vírus não identificado'],
        ['J18.9', 'Pneumonia não especificada'],
        ['J30.4', 'Rinite alérgica não especificada'],
        ['J45.9', 'Asma não especificada'],
        ['K21.9', 'Doença de refluxo gastroesofágico sem esofagite'],
        ['K29.7', 'Gastrite não especificada'],
        ['K59.0', 'Constipação'],
        ['L20.9', 'Dermatite atópica, não especificada'],
        ['L30.9', 'Dermatite não especificada'],
        ['M25.5', 'Dor articular'],
        ['M54.2', 'Cervicalgia'],
        ['M54.5', 'Dor lombar baixa'],
        ['M79.1', 'Mialgia'],
        ['N30.0', 'Cistite aguda'],
        ['N39.0', 'Infecção do trato urinário de localização não especificada'],
        ['R05', 'Tosse'],
        ['R10.4', 'Outras dores abdominais e as não especificadas'],
        ['R50.9', 'Febre não especificada'],
        ['R51', 'Cefaléia'],
        ['U07.1', 'COVID-19, vírus identificado'],
        ['Z00.0', 'Exame médico geral'],
        ['Z01.4', 'Exame ginecológico (geral) (de rotina)'],
        ['Z30.0', 'Aconselhamento geral sobre contracepção'],
        ['Z34.9', 'Supervisão de gravidez normal, não especificada'],
        ['Z76.0', 'Emissão de prescrição de repetição'],
    ];

    /** [princípio ativo, concentração, apresentação, via, posologia, controle] */
    private const MEDICATIONS = [
        ['Dipirona monoidratada', '500 mg', 'comprimido', 'oral', '1 comprimido de 6/6 horas se dor ou febre', 'none'],
        ['Paracetamol', '750 mg', 'comprimido', 'oral', '1 comprimido de 6/6 horas se dor ou febre', 'none'],
        ['Ibuprofeno', '600 mg', 'comprimido revestido', 'oral', '1 comprimido de 8/8 horas por 5 dias, após as refeições', 'none'],
        ['Losartana potássica', '50 mg', 'comprimido revestido', 'oral', '1 comprimido ao dia', 'none'],
        ['Hidroclorotiazida', '25 mg', 'comprimido', 'oral', '1 comprimido pela manhã', 'none'],
        ['Anlodipino', '5 mg', 'comprimido', 'oral', '1 comprimido ao dia', 'none'],
        ['Metformina', '850 mg', 'comprimido', 'oral', '1 comprimido após almoço e jantar', 'none'],
        ['Sinvastatina', '20 mg', 'comprimido revestido', 'oral', '1 comprimido à noite', 'none'],
        ['Levotiroxina sódica', '50 mcg', 'comprimido', 'oral', '1 comprimido em jejum, 30 minutos antes do café', 'none'],
        ['Omeprazol', '20 mg', 'cápsula', 'oral', '1 cápsula em jejum', 'none'],
        ['Loratadina', '10 mg', 'comprimido', 'oral', '1 comprimido ao dia', 'none'],
        ['Prednisona', '20 mg', 'comprimido', 'oral', '1 comprimido pela manhã por 5 dias', 'none'],
        ['Salbutamol', '100 mcg/dose', 'aerossol', 'inalatória', '2 jatos de 6/6 horas se falta de ar', 'none'],
        ['Soro fisiológico 0,9%', null, 'solução nasal', 'nasal', 'Lavar as narinas 3 a 4 vezes ao dia', 'none'],
        ['Amoxicilina', '500 mg', 'cápsula', 'oral', '1 cápsula de 8/8 horas por 7 dias', 'antimicrobial'],
        ['Amoxicilina + clavulanato de potássio', '875 mg + 125 mg', 'comprimido revestido', 'oral', '1 comprimido de 12/12 horas por 7 dias', 'antimicrobial'],
        ['Azitromicina', '500 mg', 'comprimido revestido', 'oral', '1 comprimido ao dia por 3 dias', 'antimicrobial'],
        ['Cefalexina', '500 mg', 'cápsula', 'oral', '1 cápsula de 6/6 horas por 7 dias', 'antimicrobial'],
        ['Nitrofurantoína', '100 mg', 'cápsula', 'oral', '1 cápsula de 6/6 horas por 7 dias', 'antimicrobial'],
        ['Sertralina', '50 mg', 'comprimido revestido', 'oral', '1 comprimido pela manhã', 'C1'],
        ['Fluoxetina', '20 mg', 'cápsula', 'oral', '1 cápsula pela manhã', 'C1'],
        ['Amitriptilina', '25 mg', 'comprimido', 'oral', '1 comprimido à noite', 'C1'],
        ['Clonazepam', '2 mg', 'comprimido', 'oral', '1/2 comprimido à noite', 'B1'],
        ['Alprazolam', '0,5 mg', 'comprimido', 'oral', '1 comprimido à noite', 'B1'],
        ['Zolpidem', '10 mg', 'comprimido revestido', 'oral', '1 comprimido ao deitar', 'B1'],
        ['Tramadol', '50 mg', 'cápsula', 'oral', '1 cápsula de 8/8 horas se dor intensa', 'A2'],
        ['Isotretinoína', '20 mg', 'cápsula', 'oral', 'Conforme orientação médica', 'C2'],
    ];

    public function run(TenantContext $context): void
    {
        $context->runAsSystem(function () {
            if (! CidCode::query()->exists()) {
                foreach (self::CID as [$code, $description]) {
                    CidCode::create(['code' => $code, 'description' => $description, 'is_sample' => true]);
                }
            }

            if (! Medication::query()->whereNull('company_id')->exists()) {
                foreach (self::MEDICATIONS as [$ingredient, $concentration, $presentation, $route, $posology, $control]) {
                    Medication::withoutAuditing(fn () => Medication::create([
                        'active_ingredient' => $ingredient, 'concentration' => $concentration, 'presentation' => $presentation,
                        'route' => $route, 'default_posology' => $posology, 'control_type' => $control, 'is_sample' => true,
                    ]));
                }
            }
        });
    }
}
