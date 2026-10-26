<?php

declare(strict_types=1);

/**
 * PWA / ホーム画面用アイコンの生成。GD だけで描く（外部アセットなし）。
 *
 *   php bin/make-icons.php
 *
 * 生成結果はコミットする。実行が必要になるのはデザインを変えるときだけ。
 * モチーフは「水滴」。タイムを落とす（drop）というアプリ名から。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD 拡張がありません。\n");
    exit(1);
}

$outDir = dirname(__DIR__) . '/public/assets/icons';

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

foreach ([180, 192, 512] as $size) {
    $path = $outDir . '/icon-' . $size . '.png';
    makeIcon($size, $path);
    echo "生成: {$path}\n";
}

function makeIcon(int $size, string $path): void
{
    // 曲線を滑らかにするため 4 倍で描いて縮小する。
    $scale = 4;
    $s = $size * $scale;
    $image = imagecreatetruecolor($s, $s);

    $bg = (int) imagecolorallocate($image, 0x0d, 0x1b, 0x24);      // 夜のプール
    $primary = (int) imagecolorallocate($image, 0x0b, 0x4f, 0x6c); // 深い水色
    $accent = (int) imagecolorallocate($image, 0x00, 0xc2, 0xa8);  // 水面の光
    $foam = (int) imagecolorallocate($image, 0xf2, 0xf7, 0xf9);    // 白い水しぶき

    imagefilledrectangle($image, 0, 0, $s, $s, $bg);

    // 下 1/3 に静かな水面。primary の帯 + accent の細い波線。
    $waterTop = (int) ($s * 0.70);
    imagefilledrectangle($image, 0, $waterTop, $s, $s, $primary);

    $waveHeight = (int) ($s * 0.02);

    for ($x = 0; $x < $s; $x++) {
        $y = $waterTop + (int) (sin($x / $s * 4 * M_PI) * $waveHeight);
        imagefilledrectangle($image, $x, $y, $x, $y + (int) ($s * 0.012), $accent);
    }

    // 水滴。上の頂点から膨らむ涙形（三角形 + 円）。
    $cx = (int) ($s * 0.5);
    $dropTipY = (int) ($s * 0.16);
    $circleCy = (int) ($s * 0.46);
    $radius = (int) ($s * 0.185);

    imagefilledellipse($image, $cx, $circleCy, $radius * 2, $radius * 2, $accent);
    imagefilledpolygon($image, [
        $cx, $dropTipY,
        $cx - $radius, $circleCy,
        $cx + $radius, $circleCy,
    ], $accent);

    // 水滴のハイライト。
    imagefilledellipse(
        $image,
        (int) ($cx - $radius * 0.4),
        (int) ($circleCy - $radius * 0.15),
        (int) ($radius * 0.5),
        (int) ($radius * 0.8),
        $foam
    );

    // 波紋。水滴の落下点に楕円のリング。
    $rippleCy = (int) ($s * 0.80);
    imagesetthickness($image, max(2, (int) ($s * 0.012)));
    imagearc($image, $cx, $rippleCy, (int) ($s * 0.34), (int) ($s * 0.10), 0, 360, $foam);
    imagearc($image, $cx, $rippleCy, (int) ($s * 0.52), (int) ($s * 0.16), 0, 360, $accent);

    $final = imagecreatetruecolor($size, $size);
    imagecopyresampled($final, $image, 0, 0, 0, 0, $size, $size, $s, $s);
    imagepng($final, $path, 9);
    imagedestroy($image);
    imagedestroy($final);
}
