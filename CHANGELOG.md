# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [1.1.0] - 2026-10-25

Compatível com a 1.0: só adiciona recurso.

### AtomicJobChain: retomar de onde parou
- Novo `->skipWhen(fn (string $jobClass, array $passable): bool)`, par do `onStepComplete`: consultado antes de cada Job; `true` (estrito) pula o Job. A fila (ex.: Horizon com `tries` > 1) reexecuta a cadeia a partir do payload original, e sem isso a nova tentativa recomeçava do primeiro Job, refazendo o que já tinha dado certo.
- O callback deve ler um registro **persistente** (banco), nunca o `passable`: ele é a foto do momento do dispatch.
- Job em Closure sempre roda (sem identidade estável). Sem `skipWhen`, nada muda.
- Testes: retomada após falha com payload serializado (mesmo caminho do `toListener`), argumentos do callback, só `true` pula e Closure sempre roda.

## [1.0.0] - 2026-10-20

> **Primeira versão estável.** As versões publicadas antes (1.0.0 a 3.1.0, de nov/2025 a out/2026) eram de teste e foram retiradas; o histórico delas não vale para esta linha. Quem usava uma versão de teste: veja [Migrando das versões de teste](#migrando-das-versões-de-teste--310) abaixo e no README. Pacotes que pediam `^3.1.0` passam a pedir `^1.0`.

Requer PHP 8.4 e Laravel 12.

### Recursos

**Respostas da API**
- Macros `jsonSuccess` (200), `jsonCreated` (201), `jsonNoContent` (204, sem corpo), `jsonUnauthorized` (401), `jsonForbidden` (403), `jsonNotFound` (404), `jsonGone` (410), `jsonError` (422), `jsonValidation` (422, com `errors` no formato do Laravel) e `jsonInternal` (500), no envelope `success`/`code`/`message`/`data`.
- `JsonResource` passa pelo `->response()` do Laravel: coleções paginadas trazem `meta` e `links`, e `with()`/`additional()` são mantidos, sem sobrescrever o envelope. `[]` vira `"data": []`; `null` omite `data`.
- `JsonExceptionRenderer::register($exceptions)` (opcional): exceções em requests JSON/`api/*` saem no mesmo envelope; `ModelNotFoundException` não expõe a classe; headers de `HttpException` mantidos; detalhes do 500 só com `APP_DEBUG`.

**Facade e config**
- Facade `RiseTools`: `avatar()`, `domain()`, `email()`, `device()`, `clientIp()`, `mask()`, `snapshot()`, `dbHealth()`, `nPlusOne()`, `doctor()`.
- Config publicável: `php artisan vendor:publish --tag=risetools-config`; tudo configurável também por `.env`.
- Helpers: `avatarGenerator()`, `MaskInput()`, `domainTools()`, `email_validator()`, `n_plus_one_detector()`, `database_snapshot()`, `db_health_monitor()`.

**AvatarGenerator**
- Avatar circular com gradiente e iniciais, cores consistentes por nome; PNG (GD), base64, arquivo ou Storage.
- SVG (`generateSvg()`, `generateSvgDataUri()`) sem GD.
- Cor do texto (branco ou cinza-escuro) pelo contraste WCAG com o gradiente; cantos transparentes com borda suavizada.

**MaskInput**
- Máscara livre (`###.###.###-##`) e presets `cpf()`, `cnpj()` (inclusive CNPJ alfanumérico), `document()`, `cep()`, `phone()` e `unmask()`, com constantes das máscaras.

**Device**
- Dispositivo, navegador e plataforma pelo `User-Agent` do request (seguro no Octane).
- IP do cliente por `request()->ip()` (respeita o TrustProxies); `CF-Connecting-IP` só com opt-in.
- Geolocalização opcional (ip-api), com cache por IP, cache negativo de falha, timeout curto e sem consulta para IP privado.

**Domain**
- Domínio registrável e subdomínio (Public Suffix List com cache, memo por processo e cópia embutida para uso offline), IP, DNS, SSL (`status`, `is_trusted`, `is_expired`, emissor, validade), WHOIS e `getInfo(?int $cacheSeconds)`.
- Consultas de rede memorizadas por instância; `fullUrl` só em https com certificado confiável e válido.

**EmailValidator**
- Formato, MX (ou A), descartável (inclui subdomínios), genérico/role-based e verificação SMTP com respostas multi-linha.
- Regras `Rules\NotDisposableEmail` e `Rules\NotRoleBasedEmail`.
- `risetools:email-disposable-update`: baixa a lista completa de descartáveis (recusa download com menos de 100 domínios); recarregada sozinha quando o arquivo muda; extras por config.
- Cache dos lookups de MX/A por domínio.

**AtomicJobChain**
- Cadeia de jobs que para na primeira falha, com `then`/`catch`/`finally`, `onStepComplete`, despacho após o commit da transação, nome e tags para o Horizon.

**NPlusOneDetector**
- Detecção de N+1 em tempo de execução por padrão de SQL, com amostragem, sugestão de correção, log e Sentry.
- Um listener por dispatcher e estado zerado a cada job da fila e request do Octane.

**DatabaseSnapshot**
- `risetools:snapshot-create|restore|list|delete` e trait `InteractsWithSnapshots` para testes; PostgreSQL, MySQL e SQLite.
- Qualquer disco do Laravel (S3 etc.) por stream, sem o dump passar pela memória do PHP.
- PostgreSQL: dump com `--clean --if-exists` e restore em transação única com `ON_ERROR_STOP` (erro faz rollback).
- MySQL: senha por `MYSQL_PWD`, fora da linha de comando.
- Cliente do banco ausente detectado antes de tocar no banco (`Exceptions\ToolNotFoundException`), com o pacote certo para instalar.

**DatabaseHealthMonitor**
- `risetools:db-health` com `--table`, `--severity`, `--json` e `--export`; código de saída `1` com problema (útil em CI).
- Checks: sem chave primária, auto increment perto do limite, índices duplicados (mesma ordem, ignora parciais/expressão), `*_id` sem índice, índices possivelmente sem uso (nunca UNIQUE/PK), JSON em tabela grande, ENUM grande, VARCHAR longo indexado, sem timestamps; checks próprios com `addCheck()`.
- Driver pela config da conexão; no PostgreSQL, filtro por `current_schema()`.

**Doctor**
- `risetools:doctor` (com `--json`): security, config, database, migrations, redis, cache, storage, disk, queue, failed-jobs, smtp, scheduler (heartbeat agendado pelo pacote) e horizon.
- Checks padrão conforme o app (redis só se usado; scheduler e horizon em produção); checks próprios por `addCheck()` ou config.
- Rotas opcionais `GET /health/live` e `GET /health/ready` (200/503), com token ou lista de IPs/CIDRs para ver o detalhe dos checks.

**Qualidade**
- 118 testes (PHPUnit + Testbench) mais 4 de rede; testes de PostgreSQL no CI.
- CI no GitHub Actions: PHP 8.4, Laravel 12, PostgreSQL 16.

### Migrando das versões de teste (≤ 3.1.0)

Mudanças de comportamento para quem usava uma versão de teste:

- **IP do cliente**: `Device::getClientPublicIp()` não confia mais em `CF-Connecting-IP`/`X-Forwarded-For`/`X-Real-IP` vindos de qualquer cliente (eram forjáveis). Configure o TrustProxies; para Cloudflare, `RISETOOLS_TRUST_CLOUDFLARE_HEADER=true` ou os IPs dela no `trustProxies`.
- **`?agent=` removido**: o user agent vem só do header do request.
- **Geolocalização desligada por padrão** (LGPD): `RISETOOLS_GEOIP_ENABLED=true` para manter `geo_ip` preenchido.
- **Respostas JSON**: `jsonSuccess([])` traz `"data": []`; Resources paginadas trazem `meta`/`links`; `jsonGone()` tem a mensagem padrão `Resource no longer available.`.
- **Mensagens em inglês** nos comandos de snapshot e exceções do `DatabaseSnapshot`.
- **Classes renomeadas**: `Features\HealthCheck\HealthCheckCommand` → `DoctorCommand`; `Features\DatabaseHealthMonitor\Console\HealthCheckCommand` → `DatabaseHealthCommand` (nomes no artisan iguais).
- **db-health**: `--json`/`--export` saem com `1` quando há problema; check `nullable_without_default` removido; no relatório, `database.connection` é o nome da conexão e o banco vai em `database.database`.
- **Snapshots do PostgreSQL** gerados com `--clean --if-exists`; snapshots antigos continuam restaurando.
- **AvatarGenerator**: em gradientes claros o texto passa a ser escuro.
- **Scheduler**: o pacote agenda um heartbeat por minuto (`RISETOOLS_SCHEDULER_HEARTBEAT=false` desliga).

### Corrigido em relação às versões de teste

- Restore de snapshot quebrado em MySQL e PostgreSQL; `snapshot-list` quebrava no Alpine e mostrava nomes que `restore`/`delete` não achavam; "Tool not found" aparecia para qualquer erro do `pg_dump`.
- db-health não rodava checks em conexões com nome diferente do driver, misturava schemas no PostgreSQL, ignorava filtros no `--json`/`--export` e sugeria remover índices UNIQUE.
- doctor falhava em app sem Redis e carregava todos os failed jobs na memória.
- NPlusOneDetector dobrava contagens a cada `enable()` e vazava estado entre requests/jobs.
- EmailValidator marcava "rhaissa@" como genérico e desalinhava a conversa SMTP em respostas multi-linha.
- Domain nunca reportava certificado expirado (`is_expired`).
- AvatarGenerator gerava cantos pretos.
- A facade apontava para um binding inexistente.
- `composer.json` sem as dependências `illuminate/*` e `laravel/serializable-closure` que o código usa.
