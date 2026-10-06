<?php

namespace App\Support;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/** PNG QR codes as data URIs, for PDFs (dompdf renders PNG reliably, SVG less so). */
class QrCode
{
    public static function pngDataUri(string $data, int $scale = 5): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'scale' => $scale,
            'quietzoneSize' => 2,
            'outputBase64' => true,
        ]);

        return (new Generator($options))->render($data);
    }
}
