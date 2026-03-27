<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/finance_functions.php';

$logId = (int) ($_GET['id'] ?? 0);
$summary = finance_get_request_action_log_hr_summary_data($pdo, $logId);

if (!$summary) {
    http_response_code(404);
    echo 'HR summary is not available for this log entry.';
    exit;
}

function hr_summary_pdf_escape(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    return $text;
}

function hr_summary_pdf_line(float $x1, float $y1, float $x2, float $y2): string
{
    return sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
}

function hr_summary_pdf_text(float $x, float $y, string $font, int $size, string $text): string
{
    return "BT\n"
        . sprintf("/%s %d Tf\n", $font, $size)
        . sprintf("%.2F %.2F Td\n", $x, $y)
        . '(' . hr_summary_pdf_escape($text) . ") Tj\n"
        . "ET\n";
}

function hr_summary_pdf_value(mixed $value): string
{
    $text = trim((string) $value);
    return $text !== '' ? $text : '-';
}

function hr_summary_pdf_build(array $summary): string
{
    $pageWidth = 595.00;
    $pageHeight = 842.00;
    $left = 52.00;
    $right = 543.00;
    $y = 790.00;

    $rows = [
        ['Request ID', hr_summary_pdf_value($summary['request_id'] ?? '')],
        ['Approval Date', hr_summary_pdf_value(date('M d, Y g:i A', strtotime((string) ($summary['approval_date'] ?? 'now'))))],
        ['Employee Name', hr_summary_pdf_value($summary['employee_name'] ?? '')],
        ['Request Type', hr_summary_pdf_value($summary['request_type'] ?? '')],
        ['Description', hr_summary_pdf_value($summary['description'] ?? '')],
        ['Amount', 'PHP ' . finance_money($summary['amount'] ?? 0)],
        ['Department', hr_summary_pdf_value($summary['department'] ?? '')],
        ['Remarks', hr_summary_pdf_value($summary['remarks'] ?? '')],
        ['Generated Date', hr_summary_pdf_value(date('M d, Y g:i A'))],
    ];

    $stream = "0 G 0 g\n";
    $stream .= hr_summary_pdf_text($left, $y, 'F2', 18, 'ServiBoard');
    $stream .= hr_summary_pdf_text($left, $y - 22, 'F1', 10, 'Financial Module');
    $stream .= hr_summary_pdf_text($left, $y - 54, 'F2', 18, 'HR Request Summary');
    $stream .= hr_summary_pdf_line($left, $y - 68, $right, $y - 68);

    $y -= 108;
    foreach ($rows as [$label, $value]) {
        $stream .= hr_summary_pdf_text($left, $y, 'F2', 10, $label);
        $stream .= hr_summary_pdf_text($left + 180, $y, 'F1', 10, $value);
        $stream .= hr_summary_pdf_line($left, $y - 10, $right, $y - 10);
        $y -= 34;
    }

    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [ 5 0 R ] /Count 1 >>";
    $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    $objects[5] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents 6 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>";
    $objects[6] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 7\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= 6; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

$pdf = hr_summary_pdf_build($summary);
$filename = 'hr_request_summary_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($summary['request_id'] ?? ('log_' . $logId))) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
