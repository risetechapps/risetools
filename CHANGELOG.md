# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [3.0.0] - 2026-07-17
- Corrigido parametros e funções obsoletas em php8.4
- Atualizado Packages
- Atualizado para php 8.4

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
