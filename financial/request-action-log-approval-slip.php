<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/finance_functions.php';

$logId = (int) ($_GET['id'] ?? 0);
$slip = finance_get_request_action_log_logistics_slip_data($pdo, $logId);

if (!$slip) {
    http_response_code(404);
    echo 'Approval slip not available for this log entry.';
    exit;
}

function approval_slip_pdf_escape(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    return $text;
}

function approval_slip_pdf_line(float $x1, float $y1, float $x2, float $y2): string
{
    return sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
}

function approval_slip_pdf_text(float $x, float $y, string $font, int $size, string $text): string
{
    return "BT\n"
        . sprintf("/%s %d Tf\n", $font, $size)
        . sprintf("%.2F %.2F Td\n", $x, $y)
        . '(' . approval_slip_pdf_escape($text) . ") Tj\n"
        . "ET\n";
}

function approval_slip_pdf_value(mixed $value): string
{
    $text = trim((string) $value);
    return $text !== '' ? $text : '-';
}

function approval_slip_pdf_build(array $slip): string
{
    $pageWidth = 595.00;
    $pageHeight = 842.00;
    $left = 52.00;
    $right = 543.00;
    $y = 790.00;

    $rows = [
        ['Request ID', approval_slip_pdf_value($slip['request_id'] ?? '')],
        ['Approval Date', approval_slip_pdf_value(date('M d, Y g:i A', strtotime((string) ($slip['approval_date'] ?? 'now'))))],
        ['Module', approval_slip_pdf_value($slip['module'] ?? '')],
        ['Description / Requested Item', approval_slip_pdf_value($slip['description'] ?? '')],
        ['Approved Amount', 'PHP ' . finance_money($slip['approved_amount'] ?? 0)],
        ['Department', approval_slip_pdf_value($slip['department'] ?? '')],
        ['Requested By', approval_slip_pdf_value($slip['requested_by'] ?? '')],
        ['Remarks', approval_slip_pdf_value($slip['remarks'] ?? '')],
        ['Generated Date', approval_slip_pdf_value(date('M d, Y g:i A'))],
    ];

    $stream = "0 G 0 g\n";
    $stream .= approval_slip_pdf_text($left, $y, 'F2', 18, 'ServiBoard');
    $stream .= approval_slip_pdf_text($left, $y - 22, 'F1', 10, 'Financial Module');
    $stream .= approval_slip_pdf_text($left, $y - 54, 'F2', 18, 'Request Approval Slip');
    $stream .= approval_slip_pdf_line($left, $y - 68, $right, $y - 68);

    $y -= 108;
    foreach ($rows as [$label, $value]) {
        $stream .= approval_slip_pdf_text($left, $y, 'F2', 10, $label);
        $stream .= approval_slip_pdf_text($left + 180, $y, 'F1', 10, $value);
        $stream .= approval_slip_pdf_line($left, $y - 10, $right, $y - 10);
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

$pdf = approval_slip_pdf_build($slip);
$filename = 'request_approval_slip_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($slip['request_id'] ?? ('log_' . $logId))) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
