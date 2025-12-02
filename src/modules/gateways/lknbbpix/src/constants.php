<?php

return [
    'name' => 'lknbbpix',
    'version' => '2.2.0',
    'resources_path' => realpath(__DIR__ . '/../resources/config_header.tpl'),

    'public_key_path' => realpath(__DIR__ . '/../certs/public.key'),
    'private_key_path' => realpath(__DIR__ . '/../certs/private.key'),

    'dev' => [
        'baseUrl' => 'https://api.hm.bb.com.br/pix/v2',
        'oAuthUrl' => 'https://oauth.hm.bb.com.br',
		'autoPixBaseUrl' => 'https://api-pix.hm.bb.com.br/pix/v2',
		'pixRecebimentoBaseUrl' => 'https://api.hm.bb.com.br/pix/v2'
    ],

    'prod' => [
        'baseUrl' => 'https://api-pix.bb.com.br/pix/v2',
        'oAuthUrl' => 'https://oauth.bb.com.br',
		'autoPixBaseUrl' => 'https://api-pix.bb.com.br/pix/v2',
		'pixRecebimentoBaseUrl' => 'https://api-pix.bb.com.br/pix/v2'
    ]
];
