# 🌅 Rise Tech Tools

Pacote de **macros, helpers e utilitários avançados** da [Rise Tech](https://risetech.com.br) para aplicações Laravel.

Inclui:

| Recurso | O que faz |
|---|---|
| [Macros de resposta JSON](#macros-de-resposta-json) | `jsonSuccess`, `jsonCreated`, `jsonValidation`, `jsonForbidden`... e renderer de exceções no mesmo envelope |
| [AvatarGenerator](#-avatargenerator) | Avatar circular com gradiente e iniciais, em PNG ou SVG |
| [MaskInput](#maskinput) | Máscaras livres e presets de CPF, CNPJ (inclusive alfanumérico), CEP e telefone |
| [Device](#device) | Dispositivo, navegador, IP do cliente e geolocalização |
| [Domain](#domain) | Domínio registrável, DNS, SSL, WHOIS |
| [EmailValidator](#emailvalidator) | Formato, MX, descartável, genérico, SMTP e regras de validação |
| [AtomicJobChain](#atomicjobchain) | Cadeia de jobs que para na primeira falha |
| [NPlusOneDetector](#nplusonedetector) | Detecta N+1 em tempo de execução |
| [DatabaseSnapshot](#databasesnapshot) | Snapshot/restore do banco (MySQL, PostgreSQL, SQLite) em qualquer disco |
| [DatabaseHealthMonitor](#databasehealthmonitor) | `risetools:db-health`: problemas de schema e índices |
| [Doctor](#doctor) | `risetools:doctor` e rotas `/health/live` e `/health/ready` |

> Compatível com **Laravel 12+** e **PHP 8.4+**

[![Packagist Version](https://img.shields.io/packagist/v/risetechapps/risetools.svg?color=00bfa5)](https://packagist.org/packages/risetechapps/risetools)
[![License](https://img.shields.io/github/license/risetechapps/risetools.svg?color=00bfa5)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.4-blue.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)

---

## 🚀 Instalação

```bash
composer require risetechapps/risetools
```

O service provider e a facade são registrados automaticamente (package discovery).

Para customizar a config (opcional, tudo também funciona por `.env`):

```bash
php artisan vendor:publish --tag=risetools-config   # cria config/risetools.php
```

### Facade `RiseTools`

Uma porta de entrada para todas as features:

```php
use RiseTools;

RiseTools::avatar()->generateSvg('Ana Souza');
RiseTools::domain('app.example.com')->getInfo(3600);
RiseTools::email()->isDisposable($email);
RiseTools::device(false);                 // Device::info(false)
RiseTools::clientIp();                    // Device::getClientPublicIp()
RiseTools::mask()->cpf('12345678901');
RiseTools::snapshot()->create('base');
RiseTools::dbHealth()->run();
RiseTools::nPlusOne();
RiseTools::doctor()->addCheck('payments', fn() => 'ok');
```

Os helpers (`email_validator()`, `database_snapshot()`, `MaskInput()`...) continuam funcionando.

```bash
composer require risetechapps/risetools:^1.0
```

A 1.0 muda comportamentos das versões de teste por segurança e LGPD. Antes de atualizar:

1. **TrustProxies**: `Device::getClientPublicIp()` passou a usar `request()->ip()`. Atrás de proxy, load balancer ou Cloudflare, configure os proxies confiáveis, senão o IP registrado será o do proxy:
   ```php
   // bootstrap/app.php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->trustProxies(at: ['10.0.0.0/8']); // IPs/CIDRs dos seus proxies
   })
   ```
   Atenção: `$middleware->use([...])` **substitui** a pilha global inteira (TrustProxies junto). Prefira `append`/`prepend`.
2. **Geolocalização desligada por padrão**: para manter, `RISETOOLS_GEOIP_ENABLED=true`.
3. **`?agent=` removido**: o user agent vem só do header do request.
4. **Respostas JSON**: `jsonSuccess([])` devolve `"data": []` (antes omitia `data`); Resources paginadas trazem `meta` e `links`; `jsonGone()` tem a mensagem padrão `Resource no longer available.`.
5. **Comandos**: `risetools:db-health --json/--export` saem com código `1` quando há problemas; o check `nullable_without_default` foi removido.
6. **Classes renomeadas**: `Features\HealthCheck\HealthCheckCommand` → `DoctorCommand` e `Features\DatabaseHealthMonitor\Console\HealthCheckCommand` → `DatabaseHealthCommand` (os nomes dos comandos no artisan não mudaram).

Detalhes no [CHANGELOG](CHANGELOG.md).

---

## Macros de Resposta JSON

Para padronizar as respostas da API e facilitar o consumo por clientes, foram registradas macros na `Illuminate\Contracts\Routing\ResponseFactory` que seguem um formato JSON consistente.

Todas as respostas JSON seguirão a seguinte estrutura base:

| Campo | Tipo | Descrição |
| :--- | :--- | :--- |
| `success` | `boolean` | Indica se a operação foi bem-sucedida (`true`) ou se ocorreu um erro (`false`). |
| `code` | `integer` | O código de status HTTP da resposta. |
| `message` | `string` | Uma mensagem descritiva sobre o resultado da operação (opcional). |
| `data` | `object/array` | Os dados de resposta da operação (opcional). |

### Macros Disponíveis

As macros podem ser chamadas diretamente a partir da *facade* `response()`.

#### 1. `response()->jsonSuccess($data = null, $message = 'Operation completed successfully.')`

Utilizada para retornar uma resposta de sucesso.

*   **Status HTTP:** `200 OK`
*   **Parâmetros:**
    *   `$data`: Dados a serem retornados (array ou `JsonResource`).
    *   `$message`: Mensagem de sucesso personalizada.
*   **Exemplo de Uso:**
    ```php
    return response()->jsonSuccess(['id' => 1, 'name' => 'Produto X']);
    ```
*   **Exemplo de Resposta:**
    ```json
    {
        "success": true,
        "code": 200,
        "message": "Operation completed successfully.",
        "data": {
            "id": 1,
            "name": "Produto X"
        }
    }
    ```

#### 2. `response()->jsonError($message = 'Resource not available.', $data = null)`

Utilizada para retornar um erro de processamento ou de entidade não processável.

*   **Status HTTP:** `422 Unprocessable Entity`
*   **Parâmetros:**
    *   `$message`: Mensagem de erro personalizada.
    *   `$data`: Dados adicionais sobre o erro (ex: erros de validação).
*   **Exemplo de Uso:**
    ```php
    return response()->jsonError('Os dados fornecidos são inválidos.', ['errors' => ['field' => 'required']]);
    ```

#### 3. `response()->jsonGone($message = 'Resource no longer available.', $data = null)`

Utilizada para indicar que o recurso solicitado não está mais disponível e não será novamente.

*   **Status HTTP:** `410 Gone`
*   **Parâmetros:**
    *   `$message`: Mensagem de erro personalizada.
    *   `$data`: Dados adicionais sobre o erro.
*   **Exemplo de Uso:**
    ```php
    return response()->jsonGone('A versão desta API foi descontinuada.');
    ```

#### 4. `response()->jsonNotFound($message = 'Resource not found.', $data = null)`

Utilizada para indicar que o recurso solicitado não foi encontrado.

*   **Status HTTP:** `404 Not Found`
*   **Parâmetros:**
    *   `$message`: Mensagem de erro personalizada.
    *   `$data`: Dados adicionais sobre o erro.
*   **Exemplo de Uso:**
    ```php
    return response()->jsonNotFound('O usuário com ID 5 não existe.');
    ```

#### 5. `response()->jsonInternal($message = 'Internal server error.', $data = null)`

Utilizada para indicar um erro interno do servidor.

*   **Status HTTP:** `500 Internal Server Error`
*   **Parâmetros:**
    *   `$message`: Mensagem de erro personalizada.
    *   `$data`: Dados adicionais sobre o erro (ex: ID de rastreamento de log).
*   **Exemplo de Uso:**
    ```php
    return response()->jsonInternal('Ocorreu um erro inesperado ao processar a requisição.');
    ```

***

#### 6. Outras macros

| Macro | Status | Mensagem padrão |
|---|---|---|
| `jsonCreated($data = null, $message)` | 201 | `Resource created successfully.` |
| `jsonNoContent($headers = [])` | 204 | sem corpo (204 não pode ter corpo) |
| `jsonUnauthorized($message, $data = null)` | 401 | `Unauthenticated.` |
| `jsonForbidden($message, $data = null)` | 403 | `This action is unauthorized.` |
| `jsonValidation($errors, $message)` | 422 | `The given data was invalid.` |

`jsonValidation()` aceita array ou `MessageBag` e mantém o formato de erros do Laravel:

```php
return response()->jsonValidation($validator->errors());
```

```json
{ "success": false, "code": 422, "message": "The given data was invalid.", "errors": { "email": ["The email field is required."] } }
```

***

### Macro Base (Interna)

A macro `jsonBase` é a implementação interna utilizada por todas as outras macros e não deve ser chamada diretamente em seu código de aplicação.

`response()->jsonBase(bool $success, string $message = null, array|JsonResource $data = null, int $code = Response::HTTP_OK)`

### Resources e listas vazias

- `JsonResource` passa pelo mesmo caminho do Laravel (`->response()`): `with()`, `additional()` e, em coleções paginadas, `meta` e `links` ficam no mesmo nível de `data`, sem sobrescrever `success`/`code`/`message`.
- `null` omite `data`; `[]` vira `"data": []`.

```php
return response()->jsonSuccess(UserResource::collection(User::paginate(15)));
```

```json
{
    "success": true,
    "code": 200,
    "message": "Operation completed successfully.",
    "data": [{ "id": 1 }],
    "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
    "meta": { "current_page": 1, "last_page": 7, "per_page": 15, "total": 100 }
}
```

### Exceções no mesmo envelope (opcional)

Para 401, 403, 404, 422, 429, 500 etc. saírem no formato `success/code/message/data` sem chamar macro em cada controller:

```php
// bootstrap/app.php
use RiseTechApps\RiseTools\Http\JsonExceptionRenderer;

->withExceptions(function (Exceptions $exceptions) {
    JsonExceptionRenderer::register($exceptions);
})
```

- Vale para requests que esperam JSON ou em `api/*`; outra regra: `JsonExceptionRenderer::register($exceptions, fn ($request, $e) => $request->is('v1/*'))`.
- Validação vira `jsonValidation()` (com `errors`); `ModelNotFoundException` vira `Resource not found.` (sem expor a classe do model); headers de `HttpException` (ex.: `Retry-After` no 429) são mantidos.
- Erro 500 sai como `Internal server error.`; com `APP_DEBUG=true`, `data` traz classe, mensagem, arquivo, linha e trace.
- O report (log) das exceções não muda.

---

# 🎨 AvatarGenerator

O **AvatarGenerator** permite gerar imagens de avatar totalmente automáticas com:

- ✔ Gradiente circular elegante
- ✔ Cores únicas e consistentes baseadas no nome
- ✔ Iniciais automáticas (ex.: “Mateus Soares” → MS)
- ✔ Fundo circular com transparência
- ✔ Retorno como PNG binário
- ✔ Retorno Base64 (ideal para API)
- ✔ Retorno em **SVG** (sem GD, escala sem perder qualidade)
- ✔ Cor do texto (branco ou cinza-escuro) escolhida pelo contraste com o gradiente (WCAG)
- ✔ Salvamento como arquivo
- ✔ Salvamento via Laravel Storage

---

## 🧪 Exemplo de Uso

### ➤ Gerar avatar como PNG

```php
use RiseTechApps\RiseTools\Features\AvatarGenerator;

$avatar = new AvatarGenerator();
$png = $avatar->generate('Mateus Soares');

return response($png)->header('Content-Type', 'image/png');
```

---

### ➤ Gerar avatar em Base64

```php
$avatar = new AvatarGenerator();

return [
    'avatar' => $avatar->generateBase64('Mateus Soares'),
];
```

---

### ➤ Gerar avatar em SVG

```php
$svg = $avatar->generateSvg('Mateus Soares', 128);          // string <svg ...>
$uri = $avatar->generateSvgDataUri('Mateus Soares');        // data:image/svg+xml;base64,...

return response($svg)->header('Content-Type', 'image/svg+xml');
```

Mesmas cores e iniciais do PNG. Não precisa de GD; o texto usa a fonte do navegador (Roboto, se houver).

---

### ➤ Salvar avatar em arquivo

```php
$avatar = new AvatarGenerator();
$avatar->saveToFile('avatars/mateus.png', 'Mateus Soares');
```

---

### ➤ Salvar usando Storage do Laravel

```php
$avatar = new AvatarGenerator();

$avatar->saveToStorage(
    'public',
    'avatars/mateus.png',
    'Mateus Soares'
);
```

---

## ⚙️ Funcionamento

O gradiente é criado com base em um hash MD5 do nome, garantindo que cada usuário tenha sempre **as mesmas cores**.  
As iniciais são extraídas automaticamente:

| Nome | Resultado |
|------|-----------|
| Mateus Soares | **MS** |
| Mateus | **MA** |
| João da Silva | **JS** |
| "" | **U** |

---

## 🛠️ Tecnologias Utilizadas

- PHP GD / FreeType (só para PNG; SVG não precisa)
- Nenhuma dependência externa
- Totalmente stateless

---

# MaskInput

O **MaskInput** permite **aplicar máscaras em strings**,  ideal para CPF, CNPJ, telefone, CEP e outros formatos personalizados.

### Utilizando a classe `MaskInput`

```php
use RiseTechApps\RiseTools\Features\MaskInput\MaskInput;

$maskInput = new MaskInput();

$result = $maskInput->MaskInput('12345678901', '###.###.###-##');

echo $result;
// 123.456.789-01

echo MaskInput('12345678901', '###.###.###-##');
// 123.456.789-01
```

### Presets

Aceitam o valor com ou sem formatação. **Só formatam**: validação de CPF/CNPJ fica com o `form-request-for-laravel`.

```php
$mask = RiseTools::mask(); // ou new MaskInput()

$mask->cpf('12345678901');            // 123.456.789-01
$mask->cnpj('12345678000190');        // 12.345.678/0001-90
$mask->cnpj('12abc34501de35');        // 12.ABC.345/01DE-35 (CNPJ alfanumérico)
$mask->document('12345678901');       // CPF ou CNPJ, pelo tamanho
$mask->cep('01310100');               // 01310-100
$mask->phone('1134567890');           // (11) 3456-7890
$mask->phone('11912345678');          // (11) 91234-5678
$mask->phone('5511912345678');        // +55 (11) 91234-5678
$mask->unmask('123.456.789-01');      // 12345678901 (mantém letras, em maiúsculas)
```

Constantes com as máscaras: `MaskInput::CPF`, `CNPJ`, `CEP`, `PHONE`, `MOBILE`.

---

## 🧩 Como funciona

- O caractere `#` representa um valor dinâmico
- Qualquer outro caractere na máscara é inserido automaticamente
- A máscara é aplicada da esquerda para a direita
- Valores excedentes são ignorados

### Parâmetros

| Parâmetro | Tipo | Descrição |
|---------|------|----------|
| `$value` | string | Valor sem máscara |
| `$mask` | string | Máscara desejada |

---

# Device
O utilitário para **detecção de informações do dispositivo, navegador, plataforma e geolocalização por IP** em aplicações Laravel.

Este recurso utiliza o pacote `hisorange/browser-detect` para identificar o ambiente do usuário (pelo header `User-Agent` do request) e, se habilitada, a API pública `ip-api.com` para dados de geolocalização.

---

## 🚀 Uso

### Obtendo informações do dispositivo

```php
use RiseTechApps\RiseTools\Features\Device\Device;

$info = Device::info();       // com geo (se RISETOOLS_GEOIP_ENABLED=true)
$info = Device::info(false);  // sem geo: use no caminho de cada request

dd($info);
```

Lê tudo do `request()` corrente (sem `$_SERVER`/`$_GET`), então funciona no Octane.

---

## 📌 Retorno do método `info()`

O método retorna um array com as seguintes informações:

```php
[
    'device' => 'Desktop | Mobile | Tablet | Bot | Unknown',
    'browser' => 'Chrome | Safari | Firefox | Edge | Opera | IE | webView | Unknown',
    'browser_name' => 'Nome completo do navegador',
    'platformName' => 'Windows | Android | iOS | Linux | MacOS | etc',
    'geo_ip' => [
        'status' => '',
        'country' => '',
        'countryCode' => '',
        'region' => '',
        'regionName' => '',
        'city' => '',
        'zip' => '',
        'lat' => '',
        'lon' => '',
        'timezone' => '',
        'isp' => '',
        'org' => '',
        'as' => '',
        'query' => '',
    ]
]
```

---

## 🌍 Geolocalização por IP

**Desligada por padrão.** O IP do usuário sai para um terceiro (LGPD), por HTTP sem TLS no plano gratuito do ip-api. Para ligar:

```dotenv
RISETOOLS_GEOIP_ENABLED=true
```

- Resultado **cacheado por IP durante 24h**; falha (provedor fora, limite) fica em **cache negativo** por `RISETOOLS_GEOIP_FAILURE_TTL` minutos (10), para não pagar o timeout em todo request.
- Timeout curto (connect 2s / total 4s).
- IP privado/reservado (rede interna, localhost) não é consultado.
- O cache usa um store **sem prefixo** (`RISETOOLS_GEOIP_CACHE_STORE`, padrão: o store default), para o mesmo IP ser reaproveitado entre usuários. Precisa ser persistente (não `array`).
- O ip-api gratuito tem limite de ~45 req/min e uso não comercial; `RISETOOLS_GEOIP_URL` troca o provedor (`{ip}` é substituído).

Com o geo desligado, `geo_ip` vem com todos os campos vazios.

---

## 🧠 Detecção de IP do Cliente

`Device::getClientPublicIp()` devolve `request()->ip()`. Headers como `X-Forwarded-For` e `X-Real-IP` são escritos pelo cliente e só valem quando chegam de um **proxy confiável** — quem decide é o TrustProxies do Laravel:

```php
// bootstrap/app.php
$middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12']);
```

**Cloudflare**: o header `CF-Connecting-IP` só é lido com `RISETOOLS_TRUST_CLOUDFLARE_HEADER=true`. Ligue apenas se a origem aceita tráfego **só** da Cloudflare (firewall com os IPs dela ou Authenticated Origin Pulls); senão qualquer um forja o header acessando o servidor direto. Alternativa sem essa opção: incluir os [IPs da Cloudflare](https://www.cloudflare.com/ips/) no `trustProxies`.

---

## 🧪 Métodos Disponíveis

```php
Device::info(): array
Device::getClientPublicIp(): ?string
```

---

# Domain

Package utilitário para **análise e obtenção de informações de domínios**, incluindo subdomínio, IP, registros DNS, SSL, status de publicação e dados WHOIS.

Este recurso faz parte do ecossistema **RiseTools** e foi projetado para uso em aplicações Laravel.

---

As dependências (`spatie/dns`, `jeremykendall/php-domain-parser`, `io-developer/php-whois`) já vêm com o pacote.

> O pacote utiliza a lista oficial do Public Suffix (`publicsuffix.org`).

### 🗂️ Cache do Public Suffix List

A lista **não** é baixada a cada `new Domain()`. A resolução segue esta ordem:

1. Memo por processo (objeto `Rules` já parseado)
2. Cache do Laravel (`risetools:psl`, 7 dias)
3. Um único download remoto (timeout 5s), armazenado no cache
4. Cópia local empacotada (`src/Features/Domain/public_suffix_list.dat`) como fallback offline

Assim não há chamada de rede no caminho da request após o primeiro carregamento, e o recurso não quebra sem internet. O cache de 7 dias requer um driver de cache persistente (não `array`).

---

## ⚙️ Requisitos

- PHP **8.4+**
- Laravel **12+**
- Extensões PHP:
    - `openssl`
    - `dns`

---

## 🚀 Uso Básico

### Criando a instância da classe Domain

```php
use RiseTechApps\RiseTools\Features\Domain\Domain;

$domain = new Domain('blog.example.com');

$domain = domainTools('blog.example.com');
```

---

## 📌 Métodos Disponíveis

### Obter domínio principal (registrável)

```php
$domain->getDomain();
// example.com
```

### Obter subdomínio

```php
$domain->getSubDomain();
// blog
```

### Obter IP do domínio

```php
$domain->getIp();
// 93.184.216.34
```

### Obter registros DNS

```php
$domain->getDnsRecords();
// Retorna registros A, MX, TXT, CNAME, etc
```

---

## 🔐 Informações de SSL

```php
$domain->getSslInfo();
```

Retorno esperado:

```php
[
    'status' => true,        // conseguiu ler o certificado
    'issuer' => 'Let\'s Encrypt',
    'expires_at' => '2025-01-01 12:00:00',
    'is_expired' => false,   // passou da validade
    'is_trusted' => true,    // cadeia e nome conferem
]
```

Certificado expirado, autoassinado ou com nome errado ainda é lido (`status: true`), com `is_trusted: false` e, se for o caso, `is_expired: true`. Sem conexão TLS: `['status' => false, 'expires_at' => null]`.

---

## 🌐 Verificações de Domínio

### Verificar se o domínio resolve no DNS

```php
$domain->isResolvable();
// true | false
```

### Verificar se o domínio está publicado

```php
$domain->isPublished();
// true | false
```

---

## 🧾 WHOIS – Data de Expiração

```php
$domain->getWhoisExpiration();
// 2026-03-15
```

> ⚠️ O WHOIS pode falhar dependendo do TLD ou indisponibilidade do servidor.

---

## 📊 Informações Completas do Domínio

```php
$domain->getInfo();      // consulta tudo agora
$domain->getInfo(3600);  // guarda o resultado no cache do Laravel por 1h
```

São várias consultas de rede (DNS, SSL, WHOIS). Dentro da mesma instância cada uma sai uma vez só (chamar `getSslInfo()` e depois `getInfo()` não repete o handshake); entre requests, use o parâmetro de cache.

Retorno:

```php
[
    'domain' => 'example.com',
    'hasSubDomain' => true,
    'subDomain' => 'blog',
    'ip' => '93.184.216.34',
    'dns' => [],
    'ssl' => [],
    'resolve' => true,
    'status' => true,
    'expires_at' => '2026-03-15',
    'url' => 'http://blog.example.com',
    'fullUrl' => 'https://blog.example.com'
]
```

> `fullUrl` usa `https` apenas quando o certificado é confiável e está dentro da validade; caso contrário, é igual a `url` (`http`).

---

# AtomicJobChain

O `AtomicJobChain` é uma poderosa classe utilitária do Laravel que permite encadear múltiplos Jobs de forma **atômica** e **sequencial**. Diferente do encadeamento nativo do Laravel, esta implementação oferece um controle mais refinado sobre o fluxo de execução e incorpora os callbacks de sucesso, falha e finalização (`then`, `catch`, `finally`), inspirados no recurso de Batches.

## 🌟 Funcionalidades Principais

*   **Execução Sequencial Atômica:** Os Jobs são executados um após o outro. A falha em qualquer Job interrompe imediatamente a execução da cadeia.
*   **Callbacks de Fluxo de Controle:** Suporte a `then()`, `catch()` e `finally()` para reagir ao resultado final da cadeia.
*   **Integração com Eventos:** Método `toListener()` para fácil despacho da cadeia a partir de Listeners de Eventos.
*   **Visibilidade no Horizon:** Implementação do `displayName()` para uma visualização clara e descritiva no painel do Laravel Horizon.

## 🚀 Uso

A cadeia é tipicamente construída usando o método estático `make()` e configurada com a *Fluent Interface*.

### 1. Construção e Despacho

O uso mais comum é dentro de um Listener de Eventos, garantindo que a cadeia seja despachada de forma assíncrona.

```php
use App\Jobs\Database\SeedDatabaseJob;
use App\Jobs\SubTenant\CreateSubTenantDefaultJob;
use App\Events\Database\DatabaseMigratedEvent;
use RiseTechApps\RiseTools\Features\AtomicJobChain\AtomicJobChain;

// Dentro de um EventServiceProvider ou Listener
Event::listen(DatabaseMigratedEvent::class, function (DatabaseMigratedEvent $event) {
    
    AtomicJobChain::make([
        SeedDatabaseJob::class,
        CreateSubTenantDefaultJob::class,
        // ... adicione quantos Jobs forem necessários
    ])
    // Transforma o evento em um objeto passável para os Jobs internos
    ->send(function (DatabaseMigratedEvent $event) {
        $event->tenancy->refresh();
        return $event->tenancy; // O objeto retornado será passado para os Jobs
    })
    ->shouldBeQueued(true) // Garante que a cadeia será enfileirada
    ->toListener(); // Retorna a Closure que o Laravel usa para despachar o Job
});
```

### 2. Utilizando Callbacks (`then`, `catch`, `finally`)

Os callbacks permitem que você execute ações após a conclusão ou falha da cadeia.

| Método | Descrição | Argumentos Recebidos |
| :--- | :--- | :--- |
| `->then(callable $callback)` | Executado se **todos** os Jobs na cadeia forem concluídos com sucesso. | Nenhum |
| `->catch(callable $callback)` | Executado se **qualquer** Job na cadeia falhar. | `Throwable $exception` (a exceção que causou a falha) |
| `->finally(callable $callback)` | Executado **sempre** ao final da execução, independente do resultado. | Nenhum |

**Exemplo:**

```php
AtomicJobChain::make([...])
    ->send([...])
    ->then(function () {
        // Notifica o sucesso da operação
        Log::info('Cadeia de Jobs concluída com sucesso!');
    })
    ->catch(function (Throwable $e) {
        // Registra a falha e a exceção
        Log::error('A cadeia falhou: ' . $e->getMessage());
    })
    ->finally(function () {
        // Executa a limpeza ou notificação final
        Cache::forget('chain_running_flag');
    })
    ->toListener();
```

## 📊 Monitoramento com Laravel Horizon

O `AtomicJobChain` implementa o método `displayName()`, garantindo que o painel do Horizon exiba um nome descritivo em vez do nome da classe.

| Antes | Depois |
| :--- | :--- |
| `RiseTechApps\RiseTools\Features\AtomicJobChain\AtomicJobChain` | `Atomic Chain: SeedDatabaseJob, CreateSubTenantDefaultJob, ...` |

### Rastreamento de Falhas

Em caso de falha, o Horizon registrará o Job pai (`AtomicJobChain`) como falho. A exceção será encapsulada para indicar **qual Job interno** causou a interrupção, facilitando a depuração:

> **Exception:** `Job [App\Jobs\Database\SeedDatabaseJob] failed: SQLSTATE[HY000]: General error: ...`

Isso elimina a necessidade de vasculhar o Stack Trace para identificar o ponto exato da falha.

## 🛠️ Detalhes Técnicos

A classe utiliza a interface `ShouldQueue` e garante a atomicidade da execução no método `handle()`.

```php
// Trecho do método handle()
try {
    // ... execução do Job interno
} catch (Throwable $exception) {
    $hasFailed = true;
    
    // Executa o callback de falha
    if ($this->onFailure) {
        app()->call($this->onFailure, ['exception' => $exception]);
    }
    
    // Lança a exceção encapsulada para o Horizon
    throw $wrapperException; 
}
// ...
```

O uso de `DB::afterCommit()` no método `toListener()` garante que a cadeia de Jobs só seja despachada para a fila **após** o commit de qualquer transação de banco de dados ativa, prevenindo problemas de concorrência.

```php
// Trecho do método toListener()
if (DB::transactionLevel() > 0) {
    DB::afterCommit(function () use ($executable) {
        dispatch($executable);
    });
} else {
    dispatch($executable);
}
```
---

# NPlusOneDetector

Detector automático do problema de **N+1 queries** em tempo de execução. Escuta todas as queries do banco (`DB::listen()`), agrupa por padrão, e quando um mesmo padrão se repete acima de um limite, reporta com uma **sugestão de eager loading**.

## 🧠 O que é N+1

Uma query para a lista + uma query por item ao acessar um relacionamento:

```php
$posts = Post::all();          // 1 query
foreach ($posts as $post) {
    echo $post->author->name;  // +1 query POR post → N queries
}
// 100 posts = 101 queries. O ideal seriam 2.
```

Correção típica:

```php
Post::with('author')->get();   // 2 queries
```

O `NPlusOneDetector` encontra esses casos automaticamente e sugere o `with()`/`whereIn()`.

## 🚀 Uso

Ative no início do ciclo (ex.: um `ServiceProvider` em ambiente `local`/`staging`):

```php
use RiseTechApps\RiseTools\Features\NPlusOneDetector\NPlusOneDetector;

NPlusOneDetector::enable()    // inicia a escuta (DB::listen) — obrigatório
    ->threshold(5)            // dispara após 5 repetições do mesmo padrão
    ->sampleRate(1.0)         // 0.0–1.0: fração das queries analisadas
    ->suggestEagerLoading()   // inclui sugestão de correção no relatório
    ->reportToLog();          // grava aviso via Log::warning
```

> A escuta só começa após `NPlusOneDetector::enable()` (método estático). O helper `n_plus_one_detector()` retorna a instância para configuração/consulta, mas **não** ativa a escuta sozinho — use-o após `enable()`:

```php
NPlusOneDetector::enable();
n_plus_one_detector()->threshold(5)->suggestEagerLoading()->reportToLog();
```

Quando um N+1 é detectado, um aviso como este vai para o log:

```
[N+1 Query] 5 queries detected for table 'posts'. Eager load the relationship that returns Post on the parent query: ->with('<relation>')
```

## 📊 Estatísticas

```php
NPlusOneDetector::getStats();
/*
[
    'enabled' => true,
    'total_unique_patterns' => 12,
    'suspicious_patterns' => [...],
    'top_queries' => [...]   // padrões mais frequentes (até 10)
]
*/

NPlusOneDetector::clearStats();  // zera contadores
NPlusOneDetector::disable();     // para de escutar
```

## ⚙️ Métodos de configuração

| Método | Descrição |
|--------|-----------|
| `threshold(int)` | Nº de repetições do padrão até reportar (default `5`) |
| `sampleRate(float)` | Amostragem de `0.0` a `1.0` (default `1.0`) |
| `suggestEagerLoading()` | Adiciona sugestão de `with()`/`whereIn()` ao relatório |
| `reportToLog()` | Reporta via `Log::warning` |
| `reportToSentry()` | Reporta ao Sentry (se instalado) |

## 🔗 Integração com Sentry

Se o SDK do Sentry estiver presente, `reportToSentry()` envia o contexto (`table`, `count`, `suggestion`) e uma mensagem de aviso. Sem o SDK, é silenciosamente ignorado.

## ⚠️ Cuidados

- **Ferramenta de desenvolvimento/diagnóstico.** Ative em `local`/`staging`. Em produção, use `sampleRate` baixo (ex.: `0.05`) — cada padrão novo captura um `debug_backtrace` (custo).
- **Estado interno é estático**, mas é zerado automaticamente no início de cada job da fila (`JobProcessing`) e de cada request do Octane (`RequestReceived`). Chamar `enable()` de novo não registra outro listener.
- **Apenas detecta** — não altera queries nem código. Emite aviso + sugestão.
- O detector não sabe qual relação foi acessada: a sugestão usa `<relation>` como marcador.

### NPlusOneDetector ou `Model::preventLazyLoading()`?

| | `Model::preventLazyLoading()` | `NPlusOneDetector` |
|---|---|---|
| Como detecta | Exceção no primeiro lazy load de relação | Padrão de SQL repetido acima do limite |
| Queries fora do Eloquent (`DB::`, query builder, loops manuais) | Não | Sim |
| Diz qual relação | Sim | Não (só a tabela) |
| Produção | Só como log (`handleLazyLoadingViolationUsing`) | Sim, com `sampleRate` |

Em desenvolvimento, `preventLazyLoading()` é o mais preciso para Eloquent; o detector complementa pegando N+1 de query builder e permite amostragem em produção.

---

# EmailValidator

```php
$validator = email_validator();

$validator->isValidFormat('ana@example.com');   // formato (filter_var)
$validator->hasValidMxRecords('ana@example.com'); // MX (ou registro A)
$validator->isValid('ana@example.com');          // formato + MX
$validator->isDisposable('x@mailinator.com');    // domínio descartável (inclui subdomínios)
$validator->isRoleBased('contato@empresa.com');  // e-mail genérico
$validator->getInfo('ana@example.com');          // tudo acima num array
```

`isRoleBased()` exige o termo inteiro ou seguido de `.`, `_`, `+`, `-` ou dígito: `rh@`, `rh.sp@` e `suporte2@` são genéricos; `rhaissa@` e `informatica@` não.

### Regras de validação

```php
use RiseTechApps\RiseTools\Features\EmailValidator\Rules\NotDisposableEmail;
use RiseTechApps\RiseTools\Features\EmailValidator\Rules\NotRoleBasedEmail;

'email' => ['required', 'email', new NotDisposableEmail(), new NotRoleBasedEmail()],
'email' => [new NotDisposableEmail('Use um e-mail permanente.')], // mensagem própria
```

### Lista de descartáveis

A lista embutida é pequena (21 domínios). Para a lista completa (milhares de domínios, do projeto [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains)):

```bash
php artisan risetools:email-disposable-update
```

Grava em `storage/app/risetools/disposable_domains.txt` (`RISETOOLS_DISPOSABLE_LIST_PATH`), que é recarregado sozinho quando muda. Download com menos de 100 domínios (página de erro, lista truncada) é recusado e a lista atual fica. Agende para manter atualizada:

```php
Schedule::command('risetools:email-disposable-update')->weekly();
```

Domínios extras: `risetools.email.disposable_domains` (publique a config). Subdomínios contam: `x@eu.mailinator.com` é descartável.

### Cache de DNS

Os lookups de MX/A são cacheados por domínio por `RISETOOLS_EMAIL_DNS_CACHE` minutos (60; `0` desliga), num store sem prefixo dinâmico (`RISETOOLS_EMAIL_CACHE_STORE`). Um cadastro em massa do mesmo domínio faz uma consulta só.

### Verificação SMTP

```php
$validator->verifySmtp('ana@example.com', timeout: 10, from: 'verify@seudominio.com');
// ['email' => ..., 'verified' => bool, 'smtp_code' => 250|550|..., 'message' => ..., 'server' => banner]
```

Conversa `HELO` → `MAIL FROM` → `RCPT TO` com o MX de maior prioridade, lendo respostas multi-linha inteiras e exigindo `250` em cada etapa.

> ⚠️ Muitos servidores bloqueiam ou mentem nessa verificação (anti-enumeração) e muitas nuvens bloqueiam a porta 25 de saída. Trate o resultado como indício, não como prova.

---

# DatabaseSnapshot

Snapshot e restore do banco da conexão padrão. Suporta **PostgreSQL** (`pg_dump`/`psql`), **MySQL** (`mysqldump`/`mysql`) e **SQLite** (backup API).

```bash
php artisan risetools:snapshot-create base          # --seed roda db:seed antes
php artisan risetools:snapshot-list
php artisan risetools:snapshot-restore base
php artisan risetools:snapshot-delete base
```

```php
database_snapshot()->create('base');
database_snapshot()->restore('base');
database_snapshot()->exists('base');
database_snapshot()->list();   // [['name', 'size', 'created_at', 'driver'], ...]
database_snapshot()->delete('base');
database_snapshot()->purge();  // remove todos
```

Em testes, a trait `Features\DatabaseSnapshot\Traits\InteractsWithSnapshots` oferece `createSnapshot()`, `restoreSnapshot()` e `withSnapshot()`.

### Como funciona

- **Qualquer disco do Laravel** (`RISETOOLS_SNAPSHOT_DISK`, inclusive S3): o dump vai para um arquivo temporário local, direto da ferramenta (`--result-file`/`--file`, sem passar pela memória do PHP), e é enviado por stream. O restore baixa por stream para um temporário. Temporários são apagados no fim.
- **PostgreSQL**: o dump é gerado com `--clean --if-exists` e o restore roda em **uma transação** com `ON_ERROR_STOP`: qualquer erro faz rollback e o banco atual fica intacto. Snapshots antigos (sem `--clean`) continuam funcionando: os dados do schema atual são truncados antes da carga.
- **MySQL**: a senha vai pela variável `MYSQL_PWD`, não pela linha de comando (não aparece no `ps`).
- **Ferramenta ausente**: se o cliente do banco não estiver no `PATH`, o comando avisa qual instalar, antes de tocar no banco. Erros da ferramenta (senha errada etc.) aparecem com a mensagem original.

```bash
# Debian/Ubuntu
sudo apt-get install postgresql-client        # ou default-mysql-client
# Alpine
apk add postgresql-client                      # ou mysql-client
```

> Use a mesma versão major do servidor (ou maior) para `pg_dump`.

---

# DatabaseHealthMonitor

```bash
php artisan risetools:db-health                      # todas as tabelas
php artisan risetools:db-health --table=users        # só uma tabela
php artisan risetools:db-health --severity=critical  # critical | warning | info
php artisan risetools:db-health --json               # relatório JSON (só o JSON na saída)
php artisan risetools:db-health --export=report.json
```

Sai com código `1` quando há problema `critical` ou `warning` (útil em CI). Filtros valem também para o resumo, `--json` e `--export`.

| Check | Severidade | Drivers |
|---|---|---|
| Tabela sem chave primária | critical | todos |
| Auto increment perto do limite | critical | MySQL, PostgreSQL |
| Índices duplicados (mesmas colunas, **mesma ordem**; ignora parciais e de expressão) | warning | MySQL, PostgreSQL |
| Coluna `*_id` sem índice | warning | MySQL, PostgreSQL |
| JSON em tabela com 1M+ linhas, ENUM grande, índice em VARCHAR longo | warning | MySQL (VARCHAR longo também PostgreSQL) |
| Sem `created_at`/`updated_at` | info | todos |
| Índices possivelmente sem uso (nunca inclui UNIQUE/PK) | info | MySQL, PostgreSQL |

No PostgreSQL tudo é filtrado pelo `current_schema()`, ou seja, segue o `search_path` da conexão. O driver vem da config da conexão (`database.connections.{nome}.driver`), então conexões com qualquer nome funcionam.

Checks próprios:

```php
db_health_monitor()->addCheck(function (string $table) {
    return $table === 'legacy' ? ['table' => $table, 'type' => 'legacy', 'severity' => 'warning', 'message' => '...'] : null;
});
```

---

# Doctor

```bash
php artisan risetools:doctor
php artisan risetools:doctor --only=database,cache,queue
php artisan risetools:doctor --disk=s3 --mailer=smtp --min-disk-gb=5 --max-failed-jobs=10
php artisan risetools:doctor --json                  # só o JSON: {"healthy": bool, "checks": [...]}
```

Checks: `security` (APP_KEY, APP_DEBUG em produção), `config` (cache em produção), `database`, `migrations` (pendentes), `redis`, `cache` (escrita e leitura), `storage` (escrita e leitura no disco), `disk` (espaço livre), `queue` (alcança o broker), `failed-jobs` (contagem via `count()`), `smtp` (handshake), `scheduler` (heartbeat), `horizon` (supervisores rodando).

Sem `--only`:
- `redis` só roda se cache, fila, sessão ou broadcast usarem Redis;
- `scheduler` e `horizon` só rodam em produção (e `horizon` só com o Horizon instalado).

Sai com código `1` se algum check falhar.

### Scheduler

O pacote agenda um heartbeat a cada minuto (`risetools:scheduler-heartbeat`, desligue com `RISETOOLS_SCHEDULER_HEARTBEAT=false`). O check `scheduler` falha se o último tem mais de `RISETOOLS_SCHEDULER_MAX_AGE` minutos (5) ou `--scheduler-max-age`, ou seja, se o `schedule:run` parou. O store precisa ser compartilhado entre o scheduler e quem roda o doctor (`RISETOOLS_HEALTH_CACHE_STORE`; não `array`).

### Checks próprios

```php
// Num ServiceProvider:
RiseTools::doctor()->addCheck('payments', function (array $options) {
    $ok = PaymentGateway::ping();
    if (!$ok) {
        throw new RuntimeException('Gateway unreachable.');
    }

    return 'gateway up';
});
```

Ou por config (`risetools.health.checks`): `'payments' => App\Health\PaymentsCheck::class` (classe invocável, recebe `array $options`). Checks próprios rodam por padrão e aceitam `--only=payments`.

### Rotas de health (orquestrador / load balancer)

Desligadas por padrão. Com `RISETOOLS_HEALTH_ROUTE=true`:

| Rota | O que faz |
|---|---|
| `GET /health/live` | 200 sempre que o processo responde. Não toca em banco nem expõe nada. |
| `GET /health/ready` | Roda `risetools.health.route.checks` (padrão `database`, `cache`): 200 ou 503. |

```dotenv
RISETOOLS_HEALTH_ROUTE=true
RISETOOLS_HEALTH_PATH=health
RISETOOLS_HEALTH_TOKEN=um-segredo-longo         # header X-Health-Token ou Authorization: Bearer
RISETOOLS_HEALTH_ALLOWED_IPS=10.0.0.0/8,127.0.0.1
```

Sem token nem IPs configurados, o `/ready` responde só ok/falhou por check. Com eles, quem não passa recebe 403 e quem passa vê o detalhe de cada check. Mantenha os checks do `/ready` rápidos: ele roda a cada probe.

---

## ⚙️ Configuração

Todas por `.env`:

| Variável | Padrão | Uso |
|---|---|---|
| `RISETOOLS_GEOIP_ENABLED` | `false` | Liga a geolocalização no `Device::info()` |
| `RISETOOLS_GEOIP_URL` | `http://ip-api.com/json/{ip}` | Provedor de geo |
| `RISETOOLS_GEOIP_CONNECT_TIMEOUT` / `RISETOOLS_GEOIP_TIMEOUT` | `2` / `4` | Timeouts (s) |
| `RISETOOLS_GEOIP_FAILURE_TTL` | `10` | Cache negativo de falha (min) |
| `RISETOOLS_GEOIP_CACHE_STORE` | store padrão | Store do cache de geo |
| `RISETOOLS_TRUST_CLOUDFLARE_HEADER` | `false` | Lê `CF-Connecting-IP` |
| `RISETOOLS_SNAPSHOT_DISK` | `local` | Disco dos snapshots |
| `RISETOOLS_SNAPSHOT_PATH` | `snapshots` | Pasta dentro do disco |
| `RISETOOLS_SCHEDULER_HEARTBEAT` | `true` | Agenda o heartbeat do scheduler |
| `RISETOOLS_SCHEDULER_MAX_AGE` | `5` | Idade máxima do heartbeat (min) |
| `RISETOOLS_HEALTH_CACHE_STORE` | store padrão | Store do heartbeat |
| `RISETOOLS_HEALTH_ROUTE` | `false` | Liga `/health/live` e `/health/ready` |
| `RISETOOLS_HEALTH_PATH` | `health` | Prefixo das rotas de health |
| `RISETOOLS_HEALTH_TOKEN` | — | Token do `/health/ready` |
| `RISETOOLS_HEALTH_ALLOWED_IPS` | — | IPs/CIDRs liberados no `/health/ready` (vírgula) |
| `RISETOOLS_EMAIL_DNS_CACHE` | `60` | Cache de MX/A por domínio (min; `0` desliga) |
| `RISETOOLS_EMAIL_CACHE_STORE` | store padrão | Store do cache de DNS |
| `RISETOOLS_DISPOSABLE_LIST_PATH` | `storage/app/risetools/disposable_domains.txt` | Lista de descartáveis baixada |
| `RISETOOLS_DISPOSABLE_LIST_URL` | disposable-email-domains (GitHub) | Origem da lista |

Arrays (checks próprios, checks do `/ready`, middleware das rotas, domínios descartáveis extras) ficam na config publicada (`php artisan vendor:publish --tag=risetools-config`).

---

## 🛠️ Requisitos

| Dependência | Versão / observação |
|--------------|----------------|
| PHP | 8.4 (`ext-openssl`) |
| Laravel | 12.x |
| `ext-gd` (com FreeType) | AvatarGenerator |
| `ext-sqlite3` | DatabaseSnapshot em SQLite |
| `ext-intl` | Recomendado para domínios IDN |
| `pg_dump`/`psql` ou `mysqldump`/`mysql` | DatabaseSnapshot em PostgreSQL / MySQL |

---

## 🧪 Testes

```bash
composer test                     # suíte padrão (SQLite)
vendor/bin/phpunit --group network # testes que acessam a internet (badssl.com)
RISETOOLS_TEST_PGSQL=1 vendor/bin/phpunit  # inclui PostgreSQL (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
```

Testes de snapshot em SQLite precisam de `ext-sqlite3`; os de PostgreSQL, de `pg_dump`/`psql` no `PATH`. Sem eles, são pulados. O CI (GitHub Actions) sobe um PostgreSQL 16 e roda tudo.

---

## 🧑‍💻 Autor

**Rise Tech**  
📧 apps@risetech.com.br  
🌐 https://risetech.com.br  
💼 https://github.com/risetechapps

---

## 🪪 Licença

MIT — veja arquivo LICENSE.
