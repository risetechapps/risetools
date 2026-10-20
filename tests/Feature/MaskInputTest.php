<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use RiseTechApps\RiseTools\Features\MaskInput\MaskInput;
use RiseTechApps\RiseTools\Tests\TestCase;

class MaskInputTest extends TestCase
{
    public function test_applies_mask(): void
    {
        $mask = new MaskInput();

        $this->assertSame('123.456.789-01', $mask->MaskInput('12345678901', '###.###.###-##'));
        $this->assertSame('12.345.678/0001-90', $mask->MaskInput('12345678000190', '##.###.###/####-##'));
        $this->assertSame('01310-100', $mask->MaskInput('01310100', '#####-###'));
    }

    public function test_short_value_stops_at_the_last_digit(): void
    {
        $this->assertSame('123.45', (new MaskInput())->MaskInput('12345', '###.###.###-##'));
    }

    public function test_empty_value(): void
    {
        $this->assertSame('', (new MaskInput())->MaskInput('', '###'));
    }

    public function test_unmask_keeps_digits_and_letters(): void
    {
        $mask = new MaskInput();

        $this->assertSame('12345678901', $mask->unmask('123.456.789-01'));
        $this->assertSame('12ABC34501DE35', $mask->unmask('12.abc.345/01de-35'));
    }

    public function test_presets_accept_formatted_or_raw_values(): void
    {
        $mask = new MaskInput();

        $this->assertSame('123.456.789-01', $mask->cpf('12345678901'));
        $this->assertSame('123.456.789-01', $mask->cpf('123.456.789-01'));
        $this->assertSame('12.345.678/0001-90', $mask->cnpj('12345678000190'));
        $this->assertSame('01310-100', $mask->cep('01310100'));
    }

    public function test_alphanumeric_cnpj(): void
    {
        $this->assertSame('12.ABC.345/01DE-35', (new MaskInput())->cnpj('12abc34501de35'));
    }

    public function test_document_picks_cpf_or_cnpj_by_length(): void
    {
        $mask = new MaskInput();

        $this->assertSame('123.456.789-01', $mask->document('12345678901'));
        $this->assertSame('12.345.678/0001-90', $mask->document('12345678000190'));
    }

    public function test_phone(): void
    {
        $mask = new MaskInput();

        $this->assertSame('(11) 3456-7890', $mask->phone('1134567890'));
        $this->assertSame('(11) 91234-5678', $mask->phone('11912345678'));
        $this->assertSame('(11) 91234-5678', $mask->phone('(11) 91234-5678'));
        $this->assertSame('+55 (11) 91234-5678', $mask->phone('+55 11 91234-5678'));
    }
}
