<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SmartTrackPdf
{
    public static function create(string $orientation = 'P', array $allowedPaths = [])
    {
        [$pdf] = self::createWithPage($orientation, $allowedPaths);
        return $pdf;
    }

    public static function createWithPage(string $orientation = 'P', array $allowedPaths = [])
    {
        $pdf = self::newDocument($allowedPaths);
        $page = $pdf->addPage(['format' => 'A4', 'orientation' => $orientation]);
        self::selectFont($pdf);

        return [$pdf, $page];
    }

    public static function newDocument(array $allowedPaths = [])
    {
        $fontsPath = realpath(ROOT_DIR . 'vendor/tecnickcom/tc-lib-pdf-font/target/fonts');
        if ($fontsPath === false) {
            throw new RuntimeException('PDF font assets are missing. Run Composer install to generate them.');
        }
        if (!defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontsPath);
        }

        require_once ROOT_DIR . 'vendor/autoload.php';

        $allowedPaths = array_values(array_unique(array_merge([$fontsPath], $allowedPaths)));
        return new \Com\Tecnick\Pdf\Tcpdf(
            fileOptions: ['allowedPaths' => $allowedPaths]
        );
    }

    public static function selectFont($pdf, string $font = 'dejavusans', string $style = '', float $size = 10)
    {
        $fontData = $pdf->font->insert($pdf->pon, $font, $style, $size);
        $pdf->page->addContent($fontData['out']);
        return $fontData;
    }
}
