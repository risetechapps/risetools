# 🌅 Rise Tech Tools

Pacote de **macros, helpers e utilitários avançados** da [Rise Tech](https://risetech.com.br) para aplicações Laravel.

Inclui agora:

✨ **AvatarGenerator** — criação automática de avatares circulares com gradiente, iniciais e cores consistentes.  
Ideal para APIs, dashboards, perfis de usuários e sistemas que precisam de avatares dinâmicos.

> Compatível com **Laravel 12+** e **PHP 8.3+**

[![Packagist Version](https://img.shields.io/packagist/v/risetechapps/risetools.svg?color=00bfa5)](https://packagist.org/packages/risetechapps/risetools)
[![License](https://img.shields.io/github/license/risetechapps/risetools.svg?color=00bfa5)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.3-blue.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)

---

## 🚀 Instalação

```bash
composer require risetechapps/risetools
```

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

#### 3. `response()->jsonGone($message = 'Recurso não disponível.', $data = null)`

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

### Macro Base (Interna)

A macro `jsonBase` é a implementação interna utilizada por todas as outras macros e não deve ser chamada diretamente em seu código de aplicação.

`response()->jsonBase(bool $success, string $message = null, array|JsonResource $data = null, int $code = Response::HTTP_OK)`

---

# 🎨 AvatarGenerator

O **AvatarGenerator** permite gerar imagens de avatar totalmente automáticas com:

- ✔ Gradiente circular elegante
- ✔ Cores únicas e consistentes baseadas no nome
- ✔ Iniciais automáticas (ex.: “Mateus Soares” → MS)
- ✔ Fundo circular com transparência
- ✔ Retorno como PNG binário
- ✔ Retorno Base64 (ideal para API)
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

- PHP GD / FreeType
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

echo mask_input('12345678901', '###.###.###-##');
// 123.456.789-01
```
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

Este recurso utiliza o pacote `hisorange/browser-detect` para identificar o ambiente do usuário e a API pública `ip-api.com` para dados de geolocalização.

---

## 🚀 Uso

### Obtendo informações do dispositivo

```php
use RiseTechApps\RiseTools\Features\Device\Device;

$info = Device::info();

dd($info);
```
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

A geolocalização é obtida através do serviço público:

- **ip-api.com**

⚠️ Observação:
- O serviço possui limites de requisição
- Não recomendado para uso crítico ou de alta escala sem cache

---

## 🧠 Detecção de IP do Cliente

O método tenta identificar corretamente o IP público considerando:

- Cloudflare (`HTTP_CF_CONNECTING_IP`)
- Proxy reverso (`X-Forwarded-For`)
- IP real (`REMOTE_ADDR`)
- Fallback para `request()->ip()`

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

## 📦 Instalação

Instale as dependências necessárias via Composer:

```bash
composer require spatie/dns jeremykendall/php-domain-parser iodev/whois
```

> O pacote utiliza a lista oficial do Public Suffix (`publicsuffix.org`).

---

## ⚙️ Requisitos

- PHP **8.3+**
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
    'status' => true,
    'issuer' => 'Let\'s Encrypt',
    'expires_at' => '2025-01-01 12:00:00',
    'is_expired' => false
]
```

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

## 🧾 isValidMail – Validar caixa de email

```php
$domain->isValidMail($mail);
// true | false
```

## 📊 Informações Completas do Domínio

```php
$domain->getInfo();
```

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
    'expires_at' => '2026-03-15'
]
```

---


## 🧪 Testes

Este package utiliza o Orchestra Testbench para testes isolados.

```bash
  composer test
```

Cobertura:

```bash
  composer test-coverage
```

---

## 🛠️ Requisitos

| Dependência | Versão mínima |
|--------------|----------------|
| PHP | 8.3 |
| Laravel | 12.x |
| GD + FreeType | required |
| Orchestra Testbench | 9.x |
| PHPUnit | 11.x |
| jeremykendall/php-domain-parser | 6.0 |
| spatie/dns | 2.7.1 |
| io-developer/php-whois | 4.1.10 |

---

## 🧑‍💻 Autor

**Rise Tech**  
📧 apps@risetech.com.br  
🌐 https://risetech.com.br  
💼 https://github.com/risetechapps

---

## 🪪 Licença

MIT — veja arquivo LICENSE.
