<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';
require_once APP_DIR . 'libraries/SmartTrackPdf.php';

class Reports extends Base
{
    public function before_action()
    {
        parent::before_action();
    }

    public function index()
    {
        if (!$this->requireUser() || !$this->canAccessReports($this->user)) {
            return $this->deny('Reports are available to administrator and staff accounts.');
        }

        $data = [
            'title' => 'Reports',
            'user' => $this->user,
            'navigation' => $this->navigationForUser(),
            'app_url' => $this->url(''),
            'csrf' => $this->session->userdata('csrf'),
            'reports' => $this->getReportsModel()->availableReports(),
            'programs' => $this->recordModel->options('programs', $this->user),
            'classes' => $this->recordModel->options('classes', $this->user),
        ];

        $this->call->view('reports', $data);
    }

    public function generate()
    {
        if (!$this->requireUser() || !$this->canAccessReports($this->user)) {
            return $this->deny('Reports are available to administrator and staff accounts.');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['message' => 'Method not allowed.'], 405);
        }

        if (!$this->validCsrf()) {
            return $this->jsonResponse(['message' => 'Invalid CSRF token.'], 403);
        }

        $reportType = trim((string) $this->input('report_type'));
        $format = trim((string) $this->input('format'));
        $filters = [
            'start_date' => trim((string) $this->input('start_date')),
            'end_date' => trim((string) $this->input('end_date')),
            'program_id' => trim((string) $this->input('program_id')),
            'class_id' => trim((string) $this->input('class_id')),
            'status' => trim((string) $this->input('status')),
            'fee_kind' => trim((string) $this->input('fee_kind')),
            'method' => trim((string) $this->input('method')),
        ];

        $availableReports = $this->getReportsModel()->availableReports();
        if (!isset($availableReports[$reportType])) {
            return $this->jsonResponse(['message' => 'Invalid report type.'], 400);
        }

        if (!in_array($format, $availableReports[$reportType]['formats'])) {
            return $this->jsonResponse(['message' => 'Invalid format for this report type.'], 400);
        }

        $data = $this->getReportsModel()->getReportData($reportType, $filters);

        if ($format === 'pdf') {
            $this->generatePDF($reportType, $data, $filters);
        } else {
            $this->generateCSV($reportType, $data, $filters);
        }
    }

    private function generatePDF($reportType, $data, array $filters)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $availableReports = $this->getReportsModel()->availableReports();

        $pdf = SmartTrackPdf::create('L');
        $pdf->setTitle('SmartTrack ' . $availableReports[$reportType]['name']);
        $pdf->setAuthor('LSI SmartTrack');
        $pdf->setCreator('LSI SmartTrack System');

        $html = '<h1 style="color: #173a60;">' . htmlspecialchars($availableReports[$reportType]['name'], ENT_QUOTES, 'UTF-8') . '</h1>';
        $html .= '<p style="color: #5d6f82; font-size: 12px;">';
        $html .= 'Report Period: ' . htmlspecialchars($filters['start_date'] . ' to ' . $filters['end_date'], ENT_QUOTES, 'UTF-8') . '<br>';
        $html .= 'Generated: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $html .= '</p>';

        if (!empty($data)) {
            $html .= '<table border="1" cellpadding="8" cellspacing="0" style="width: 100%; border-collapse: collapse; font-size: 10px;">';
            $html .= '<thead style="background-color: #173a60; color: white;"><tr>';

            $headers = array_keys($data[0]);
            foreach ($headers as $header) {
                $html .= '<th style="padding: 8px; text-align: left;">' . htmlspecialchars(str_replace('_', ' ', $header), ENT_QUOTES, 'UTF-8') . '</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($data as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td style="padding: 6px; border-bottom: 1px solid #e1e8f1;">' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '<p style="font-size: 10px; color: #5d6f82; margin-top: 20px;">Total records: ' . count($data) . '</p>';
        } else {
            $html .= '<p style="color: #c4372c;">No data found for the selected filters.</p>';
        }

        $filename = 'smarttrack-' . $reportType . '-report-' . bin2hex(random_bytes(8)) . '.pdf';
        $pdf->setPDFFilename($filename);
        $pdf->addHTMLCell(html: $html, posx: 15, posy: 15);
        $pdf->downloadPDF($pdf->getOutPDFString());
        exit;
    }

    private function generateCSV($reportType, $data, array $filters)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="smarttrack-' . $reportType . '-report-' . $filters['start_date'] . '-to-' . $filters['end_date'] . '.csv"');

        $output = fopen('php://output', 'w');

        $safeCell = static function ($value) {
            $value = (string) $value;
            return preg_match('/^\s*[=+\-@]/u', $value) ? '"' . $value . '"' : $value;
        };

        if (!empty($data)) {
            $headers = array_keys($data[0]);
            $formattedHeaders = array_map(
                static function ($header) {
                    return ucwords(str_replace('_', ' ', $header));
                },
                $headers
            );
            fputcsv($output, $formattedHeaders);

            foreach ($data as $row) {
                fputcsv($output, array_map($safeCell, $row));
            }
        } else {
            fputcsv($output, ['No data found for the selected filters.']);
        }

        fclose($output);
        exit;
    }

    private function canAccessReports(array $user)
    {
        return $this->inRoleGroup('administrative_staff', $user);
    }
}
