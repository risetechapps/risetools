<?php

namespace RiseTechApps\RiseTools\Features\MaskInput;
use Illuminate\Support\Str;
class MaskInput
{
    public function MaskInput(string $value, string $mask): string
    {
        if (is_null($value)) return $value;

        $masked = '';
        $k = 0;

        for ($i = 0; $i < Str::length($mask); $i++) {
            if (isset($value[$k])) {
                $masked .= $mask[$i] === '#' ? $value[$k++] : $mask[$i];
            }
        }

        return $masked;
    }
}
