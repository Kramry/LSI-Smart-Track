<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once ROOT_DIR . 'vendor/autoload.php';
require_once APP_DIR . 'libraries/SmartTrackPdf.php';

class CertificateGenerator
{
    private $template;
    private $pdf;
    private $page;

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

        return $this->pdf->getOutPDFString();
    }

    private function generateFromUploadedTemplate($filePath, array $data, array $settings)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowedPaths = [realpath(ROOT_DIR . 'runtime/uploads/certificates')];
        $allowedPaths = array_values(array_filter($allowedPaths, 'is_string'));
        if ($extension === 'pdf') {
            $this->pdf = SmartTrackPdf::newDocument($allowedPaths);
            $sourceId = $this->pdf->setImportSourceFile($filePath);
            $pageCount = $this->pdf->getSourcePageCount($sourceId);
            if ($pageCount < 1) {
                throw new RuntimeException('The uploaded certificate PDF has no pages.');
            }
            $this->pdf->addPageFromImport($sourceId, 1);
            $this->page = $this->pdf->page->getPage();
            SmartTrackPdf::selectFont($this->pdf);
        } else {
            [$this->pdf, $this->page] = SmartTrackPdf::createWithPage('L', $allowedPaths);
            $imageId = $this->pdf->image->add($filePath);
            $this->pdf->page->addContent(
                $this->pdf->image->getSetImage(
                    $imageId,
                    0,
                    0,
                    $this->page['width'],
                    $this->page['height'],
                    $this->page['height']
                ),
                $this->page['pid']
            );
        }

        $this->prepareDocument($settings);
        $this->drawConfiguredFields($data, $settings);
    }

    private function generateDesignedCertificate(array $data, array $settings)
    {
        $allowedPaths = [realpath(ROOT_DIR . 'runtime/uploads/certificates')];
        $allowedPaths = array_values(array_filter($allowedPaths, 'is_string'));
        [$this->pdf, $this->page] = SmartTrackPdf::createWithPage('L', $allowedPaths);
        $this->prepareDocument($settings);

        $accent = $this->rgb($settings['accent_color'] ?? '#dd4814');
        $border = $settings['border_style'] ?? 'double';
        if ($border !== 'none') {
            $this->pdf->page->addContent($this->pdf->graph->getRect(
                10,
                10,
                277,
                190,
                'D',
                ['all' => ['lineWidth' => 1.6, 'lineColor' => $this->rgbString($accent)]
                ]
            ));
            if ($border === 'double') {
                $this->pdf->page->addContent($this->pdf->graph->getRect(
                    14,
                    14,
                    269,
                    182,
                    'D',
                    ['all' => ['lineWidth' => 0.5, 'lineColor' => $this->rgbString($accent)]
                    ]
                ));
            }
        }

        if (!empty($settings['logo_file_path']) && is_file($settings['logo_file_path'])) {
            $this->addImage($settings['logo_file_path'], 22, 22, 28, 22);
        }

        $font = $this->font($settings['font'] ?? 'helvetica');
        $this->addText(
            $this->plain($settings['institution_name'] ?? 'LSI Training Center'),
            25,
            30,
            247,
            12,
            $font,
            'B',
            22,
            $this->rgbString($accent),
            'C'
        );

        $this->addText(
            $this->plain($settings['title_text'] ?? 'Certificate of Completion'),
            25,
            61,
            247,
            16,
            $font,
            'B',
            30,
            'rgb(45,50,60)',
            'C'
        );

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
        $this->addText($this->plain($body), 42, 91, 213, 36, $font, '', 16, 'rgb(60,60,60)', 'C', 'C');
        $this->pdf->page->addContent($this->pdf->graph->getLine(
            88,
            158,
            143,
            158,
            ['lineWidth' => 0.3, 'lineColor' => 'rgb(150,150,150)']
        ));
        $this->addText($this->plain($settings['signatory_name'] ?? ''), 78, 161, 75, 7, $font, 'B', 12, 'rgb(45,50,60)', 'C');
        $this->addText($this->plain($settings['signatory_title'] ?? ''), 78, 168, 75, 6, $font, '', 10, 'rgb(45,50,60)', 'C');
        $this->addText('Certificate No. ' . $this->plain($data['certificate_number'] ?? ''), 190, 170, 82, 5, $font, '', 8, 'rgb(45,50,60)', 'R');
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
        $pageWidth = $this->page['width'];
        $pageHeight = $this->page['height'];
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
            $this->addText(
                $this->plain((string) $values[$field]),
                $left,
                max(0, min($pageHeight - 12, $y)),
                $width,
                8,
                $this->font($settings['font'] ?? 'helvetica'),
                '',
                $fontSize,
                $this->rgbString($accent),
                strtoupper(substr($align, 0, 1))
            );
        }
        $this->drawSignatures($data['signatories'] ?? []);
    }

    private function drawSignatures(array $signatories)
    {
        $pageWidth = $this->page['width'];
        $pageHeight = $this->page['height'];
        $font = $this->font($this->template['settings']['font'] ?? 'helvetica');
        foreach ($signatories as $signatory) {
            $path = $signatory['signature_file_path'] ?? '';
            $centerX = max(28, min($pageWidth - 28, (float) ($signatory['x_position'] ?? 50) * $pageWidth / 100));
            $topY = max(8, min($pageHeight - 38, (float) ($signatory['y_position'] ?? 80) * $pageHeight / 100));
            $signatureWidth = 42;
            $signatureHeight = 18;
            if ($path !== '' && is_file($path)) {
                $this->addImage($path, $centerX - ($signatureWidth / 2), $topY, $signatureWidth, $signatureHeight);
            } else {
                $this->addText('Signature image unavailable', $centerX - 35, $topY + 5, 70, 5, $font, '', 7, 'rgb(160,70,50)', 'C');
            }
            $this->addText($this->plain($signatory['signatory_name'] ?? ''), $centerX - 35, $topY + 18, 70, 5, $font, 'B', 9, 'rgb(45,50,60)', 'C');
            $this->addText($this->plain($signatory['signatory_position'] ?? ''), $centerX - 35, $topY + 23, 70, 4, $font, '', 8, 'rgb(45,50,60)', 'C');
            $signedAt = strtotime((string) ($signatory['signed_at'] ?? ''));
            if ($signedAt) {
                $this->addText('Digitally signed on ' . date('M j, Y H:i', $signedAt), $centerX - 35, $topY + 27, 70, 4, $font, '', 6, 'rgb(45,50,60)', 'C');
            } elseif (($signatory['signed_at'] ?? '') === 'PREVIEW') {
                $this->addText('Signature preview - not signed', $centerX - 35, $topY + 27, 70, 4, $font, '', 6, 'rgb(45,50,60)', 'C');
            }
        }
    }

    private function prepareDocument(array $settings)
    {
        $this->pdf->setCreator('LSI SmartTrack');
        $this->pdf->setAuthor($this->plain($settings['institution_name'] ?? 'LSI Training Center'));
        $this->pdf->setTitle($this->plain($settings['title_text'] ?? 'Certificate of Completion'));
        $this->pdf->setSubject('Training Certificate');
    }

    private function drawQrCode($value, $x, $y, $size)
    {
        if ($value === '') {
            return;
        }
        $this->pdf->page->addContent($this->pdf->graph->getRect(
            $x,
            $y,
            $size,
            $size,
            'DF',
            ['all' => ['lineWidth' => 0, 'lineColor' => 'white', 'fillColor' => 'white']]
        ));
        $this->pdf->page->addContent($this->pdf->getBarcode(
            'QRCODE,H',
            (string) $value,
            $x,
            $y,
            $size,
            $size,
            [0, 0, 0, 0],
            ['fillColor' => 'rgb(0,0,0)']
        ));
    }

    private function font($font)
    {
        return match ($font) {
            'times' => 'dejavuserif',
            'courier' => 'dejavusansmono',
            default => 'dejavusans',
        };
    }

    private function addText($text, $x, $y, $width, $height, $font, $style, $size, $color, $alignment, $verticalAlignment = 'C')
    {
        SmartTrackPdf::selectFont($this->pdf, $font, $style, (float) $size);
        $this->pdf->page->addContent($this->pdf->color->getPdfColor($color), $this->page['pid']);
        $this->pdf->addTextCellXY(
            $text,
            $this->page['pid'],
            (float) $x,
            (float) $y,
            (float) $width,
            (float) $height,
            valign: $verticalAlignment,
            halign: $alignment,
            drawcell: false
        );
    }

    private function addImage($path, $x, $y, $width, $height)
    {
        $imageId = $this->pdf->image->add($path);
        $this->pdf->page->addContent(
            $this->pdf->image->getSetImage($imageId, $x, $y, $width, $height, $this->page['height']),
            $this->page['pid']
        );
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

    private function rgbString(array $color)
    {
        return sprintf('rgb(%d,%d,%d)', $color[0], $color[1], $color[2]);
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
