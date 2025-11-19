# 🌅 Rise Tech Tools

Pacote de **macros e helpers** da [Rise Tech](https://risetech.com.br) para aplicações Laravel.  

> Compatível com **Laravel 12+** e **PHP 8.3+**

[![Packagist Version](https://img.shields.io/packagist/v/risetechapps/view-suite.svg?color=00bfa5)](https://packagist.org/packages/risetechapps/risetools)
[![License](https://img.shields.io/github/license/risetechapps/risetools.svg?color=00bfa5)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.3-blue.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)

---

## 🚀 Instalação

### Via Composer

```bash
  composer require risetechapps/risetools
```

---

## ⚙️ Configuração

O pacote é automaticamente registrado pelo Laravel através do *Service Provider*:

```php
RiseTechApps\RiseTools\RiseToolsServiceProvider::class
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
