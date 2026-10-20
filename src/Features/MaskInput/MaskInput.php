<?php

namespace RiseTechApps\RiseTools\Features\MaskInput;
use Illuminate\Support\Str;
class MaskInput
{
    public const CPF = '###.###.###-##';
    public const CNPJ = '##.###.###/####-##';
    public const CEP = '#####-###';
    public const PHONE = '(##) ####-####';
    public const MOBILE = '(##) #####-####';

    public function MaskInput(string $value, string $mask): string
    {
        $masked = '';
        $k = 0;

        for ($i = 0; $i < Str::length($mask); $i++) {
            if (isset($value[$k])) {
                $masked .= $mask[$i] === '#' ? $value[$k++] : $mask[$i];
            }
        }

        return $masked;
    }

    /**
     * Remove a formatação: fica só com dígitos e letras (maiúsculas).
     *
     * Letras ficam porque o CNPJ alfanumérico (Receita Federal, a partir de
     * julho/2026) usa A-Z nas 12 primeiras posições.
     */
    public function unmask(string $value): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? '');
    }

    /**
     * Aceita o valor com ou sem formatação. Só formata: não valida dígitos.
     */
    public function cpf(string $value): string
    {
        return $this->MaskInput($this->unmask($value), self::CPF);
    }

    /**
     * Numérico ou alfanumérico (o '#' aceita letra).
     */
    public function cnpj(string $value): string
    {
        return $this->MaskInput($this->unmask($value), self::CNPJ);
    }

    /**
     * CPF (até 11 caracteres) ou CNPJ (mais que isso), pelo tamanho.
     */
    public function document(string $value): string
    {
        $raw = $this->unmask($value);

        return strlen($raw) > 11 ? $this->cnpj($raw) : $this->cpf($raw);
    }

    public function cep(string $value): string
    {
        return $this->MaskInput($this->unmask($value), self::CEP);
    }

    /**
     * Telefone com DDD: 10 dígitos = fixo, 11 = celular. Com o código do país
     * (55 + 10/11 dígitos) devolve "+55 (11) 91234-5678".
     */
    public function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            return '+55 ' . $this->phone(substr($digits, 2));
        }

        return $this->MaskInput($digits, strlen($digits) > 10 ? self::MOBILE : self::PHONE);
    }
}
