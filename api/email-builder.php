<?php
namespace UtsovAPI;

/**
 * Generates a personalized email PNG by compositing text onto the emaildurga.png
 * frame at the exact positions matching email-preview.html.
 *
 * @param string $badge        Badge text, e.g. 'BOOKING CONFIRMED'
 * @param bool   $badgeMaroon  true = maroon+gold badge, false = green+white
 * @param string $heading      Large heading text
 * @param array  $greeting     Lines of greeting text (plain strings)
 * @param string $tableTitle   Section header, e.g. 'BOOKING DETAILS', or null
 * @param array  $rows         Table rows: [label, value, altBg, isTotal, isMono]
 * @param array  $closing      Closing lines of text
 * @param string $outKey       Unique key used for the output filename
 * @return string|null         Full HTTPS URL of generated image, or null on failure
 */
function buildEmailImage($badge, $badgeMaroon, $heading, $greeting, $tableTitle, $rows, $closing, $outKey) {
    if (!function_exists('imagecreatefrompng')) return null;

    $assDir  = dirname(__FILE__) . '/assets/';
    $frame   = $assDir . 'emaildurga.png';
    $durga   = $assDir . 'emaildurga1.JPG';
    $fBold   = $assDir . 'fonts/Cinzel-Bold.ttf';
    $fBody   = $assDir . 'fonts/EBGaramond-Regular.ttf';
    $fItalic = $assDir . 'fonts/EBGaramond-Italic.ttf';
    $fSig    = $assDir . 'fonts/Tangerine-Bold.ttf';

    if (!file_exists($frame) || !file_exists($fBold) || !file_exists($fBody)) return null;

    // Output at 600×851 (preserves 1728×2450 aspect ratio)
    $TW = 600;
    $TH = 851;

    // Load and scale the frame image
    $src = @imagecreatefrompng($frame);
    if (!$src) return null;
    $img = imagecreatetruecolor($TW, $TH);
    imagealphablending($img, true);
    imagesavealpha($img, false);
    imagecopyresampled($img, $src, 0, 0, 0, 0, $TW, $TH, imagesx($src), imagesy($src));
    imagedestroy($src);

    // Overlay Durga Maa watermark at 13% opacity inside the cream panel
    if (file_exists($durga)) {
        $d2 = @imagecreatefromjpeg($durga);
        if ($d2) {
            $pL = (int)($TW * 0.105);   // 63
            $pT = (int)($TH * 0.33);    // 281
            $pW = (int)($TW * 0.790);   // 474
            $pH = (int)($TH * 0.640);   // 544
            $sH = (int)($pW * imagesy($d2) / imagesx($d2));
            $ds = imagecreatetruecolor($pW, $sH);
            imagecopyresampled($ds, $d2, 0, 0, 0, 0, $pW, $sH, imagesx($d2), imagesy($d2));
            imagedestroy($d2);
            $dstY = $pT + (int)(($pH - $sH) / 2);
            if ($dstY < $pT) $dstY = $pT;
            imagecopymerge($img, $ds, $pL, $dstY, 0, 0, $pW, min($sH, $pH), 13);
            imagedestroy($ds);
        }
    }

    // Color palette
    $cDarkRed = imagecolorallocate($img, 92,  0,   0);
    $cMaroon  = imagecolorallocate($img, 139, 26,  26);
    $cGreen   = imagecolorallocate($img, 26,  107, 46);
    $cGold    = imagecolorallocate($img, 201, 168, 76);
    $cWhite   = imagecolorallocate($img, 255, 255, 255);
    $cCream   = imagecolorallocate($img, 255, 248, 238);
    $cDark    = imagecolorallocate($img, 15,  15,  15);   // near-black for readability
    $cBrown   = imagecolorallocate($img, 42,  10,  0);
    $cLabel   = imagecolorallocate($img, 100, 25,  0);    // darker maroon for labels
    $cMoney   = imagecolorallocate($img, 26,  107, 46);
    $cGray    = imagecolorallocate($img, 55,  55,  55);   // dark gray for reference IDs
    $cRowBdr  = imagecolorallocate($img, 232, 200, 160);
    $cTblBdr  = imagecolorallocate($img, 196, 122, 90);

    // Layout
    $CX = 300;
    $tX = 83;  $tR = 517;  $tW = $tR - $tX;  // content bounds
    $lW = (int)($tW * 0.42);                   // label column: 182px

    // Draw centered text; return next Y position
    $drawCentered = function($size, $font, $text, $color, $y) use ($img, $CX) {
        $bb = imagettfbbox($size, 0, $font, $text);
        $x  = $CX - (int)(abs($bb[4] - $bb[0]) / 2);
        imagettftext($img, $size, 0, max(4, $x), $y + $size, $color, $font, $text);
        return $y + (int)($size * 1.5) + 1;
    };

    $Y = (int)($TH * 0.33) + 12;   // ≈ 293 — top of cream content area

    // ── BADGE ───────────────────────────────────────────────────────────────────
    $bSz = 9;  $bPX = 16;  $bPY = 4;
    $bb  = imagettfbbox($bSz, 0, $fItalic, $badge);
    $bW  = abs($bb[4] - $bb[0]) + $bPX * 2;
    $bH  = $bSz + $bPY * 2 + 2;
    $bXs = $CX - (int)($bW / 2);
    $bgC = $badgeMaroon ? $cMaroon : $cGreen;
    $fgC = $badgeMaroon ? $cGold   : $cWhite;
    imagefilledrectangle($img, $bXs, $Y, $bXs + $bW, $Y + $bH, $bgC);
    imagettftext($img, $bSz, 0, $bXs + $bPX, $Y + $bH - $bPY - 1, $fgC, $fItalic, $badge);
    $Y += $bH + 10;

    // ── HEADING — auto-shrink so long headings never overflow the content area ──
    $hSz  = 16;
    $hMax = $tR - $tX - 16;   // 418px max width
    while ($hSz > 9) {
        $hbb = imagettfbbox($hSz, 0, $fBold, $heading);
        if (abs($hbb[4] - $hbb[0]) <= $hMax) break;
        $hSz--;
    }
    $Y = $drawCentered($hSz, $fBold, $heading, $cDarkRed, $Y) + 9;

    // ── GREETING ────────────────────────────────────────────────────────────────
    foreach ($greeting as $line) {
        $Y = $drawCentered(11, $fBody, (string)$line, $cBrown, $Y) + 1;
    }
    $Y += 9;

    // ── DETAILS TABLE (optional) ─────────────────────────────────────────────────
    if ($tableTitle && is_array($rows) && count($rows) > 0) {
        $rH      = 24;   // taller rows for legibility
        $tStartY = $Y;

        // Header row
        imagefilledrectangle($img, $tX, $Y, $tR, $Y + $rH, $cMaroon);
        imagettftext($img, 8, 0, $tX + 8, $Y + $rH - 8, $cGold, $fBold, strtoupper($tableTitle));
        $Y += $rH;

        foreach ($rows as $row) {
            $lbl  = isset($row[0]) ? (string)$row[0] : '';
            $val  = isset($row[1]) ? (string)$row[1] : '';
            $alt  = !empty($row[2]);
            $isT  = !empty($row[3]);
            $isM  = !empty($row[4]);

            if ($alt) imagefilledrectangle($img, $tX, $Y, $tR, $Y + $rH, $cCream);
            imageline($img, $tX, $Y + $rH - 1, $tR, $Y + $rH - 1, $cRowBdr);
            // Label: 10pt italic, dark maroon
            imagettftext($img, 10, 0, $tX + 6, $Y + $rH - 7, $cLabel, $fItalic, $lbl);
            // Value: 11pt bold body, dark colors
            $vSz  = $isT ? 13 : 11;
            $vCol = $isT ? $cMoney : ($isM ? $cGray : $cDark);
            $vFnt = $isT ? $fBold  : $fBody;
            imagettftext($img, $vSz, 0, $tX + $lW + 6, $Y + $rH - 7, $vCol, $vFnt, $val);
            $Y += $rH;
        }

        imagerectangle($img, $tX, $tStartY, $tR, $Y, $cTblBdr);
        $Y += 10;
    }

    // ── CLOSING ─────────────────────────────────────────────────────────────────
    foreach ($closing as $line) {
        $Y = $drawCentered(10, $fItalic, (string)$line, $cBrown, $Y) + 1;
    }
    $Y += 10;

    // ── SIGNATURE ───────────────────────────────────────────────────────────────
    $Y = $drawCentered(24, $fSig, 'Jai Maa Durga!', $cMaroon, $Y) + 4;
    $drawCentered(12, $fBold, '-- The UTSOV Team', $cDarkRed, $Y);

    // ── SAVE AND RETURN URL ──────────────────────────────────────────────────────
    $outDir = dirname(__FILE__) . '/email-images/';
    if (!is_dir($outDir)) mkdir($outDir, 0755, true);

    // Clean up images older than 7 days to prevent disk fill
    $old = glob($outDir . 'em_*.png');
    if (is_array($old)) {
        foreach ($old as $f) {
            if (filemtime($f) < time() - 604800) @unlink($f);
        }
    }

    $safe  = preg_replace('/[^A-Za-z0-9]/', '', $outKey);
    $fname = 'em_' . substr($safe, 0, 28) . '_' . substr(md5($outKey), 0, 6) . '.png';
    imagepng($img, $outDir . $fname, 7);
    imagedestroy($img);

    return 'https://utsov.org/api/email-images/' . $fname;
}

/**
 * Strip HTML entities and tags — use before passing display strings to GD.
 */
function imgText($s) {
    return html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
?>
