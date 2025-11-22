<?php

namespace RiseTechApps\RiseTools\Features\AvatarGenerator;

use Exception;
use Illuminate\Support\Facades\Storage;

class AvatarGenerator
{
    private string $fontFile;

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
        $this->writeInitials($image, $initials, $size);

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
        $parts = preg_split('/\s+/', trim($name));

        if (count($parts) === 0) {
            return "U";
        }

        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }

        return strtoupper(
            $parts[0][0] . $parts[array_key_last($parts)][0]
        );
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

        for ($y = 0; $y < $size; $y++) {
            $ratio = $y / $size;
            $r = intval($color1[0] * (1 - $ratio) + $color2[0] * $ratio);
            $g = intval($color1[1] * (1 - $ratio) + $color2[1] * $ratio);
            $b = intval($color1[2] * (1 - $ratio) + $color2[2] * $ratio);

            $lineColor = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $size, $y, $lineColor);
        }

        // recorta para um círculo perfeito
        $mask = imagecreatetruecolor($size, $size);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);
        $transparentMask = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        imagefill($mask, 0, 0, $transparentMask);

        $circleColor = imagecolorallocate($mask, 0, 0, 0);
        imagefilledellipse($mask, $size / 2, $size / 2, $size, $size, $circleColor);

        imagecolortransparent($mask, $circleColor);

        imagecopymerge($img, $mask, 0, 0, 0, 0, $size, $size, 100);

        imagedestroy($mask);

        return $img;
    }

    /**
     * Escreve as iniciais no avatar.
     */
    private function writeInitials($image, string $initials, int $size)
    {
        $fontSize = $size * 0.35;
        $bbox = imagettfbbox($fontSize, 0, $this->fontFile, $initials);

        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];

        $x = ($size - $textWidth) / 2;
        $y = ($size + $textHeight) / 2;

        // Melhor contraste (texto claro em fundo escuro e vice-versa)
        $textColor = imagecolorallocate($image, 255, 255, 255);

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
