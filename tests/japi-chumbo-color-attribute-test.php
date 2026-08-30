<?php
declare(strict_types=1);

require_once __DIR__ . '/../olist/catalog-attributes.php';

function svjapi_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$japiChumbo = [
    'marca' => ['nome' => 'JAPI'],
    'descricao' => 'Vaso Unique 16 Chumbo Japi',
    'descricaoComplementar' => 'Acabamento Chumbo para ambientes internos e externos.',
    'variacoes' => [],
];
$attrs = svs_catalog_attributes($japiChumbo);
svjapi_assert(in_array('Cor: Preto', $attrs, true), 'JAPI Chumbo deve publicar atributo Cor: Preto');
svjapi_assert($japiChumbo['descricao'] === 'Vaso Unique 16 Chumbo Japi', 'regra nao pode alterar a descricao do produto');

$japiAzul = $japiChumbo;
$japiAzul['descricao'] = 'Vaso Unique 16 Azul Japi';
$japiAzul['descricaoComplementar'] = 'Acabamento Azul.';
svjapi_assert(!in_array('Cor: Preto', svs_catalog_attributes($japiAzul), true), 'JAPI Azul nao pode virar Preto');

$outraMarca = $japiChumbo;
$outraMarca['marca'] = ['nome' => 'OUTRA'];
svjapi_assert(!in_array('Cor: Preto', svs_catalog_attributes($outraMarca), true), 'outra marca Chumbo nao pode receber regra JAPI');

$comGrade = $japiChumbo;
$comGrade['variacoes'] = [['grade' => [['chave' => 'Tamanho', 'valor' => 'M']]]];
$attrsGrade = svs_catalog_attributes($comGrade);
svjapi_assert(in_array('Tamanho: M', $attrsGrade, true), 'atributos reais de grade devem ser preservados');
svjapi_assert(in_array('Cor: Preto', $attrsGrade, true), 'regra JAPI deve coexistir com grade real');

fwrite(STDOUT, "japi-chumbo-color-attribute-test: ok\n");
