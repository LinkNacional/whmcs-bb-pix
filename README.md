# WHMCS BB Pix

Modulo de gateway de pagamento [WHMCS Pix](https://stage.linknacional.com.br/whmcs/gateways/pix/banco-brasil/) Automático para WHMCS com integracao ao Banco do Brasil.

Este README foi escrito para administradores de WHMCS, equipes de suporte e times tecnicos que precisam instalar, configurar, atualizar, validar e remover o modulo com seguranca.

## Sumario
1. [Visao geral](#visao-geral)
2. [Recursos do modulo](#recursos-do-modulo)
3. [Requisitos](#requisitos)
4. [Estrutura de arquivos](#estrutura-de-arquivos)
5. [Instalacao](#instalacao)
6. [Configuracao inicial no WHMCS](#configuracao-inicial-no-whmcs)
7. [Como usar no dia a dia](#como-usar-no-dia-a-dia)
8. [Webhooks e Processo de Cobranca](#webhooks-e-processo-de-cobranca)
9. [Atualizacao do modulo](#atualizacao-do-modulo)
10. [Remocao do modulo](#remocao-do-modulo)
11. [Checklist de validacao pos-instalacao](#checklist-de-validacao-pos-instalacao)
12. [Problemas comuns e solucoes](#problemas-comuns-e-solucoes)
13. [Boas praticas de seguranca](#boas-praticas-de-seguranca)
14. [Notas tecnicas](#notas-tecnicas)
15. [Referencias oficiais BB](#referencias-oficiais-bb)

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
- PHP 8.3 ou superior.

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
- src/includes/hooks/lknbbpix_admin.php

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

## Webhooks e Processo de Cobranca

### O que sao webhooks?
Webhooks sao chamadas HTTP automaticas que o Banco do Brasil realiza para notificar seu WHMCS sobre eventos importantes:
- Pagamento recebido
- Autorizacao de pagamento recorrente aprovada
- Falha ou cancelamento de cobranca recorrente

Dessa forma, o sistema nao precisa ficar consultando a API constantemente (polling) — fica notificado em tempo real.

### Tipos de webhooks suportados

#### 1. webhook.php — Pix Imediato
- **Quando acionado:** Quando um cliente paga um Pix gerado normalmente.
- **O que faz:** Confirma automaticamente o pagamento na fatura.
- **Registrado em:** Configuracoes > Pagamentos > Gateways de Pagamento (lknbbpix).

#### 2. webhookrec.php — Autorizacao de Pagamento Recorrente
- **Quando acionado:** Quando o cliente autoriza ou cancela um pagamento recorrente (Pix Automatico - Jornada 4).
- **Eventos recebidos:**
  - `CRIADA` — Autorizacao iniciada, aguardando aceite do cliente.
  - `APROVADA` — Cliente aceito no app. Sistema ja pode cobrar automaticamente.
  - `CANCELADA` — Cliente cancelou a autorizacao. Sem cobranças futuras.
  - `REJEITADA` — Banco rejeitou a autorizacao.
  - `REVOGADA` — Autorizacao revogada pelo cliente no banco.
- **O que faz:** Atualiza o status da autorizacao (coluna `status` em `mod_lknbbpix_auths`).
- **Resultado:** Quando status = APROVADA, futuras cobranças automaticas serao disparadas conforme ciclo configurado.

#### 3. webhookcobr.php — Resultado da Cobranca Automatica
- **Quando acionado:** Quando uma cobranca automatica (Pix Automatico) e liquidada, falha ou expira.
- **Eventos recebidos:**
  - `CONCLUIDA` — Pix foi pago. Sistema marca a fatura como Paid.
  - `REJEITADA` — Cliente rejeitou ou banco negou. Fatura permanece aberta.
  - `EXPIRADA` — Cobranca expirou. Fatura permanece aberta.
  - `CANCELADA` — Cobranca foi cancelada. Fatura permanece aberta.
- **O que faz:**
  - Se CONCLUIDA: chama `addInvoicePayment()` para confirmar e marcar como Paid.
  - Se REJEITADA/EXPIRADA/CANCELADA: adiciona nota na fatura e pedido informando a falha. WHMCS continua com sua automacao nativa de inadimplencia.
- **Resultado:** Fatura confirmada ou falha registrada para rastreamento.

### Configuracao de Webhooks no Banco do Brasil

#### Registro Manual
1. Acesse o painel de configuracoes do modulo (Configuracao > Pagamentos > Gateways).
2. Clique no botao "Inserir Webhooks do Banco".
3. Sistema valida HTTPS e credenciais, depois registra automaticamente.
4. Tabela exibira status "Registrado" (verde) ou mensagem de erro.

#### Ciclo de vida da authorization (Pix Automatico)

**Ponto-chave:** A cobrança é disparada **IMEDIATAMENTE** quando a fatura é criada, não no vencimento.

```
┌─ PRIMEIRO CICLO (Cliente novo ou novo due_day)
│  Fatura criada com vencimento no dia 15
│  ❌ Cliente não tem autorização APROVADA para vencimento=15
│  └─ Sistema oferece Jornada 4 (QR Code composto)
│     ↓
│     Cliente escaneia e autoriza no app bancário
│     ↓
│     Banco envia webhookrec com status = APROVADA
│     ↓
│     Sistema salva em mod_lknbbpix_auths:
│        - client_id: [ID do cliente]
│        - id_rec: [Autorização do BB]
│        - due_day: 15 (vinculado a este dia do mês)
│        - status: APROVADA
│
├─ PRÓXIMO CICLO (Renovação automática cron)
│  Fatura criada com vencimento no dia 15 (same due_day)
│  ✅ Cliente TEM autorização APROVADA para due_day=15
│  └─ Sistema IMEDIATAMENTE envia cobrança:
│     ↓
│     PUT /cobr/{txid} com dataDeVencimento=15
│     ↓
│     Pix é agendado na conta do cliente para aquela data
│     ↓
│     Banco envia webhookcobr no vencimento
│     ↓
│     CONCLUIDA? → Fatura marcada Paid
│     REJEITADA/EXPIRADA? → Nota adicionada, fatura permanece Unpaid
│
└─ MUDANÇA DE VENCIMENTO (Ex: 15 → 20)
   Fatura criada com vencimento = 20 (novo due_day)
   ❌ Não há autorização para due_day=20 (só tem para 15)
   └─ Sistema oferece nova Jornada 4 (novo ciclo de autorização)
      (A autorização anterior para due_day=15 permanece válida
       mas só será usada se faturas vencerem no dia 15 novamente)
```

**Regra importante - Imutabilidade:** A autorização é **permanentemente vinculada ao `due_day`** (dia do mês). Se o vencimento muda, você precisa de uma nova autorização para o novo ciclo. Não reutilize autorização de outro `due_day`.

### Timing da Cobrança Automática

**Quando a cobrança é disparada?**

A cobrança automática (`PUT /cobr/{txid}`) é disparada **IMEDIATAMENTE na geração da fatura**, não no vencimento. Fluxo:

1. **Fatura criada** (novo pedido ou renovação cron)
2. **Hook `InvoiceCreationPreEmail` acionado**
3. Sistema verifica: `"Cliente tem autorização APROVADA para este due_day?"`
4. **SIM** → Dispara `ScheduleAutomaticChargeService`:
   - Envia `PUT /cobr/{txid}` para Banco do Brasil
   - Com `dataDeVencimento` = data de vencimento da fatura
   - Com `valor.original` = saldo da fatura
   - Pix fica **agendado** na conta do cliente para aquela data
   - Nota adicionada na fatura: "Pagamento agendado via Pix Automático para o dia DD/MM/YYYY."

5. **Resultado:** O Pix não aparece imediatamente na conta do cliente, mas fica em agendamento para o vencimento.

**Pode haver discrepância entre criação e vencimento:**
- Se fatura criada em 01/mai com vencimento 15/mai
- Cobrança enviada ao BB em 01/mai
- Pix só aparece na conta do cliente em ~14/mai (véspera do vencimento)
- Liquidação acontece no dia 15/mai

Cancelamento de Autorizacao

Quando um cliente cancela a autorizacao no app do banco:

1. Banco envia webhook `webhookrec.php` com `status = CANCELADA`.
2. Sistema atualiza `mod_lknbbpix_auths` para `status = CANCELADA`.
3. **Resultado:** Autorizacao fica inativa. Futuras tentativas de cobranca recorrente para esse ciclo nao serao mais disparadas.
4. Cliente pode:
   - Autorizar novamente (nova Jornada 4)
   - Pagar manualmente cada fatura
   - Deixar faturas vencerem conforme decisao de negocio

### Monitoramento e Logs

Todos os eventos de webhook sao registrados no log de transacoes do WHMCS. Para ativar logs adicionais:

1. Acesse: Configuracao > Outras inforacoes de sistema > Utilitarios > Historico de transacoes.
2. Ative "Log de transacoes do gateway".
3. Procure por entradas do modulo `lknbbpix`.

Cada webhook registra:
- Tipo de evento (CRIADA, APROVADA, CONCLUIDA, etc.)
- ID da fatura ou autorizacao
- Resultado da operacao

### Validacao de Webhooks em Producao

Checklist apos ativar em producao:

1. Tabela de webhooks mostra status "Registrado" (verde) para webhookrec e webhookcobr.
2. Pelo menos um pagamento de teste foi confirmado automaticamente (webhook.php).
3. Um teste de autorizacao recorrente foi aprovado e status em `mod_lknbbpix_auths` mudou para APROVADA.
4. Uma cobranca recorrente foi disparada e resultado foi recebido em `webhookcobr.php` (CONCLUIDA ou REJEITADA).
5. Logs nao mostram erros de conexao ou payloads invalidos.

### Troubleshooting - Webhook nao funcionando

Se o webhook nao esta funcionando:

1. **Verificar registro:** Tabela de webhooks mostra "Nao registrado" ou erro?
   - Clique "Inserir Webhooks do Banco" novamente.
   - Valide que HTTPS esta ativo.
   - Confirme credenciais no portal BB Developers.

2. **Verificar HTTPS:** Webhook so funciona com HTTPS. Confirme https://{seu_whmcs}/modules/gateways/lknbbpix/webhook.php.

3. **Firewall/Bloqueio:** Se BB consegue ver seu WHMCS?
   - Teste manualmente acessando a URL do webhook  via navegador (deve retornar POST 405 - metodo nao permitido).

4. **Payload nao chegando:** Habilitar verbose logging no painel Logs.

5. **Regenerar webhooks:** Na tabela, clique "Remover Webhooks", aguarde, depois clique "Inserir Webhooks do Banco".

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
   - src/includes/hooks/lknbbpix_admin.php

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
