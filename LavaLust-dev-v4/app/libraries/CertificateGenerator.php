<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once ROOT_DIR . 'vendor/autoload.php';

class CertificateGenerator
{
    private $template;
    private $pdf;

    public function __construct(array $template = [])
    {
        $this->template = $template;
    }

    public function generate(array $data)
    {
        $settings = $this->template['settings'] ?? [];
        $filePath = $data['template_file_path'] ?? null;

        if (($this->template['mode'] ?? 'generated') === 'upload') {
            if (!$filePath || !is_file($filePath)) {
                throw new RuntimeException('An uploaded certificate background is required to render this template.');
            }
            $this->generateFromUploadedTemplate($filePath, $data, $settings);
        } elseif (($this->template['mode'] ?? 'generated') === 'generated') {
            $this->generateDesignedCertificate($data, $settings);
        } else {
            throw new RuntimeException('The saved certificate template mode is invalid.');
        }

        return $this->pdf->Output('', 'S');
    }

    private function generateFromUploadedTemplate($filePath, array $data, array $settings)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === 'pdf') {
            $this->pdf = new \setasign\Fpdi\Tcpdf\Fpdi('L', 'mm', 'A4', true, 'UTF-8', false);
            $pageCount = $this->pdf->setSourceFile($filePath);
            if ($pageCount < 1) {
                throw new RuntimeException('The uploaded certificate PDF has no pages.');
            }
            $page = $this->pdf->importPage(1);
            $size = $this->pdf->getTemplateSize($page);
            $this->pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $this->pdf->useTemplate($page);
        } else {
            $this->pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
            $this->pdf->AddPage();
            $this->pdf->Image($filePath, 0, 0, 297, 210, '', '', '', false, 300);
        }

        $this->prepareDocument($settings);
        $this->drawConfiguredFields($data, $settings);
    }

    private function generateDesignedCertificate(array $data, array $settings)
    {
        $this->pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->AddPage();
        $this->prepareDocument($settings);

        $accent = $this->rgb($settings['accent_color'] ?? '#dd4814');
        $border = $settings['border_style'] ?? 'double';
        if ($border !== 'none') {
            $this->pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
            $this->pdf->SetLineWidth(1.6);
            $this->pdf->Rect(10, 10, 277, 190, 'D');
            if ($border === 'double') {
                $this->pdf->SetLineWidth(0.5);
                $this->pdf->Rect(14, 14, 269, 182, 'D');
            }
        }

        if (!empty($settings['logo_file_path']) && is_file($settings['logo_file_path'])) {
            $this->pdf->Image($settings['logo_file_path'], 22, 22, 28, 22, '', '', '', true, 300);
        }

        $font = $this->font($settings['font'] ?? 'helvetica');
        $this->pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $this->pdf->SetFont($font, 'B', 22);
        $this->pdf->SetXY(25, 30);
        $this->pdf->MultiCell(247, 12, $this->plain($settings['institution_name'] ?? 'LSI Training Center'), 0, 'C');

        $this->pdf->SetTextColor(45, 50, 60);
        $this->pdf->SetFont($font, 'B', 30);
        $this->pdf->SetXY(25, 61);
        $this->pdf->MultiCell(247, 16, $this->plain($settings['title_text'] ?? 'Certificate of Completion'), 0, 'C');

        $placeholders = [
            '{trainee_name}' => $data['trainee_name'] ?? '',
            '{program_name}' => $data['program_name'] ?? '',
            '{date_completed}' => $this->formatDate($data['completion_date'] ?? ''),
            '{cert_no}' => $data['certificate_number'] ?? '',
            '{signatory_name}' => $settings['signatory_name'] ?? '',
            '{signatory_title}' => $settings['signatory_title'] ?? '',
            '{verification_code}' => $data['certificate_number'] ?? '',
        ];
        $body = strtr((string) ($settings['body_text'] ?? ''), $placeholders);
        $this->pdf->SetFont($font, '', 16);
        $this->pdf->SetTextColor(60, 60, 60);
        $this->pdf->SetXY(42, 91);
        $this->pdf->MultiCell(213, 36, $this->plain($body), 0, 'C', false, 1, '', '', true, 0, false, true, 36, 'M');

        $this->pdf->SetDrawColor(150, 150, 150);
        $this->pdf->Line(88, 158, 143, 158);
        $this->pdf->SetXY(78, 161);
        $this->pdf->SetFont($font, 'B', 12);
        $this->pdf->Cell(75, 7, $this->plain($settings['signatory_name'] ?? ''), 0, 1, 'C');
        $this->pdf->SetX(78);
        $this->pdf->SetFont($font, '', 10);
        $this->pdf->Cell(75, 6, $this->plain($settings['signatory_title'] ?? ''), 0, 0, 'C');

        $this->pdf->SetFont($font, '', 8);
        $this->pdf->SetXY(190, 170);
        $this->pdf->MultiCell(82, 5, 'Certificate No. ' . $this->plain($data['certificate_number'] ?? ''), 0, 'R');
        $this->drawQrCode($data['verification_url'] ?? '', 256, 150, 24);
        $this->drawSignatures($data['signatories'] ?? []);
    }

    private function drawConfiguredFields(array $data, array $settings)
    {
        $values = [
            'trainee_name' => $data['trainee_name'] ?? '',
            'program_name' => $data['program_name'] ?? '',
            'date_completed' => $this->formatDate($data['completion_date'] ?? ''),
            'certificate_number' => $data['certificate_number'] ?? '',
            'signatory_name' => $settings['signatory_name'] ?? '',
            'verification_code' => $data['certificate_number'] ?? '',
        ];
        $pageWidth = $this->pdf->getPageWidth();
        $pageHeight = $this->pdf->getPageHeight();
        $accent = $this->rgb($settings['accent_color'] ?? '#000000');
        foreach (($this->template['field_layout'] ?? []) as $field => $layout) {
            if (!isset($values[$field]) || !is_array($layout)) {
                continue;
            }
            $x = (float) ($layout['x'] ?? 50) * $pageWidth / 100;
            $y = (float) ($layout['y'] ?? 50) * $pageHeight / 100;
            $align = in_array($layout['align'] ?? '', ['left', 'center', 'right'], true) ? $layout['align'] : 'center';
            $fontSize = max(6, min(72, (float) ($layout['font_size'] ?? 16)));
            if ($field === 'verification_code') {
                $this->drawQrCode($data['verification_url'] ?? '', max(0, min($pageWidth - 28, $x)), max(0, min($pageHeight - 28, $y)), 24);
                continue;
            }

            $width = min(170, $pageWidth);
            $left = $align === 'left' ? $x : ($align === 'right' ? $x - $width : $x - $width / 2);
            $left = max(0, min($pageWidth - $width, $left));
            $this->pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
            $this->pdf->SetFont($this->font($settings['font'] ?? 'helvetica'), '', $fontSize);
            $this->pdf->SetXY($left, max(0, min($pageHeight - 12, $y)));
            $this->pdf->MultiCell($width, 8, $this->plain((string) $values[$field]), 0, strtoupper(substr($align, 0, 1)), false, 1);
        }
        $this->drawSignatures($data['signatories'] ?? []);
    }

    private function drawSignatures(array $signatories)
    {
        $pageWidth = $this->pdf->getPageWidth();
        $pageHeight = $this->pdf->getPageHeight();
        $font = $this->font($this->template['settings']['font'] ?? 'helvetica');
        foreach ($signatories as $signatory) {
            $path = $signatory['signature_file_path'] ?? '';
            if ($path === '' || !is_file($path)) {
                throw new RuntimeException('A signed certificate is missing a signatory image.');
            }
            $centerX = max(28, min($pageWidth - 28, (float) ($signatory['x_position'] ?? 50) * $pageWidth / 100));
            $topY = max(8, min($pageHeight - 38, (float) ($signatory['y_position'] ?? 80) * $pageHeight / 100));
            $signatureWidth = 42;
            $signatureHeight = 18;
            $this->pdf->Image($path, $centerX - ($signatureWidth / 2), $topY, $signatureWidth, $signatureHeight, 'PNG', '', '', true, 300);
            $this->pdf->SetTextColor(45, 50, 60);
            $this->pdf->SetFont($font, 'B', 9);
            $this->pdf->SetXY($centerX - 35, $topY + 18);
            $this->pdf->MultiCell(70, 5, $this->plain($signatory['signatory_name'] ?? ''), 0, 'C');
            $this->pdf->SetFont($font, '', 8);
            $this->pdf->SetX($centerX - 35);
            $this->pdf->MultiCell(70, 4, $this->plain($signatory['signatory_position'] ?? ''), 0, 'C');
            $this->pdf->SetFont($font, '', 6);
            $signedAt = strtotime((string) ($signatory['signed_at'] ?? ''));
            if ($signedAt) {
                $this->pdf->SetX($centerX - 35);
                $this->pdf->MultiCell(70, 4, 'Digitally signed on ' . date('M j, Y H:i', $signedAt), 0, 'C');
            } elseif (($signatory['signed_at'] ?? '') === 'PREVIEW') {
                $this->pdf->SetX($centerX - 35);
                $this->pdf->MultiCell(70, 4, 'Signature preview - not signed', 0, 'C');
            }
        }
    }

    private function prepareDocument(array $settings)
    {
        $this->pdf->SetCreator('LSI SmartTrack');
        $this->pdf->SetAuthor($this->plain($settings['institution_name'] ?? 'LSI Training Center'));
        $this->pdf->SetTitle($this->plain($settings['title_text'] ?? 'Certificate of Completion'));
        $this->pdf->SetSubject('Training Certificate');
        $this->pdf->SetMargins(0, 0, 0);
        $this->pdf->SetAutoPageBreak(false, 0);
    }

    private function drawQrCode($value, $x, $y, $size)
    {
        if ($value === '') {
            return;
        }
        $this->pdf->write2DBarcode($value, 'QRCODE,M', $x, $y, $size, $size, [
            'border' => false,
            'vpadding' => 'auto',
            'hpadding' => 'auto',
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
            'module_width' => 2,
            'module_height' => 2,
        ]);
    }

    private function font($font)
    {
        return in_array($font, ['helvetica', 'times', 'courier'], true) ? $font : 'helvetica';
    }

    private function rgb($color)
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color)) {
            $color = '#dd4814';
        }
        return [
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2)),
        ];
    }

    private function formatDate($date)
    {
        $timestamp = strtotime((string) $date);
        return $timestamp ? date('F j, Y', $timestamp) : '';
    }

    private function plain($value)
    {
        return trim(strip_tags((string) $value));
    }
}
