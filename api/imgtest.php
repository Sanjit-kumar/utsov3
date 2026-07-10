<?php
// Quick diagnostic: GD + FreeType font test
// DELETE after confirming fonts work on server

header('Content-Type: text/plain');

$assDir  = dirname(__FILE__) . '/assets/';
$fBold   = $assDir . 'fonts/Cinzel-Bold.ttf';
$fBody   = $assDir . 'fonts/EBGaramond-Regular.ttf';
$fItalic = $assDir . 'fonts/EBGaramond-Italic.ttf';
$fSig    = $assDir . 'fonts/Tangerine-Bold.ttf';
$frame   = $assDir . 'emaildurga.png';
$durga   = $assDir . 'emaildurga1.JPG';

echo "=== GD Info ===\n";
$gd = gd_info();
echo "FreeType Support: " . ($gd['FreeType Support'] ? 'YES' : 'NO') . "\n";
echo "FreeType Linkage: " . (isset($gd['FreeType Linkage']) ? $gd['FreeType Linkage'] : 'n/a') . "\n";
echo "GD version: " . (isset($gd['GD Version']) ? $gd['GD Version'] : 'n/a') . "\n";

echo "\n=== Font Files ===\n";
foreach (array('Cinzel-Bold.ttf','EBGaramond-Regular.ttf','EBGaramond-Italic.ttf','Tangerine-Bold.ttf') as $fn) {
    $p = $assDir . 'fonts/' . $fn;
    if (file_exists($p)) {
        $b = file_get_contents($p, false, null, 0, 4);
        echo "$fn: EXISTS " . filesize($p) . "B  magic=" . bin2hex($b) . "\n";
    } else {
        echo "$fn: MISSING\n";
    }
}

echo "\n=== Image Assets ===\n";
echo "emaildurga.png:  " . (file_exists($frame) ? filesize($frame)."B" : "MISSING") . "\n";
echo "emaildurga1.JPG: " . (file_exists($durga) ? filesize($durga)."B" : "MISSING") . "\n";

echo "\n=== imagettfbbox test ===\n";
if (file_exists($fBold) && function_exists('imagettfbbox')) {
    $bb = @imagettfbbox(16, 0, $fBold, 'UTSOV 2026');
    if ($bb) {
        echo "Cinzel-Bold 16pt bbox: OK (" . abs($bb[4]-$bb[0]) . "w x " . abs($bb[5]-$bb[1]) . "h)\n";
    } else {
        echo "Cinzel-Bold imagettfbbox FAILED (null) - FreeType may not support this font\n";
    }
} else {
    echo "imagettfbbox not available or font missing\n";
}

if (file_exists($fSig) && function_exists('imagettfbbox')) {
    $bb = @imagettfbbox(24, 0, $fSig, 'Jai Maa Durga!');
    if ($bb) {
        echo "Tangerine-Bold 24pt bbox: OK (" . abs($bb[4]-$bb[0]) . "w)\n";
    } else {
        echo "Tangerine-Bold imagettfbbox FAILED\n";
    }
}

echo "\n=== Test image render ===\n";
$img = imagecreatetruecolor(300, 80);
$bg  = imagecolorallocate($img, 255, 248, 238);
imagefill($img, 0, 0, $bg);
$col = imagecolorallocate($img, 92, 0, 0);
if (file_exists($fBold)) {
    $ok = @imagettftext($img, 14, 0, 10, 30, $col, $fBold, 'UTSOV 2026');
    echo "imagettftext Cinzel: " . ($ok ? "OK" : "FAILED") . "\n";
}
if (file_exists($fSig)) {
    $ok = @imagettftext($img, 20, 0, 10, 65, $col, $fSig, 'Jai Maa Durga!');
    echo "imagettftext Tangerine: " . ($ok ? "OK" : "FAILED") . "\n";
}
// Save test image
$testOut = dirname(__FILE__) . '/email-images/imgtest.png';
if (!is_dir(dirname($testOut))) mkdir(dirname($testOut), 0755, true);
imagepng($img, $testOut);
imagedestroy($img);
echo "Test image saved to /api/email-images/imgtest.png\n";
echo "View at: https://utsov.org/api/email-images/imgtest.png\n";

echo "\nDone.\n";
?>
