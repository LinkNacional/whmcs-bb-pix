# WHMCS BB Pix

Modulo de gateway de pagamento Pix para WHMCS com integracao ao Banco do Brasil.

Este README foi escrito para administradores de WHMCS, equipes de suporte e times tecnicos que precisam instalar, configurar, atualizar, validar e remover o modulo com seguranca.

## Sumario
1. [Visao geral](#visao-geral)
2. [Recursos do modulo](#recursos-do-modulo)
3. [Requisitos](#requisitos)
4. [Estrutura de arquivos](#estrutura-de-arquivos)
5. [Instalacao](#instalacao)
6. [Configuracao inicial no WHMCS](#configuracao-inicial-no-whmcs)
7. [Como usar no dia a dia](#como-usar-no-dia-a-dia)
8. [Atualizacao do modulo](#atualizacao-do-modulo)
9. [Remocao do modulo](#remocao-do-modulo)
10. [Checklist de validacao pos-instalacao](#checklist-de-validacao-pos-instalacao)
11. [Problemas comuns e solucoes](#problemas-comuns-e-solucoes)
12. [Boas praticas de seguranca](#boas-praticas-de-seguranca)
13. [Notas tecnicas](#notas-tecnicas)
14. [Referencias oficiais BB](#referencias-oficiais-bb)

## Visao geral
O modulo permite receber pagamentos via Pix diretamente nas faturas do WHMCS, com:

- geracao de QR Code e copia e cola;
- confirmacao automatica de pagamento (webhook e/ou verificacao manual);
- controle de descontos e regras;
- suporte a fluxos de Pix imediato e recursos avancados de Pix via BB API v2.

## Recursos do modulo
- Geracao de cobranca Pix para faturas WHMCS.
- Confirmacao automatica de pagamento.
- Confirmacao manual de pagamento.
- Reembolso manual (quando aplicavel ao fluxo configurado).
- Compartilhamento do codigo Pix copia e cola.
- Configuracao de desconto por produto.
- Configuracao de desconto global para pagamento via Pix.
- Regras de desconto por criterio.
- Suporte a webhooks da API BB (v2) para eventos de autorizacao e cobranca.

## Requisitos
### Plataforma
- WHMCS 8.6 ou superior.
- PHP 8.1 ou superior (recomendado utilizar versao suportada mais recente do seu ambiente).

### Extensoes PHP
- GD
- mbstring
- cURL

### Dependencias e infraestrutura
- Credenciais validas no portal BB Developers.
- Acesso HTTPS funcional no WHMCS (obrigatorio para webhooks em producao).
- Permissao de leitura/escrita para upload dos arquivos do modulo.

## Estrutura de arquivos
Arquivos principais do modulo:

- src/modules/gateways/lknbbpix.php
- src/modules/gateways/lknbbpix/api.php
- src/modules/gateways/lknbbpix/webhook.php
- src/modules/gateways/lknbbpix/webhookrec.php
- src/modules/gateways/lknbbpix/webhookcobr.php
- src/includes/hooks/lknbbpix.php

## Instalacao
### 1. Backup (obrigatorio)
Antes de instalar em ambiente produtivo:

- faca backup dos arquivos do WHMCS;
- faca backup do banco de dados;
- valide se consegue restaurar rapidamente em caso de rollback.

### 2. Upload do pacote
1. Baixe o arquivo zip da versao do modulo.
2. Extraia localmente.
3. Envie o conteudo para a raiz do WHMCS, preservando a estrutura de pastas.

Observacao:
- nao envie a pasta raiz do zip inteira se isso criar um nivel extra incorreto de diretorio;
- confirme que os arquivos ficaram exatamente nos caminhos esperados.

### 3. Permissoes
Garanta permissao de leitura dos arquivos PHP e permissao adequada para diretorios de certificado, quando aplicavel.

### 4. Ativacao no WHMCS
1. Acesse: Configuracao > Pagamentos > Gateways de pagamento.
2. Ative o gateway Pix Banco do Brasil (lknbbpix).
3. Salve as configuracoes.

## Configuracao inicial no WHMCS
Preencha no minimo os campos abaixo para operacao basica:

- developer_application_key
- client_id
- client_secret
- auth_basic (Basic Authorization)
- receiver_pix_key
- nome/razao social do recebedor
- cidade do recebedor
- env (ambiente)

Esses dados sao obtidos no portal BB Developers:

https://app.developers.bb.com.br/core/gcs/statics/login/login.novo.bb?tipo=st_cpf&urlRetorno=https%3A%2F%2Fapp.developers.bb.com.br%2F%23%2Flogin#/st-cpf

### Campos opcionais (com valores padrao)
- descricao do Pix
- expiracao do Pix
- id do campo customizado para CNPJ
- id do campo customizado para CPF
- envio de nome e documento do pagador na cobranca

### Ambientes
Use credenciais e chaves correspondentes ao ambiente selecionado:

- homologacao
- producao

Nunca misture credencial de homologacao em ambiente de producao.

## Como usar no dia a dia
1. Abra a fatura no WHMCS.
2. Selecione o gateway Pix Banco do Brasil.
3. O QR Code sera gerado automaticamente.
4. O cliente paga no app bancario.
5. A fatura e atualizada conforme confirmacao automatica ou verificacao manual.

## Atualizacao do modulo
### Fluxo recomendado (seguro)
1. Ative modo manutencao (recomendado).
2. Gere backup completo de arquivos e banco.
3. Leia o CHANGELOG da nova versao.
4. Substitua os arquivos do modulo pelos da nova versao.
5. Revise permissao de arquivos e diretorios sensiveis.
6. Reabra as configuracoes do gateway e salve novamente.
7. Execute testes funcionais (ver checklist abaixo).

### Boas praticas na atualizacao
- atualize primeiro em homologacao e depois em producao;
- nunca atualize direto em horario de pico;
- mantenha plano de rollback pronto.

## Remocao do modulo
### Remocao funcional (desativar uso)
1. Desative o gateway no WHMCS.
2. Altere o gateway padrao de cobranca das faturas futuras, se necessario.

### Remocao completa (arquivos)
1. Confirme que nao ha pagamentos pendentes que dependam do modulo.
2. Faça backup final.
3. Remova os arquivos em:
	- src/modules/gateways/lknbbpix.php
	- src/modules/gateways/lknbbpix/
	- src/includes/hooks/lknbbpix.php

### Remocao de dados
O modulo pode ter criado tabelas proprias para configuracoes/regras. Avalie com cuidado antes de excluir dados historicos. Em producao, recomenda-se manter dados para auditoria.

## Checklist de validacao pos-instalacao
- Gateway aparece e salva sem erro no admin.
- Credenciais e ambiente corretos.
- Geracao de QR Code funcionando.
- Pagamento de teste confirma a fatura.
- Logs do gateway sem erros criticos.
- Webhooks respondendo em HTTPS e com retorno esperado.
- Fluxo de confirmacao manual funcionando (fallback).

## Problemas comuns e solucoes
### 1) QR Code nao gera
Possiveis causas:
- credenciais invalidas;
- ambiente incorreto (homologacao/producao);
- falha de comunicacao cURL;
- chave Pix recebedora invalida.

Acoes:
- validar credenciais no BB Developers;
- revisar logs do modulo/WHMCS;
- testar conectividade do servidor com endpoints do BB.

### 2) Fatura nao confirma automaticamente
Possiveis causas:
- webhook nao registrado;
- URL de webhook sem HTTPS;
- bloqueio de firewall/rede;
- payload nao chegando ao endpoint correto.

Acoes:
- validar URLs dos webhooks configurados;
- checar acesso externo aos endpoints;
- analisar logs de entrada e resposta do webhook.

### 3) Erro de autenticacao na API BB
Possiveis causas:
- client_id/client_secret incorretos;
- auth_basic incorreto;
- certificado/chave incompativeis com ambiente.

Acoes:
- regenerar credenciais;
- revisar formacao do Basic Authorization;
- validar certificados em homologacao antes de producao.

## Boas praticas de seguranca
- Nunca versionar credenciais, tokens ou chaves privadas.
- Restrinja acesso aos arquivos de certificado.
- Use sempre HTTPS no WHMCS e nos webhooks.
- Monitore logs periodicamente para detectar falhas e tentativas indevidas.
- Defina rotina de rotacao de credenciais.

## Notas tecnicas
### Logica de txid e identificacao da fatura
Historicamente, a identificacao utilizava padrao legado com invoice antes de x, por exemplo:

- 0000xa09ad1679ebbaf88a92cd98fd4

No fluxo atual do modulo, ha suporte aos formatos necessarios para correlacao com a fatura no WHMCS, inclusive para cenarios de compatibilidade.

### Descontos
Quando habilitado, o desconto por produto considera o valor total relacionado ao item (produto + encargos associados), conforme regra de negocio do modulo.

## Referencias oficiais BB
### API Pix v1
- Documentacao Pix: https://apoio.developers.bb.com.br/referency/post/648382d5d7ffe20012f2c287
- Endpoints Pix API: https://apoio.developers.bb.com.br/referency/post/6483836ddcefbe00128886ce
- Colecao Postman e chaves de teste: https://apoio.developers.bb.com.br/referency/post/5ff4946ce2a4400012dad1d9
- Simulacao de pagamento em homologacao: https://apoio.developers.bb.com.br/referency/post/61bcdd19b6164800123d7654

### API Pix v2
- Instrucoes de testes: https://apoio.developers.bb.com.br/referency/post/5ff4946ce2a4400012dad1d9
- Webhooks: https://apoio.developers.bb.com.br/referency/post/64f878e5a7287f001313fc6e

### Forum BB Developers
- Portal do forum: https://forum.developers.bb.com.br/
- Caracteres no campo solicitacaoPagador: https://forum.developers.bb.com.br/t/caracteres-suportados-para-o-campo-solicitacaopagador-na-criacao-do-pix/10182/6
- Leitura de QR Code em homologacao: https://forum.developers.bb.com.br/t/como-ler-qr-codes-gerados-com-a-api-de-homologacao/5688