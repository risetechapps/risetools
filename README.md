# 🌅 RiseTools

Pacote de **macros, helpers, features avançadas e ferramentas de desenvolvimento** da [Rise Tech](https://risetech.com.br) para aplicações Laravel.

Inclui ferramentas para:
- ✅ **Respostas JSON padronizadas**
- 🎨 **Geração de avatares**
- 🔍 **Detecção de dispositivos e geolocalização**
- 🌐 **Análise de domínios**
- 🎭 **Máscaras de input**
- ⚡ **Detecção de N+1 queries**
- 📸 **Snapshots de banco para testes**
- 🏥 **Monitor de saúde do banco**
- ✉️ **Validação de e-mails**
- ⛓️ **Cadeias atômicas de jobs**

> Compatível com **Laravel 12+** e **PHP 8.3+**

[![Packagist Version](https://img.shields.io/packagist/v/risetechapps/risetools.svg?color=00bfa5)](https://packagist.org/packages/risetechapps/risetools)
[![License](https://img.shields.io/github/license/risetechapps/risetools.svg?color=00bfa5)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.3-blue.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)

---

## 📦 Instalação

```bash
composer require risetechapps/risetools
```

O pacote usa **auto-discovery** do Laravel. O service provider será registrado automaticamente.

---

## 📚 Sumário

- [Macros de Resposta JSON](#macros-de-resposta-json)
- [AvatarGenerator](#avatargenerator)
- [Device](#device)
- [Domain](#domain)
- [MaskInput](#maskinput)
- [AtomicJobChain](#atomicjobchain)
- [EmailValidator](#emailvalidator)
- [NPlusOneDetector](#nplusonedetector)
- [DatabaseSnapshot](#databasesnapshot)
- [DatabaseHealthMonitor](#databasehealthmonitor)
- [Comandos Artisan](#comandos-artisan)
- [Helpers](#helpers)

---

## Macros de Resposta JSON

Padronize as respostas da API com estrutura consistente.

### Estrutura Base

```json
{
    "success": true,
    "code": 200,
    "message": "Operation completed successfully.",
    "data": { ... }
}
```

### Métodos Disponíveis

| Macro | Status HTTP | Uso |
|-------|-------------|-----|
| `jsonSuccess()` | 200 | Operações bem-sucedidas |
| `jsonError()` | 422 | Erros de validação/entidade |
| `jsonGone()` | 410 | Recursos descontinuados |
| `jsonNotFound()` | 404 | Recursos não encontrados |
| `jsonInternal()` | 500 | Erros internos |

### Exemplos

```php
// Sucesso com dados
return response()->jsonSuccess(['id' => 1, 'name' => 'Produto']);

// Erro de validação
return response()->jsonError('Dados inválidos', ['errors' => $validator->errors()]);

// Recurso não encontrado
return response()->jsonNotFound('Usuário não existe');
```

---

## AvatarGenerator

Criação automática de avatares circulares com gradiente, iniciais e cores consistentes.

### Características

- ✔ Gradiente circular elegante
- ✔ Cores únicas baseadas no nome (hash MD5)
- ✔ Iniciais automáticas (ex: "Mateus Soares" → MS)
- ✔ Fundo transparente
- ✔ Retorno como PNG binário ou Base64
- ✔ Salvamento via Laravel Storage

### Exemplos

```php
use RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator;

$avatar = new AvatarGenerator();

// Como resposta HTTP
$png = $avatar->generate('Mateus Soares');
return response($png)->header('Content-Type', 'image/png');

// Base64 para APIs
$base64 = $avatar->generateBase64('Mateus Soares');

// Salvar em arquivo
$avatar->saveToFile('avatars/mateus.png', 'Mateus Soares');

// Via Storage
$avatar->saveToStorage('public', 'avatars/mateus.png', 'Mateus Soares');
```

**Via helper:**
```php
avatar_generator()->generate('João Silva');
```

---

## Device

Detecção de informações do dispositivo, navegador, plataforma e geolocalização por IP.

### Retorno

```php
[
    'device' => 'Desktop | Mobile | Tablet | Bot',
    'browser' => 'Chrome | Safari | Firefox | Edge',
    'browser_name' => 'Chrome 120.0',
    'platformName' => 'Windows | Android | iOS | MacOS',
    'geo_ip' => [
        'country' => 'Brazil',
        'countryCode' => 'BR',
        'city' => 'São Paulo',
        'lat' => -23.55,
        'lon' => -46.64,
        // ...
    ]
]
```

### Exemplo

```php
use RiseTechApps\RiseTools\Features\Device\Device;

$info = Device::info();
// ou
$info = app(Device::class)->info();

// Apenas IP público
$ip = Device::getClientPublicIp(); // Detecta CloudFlare, proxies, etc
```

---

## Domain

Análise completa de domínios: subdomínios, DNS, SSL, WHOIS.

### Funcionalidades

| Método | Descrição |
|--------|-----------|
| `getDomain()` | Domínio registrável (ex: example.com) |
| `getSubDomain()` | Subdomínio (ex: blog) |
| `getIp()` | Endereço IP |
| `getDnsRecords()` | Registros DNS (A, MX, TXT, etc) |
| `getSslInfo()` | Validade e emissor do SSL |
| `isResolvable()` | Resolve no DNS? |
| `isPublished()` | Responde HTTP? |
| `getWhoisExpiration()` | Data de expiração via WHOIS |

### Exemplos

```php
use RiseTechApps\RiseTools\Features\Domain\Domain;

$domain = new Domain('blog.example.com');
// ou
$domain = domainTools('blog.example.com');

$domain->getDomain();        // "example.com"
$domain->getSubDomain();     // "blog"
$domain->getSslInfo();       // ['status' => true, 'issuer' => 'Let\'s Encrypt', ...]
$domain->getInfo();          // Array com todas as informações
```

---

## MaskInput

Aplicação de máscaras em strings: CPF, CNPJ, telefone, CEP, etc.

### Uso

```php
use RiseTechApps\RiseTools\Features\MaskInput\MaskInput;

$mask = new MaskInput();
$mask->MaskInput('12345678901', '###.###.###-##');
// "123.456.789-01"

// Via helper
mask_input('12345678901', '###.###.###-##');
mask_input('11223344000199', '##.###.###/####-##');
mask_input('11987654321', '(##) #####-####');
```

**Padrão:** `#` representa caractere dinâmico.

---

## AtomicJobChain

Encadeamento atômico de jobs com callbacks (then/catch/finally).

### Diferenciais

- ✔ Execução sequencial atômica (falha em um = interrompe tudo)
- ✔ Callbacks: `then()`, `catch()`, `finally()`
- ✔ Integração com Horizon (display name descritivo)
- ✔ Tags para filtragem
- ✔ Suporte a `DB::afterCommit()`

### Exemplo

```php
use RiseTechApps\RiseTools\Features\AtomicJobChain\AtomicJobChain;

AtomicJobChain::make([
    SeedDatabaseJob::class,
    CreateTenantDefaultsJob::class,
    SendWelcomeEmailJob::class,
])
->send(function ($event) {
    return $event->tenancy;
})
->then(function () {
    Log::info('Cadeia concluída!');
})
->catch(function ($exception) {
    Log::error('Falha: ' . $exception->getMessage());
})
->finally(function () {
    Cache::forget('chain_running');
})
->toListener();
```

---

## EmailValidator

Validação de e-mails em múltiplos níveis.

### Níveis de Validação

1. **Formato** - Regex RFC 5322
2. **MX Records** - Domínio aceita e-mail?
3. **SMTP** - Tenta verificar caixa postal

### Funcionalidades

| Método | Descrição |
|--------|-----------|
| `isValidFormat()` | Valida sintaxe |
| `hasValidMxRecords()` | Verifica DNS MX |
| `isValid()` | Formato + MX |
| `verifySmtp()` | Verificação SMTP |
| `isDisposable()` | Detecta e-mails temporários |
| `isRoleBased()` | Detecta e-mails genéricos (contato@, suporte@) |
| `getInfo()` | Relatório completo |

### Exemplos

```php
$validator = email_validator();

// Validação básica
$validator->isValid('contato@empresa.com.br'); // true

// Verificação completa
$info = $validator->getInfo('contato@empresa.com.br');
// ['valid_format' => true, 'has_mx_records' => true, 'is_disposable' => false, ...]

// Detecta temporários
$validator->isDisposable('teste@tempmail.com'); // true
```

---

## NPlusOneDetector

Detecta queries N+1 em tempo real com sugestões de correção.

### Características

- 🔍 Detecta padrões N+1 automaticamente
- 📊 Analisa queries e sugere eager loading
- 📡 Reporta para Log e/ou Sentry
- 🎲 Suporta amostragem (para produção)

### Configuração

```php
// Em AppServiceProvider::boot()
n_plus_one_detector()
    ->enable()
    ->threshold(5)              // Alerta após 5 queries iguais
    ->sampleRate(1.0)          // 100% das requisições
    ->reportToLog()
    ->reportToSentry()
    ->suggestEagerLoading();
```

### Exemplo de Alerta

```
[N+1 Query] 5 queries detectadas para tabela 'comments'.
Sugestão: Adicione ->with(['comments']) ao carregar Post
```

---

## DatabaseSnapshot

Snapshots ultra-rápidos do banco para testes.

### Vantagens

- ⚡ 100x mais rápido que `migrate:fresh --seed`
- 💾 Suporta MySQL, PostgreSQL, SQLite
- 🔄 Restauração em milissegundos
- 🧪 Trait para PHPUnit

### Comandos Artisan

```bash
# Criar snapshot
php artisan risetools:snapshot:create baseline
php artisan risetools:snapshot:create baseline --seed

# Restaurar
php artisan risetools:snapshot:restore baseline

# Listar
php artisan risetools:snapshot:list

# Remover
php artisan risetools:snapshot:delete baseline
```

### Uso em Testes

```php
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Traits\InteractsWithSnapshots;

class OrderTest extends TestCase
{
    use InteractsWithSnapshots;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Restaura em ~0.5s ao invés de 30s+
        $this->restoreSnapshot('baseline');
    }
}
```

### Programático

```php
// Criar
database_snapshot()->create('after-migrations', function () {
    \App\Models\User::factory(100)->create();
});

// Restaurar
database_snapshot()->restore('after-migrations');

// Verificar
database_snapshot()->exists('baseline');
```

---

## DatabaseHealthMonitor

Verificação completa de saúde do banco de dados.

### Verificações Realizadas

| Tipo | Severidade | Descrição |
|------|------------|-----------|
| ❌ Tabelas sem PK | Crítico | Sem chave primária |
| ⚠️ Índices duplicados | Aviso | Índices redundantes |
| ⚠️ JSON em tabelas grandes | Aviso | Colunas JSON em tabelas 1M+ |
| ⚠️ FK sem índice | Aviso | Chaves estrangeiras não indexadas |
| ⚠️ ENUM grande | Aviso | Mais de 20 valores |
| ℹ️ Sem timestamps | Info | Falta created_at/updated_at |
| ℹ️ Nullable sem default | Info | Ambiguidade em NULLs |
| ❌ Auto increment limite | Crítico | Próximo do limite do tipo |

### Comandos Artisan

```bash
# Verificação completa
php artisan risetools:db:health

# Por tabela
php artisan risetools:db:health --table=orders

# Por severidade
php artisan risetools:db:health --severity=critical

# Exportar JSON
php artisan risetools:db:health --json

# Salvar relatório
php artisan risetools:db:health --export=storage/reports/db-health.json
```

### Programático

```php
$issues = db_health_monitor()->run();
$summary = db_health_monitor()->getSummary();

// Adicionar verificação customizada
db_health_monitor()->addCheck(function (string $table) {
    // sua lógica
    return [
        'table' => $table,
        'type' => 'custom_check',
        'severity' => 'warning',
        'message' => '...',
        'suggestion' => '...',
    ];
});
```

---

## Comandos Artisan

Todos os comandos usam o prefixo `risetools:` para evitar conflitos.

### Snapshot

| Comando | Descrição |
|---------|-----------|
| `risetools:snapshot:create {name} {--seed}` | Cria snapshot do banco |
| `risetools:snapshot:restore {name}` | Restaura snapshot |
| `risetools:snapshot:list` | Lista snapshots |
| `risetools:snapshot:delete {name}` | Remove snapshot |

### Database Health

| Comando | Descrição |
|---------|-----------|
| `risetools:db:health` | Verifica saúde do banco |
| `risetools:db:health --table={table}` | Verifica tabela específica |
| `risetools:db:health --json` | Exporta JSON |

---

## Helpers

Todos os helpers disponíveis:

```php
// Avatar
avatar_generator()->generate('Nome');

// Máscara
mask_input('12345678901', '###.###.###-##');

// Domínio
domainTools('blog.example.com');

// E-mail
email_validator()->isValid('email@exemplo.com');

// N+1 Detector
n_plus_one_detector()::getStats();

// Snapshot
database_snapshot()->list();

// Health Monitor
db_health_monitor()->run();
```

---

## 🛠️ Requisitos

### PHP e Laravel

| Dependência | Versão mínima |
|-------------|---------------|
| PHP | 8.3 |
| Laravel | 12.x |
| GD + FreeType | required (AvatarGenerator) |
| ext-openssl | required (Domain SSL) |
| ext-pdo | required (Database features) |

### DatabaseSnapshot - Ferramentas CLI

Para usar os comandos de snapshot, você precisa instalar os clientes do banco de dados:

**MySQL/MariaDB:**
```bash
# Debian/Ubuntu
sudo apt-get install mysql-client

# Alpine
apk add mysql-client

# CentOS/RHEL
sudo yum install mysql
```

**PostgreSQL:**
```bash
# Debian/Ubuntu
sudo apt-get install postgresql-client

# Alpine
apk add postgresql-client

# CentOS/RHEL
sudo yum install postgresql
```

**SQLite:** Não requer ferramentas adicionais (usa PHP nativo)

---

## 🧑‍💻 Autor

**Rise Tech**  
📧 apps@risetech.com.br  
🌐 https://risetech.com.br  
💼 https://github.com/risetechapps

---

## 🪪 Licença

MIT — veja arquivo LICENSE.
