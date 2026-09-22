<?php
/**
 * Generador de iconos PNG para PWA
 *
 * OPCIÓN 1: Si tienes ImageMagick instalado, ejecuta este script desde CLI:
 *   php generate-icons.php
 *
 * OPCIÓN 2: Usa una herramienta online como:
 *   - https://realfavicongenerator.net/
 *   - https://www.pwabuilder.com/imageGenerator
 *   Sube el archivo icons/icon.svg y descarga los iconos generados
 *
 * OPCIÓN 3: Usa el icono SVG directamente (algunos navegadores lo soportan)
 */

$sizes = [16, 32, 72, 96, 114, 120, 128, 144, 152, 180, 192, 384, 512];
$svgPath = __DIR__ . '/icons/icon.svg';
$outputDir = __DIR__ . '/icons/';

// Verificar si ImageMagick está disponible
$hasImageMagick = false;
if (extension_loaded('imagick')) {
    $hasImageMagick = true;
} else {
    // Intentar con comando de sistema
    exec('convert --version 2>&1', $output, $returnCode);
    if ($returnCode === 0) {
        $hasImageMagick = true;
    }
}

if (!$hasImageMagick) {
    echo "ImageMagick no está instalado.\n\n";
    echo "ALTERNATIVAS:\n";
    echo "1. Instala ImageMagick: https://imagemagick.org/script/download.php\n";
    echo "2. Usa una herramienta online:\n";
    echo "   - https://realfavicongenerator.net/\n";
    echo "   - https://www.pwabuilder.com/imageGenerator\n";
    echo "   Sube el archivo: {$svgPath}\n\n";
    echo "3. Crea los iconos manualmente con estos tamaños:\n";
    foreach ($sizes as $size) {
        echo "   - icon-{$size}.png ({$size}x{$size}px)\n";
    }
    exit(1);
}

echo "Generando iconos PWA...\n";

foreach ($sizes as $size) {
    $outputPath = $outputDir . "icon-{$size}.png";

    if (extension_loaded('imagick')) {
        // Usar extensión PHP
        $imagick = new Imagick();
        $imagick->setBackgroundColor(new ImagickPixel('transparent'));
        $imagick->readImage($svgPath);
        $imagick->setImageFormat('png');
        $imagick->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1);
        $imagick->writeImage($outputPath);
        $imagick->clear();
        $imagick->destroy();
    } else {
        // Usar comando de sistema
        $cmd = "convert -background none -density 300 \"{$svgPath}\" -resize {$size}x{$size} \"{$outputPath}\"";
        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            echo "Error generando icon-{$size}.png\n";
            continue;
        }
    }

    echo "Generado: icon-{$size}.png\n";
}

echo "\n¡Iconos generados correctamente!\n";
?>
