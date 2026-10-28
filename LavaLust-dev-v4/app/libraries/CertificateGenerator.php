<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

if (!defined('TCPDF_SILENCE_DEPRECATION')) {
    define('TCPDF_SILENCE_DEPRECATION', true);
}

require_once ROOT_DIR . 'vendor/autoload.php';

class CertificateGenerator
{
    private $config;
    private $pdf;
    private $pageWidth;
    private $pageHeight;

    public function __construct(array $config = [])
    {
        $settings = $config['settings'] ?? [];
        $this->config = [
            'mode' => $config['mode'] ?? 'generate',
            'file_path' => $config['file_path'] ?? '',
            'file_mime' => $config['file_mime'] ?? '',
            'field_layout' => $config['field_layout'] ?? [],
            'institution_name' => $settings['institution_name'] ?? 'LSI Training Center',
            'logo_path' => $settings['logo_path'] ?? '',
            'title_text' => $settings['title_text'] ?? 'Certificate of Completion',
            'subtitle_text' => $settings['subtitle_text'] ?? 'OF COMPLETION',
            'body_text' => $settings['body_text'] ?? 'This is to certify that {trainee_name} has successfully completed {program_name} on {date_completed}.',
            'signatory_name' => $settings['signatory_name'] ?? 'Training Director',
            'signatory_designation' => $settings['signatory_designation'] ?? 'Director',
            'border_style' => $settings['border_style'] ?? 'double',
            'accent_color' => $settings['accent_color'] ?? '#dd4814',
            'font_family' => $settings['font_family'] ?? 'helvetica',
        ];
    }

    public function generate(array $data)
    {
        $isPdfBackground = $this->config['mode'] === 'upload'
            && $this->config['file_mime'] === 'application/pdf';
        $pdfClass = $isPdfBackground && class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class)
            ? \setasign\Fpdi\Tcpdf\Fpdi::class
            : TCPDF::class;

        if ($isPdfBackground && $pdfClass === TCPDF::class) {
            throw new RuntimeException('FPDI is required to render uploaded PDF certificate backgrounds.');
        }

        $this->pdf = new $pdfClass('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('LSI SmartTrack');
        $this->pdf->SetAuthor($this->config['institution_name']);
        $this->pdf->SetTitle($this->config['title_text']);
        $this->pdf->SetSubject('Training Certificate');
        $this->pdf->SetMargins(0, 0, 0);
        $this->pdf->SetAutoPageBreak(false, 0);

        $templatePage = null;
        $templateSize = null;
        if ($isPdfBackground) {
            if (!is_file($this->config['file_path'])) {
                throw new RuntimeException('The uploaded PDF certificate background is missing.');
            }
            $pageCount = $this->pdf->setSourceFile($this->config['file_path']);
            if ($pageCount < 1) {
                throw new RuntimeException('The uploaded PDF certificate background has no pages.');
            }
            $templatePage = $this->pdf->importPage(1);
            $templateSize = $this->pdf->getTemplateSize($templatePage);
        }

        if ($templateSize) {
            $this->pageWidth = (float) $templateSize['width'];
            $this->pageHeight = (float) $templateSize['height'];
            $this->pdf->AddPage($templateSize['orientation'], [$this->pageWidth, $this->pageHeight]);
            $this->pdf->useTemplate($templatePage, 0, 0, $this->pageWidth, $this->pageHeight);
        } else {
            $this->pageWidth = 297;
            $this->pageHeight = 210;
            $this->pdf->AddPage('L', 'A4');
            if ($this->config['mode'] === 'upload') {
                if (!is_file($this->config['file_path'])) {
                    throw new RuntimeException('The uploaded image certificate background is missing.');
                }
                $this->pdf->Image($this->config['file_path'], 0, 0, $this->pageWidth, $this->pageHeight, '', '', '', false, 300);
            } else {
                $this->drawBorder();
                $this->drawGeneratedHeader();
                $this->drawGeneratedBody($data);
            }
        }

        $this->drawFields($data);
        $this->drawQRCode($data);
        $this->drawSignatures($data['signatures'] ?? []);

        return $this->pdf->Output('', 'S');
    }

    private function drawBorder()
    {
        $this->setDrawColor($this->config['accent_color']);
        $style = $this->config['border_style'];
        if ($style === 'none') {
            return;
        }
        if ($style === 'double' || $style === 'ornate') {
            $this->pdf->SetLineWidth(1.6);
            $this->pdf->Rect(10, 10, $this->pageWidth - 20, $this->pageHeight - 20, 'D');
            $this->pdf->SetLineWidth(0.6);
            $inset = $style === 'ornate' ? 17 : 14;
            $this->pdf->Rect($inset, $inset, $this->pageWidth - ($inset * 2), $this->pageHeight - ($inset * 2), 'D');
            return;
        }
        $this->pdf->SetLineWidth(1);
        $this->pdf->Rect(12, 12, $this->pageWidth - 24, $this->pageHeight - 24, 'D');
    }

    private function drawGeneratedHeader()
    {
        $logoPath = $this->config['logo_path'];
        if ($logoPath !== '' && is_file($logoPath)) {
            $this->pdf->Image($logoPath, 20, 17, 24, 24, '', '', '', false, 300);
        }

        $this->setTextColor($this->config['accent_color']);
        $this->pdf->SetFont($this->config['font_family'], 'B', 22);
        $this->pdf->SetXY(48, 20);
        $this->pdf->Cell($this->pageWidth - 96, 11, $this->config['institution_name'], 0, 1, 'C');
        $this->pdf->SetFont($this->config['font_family'], '', 16);
        $this->pdf->SetXY(20, 34);
        $this->pdf->Cell($this->pageWidth - 40, 10, $this->config['title_text'], 0, 1, 'C');
        if ($this->config['subtitle_text'] !== '') {
            $this->pdf->SetFont($this->config['font_family'], '', 10);
            $this->pdf->SetXY(20, 44);
            $this->pdf->Cell($this->pageWidth - 40, 7, $this->config['subtitle_text'], 0, 1, 'C');
        }
    }

    private function drawGeneratedBody(array $data)
    {
        $body = $this->replacePlaceholders($this->config['body_text'], $data);
        $this->pdf->SetFont($this->config['font_family'], '', 13);
        $this->setTextColor('#555555');
        $bodyY = $this->pageHeight * 0.43;
        $this->pdf->MultiCell($this->pageWidth - 50, 8, $body, 0, 'C', false, 1, 25, $bodyY, true);
    }

    private function drawFields(array $data)
    {
        $layout = $this->config['field_layout'];
        $fields = [
            'trainee_name' => $data['trainee_name'] ?? '',
            'program_name' => $data['program_name'] ?? '',
            'completion_date' => $data['completion_date'] ?? '',
            'certificate_number' => $data['certificate_number'] ?? '',
        ];
        if (empty($data['signatures'])) {
            $fields['signatory_name'] = $this->config['signatory_name'];
            $fields['signatory_designation'] = $this->config['signatory_designation'];
        }

        foreach ($fields as $name => $text) {
            $position = $layout[$name] ?? null;
            if (!$position || $text === '') {
                continue;
            }
            $x = ($this->pageWidth * (float) $position['x']) / 100;
            $y = ($this->pageHeight * (float) $position['y']) / 100;
            $align = $position['align'] ?? 'C';
            $fontSize = (float) ($position['font_size'] ?? 12);
            $fontStyle = in_array($name, ['trainee_name', 'program_name', 'signatory_name'], true) ? 'B' : '';
            $this->pdf->SetFont($this->config['font_family'], $fontStyle, $fontSize);
            $this->setTextColor($name === 'trainee_name' ? $this->config['accent_color'] : '#333333');
            $textWidth = min($this->pdf->GetStringWidth($text) + 4, $this->pageWidth - 20);
            if ($align === 'C') {
                $x -= $textWidth / 2;
            } elseif ($align === 'R') {
                $x -= $textWidth;
            }
            $x = max(5, min($x, $this->pageWidth - $textWidth - 5));
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, max(6, $fontSize * 0.5), $text, 0, 0, $align);
        }
    }

    private function drawSignatures(array $signatures)
    {
        foreach ($signatures as $signature) {
            $path = $signature['file_path'] ?? '';
            if ($path === '' || !is_file($path)) {
                throw new RuntimeException('A signed certificate signature image is missing.');
            }

            $x = ($this->pageWidth * (float) $signature['field_x']) / 100;
            $y = min(
                $this->pageHeight - 22,
                max(22, ($this->pageHeight * (float) $signature['field_y']) / 100)
            );
            $align = $signature['alignment'] ?? 'C';
            $imageWidth = 42;
            if ($align === 'C') {
                $imageX = $x - ($imageWidth / 2);
            } elseif ($align === 'R') {
                $imageX = $x - $imageWidth;
            } else {
                $imageX = $x;
            }
            $imageX = max(5, min($imageX, $this->pageWidth - $imageWidth - 5));
            $this->pdf->Image($path, $imageX, max(5, $y - 16), $imageWidth, 13, 'PNG');

            $fontSize = (int) ($signature['font_size'] ?? 12);
            $this->pdf->SetFont($this->config['font_family'], 'B', $fontSize);
            $this->setTextColor('#333333');
            $this->pdf->SetXY($imageX, $y);
            $this->pdf->Cell($imageWidth, max(6, $fontSize * 0.5), $signature['signatory_name'] ?? '', 0, 0, $align);
            $this->pdf->SetFont($this->config['font_family'], '', max(7, $fontSize - 2));
            $this->pdf->SetXY($imageX, $y + max(6, $fontSize * 0.5));
            $this->pdf->Cell($imageWidth, 5, $signature['position'] ?? '', 0, 0, $align);
            $this->pdf->SetFont($this->config['font_family'], '', 8);
            $this->pdf->SetXY($imageX, $y + max(11, $fontSize * 0.5 + 5));
            $this->pdf->Cell($imageWidth, 4, 'Digitally signed on ' . ($signature['signed_at'] ?? ''), 0, 0, $align);
        }
    }

    private function drawQRCode(array $data)
    {
        $position = $this->config['field_layout']['qr_code'] ?? [
            'x' => 86,
            'y' => 80,
            'font_size' => 10,
            'align' => 'C',
        ];
        $size = 26;
        $x = (($this->pageWidth * (float) $position['x']) / 100) - ($size / 2);
        $y = (($this->pageHeight * (float) $position['y']) / 100) - ($size / 2);
        $x = max(3, min($x, $this->pageWidth - $size - 3));
        $y = max(3, min($y, $this->pageHeight - $size - 3));
        $this->pdf->write2DBarcode($data['qr_code'] ?? '', 'QRCODE,M', $x, $y, $size, $size, [
            'border' => false,
            'vpadding' => 'auto',
            'hpadding' => 'auto',
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
            'module_width' => 2,
            'module_height' => 2,
        ]);
    }

    private function replacePlaceholders($text, array $data)
    {
        $values = [
            '{trainee_name}' => $data['trainee_name'] ?? '',
            '{program_name}' => $data['program_name'] ?? '',
            '{date_completed}' => $data['completion_date'] ?? '',
            '{cert_no}' => $data['certificate_number'] ?? '',
            '{certificate_number}' => $data['certificate_number'] ?? '',
            '{class_name}' => $data['class_name'] ?? '',
            '{issue_date}' => $data['issue_date'] ?? '',
            '{signatory_name}' => $this->config['signatory_name'],
            '{signatory_title}' => $this->config['signatory_designation'],
        ];
        return strtr($text, $values);
    }

    private function setDrawColor($hex)
    {
        [$red, $green, $blue] = $this->rgb($hex);
        $this->pdf->SetDrawColor($red, $green, $blue);
    }

    private function setTextColor($hex)
    {
        [$red, $green, $blue] = $this->rgb($hex);
        $this->pdf->SetTextColor($red, $green, $blue);
    }

    private function rgb($hex)
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
