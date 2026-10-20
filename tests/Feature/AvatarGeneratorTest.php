<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use RiseTechApps\RiseTools\Features\AvatarGenerator\AvatarGenerator;
use RiseTechApps\RiseTools\Tests\TestCase;

class AvatarGeneratorTest extends TestCase
{
    public function test_svg_needs_no_gd(): void
    {
        $svg = (new AvatarGenerator())->generateSvg('Ana Souza', 64);

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"', $svg);
        $this->assertStringContainsString('>AS</text>', $svg);
        $this->assertStringContainsString('<linearGradient id="rt-avatar-', $svg);
        $this->assertNotFalse(simplexml_load_string($svg), 'SVG must be valid XML');
    }

    public function test_svg_escapes_initials_and_is_consistent(): void
    {
        $generator = new AvatarGenerator();
        $svg = $generator->generateSvg('<b>');

        $this->assertStringNotContainsString('<B>', $svg);
        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertSame($generator->generateSvg('Maria'), $generator->generateSvg('Maria'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $generator->generateSvgDataUri('Maria'));
    }

    public function test_text_color_follows_contrast(): void
    {
        $generator = new AvatarGenerator();
        $textColor = new \ReflectionMethod($generator, 'textColor');

        $this->assertSame([17, 24, 39], $textColor->invoke($generator, [250, 240, 200], [255, 250, 220]), 'light background: dark text');
        $this->assertSame([255, 255, 255], $textColor->invoke($generator, [20, 30, 90], [40, 10, 60]), 'dark background: white text');
    }

    public function test_circle_has_transparent_corners_and_opaque_center(): void
    {
        $this->requiresExtension('gd');

        foreach ([200, 37] as $size) {
            $image = imagecreatefromstring((new AvatarGenerator())->generate('Joao Silva', $size));
            $alpha = fn(int $x, int $y) => imagecolorsforindex($image, imagecolorat($image, $x, $y))['alpha'];

            $this->assertSame($size, imagesx($image));
            $this->assertSame(127, $alpha(0, 0), "corner, size {$size}");
            $this->assertSame(127, $alpha($size - 1, $size - 1), "corner, size {$size}");
            $this->assertSame(0, $alpha(intdiv($size, 2), intdiv($size, 2)), "center, size {$size}");
        }
    }

    public function test_same_name_gives_same_image(): void
    {
        $this->requiresExtension('gd');

        $generator = new AvatarGenerator();

        $this->assertSame($generator->generate('Maria'), $generator->generate('Maria'));
        $this->assertNotSame($generator->generate('Maria'), $generator->generate('Pedro'));
    }

    public function test_base64_and_file_outputs(): void
    {
        $this->requiresExtension('gd');

        $generator = new AvatarGenerator();

        $this->assertStringStartsWith('data:image/png;base64,', $generator->generateBase64('Ana'));

        $path = $this->tempPath . '/avatar.png';
        $generator->saveToFile($path, 'Ana', 64);
        $this->assertSame('image/png', getimagesize($path)['mime']);
    }
}
