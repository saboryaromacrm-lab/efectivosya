<?php
/**
 * Generador de iconos PNG para PWA - Caja SYA
 * Ejecutar una vez: php icons/generate-png.php
 * O acceder via web: /efectivo/icons/generate-png.php?key=sya2025
 */

// Protección de acceso
if (php_sapi_name() !== 'cli') {
    if (!isset($_GET['key']) || $_GET['key'] !== 'sya2025') {
        http_response_code(404);
        exit;
    }
}

$sizes = [192, 512];
$outputDir = __DIR__ . '/';

foreach ($sizes as $size) {
    // Crear imagen
    $img = imagecreatetruecolor($size, $size);

    // Habilitar alpha blending
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Colores
    $verdeOscuro = imagecolorallocate($img, 26, 77, 46);    // #1a4d2e
    $verdeMedio = imagecolorallocate($img, 45, 106, 79);    // #2d6a4f
    $blanco = imagecolorallocate($img, 255, 255, 255);
    $blancoSemi = imagecolorallocatealpha($img, 255, 255, 255, 12); // 95% opaco

    // Fondo verde (gradiente simulado con rectángulo)
    imagefilledrectangle($img, 0, 0, $size, $size, $verdeOscuro);

    // Simular gradiente con líneas
    for ($i = 0; $i < $size; $i++) {
        $ratio = $i / $size;
        $r = (int)(26 + (45 - 26) * $ratio);
        $g = (int)(77 + (106 - 77) * $ratio);
        $b = (int)(46 + (79 - 46) * $ratio);
        $color = imagecolorallocate($img, $r, $g, $b);
        imageline($img, $i, 0, $i, $size, $color);
    }

    // Billete blanco (rectángulo)
    $billeteX = (int)($size * 0.15);
    $billeteY = (int)($size * 0.31);
    $billeteW = (int)($size * 0.7);
    $billeteH = (int)($size * 0.375);
    imagefilledrectangle($img, $billeteX, $billeteY, $billeteX + $billeteW, $billeteY + $billeteH, $blanco);

    // Borde interno del billete
    imagerectangle($img, $billeteX + (int)($size * 0.04), $billeteY + (int)($size * 0.04),
                   $billeteX + $billeteW - (int)($size * 0.04), $billeteY + $billeteH - (int)($size * 0.04), $verdeOscuro);

    // Signo $ central
    $fontSize = (int)($size * 0.18);
    $fontFile = null; // Usar fuente GD por defecto

    // Usar imagestring para $ (fuente básica)
    $centroX = (int)($size / 2);
    $centroY = (int)($size * 0.47);

    // Dibujar $ grande con múltiples caracteres para simular bold
    $dollarSize = 5; // Tamaño de fuente GD (1-5)
    $dollarX = $centroX - 15;
    $dollarY = $centroY - 8;

    // Si hay fuente TTF disponible, usarla
    $fontPath = 'C:/Windows/Fonts/arialbd.ttf';
    if (file_exists($fontPath)) {
        $fontSize = (int)($size * 0.23);
        $bbox = imagettfbbox($fontSize, 0, $fontPath, '$');
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];
        $dollarX = $centroX - ($textWidth / 2);
        $dollarY = $centroY + ($textHeight / 2);
        imagettftext($img, $fontSize, 0, (int)$dollarX, (int)$dollarY, $verdeOscuro, $fontPath, '$');

        // Texto SYA
        $syaSize = (int)($size * 0.09);
        $bbox = imagettfbbox($syaSize, 0, $fontPath, 'SYA');
        $syaWidth = $bbox[2] - $bbox[0];
        $syaX = $centroX - ($syaWidth / 2);
        $syaY = (int)($size * 0.85);
        imagettftext($img, $syaSize, 0, (int)$syaX, (int)$syaY, $blanco, $fontPath, 'SYA');
    } else {
        // Fallback: usar fuente GD básica
        imagestring($img, 5, $centroX - 5, $centroY - 10, '$', $verdeOscuro);
        imagestring($img, 5, $centroX - 15, (int)($size * 0.78), 'SYA', $blanco);
    }

    // Círculos decorativos
    $circleRadius = (int)($size * 0.05);
    $leftCircleX = (int)($size * 0.27);
    $rightCircleX = (int)($size * 0.73);
    $circleY = (int)($size * 0.5);

    imageellipse($img, $leftCircleX, $circleY, $circleRadius * 2, $circleRadius * 2, $verdeOscuro);
    imageellipse($img, $rightCircleX, $circleY, $circleRadius * 2, $circleRadius * 2, $verdeOscuro);

    // Guardar PNG
    $filename = $outputDir . "icon-{$size}.png";
    imagepng($img, $filename);
    imagedestroy($img);

    echo "Generado: icon-{$size}.png\n";
}

echo "\nIconos generados correctamente!\n";
echo "Ahora sube los archivos icon-192.png y icon-512.png a la carpeta icons/\n";
?>
