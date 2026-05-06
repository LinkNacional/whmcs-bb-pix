# Copilot Instructions — whmcs-bb-pix

## Visão Geral do Projeto

Este repositório contém um **módulo de gateway de pagamento para WHMCS** que integra pagamentos via **Pix do Banco do Brasil (BB)**. Ele permite que clientes da plataforma WHMCS paguem faturas com Pix, gerando QR Codes em tempo real através da API oficial do Banco do Brasil (BB Pix API v2).

O projeto é de propriedade da **Link Nacional** e utiliza o prefixo `lkn` / `Lkn` em toda a base de código.

---

## Stack de Tecnologias

| Tecnologia | Versão mínima | Função |
|---|---|---|
| PHP | 8.3 | Toda a lógica de backend |
| WHMCS | 8.6+ | Plataforma de cobrança integrada |
| Banco do Brasil Pix API | v2 | API externa de pagamentos |
| Composer | — | Gerenciamento de dependências PHP |
| `chillerlan/php-qrcode` | 4.3.4 | Geração de imagem QR Code |
| cURL | — | Transporte HTTP para chamadas à API BB |
| JavaScript (ESNext) | — | Interações de UI no frontend |
| ESLint (Airbnb-base + Standard) | — | Linting de JS |

---

## Estrutura de Diretórios

```
src/
├── modules/gateways/
│   ├── lknbbpix.php                  # Ponto de entrada do gateway WHMCS
│   └── lknbbpix/                     # Pacote principal do módulo
│       ├── api.php                   # Endpoint AJAX interno
│       ├── webhook.php               # Receptor de webhook da BB (confirmação automática)
│       ├── webhookrec.php            # Webhook de autorização Pix Automático (status do idRec)
│       ├── webhookcobr.php           # Webhook de cobrança Pix Automático (liquidação/falha)
│       ├── certs/                    # Certificados mTLS (public.key, private.key)
│       ├── composer.json             # Dependências PHP
│       └── src/
│           ├── constants.php         # URLs da API BB (hml sem mTLS, hml com mTLS e produção), versão
│           ├── utils.php             # Funções utilitárias globais
│           ├── App/Pix/              # Lógica de aplicação principal
│           │   ├── PixController.php
│           │   ├── PixApiRepository.php      # Chamadas à API BB (cob — cobrança imediata)
│           │   ├── PixApiRepositoryLate.php  # Chamadas à API BB (cobv — cobrança pós-vencimento)
│           │   ├── PixAutoRepository.php     # Chamadas à API BB para rec/locrec/cobr
│           │   ├── Entity/PixTaxId.php       # Codificação/decodificação de IDs de transação
│           │   ├── Exceptions/               # PixException + PixExceptionCodes (enum)
│           │   ├── Controllers/              # ApiController, DiscountController
│           │   ├── Services/                 # Regras de negócio (um serviço por operação)
│           │   └── Repositories/             # Acesso ao banco de dados
│           ├── Helpers/              # Utilitários transversais (Config, Invoice, Logger, etc.)
│           ├── License/              # Lógica de licenciamento
│           └── resources/            # Templates Smarty (.tpl) e JavaScript
includes/hooks/
└── lknbbpix.php                      # Hooks do WHMCS (admin, cancelamento de fatura)
```

---

## Namespace e Convenções de Nomes

- **PHP — Namespace:** Todo o código de aplicação usa `Lkn\BBPix\` (PSR-4), mapeado para `src/modules/gateways/lknbbpix/src/`.
- **PHP — Funções WHMCS:** Funções globais expostas ao WHMCS seguem o padrão `lknbbpix_{NomeDaFunção}` (ex.: `lknbbpix_config`, `lknbbpix_MetaData`).
- **PHP — snake_case** para variáveis e parâmetros; **PascalCase** para classes; **camelCase** para métodos.
- **JS:** camelCase para variáveis e funções; ESNext (sem transpilação separada).
- **Prefixo de banco de dados:** Tabelas customizadas usam o prefixo `mod_lknbbpix_` (ex.: `mod_lknbbpix_discount_per_product`).
- **Prefixo de hooks:** Todos os `add_hook()` são registrados no arquivo `includes/hooks/lknbbpix.php`.

---

## Padrões Arquiteturais

### Camadas da Aplicação

O código segue uma arquitetura em camadas dentro de `App/Pix/`:

| Camada | Localização | Responsabilidade |
|---|---|---|
| **Controller** | `App/Pix/PixController.php` | Orquestra o fluxo: valida entrada, chama serviços, retorna resposta |
| **Service** | `App/Pix/Services/` | Regras de negócio isoladas (uma operação por serviço) |
| **Repository** | `App/Pix/PixApiRepository.php`, `Repositories/` | Comunicação com APIs externas e banco de dados |
| **Entity** | `App/Pix/Entity/` | Objetos de domínio imutáveis (ex.: `PixTaxId`) |
| **Helper** | `Helpers/` | Utilitários estáticos reutilizáveis (sem estado) |

### Modos de Cobrança

| Modo | Classe Repository | Endpoint BB | Quando usar |
|---|---|---|---|
| `cob` | `PixApiRepository` | `PUT /cob/{txId}` | Cobrança imediata (padrão) |
| `cobv` | `PixApiRepositoryLate` | `PUT /cobv/{txId}` | Cobrança com vencimento + juros/multa |
| `cobr` | `PixAutoRepository` | `PUT /cobr/{txId}` | Cobrança Pix Automático para fatura já autorizada |

O `PixController` recebe o parâmetro `$cobType` no construtor e instancia o repositório correto.

### Entidade `PixTaxId` — Codificação de IDs

A entidade `PixTaxId` é o coração da rastreabilidade. Ela encapsula a conversão entre os IDs usados pela BB API e os IDs usados internamente no WHMCS:

- **Formato BB API:** `{invoiceId}x{randomSuffix}` — ex.: `42xab09cd1e2f3a4b5`
- **Formato WHMCS (pendente):** `CRIADOx{randomSuffix}` — ex.: `CRIADOxab09cd1e2f3a4b5`
- **Formato WHMCS (pago):** `PAGOx{randomSuffix}x{endToEndId}` — ex.: `PAGOxab09cd1e2f3a4b5xE000...`

Sempre crie `PixTaxId` via seus métodos de fábrica estáticos:
- `PixTaxId::create($invoiceId, 'CRIADO')` — para criar um novo Pix
- `PixTaxId::fromWhmcsTransId($transacId, $invoiceId)` — ao ler do WHMCS
- `PixTaxId::fromApi('PAGO', $apiTxId)` — ao receber resposta da API

> Regra para Pix Automático (`/cobr`): a idempotência deve usar o `txid` exigido pelo BB.
> O módulo deve gerar `txid` determinístico (26 a 35 caracteres alfanuméricos) derivado do `invoice_id`.
> Não usar chave arbitrária paralela para deduplicação da cobrança automática.

---

## Helpers Disponíveis

Todos os helpers são classes `final` com métodos `static`. Use-os para operações comuns:

| Helper | Uso principal |
|---|---|
| `Config::setting('nome')` | Lê e faz o cast de uma configuração do gateway |
| `Config::constant('chave')` | Lê uma constante de `constants.php` (suporta `chave.subchave`) |
| `Invoice::getBalance($invoiceId)` | Retorna o saldo a pagar de uma fatura |
| `Invoice::getStatus($invoiceId)` | Retorna o status da fatura (`Unpaid`, `Paid`, etc.) |
| `Invoice::addTransac(...)` | Registra uma transação na fatura via `localAPI('AddTransaction')` |
| `Invoice::addDiscount(...)` | Adiciona item de desconto na fatura |
| `Invoice::addTax(...)` | Adiciona item de juros na fatura |
| `Logger::log($resultado, $request, $response)` | Loga via `logTransaction()` do WHMCS (respeitando `enable_logs`) |
| `Response::return($success, $data)` | Retorna array padronizado `['success' => bool, 'data' => array]` |
| `Response::api($success, $data)` | Emite JSON e encerra a execução (para endpoints AJAX) |
| `Validator::cpf($value)` | Valida CPF |
| `Validator::cnpj($value)` | Valida CNPJ |
| `View::render('nome_template', $vars)` | Renderiza template de `resources/` |

---

## Tratamento de Erros

- Use **`PixException`** para todos os erros de domínio do gateway:
  ```php
  throw new PixException(PixExceptionCodes::EXTERNAL_API_ERROR);
  ```
- Adicione novos códigos de erro no enum **`PixExceptionCodes`**, sempre com um `label()` em português.
- O `PixController` captura `PixException` e retorna via `Response::return(false, ['error' => ...])`.
- Use `Logger::log()` para registrar erros e fluxos importantes — nunca use `echo` ou `var_dump` em produção.
- Nunca exponha stack traces ao usuário final; log interno + mensagem genérica ao cliente.

---

## Autenticação com a API BB

O módulo usa **OAuth2 Client Credentials** + **mTLS**:

1. O construtor de `PixApiRepository` chama `requestAccessToken()` com os escopos necessários.
2. O token obtido é armazenado em `$this->accessToken` (`readonly`).
3. Todas as requisições subsequentes enviam `Authorization: Bearer {token}`.
4. As requisições usam os certificados `certs/public.key` e `certs/private.key` via cURL (`CURLOPT_SSLCERT` / `CURLOPT_SSLKEY`).
5. O `Basic` (base64 de `client_id:client_secret`) é usado **somente** na requisição de token.

> ⚠️ Nunca instancie `PixApiRepository` ou `PixApiRepositoryLate` mais de uma vez por requisição, pois cada instanciação faz uma chamada de autenticação à API.

---

## Fluxo de Pagamento

```
Cliente seleciona Pix → lknbbpix.php → PixController::create()
  ├─ InvoiceHasActivePixService → (se já existe Pix ativo, retorna ele)
  ├─ CreatePixService::run()
  │   ├─ validate() → checa status da fatura, saldo, CPF/CNPJ
  │   ├─ DiscountService::calculate() → aplica descontos configurados
  │   ├─ PixApiRepository::createPix() → PUT /cob/{txId}
  │   └─ Invoice::addTransac() → registra transação com status "CRIADO"
  └─ QRCode::render(pixCopiaECola) → gera imagem PNG base64

Pix pago → webhook.php OU polling do frontend → ConfirmPaymentService::run()
  ├─ Verifica duplicata por endToEndId
  ├─ Aplica desconto ou juros se necessário
  ├─ Invoice::addTransac() → registra transação com status "PAGO"
  └─ UpdateInvoice status → "Paid"
```

## Fluxo Pix Automático (BB v2)

```
Cliente abre fatura (WHMCS) → valida autorização em mod_lknbbpix_auths por client_id + due_day
  ├─ Se existir idRec APROVADA: agenda cobrança automática via PUT /cobr/{txid_deterministico}
  │   ├─ politicaRetentativa já definida como NAO_PERMITE no consentimento
  │   └─ mantém invoice como Unpaid até webhookcobr com liquidação
  └─ Se não existir idRec APROVADA: oferece Jornada 4
      ├─ cria cobrança da fatura (cob/cobv)
      ├─ cria location de recorrência (POST /locrec)
      ├─ cria consentimento (POST /rec)
      ├─ persiste idRec como CRIADA
      └─ exibe QR Code composto para aceite no app bancário

Cliente aceita no app → webhookrec.php atualiza status da autorização para APROVADA/CANCELADA

No vencimento (D) → webhookcobr.php
  ├─ CONCLUIDA: registra transação e marca invoice como Paid
  ├─ REJEITADA/EXPIRADA: mantém Unpaid e adiciona nota (append)
  └─ CANCELADA: mantém Unpaid, adiciona nota (append) e marca autorização como cancelada
```

Regras mandatórias:
- Consentimento é imutável: não migrar `idRec` entre due_day/periodicidade.
- Se due_day mudar e não houver autorização para o novo ciclo, usar Jornada 4 para novo consentimento.
- Com `politicaRetentativa = NAO_PERMITE`, não implementar retry técnico de cobrança automática.

---

## Hooks WHMCS Registrados

| Hook WHMCS | Arquivo | Comportamento |
|---|---|---|
| `AdminInvoicesControlsOutput` | `includes/hooks/lknbbpix.php` | Injeta botão de confirmação manual no admin |
| `AdminAreaHeaderOutput` | `includes/hooks/lknbbpix.php` | Injeta UI de desconto por produto na tela de config |
| `InvoiceCancelled` | `includes/hooks/lknbbpix.php` | Antes de cancelar, verifica se o Pix foi pago e confirma automaticamente |

Hooks adicionais para Pix Automático (novo fluxo):

| Hook WHMCS | Arquivo | Comportamento |
|---|---|---|
| `ClientAreaPageViewInvoice` (ou equivalente de render da fatura) | `includes/hooks/lknbbpix.php` | Decide entre cobrança automática (`/cobr`) ou oferta de Jornada 4 |
| `InvoiceCreationPreEmail` | `includes/hooks/lknbbpix.php` | Agenda cobrança automática apenas quando houver autorização APROVADA para o ciclo |

---

## Sistema de Descontos

O `DiscountService` calcula o valor final do Pix aplicando, em ordem de prioridade:

1. **Desconto por produto** (`mod_lknbbpix_discount_per_product`) — quando a fatura tem pedido associado e o produto tem desconto cadastrado.
2. **Desconto por registro de domínio** (`domain_register_discount_percentage`).
3. **Desconto global por pagamento Pix** (`discount_for_pix_payment_percentage`).
4. **Desconto por regra** (`ruled_discount_percentage`) — aplicado se existir pedido pendente.

> Quando qualquer produto da fatura tem desconto individual configurado, os descontos globais (3 e 4) são ignorados para aquela fatura.

---

## Banco de Dados

- **ORM:** `WHMCS\Database\Capsule` (Eloquent/Laravel). Sempre prefira `Capsule` a queries SQL brutas.
- **Tabela customizada:** `mod_lknbbpix_discount_per_product` (criada automaticamente em `lknbbpix_config()` se não existir).
- **Tabela customizada (Pix Automático):** `mod_lknbbpix_auths` (autorizações por cliente e ciclo).
- **Campos mínimos em `mod_lknbbpix_auths`:** `id`, `client_id`, `id_rec`, `due_day`, `periodicidade`, `status`, `created_at`, `updated_at`.
- **Imutabilidade do vínculo:** `id_rec` representa um consentimento de ciclo específico; não migrar para outro `due_day`/`periodicidade`.
- **Tabelas WHMCS usadas diretamente:**
  - `tblinvoices` — status e userid da fatura
  - `tblorders` — relação fatura → pedido
  - `tblhosting` / `tblproducts` — identificação de produto pelo item da fatura
  - `tblconfiguration` — URL do sistema WHMCS

---

## Configurações do Gateway

As configurações são lidas via `Config::setting('nome')`, que aplica os casts corretos para cada campo. As principais são:

| Chave | Tipo | Descrição |
|---|---|---|
| `env` | `string` (`hml_no_mtls`/`hml_mtls`/`prod`) | Ambiente da API BB |
| `developer_application_key` | `string` | Chave de app do portal BB Developers |
| `client_id` / `client_secret` | `string` | Credenciais OAuth2 |
| `auth_basic` | `string` | Basic auth base64 para OAuth2 |
| `receiver_pix_key` | `string` | Chave Pix do recebedor |
| `pix_expiration` | `int` (dias) | Validade do Pix gerado |
| `pix_descrip` | `string` (max 140) | Descrição da cobrança |
| `send_payer_doc_and_name` | `bool` | Incluir CPF/CNPJ e nome do pagador |
| `enable_fees_interest` | `bool` | Ativar cobrança pós-vencimento (cobv) |
| `enable_logs` | `bool` | Ativar logs via `logTransaction()` |

---

## Ambientes da API BB

| Ambiente | Base URL Pix API | OAuth URL |
|---|---|---|
| `hml_no_mtls` (homologação sem mTLS) | `https://api.extranet.hm.bb.com.br/pix/v2` | `https://oauth.hm.bb.com.br` |
| `hml_mtls` (homologação com mTLS) | `https://api-pix.hm.bb.com.br/pix/v2` | `https://oauth.hm.bb.com.br` |
| `prod` (produção) | `https://api-pix.bb.com.br/pix/v2` | `https://oauth.bb.com.br` |

Todas as requisições adicionam `?gw-dev-app-key={developer_application_key}` na query string.

---

## Boas Práticas e Restrições

### O que fazer
- Sempre usar `Config::setting()` para ler configurações — nunca acesse o array de configurações diretamente.
- Sempre registrar operações importantes com `Logger::log()`.
- Sempre validar entrada com `Validator::cpf()` / `Validator::cnpj()` antes de enviar à API.
- Criar novos serviços em `App/Pix/Services/` com um método público `run()`.
- Usar `Response::return()` para respostas internas e `Response::api()` para endpoints AJAX.
- Para Pix Automático, gerar `txid` determinístico para `PUT /cobr/{txid}`.
- Em `invoice.notes`, sempre fazer append (nunca remover histórico).
- Em falha de cobrança automática (`REJEITADA`/`EXPIRADA`), manter `Unpaid` e deixar automação do WHMCS continuar.
- Atualizar `CHANGELOG.md` e a versão em `constants.php` a cada release.
- Seguir o checklist do Pull Request Template em `.github/pull_request_template.md`.

### O que não fazer
- **Não** usar `echo`, `var_dump` ou `print_r` em código de produção.
- **Não** instanciar `PixApiRepository` / `PixApiRepositoryLate` mais de uma vez por fluxo.
- **Não** acessar tabelas WHMCS com queries SQL cruas quando `Capsule` ou `localAPI()` estiver disponível.
- **Não** usar funções ou classes depreciadas do WHMCS.
- **Não** incluir chaves privadas, credenciais ou tokens no código-fonte.
- **Não** remover ou modificar a verificação de duplicata em `ConfirmPaymentService` (prevenção de pagamento duplo).
- **Não** usar `die()` ou `exit()` fora de `webhook.php` e verificações de segurança de arquivo.
- **Não** implementar retry técnico de cobrança automática quando `politicaRetentativa = NAO_PERMITE`.
- **Não** migrar `idRec` para outro `due_day`/`periodicidade`.

---

## Adicionando Novas Funcionalidades

1. **Novo serviço de negócio:** Crie uma classe `final` em `App/Pix/Services/` com um método `run()`. Injete dependências via construtor.
2. **Novo endpoint AJAX:** Adicione o roteamento em `api.php` e o handler em `App/Pix/Controllers/ApiController.php`.
3. **Nova configuração do gateway:** Adicione o campo em `lknbbpix_config()` (em `lknbbpix.php`) e seu cast correspondente em `Config::parseConfig()`.
4. **Novo hook WHMCS:** Registre com `add_hook()` em `includes/hooks/lknbbpix.php`.
5. **Novo template:** Crie o arquivo `.tpl` em `src/resources/` e renderize com `View::render('nome_template', $vars)`.
6. **Novo erro de domínio:** Adicione o case no enum `PixExceptionCodes` e seu `label()` em português.

## Pontos Críticos (Pix Automático)

1. **Idempotência via BB:** use `txid` determinístico no `PUT /cobr/{txid}`; não criar idempotência paralela arbitrária.
2. **Consentimento imutável:** não migrar autorização existente para novo ciclo. Mudou ciclo, novo consentimento.
3. **Webhooks separados:** `webhook.php` (Pix imediato), `webhookrec.php` (status de autorização), `webhookcobr.php` (liquidação/falha).
4. **Status e ações no WHMCS:**
  - `CONCLUIDA` → marca `Paid` e registra transação.
  - `REJEITADA`/`EXPIRADA` → mantém `Unpaid` e adiciona nota de falha (append).
  - `CANCELADA` → mantém `Unpaid`, adiciona nota (append) e inativa autorização para próximos ciclos.
5. **Sem retry técnico:** com `NAO_PERMITE`, a retomada de cobrança é do fluxo nativo de inadimplência do WHMCS.

---

## Internacionalização

- As mensagens de erro para o usuário final (retornadas pelo `PixExceptionCodes::label()`) são escritas em **português**.
- Logs internos (`Logger::log`) podem ser em português ou inglês.
- Strings de configuração exibidas no painel WHMCS devem usar `__()` quando suportado pelo contexto.

---

## Versão e Release

- A versão atual do módulo está em `src/modules/gateways/lknbbpix/src/constants.php` na chave `version`.
- A versão também é exibida no cabeçalho da página de configuração via `Config::constant('version')`.
- O `composer.json` do módulo está em `src/modules/gateways/lknbbpix/composer.json`.
- Ao fazer um release: atualizar `constants.php` (version), `CHANGELOG.md` e o PR template checklist.
