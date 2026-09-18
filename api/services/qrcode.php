<?php
function qrPngMpl($token) {
    require_once dirname(dirname(__DIR__)) . '/vendor/phpqrcode/qrlib.php';
    $rows = QRcode::text($token, false, QR_ECLEVEL_M);
    $size = strlen($rows[0]); $margin = 4; $scale = 7; $width = ($size + $margin * 2) * $scale; $raw = '';
    for ($py = 0; $py < $width; $py++) {
        $raw .= "\x00"; $my = (int) floor($py / $scale) - $margin;
        for ($px = 0; $px < $width; $px++) {
            $mx = (int) floor($px / $scale) - $margin;
            $dark = $mx >= 0 && $my >= 0 && $mx < $size && $my < $size && $rows[$my][$mx] === '1';
            $raw .= $dark ? "\x10\x28\x44" : "\xff\xff\xff";
        }
    }
    $chunk = function ($type, $data) { return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data)); };
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNC5', $width, $width, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw, 9)) . $chunk('IEND', '');
}

