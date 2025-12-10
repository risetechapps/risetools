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

# 🎨 AvatarGenerator (Novo Recurso)

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

---

## 🧑‍💻 Autor

**Rise Tech**  
📧 apps@risetech.com.br  
🌐 https://risetech.com.br  
💼 https://github.com/risetechapps

---

## 🪪 Licença

MIT — veja arquivo LICENSE.
