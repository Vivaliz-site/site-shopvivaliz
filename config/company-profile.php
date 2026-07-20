<?php
/**
 * Configuração de Dados da Empresa - ShopVivaliz
 * Sincronizado com Olist Admin
 */

declare(strict_types=1);

return [
    // Dados Básicos
    'legal_name'        => 'SHOPVIVALIZ LTDA',
    'fantasy_name'      => 'Shopvivaliz',
    'description'       => 'Loja online de ferramentas e materiais de construção',

    // Endereço
    'address'           => 'RUA CAMPINA VERDE',
    'number'            => '841',
    'complement'        => '',
    'neighborhood'      => 'SAO JOSE',
    'city'              => 'Divinópolis',
    'state'             => 'MG',
    'zipcode'           => '35501-236',

    // Contatos
    'phone'             => '(37) 99937-4112',
    'mobile'            => '(37) 99937-4112',
    'email'             => 'atendimento@shopvivaliz.com.br',
    'website'           => 'shopvivaliz.com.br',

    // Dados Fiscais
    'cnpj'              => '49.903.300/0001-70',
    'state_registration' => '004567865 0076',
    'municipal_registration' => '319830',
    'cnae'              => '4744001',

    // Tipo de Entidade
    'legal_entity_type' => 'Pessoa Jurídica',
    'business_segment'  => 'Ferramentas e Construção',
    'tax_regime'        => 'Simples nacional',

    // Olist Integration
    'olist_seller_id'   => null,  // Preenchido após autenticação
    'olist_status'      => 'active',
    'last_sync'         => null,  // Data/hora da última sincronização

    // Configurações de Operação
    'operational' => [
        'default_seller_name' => 'Shopvivaliz',
        'support_email'       => 'atendimento@shopvivaliz.com.br',
        'support_phone'       => '(37) 99937-4112',
        'nfe_issuer'          => true,
        'auto_invoice'        => true,
    ],

    // Redes Sociais
    'social_media' => [
        'facebook'  => null,
        'instagram' => null,
        'whatsapp'  => '5537999374112',
        'tiktok'    => null,
    ],
];
