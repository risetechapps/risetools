<?php

namespace RiseTechApps\RiseTools\Features\AvatarGenerator;

use Exception;
use Illuminate\Support\Facades\Storage;

class AvatarGenerator
{
    private readonly string $fontFile;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->fontFile = __DIR__ . '/roboto.ttf';

        if (!file_exists($this->fontFile)) {
            throw new Exception("Font file not found: {$this->fontFile}");
        }
    }

    /**
     * Gera um avatar PNG com gradiente circular e iniciais.
     */
    public function generate(string $name, int $size = 200): string
    {
        $initials = $this->extractInitials($name);

        [$color1, $color2] = $this->generateConsistentGradientColors($name);

        $image = $this->createGradientCircle($size, $color1, $color2);
        $this->writeInitials($image, $initials, $size, $this->textColor($color1, $color2));

        ob_start();
        imagepng($image);
        $pngData = ob_get_clean();

        imagedestroy($image);

        return $pngData; // binary PNG
    }

    /**
     * Versão base64 para API.
     */
    public function generateBase64(string $name, int $size = 200): string
    {
        $png = $this->generate($name, $size);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * Avatar em SVG: mesmo nome, mesmas cores e iniciais do PNG. Não usa GD e
     * escala sem perder qualidade. O texto usa a fonte do navegador (Roboto,
     * se houver, senão sans-serif).
     */
    public function generateSvg(string $name, int $size = 200): string
    {
        $initials = htmlspecialchars($this->extractInitials($name), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        [$color1, $color2] = $this->generateConsistentGradientColors($name);
        $text = $this->hex($this->textColor($color1, $color2));

        // id único por nome: vários SVGs inline na mesma página não colidem.
        $id = 'rt-avatar-' . substr(md5($name), 0, 10);
        $half = $size / 2;
        $fontSize = round($size * 0.35, 2);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}" role="img" aria-label="{$initials}">
<defs><linearGradient id="{$id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{$this->hex($color1)}"/><stop offset="1" stop-color="{$this->hex($color2)}"/></linearGradient></defs>
<circle cx="{$half}" cy="{$half}" r="{$half}" fill="url(#{$id})"/>
<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Roboto, Arial, sans-serif" font-size="{$fontSize}" fill="{$text}">{$initials}</text>
</svg>
SVG;
    }

    public function generateSvgDataUri(string $name, int $size = 200): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->generateSvg($name, $size));
    }

    /**
     * Salva o avatar como arquivo local.
     */
    public function saveToFile(string $path, string $name, int $size = 200): void
    {
        $png = $this->generate($name, $size);
        file_put_contents($path, $png);
    }

    /**
     * Salva o avatar usando Laravel Storage.
     */
    public function saveToStorage(string $disk, string $path, string $name, int $size = 200): void
    {
        $png = $this->generate($name, $size);
        Storage::disk($disk)->put($path, $png);
    }

    //-----------------------------------------
    //   INTERNAL HELPERS
    //-----------------------------------------

    private function extractInitials(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return "U";
        }

        $parts = preg_split('/\s+/', $name);

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = mb_substr($parts[array_key_last($parts)], 0, 1);

        return mb_strtoupper($first . $last);
    }

    /**
     * Cria uma imagem PNG circular com gradiente.
     */
    private function createGradientCircle(int $size, array $color1, array $color2)
    {
        $img = imagecreatetruecolor($size, $size);

        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        // Desenha só dentro do círculo: o fundo continua transparente. A borda
        // recebe alpha proporcional à cobertura do pixel (anti-aliasing).
        $radius = $size / 2;
        $outer = $radius + 0.5;
        $inner = $radius - 0.5;

        for ($y = 0; $y < $size; $y++) {
            $dy = $y + 0.5 - $radius;

            if (abs($dy) >= $outer) {
                continue;
            }

            $ratio = $y / $size;
            $r = intval($color1[0] * (1 - $ratio) + $color2[0] * $ratio);
            $g = intval($color1[1] * (1 - $ratio) + $color2[1] * $ratio);
            $b = intval($color1[2] * (1 - $ratio) + $color2[2] * $ratio);

            // Miolo da linha totalmente coberto: uma imageline só.
            $innerHalf = abs($dy) < $inner ? sqrt($inner ** 2 - $dy ** 2) : 0;
            $solidStart = (int) ceil($radius - $innerHalf - 0.5);
            $solidEnd = (int) floor($radius + $innerHalf - 0.5);

            if ($innerHalf > 0 && $solidEnd >= $solidStart) {
                imageline($img, $solidStart, $y, $solidEnd, $y, imagecolorallocate($img, $r, $g, $b));
            }

            // Pixels da borda, um a um.
            $outerHalf = sqrt($outer ** 2 - $dy ** 2);
            $xStart = max(0, (int) floor($radius - $outerHalf));
            $xEnd = min($size - 1, (int) ceil($radius + $outerHalf) - 1);

            for ($x = $xStart; $x <= $xEnd; $x++) {
                if ($innerHalf > 0 && $x >= $solidStart && $x <= $solidEnd) {
                    continue;
                }

                $distance = sqrt(($x + 0.5 - $radius) ** 2 + $dy ** 2);
                $coverage = max(0.0, min(1.0, $radius - $distance + 0.5));

                if ($coverage <= 0) {
                    continue;
                }

                $alpha = 127 - (int) round(127 * $coverage);
                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, $alpha));
            }
        }

        // Texto precisa misturar com o gradiente, não substituir o pixel.
        imagealphablending($img, true);

        return $img;
    }

    /**
     * Escreve as iniciais no avatar.
     */
    private function writeInitials($image, string $initials, int $size, array $rgb = [255, 255, 255])
    {
        $fontSize = $size * 0.35;
        $bbox = imagettfbbox($fontSize, 0, $this->fontFile, $initials);

        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];

        $x = ($size - $textWidth) / 2;
        $y = ($size + $textHeight) / 2;

        $textColor = imagecolorallocate($image, ...$rgb);

        imagettftext(
            $image,
            $fontSize,
            0,
            $x,
            $y,
            $textColor,
            $this->fontFile,
            $initials
        );
    }

    /**
     * Branco ou cinza-escuro, o que tiver mais contraste (WCAG) com o pior
     * ponto do gradiente na faixa onde fica o texto (30% a 70% da altura).
     */
    private function textColor(array $color1, array $color2): array
    {
        $light = [255, 255, 255];
        $dark = [17, 24, 39];

        $band = [$this->mix($color1, $color2, 0.3), $this->mix($color1, $color2, 0.7)];

        $worst = fn(array $text) => min(array_map(fn($bg) => $this->contrast($text, $bg), $band));

        return $worst($light) >= $worst($dark) ? $light : $dark;
    }

    private function mix(array $a, array $b, float $ratio): array
    {
        return array_map(fn($x, $y) => (int) round($x * (1 - $ratio) + $y * $ratio), $a, $b);
    }

    private function contrast(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Luminância relativa (sRGB, WCAG 2.x).
     */
    private function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(function ($channel) {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    /**
     * Paleta aleatória, mas sempre igual para o mesmo nome.
     */
    private function generateConsistentGradientColors(string $seed): array
    {
        $hash = md5($seed);

        $r1 = hexdec(substr($hash, 0, 2));
        $g1 = hexdec(substr($hash, 2, 2));
        $b1 = hexdec(substr($hash, 4, 2));

        $r2 = hexdec(substr($hash, 6, 2));
        $g2 = hexdec(substr($hash, 8, 2));
        $b2 = hexdec(substr($hash, 10, 2));

        return [
            [$r1, $g1, $b1],
            [$r2, $g2, $b2]
        ];
    }
}
