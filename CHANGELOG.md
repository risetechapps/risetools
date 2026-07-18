# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [3.0.1] - 2026-07-18

> Vários itens abaixo restauram recursos e correções que existiam na 2.0.0 e foram perdidos na reescrita da 3.0.0 (features de banco/e-mail, PSL, `is_null` do MaskInput, duplicação de `fullUrl`).

### Added
- **EmailValidator** — restaurado (removido acidentalmente na 3.0.0).
- **NPlusOneDetector** — restaurado.
- **DatabaseSnapshot** (classe, trait `InteractsWithSnapshots` e comandos `risetools:snapshot:create|restore|list|delete`) — restaurado.
- **DatabaseHealthMonitor** (classe + comando `risetools:db-health`) — restaurado.
- **Helpers**: `email_validator()`, `n_plus_one_detector()`, `database_snapshot()`, `db_health_monitor()`.
- **ServiceProvider**: registra os comandos de snapshot e db-health e os singletons de EmailValidator, NPlusOneDetector, DatabaseSnapshot e DatabaseHealthMonitor.
- **Config**: seção `risetools.snapshot` (disk/path) e `mergeConfigFrom` sob a chave `risetools`.

### Fixed
- **Device**: `getGeoIP()` agora usa timeout (connect 2s / total 4s) e cache por IP (24h), evitando travar a request e estourar o limite do `ip-api.com`. Só cacheia respostas `status: success`.
- **Device**: Removido cache estático `self::$cached`, que vazava dados de dispositivo/geo entre requests em ambientes persistentes (Octane/Swoole).
- **Domain**: O Public Suffix List deixou de ser baixado da URL a cada `new Domain()`. Agora usa cache do Laravel (7 dias), memo por processo e cópia local empacotada como fallback offline — sem download no caminho da request e sem quebrar sem internet.
- **AvatarGenerator**: `extractInitials()` retorna `"U"` para nome vazio (branch antes inalcançável) e usa funções multibyte (`mb_*`), corrigindo iniciais de nomes acentuados.
- **Domain**: `getInfo()` — `fullUrl` agora usa `https` apenas quando há SSL válido (deixou de ser cópia de `url`); `getSslInfo()` é chamado uma única vez e reutilizado.
- **MaskInput**: Removida verificação `is_null` redundante (parâmetro já tipado como `string`).

### Changed
- **Domain**: Adicionado arquivo `src/Features/Domain/public_suffix_list.dat` empacotado como fallback do PSL. O cache de 7 dias requer um driver de cache persistente (não `array`).
- **Dependencies**: Declaradas dependências antes ausentes — `symfony/process` (DatabaseSnapshot), `guzzlehttp/guzzle` e `hisorange/browser-detect` (Device).

### Docs
- README: requisito de PHP atualizado para 8.4+, Orchestra Testbench 10.x e spatie/dns 2.8.1.
- README: corrigido exemplo do helper de máscara (`MaskInput()`, não `mask_input()`).
- README: nova seção **NPlusOneDetector** com exemplos de uso, estatísticas, integração com Sentry e a ressalva de que a escuta exige `NPlusOneDetector::enable()`.

## [3.0.0] - 2026-07-17

### Changed
- Corrigidos parâmetros e funções obsoletas em PHP 8.4
- Atualizados os pacotes de dependências
- Requisito mínimo elevado para PHP 8.4

### Removed
- **EmailValidator** — removido
- **NPlusOneDetector** — removido
- **DatabaseSnapshot** (e comandos `risetools:snapshot:*`, trait `InteractsWithSnapshots`) — removido
- **DatabaseHealthMonitor** (e comando `risetools:db:health`) — removido
- Helpers relacionados: `email_validator()`, `n_plus_one_detector()`, `database_snapshot()`, `db_health_monitor()`

> Estes recursos existiam na 2.0.0 e foram removidos nesta versão de forma não intencional durante a reescrita. Foram **restaurados na 3.0.1**.

## [2.0.0] - 2026-04-28

### Added
- **EmailValidator**: Validação de e-mails em múltiplos níveis (formato, MX, SMTP), detecção de e-mails temporários e role-based
- **NPlusOneDetector**: Detecção automática de queries N+1 em tempo real com sugestões de eager loading
- **DatabaseSnapshot**: Snapshots ultra-rápidos do banco para testes (suporta MySQL, PostgreSQL, SQLite)
- **DatabaseHealthMonitor**: Verificação completa de saúde do banco (PKs, índices duplicados, FKs sem índice, auto increment, etc)
- **Comandos Artisan**: Novos comandos com prefixo `risetools:`
    - `risetools:snapshot:create|restore|list|delete`
    - `risetools:db:health`
- **Helpers**: `email_validator()`, `n_plus_one_detector()`, `database_snapshot()`, `db_health_monitor()`
- **Traits**: `InteractsWithSnapshots` para uso em testes PHPUnit
- Suporte multi-driver (MySQL, PostgreSQL, SQLite) em DatabaseSnapshot e DatabaseHealthMonitor

### Changed
- **Prefixo em comandos**: Todos os comandos agora usam prefixo `risetools:` para evitar conflitos
- **Device**: Substituição de superglobals (`$_GET`, `$_SERVER`) por `request()` para permitir mocking em testes
- **Domain**: Cache estático do Public Suffix List para evitar downloads repetidos
- **AvatarGenerator**: Fallback de fontes do sistema quando `roboto.ttf` não existe

### Fixed
- **MaskInput**: Removida verificação `is_null` redundante (parâmetro já é tipado como `string`)
- **Domain**: Resource leak em `getSslInfo()` (adicionado `fclose`)
- **Domain**: Redundância de código refatorada para usar `getFullHost()`
- **Domain**: `isPublished()` agora verifica HTTP 200 ao invés de apenas DNS
- **Domain**: Removida chave duplicada `fullUrl` em `getInfo()`
- **Composer**: Corrigido caminho do autoload de helpers (sem barra inicial)
- **NPlusOneDetector**: Correção de conflito de nomes de métodos

### Removed
- Suporte a comandos sem prefixo (agora requerem `risetools:`)
- 
## [1.8.3] - 2026-04-02
- Corrigido validação de getCode no exception

## [1.8.2] - 2026-03-17
- Corrigido falhas de nullable

## [1.8.1] - 2026-03-17
- Refatorado classe AtomicJobChain para melhor gerenciamento de jobs e falhas
 
## [1.8.0] - 2026-03-13
- Refatorado classe AtomicJobChain para melhor gerenciamento de jobs e falhas

## [1.7.0] - 2026-02-03
### Added
- Corrigido incompatibilidade de variável

## [1.6.0] - 2026-01-24
### Added
- Class AtomicJobChain foi implementado suporte para callback de onSuccess, onFailure e onFinally
- Class AtomicJobChain foi implementado suporte de display name do laravel horizon

## [1.5.1] - 2025-12-26
### Added
- Corrigido verificação de domínio

## [1.5.0] - 2025-12-24
### Added
- Criado função para getUrl e getUrl no domínio

## [1.4.0] - 2025-12-22
### Added
- Criado Classe Domain para gerenciar infomações sobre domínios

## [1.3.0] - 2025-12-15
### Added
- Criado Classe AtomicJobChain para gerenciar jobs e listeners


## [1.2.0] - 2025-12-10
### Added
- Criado helper MaskInput para mascarar inputs.

## [1.1.0] - 2025-11-30
### Added
- Implementado função para gerar imagens avatar para o perfil.

## [1.0.0] - 2025-11-11
### Added
- Lançamento inicial (Primeira versão estável).
