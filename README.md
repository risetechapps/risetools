# 🌅 Rise Tech ViewSuite

Pacote de **views personalizadas** da [Rise Tech](https://risetech.com.br) para aplicações Laravel.  
Inclui templates para **páginas de erro**, **layouts base** e **templates de e-mail**, totalmente prontos para uso e personalização.

> Compatível com **Laravel 12+** e **PHP 8.3+**

[![Packagist Version](https://img.shields.io/packagist/v/risetechapps/view-suite.svg?color=00bfa5)](https://packagist.org/packages/risetechapps/view-suite)
[![License](https://img.shields.io/github/license/risetechapps/view-suite.svg?color=00bfa5)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.3-blue.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)

---

## 🚀 Instalação

### Via Composer

```bash
  composer require risetechapps/view-suite
```

---

## ⚙️ Configuração

O pacote é automaticamente registrado pelo Laravel através do *Service Provider*:

```php
RiseTechApps\ViewSuite\ViewSuiteServiceProvider::class
```

Se quiser publicar as views para customizar no seu projeto, rode:

```bash
  php artisan vendor:publish --provider="RiseTechApps\ViewSuite\ViewSuiteServiceProvider" --tag=views
```

As views serão copiadas para:

```
resources/views/vendor/view-suite/
```

---

## 🧱 Estrutura das Views

```
resources/
└── views/
    └── vendor/
        └── view-suite/
            ├── layouts/
            │   └── base.blade.php
            ├── errors/
            │   ├── 404.blade.php
            │   └── 500.blade.php
            └── emails/
                ├── welcome.blade.php
                └── reset.blade.php
```

---

## 🖼️ Uso

### Exibir uma view de erro

```php
return response()->view('view-suite::errors.404', [], 404);
```

### Enviar um e-mail com o template do pacote

```php
Mail::send('view-suite::emails.welcome', ['user' => $user], function ($message) use ($user) {
    $message->to($user->email)->subject('Bem-vindo à Rise Tech!');
});
```

### Usar o layout base

```blade
@extends('view-suite::layouts.base')

@section('content')
  <p>Conteúdo da sua página customizada.</p>
@endsection
```

---

## 🧩 Personalização

As views publicadas podem ser totalmente alteradas conforme a identidade visual do seu projeto.  
Basta editar os arquivos em `resources/views/vendor/view-suite/`.

Exemplo de footer padrão:

```blade
<footer>
  <p>Powered by <strong>Rise Tech</strong> 🚀</p>
</footer>
```

---

## 🧪 Testes

Este package utiliza o [Orchestra Testbench](https://github.com/orchestral/testbench) para testes isolados.

Para rodar os testes:

```bash
  composer test
```

Ou gerar relatório de cobertura:

```bash
  composer test-coverage
```

---

## 🛠️ Requisitos

| Dependência | Versão mínima |
|--------------|----------------|
| PHP | 8.3 |
| Laravel | 12.x |
| Orchestra Testbench | 9.x |
| PHPUnit | 11.x |

---

## 🧑‍💻 Autor

**Rise Tech**  
📧 [apps@risetech.com.br](mailto:apps@risetech.com.br)  
🌐 [https://risetech.com.br](https://risetech.com.br)  
💼 [https://github.com/risetechapps](https://github.com/risetechapps)

---

## 🪪 Licença

Este projeto é licenciado sob a [MIT License](LICENSE).

---

> 💡 **Dica:** Use o ViewSuite como base para padronizar todas as views da sua organização, garantindo uma identidade visual consistente entre os produtos Rise Tech.
