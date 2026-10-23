<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once ROOT_DIR . 'vendor/autoload.php';

class CertificateGenerator
{
    private $config;
    private $pdf;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'institution_name' => 'LSI Training Center',
            'logo_path' => '',
            'signatory_name' => 'Training Director',
            'signatory_designation' => 'Director',
            'certificate_title' => 'Certificate of Completion',
            'certificate_subtitle' => 'OF COMPLETION',
            'template_color' => '#0354ce',
        ], $config);
    }

    public function generate(array $data)
    {
        $this->pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

        $this->pdf->SetCreator('LSI SmartTrack');
        $this->pdf->SetAuthor($this->config['institution_name']);
        $this->pdf->SetTitle('Certificate of Completion');
        $this->pdf->SetSubject('Training Certificate');

        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->SetAutoPageBreak(true, 15);

        $this->pdf->AddPage();

        $this->drawBorder();
        $this->drawHeader();
        $this->drawTitle();
        $this->drawRecipient($data);
        $this->drawProgram($data);
        $this->drawCompletion($data);
        $this->drawFooter($data);
        $this->drawSignature();
        $this->drawQRCode($data);

        return $this->pdf->Output('', 'S');
    }

    private function drawBorder()
    {
        $this->pdf->SetDrawColor(200, 200, 200);
        $this->pdf->SetLineWidth(2);
        $this->pdf->Rect(10, 10, 277, 197, 'D');

        $this->pdf->SetDrawColor(221, 72, 20);
        $this->pdf->SetLineWidth(1);
        $this->pdf->Rect(13, 13, 271, 191, 'D');
    }

    private function drawHeader()
    {
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->SetTextColor($this->config['template_color']);
        $this->pdf->Cell(0, 15, $this->config['institution_name'], 0, 1, 'C');

        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 5, $this->config['certificate_title'], 0, 1, 'C');
    }

    private function drawTitle()
    {
        $this->pdf->Ln(10);
        $this->pdf->SetFont('times', 'B', 36);
        $this->pdf->SetTextColor(50, 50, 50);
        $this->pdf->Cell(0, 20, 'CERTIFICATE', 0, 1, 'C');

        $this->pdf->SetFont('times', 'B', 18);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 10, $this->config['certificate_subtitle'], 0, 1, 'C');
    }

    private function drawRecipient(array $data)
    {
        $this->pdf->Ln(15);
        $this->pdf->SetFont('helvetica', '', 14);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 8, 'This is to certify that', 0, 1, 'C');

        $this->pdf->Ln(5);
        $this->pdf->SetFont('times', 'B', 28);
        $this->pdf->SetTextColor($this->config['template_color']);
        $this->pdf->Cell(0, 15, $data['trainee_name'], 0, 1, 'C');
    }

    private function drawProgram(array $data)
    {
        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', '', 14);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 8, 'has successfully completed the training program', 0, 1, 'C');

        $this->pdf->Ln(5);
        $this->pdf->SetFont('times', 'B', 22);
        $this->pdf->SetTextColor(50, 50, 50);
        $this->pdf->Cell(0, 12, $data['program_name'], 0, 1, 'C');

        if (!empty($data['class_name'])) {
            $this->pdf->Ln(5);
            $this->pdf->SetFont('helvetica', '', 14);
            $this->pdf->SetTextColor(100, 100, 100);
            $this->pdf->Cell(0, 8, 'Class: ' . $data['class_name'], 0, 1, 'C');
        }
    }

    private function drawCompletion(array $data)
    {
        $this->pdf->Ln(15);
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 6, 'Completion Date: ' . date('F j, Y', strtotime($data['completion_date'])), 0, 1, 'C');

        if (!empty($data['attendance_percentage'])) {
            $this->pdf->Cell(0, 6, 'Attendance: ' . number_format($data['attendance_percentage'], 1) . '%', 0, 1, 'C');
        }
    }

    private function drawFooter(array $data)
    {
        $this->pdf->Ln(20);
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->SetTextColor(150, 150, 150);
        $this->pdf->Cell(0, 5, 'Certificate Number: ' . $data['certificate_number'], 0, 1, 'C');
        $this->pdf->Cell(0, 5, 'Date of Issue: ' . date('F j, Y', strtotime($data['issue_date'])), 0, 1, 'C');
    }

    private function drawSignature()
    {
        $this->pdf->Ln(15);
        $this->pdf->SetDrawColor(200, 200, 200);
        $this->pdf->SetLineWidth(0.5);

        $this->pdf->Line(40, 150, 100, 150);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->SetTextColor(50, 50, 50);
        $this->pdf->Cell(40, 8, $this->config['signatory_name'], 0, 0, 'C');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->Cell(0, 8, $this->config['signatory_designation'], 0, 1, 'L');
    }

    private function drawQRCode(array $data)
    {
        $qrData = $data['qr_code'];
        $style = [
            'border' => false,
            'vpadding' => 'auto',
            'hpadding' => 'auto',
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
            'module_width' => 2,
            'module_height' => 2
        ];

        $this->pdf->write2DBarcode($qrData, 'QRCODE,M', 220, 120, 40, 40, $style);
    }
}
