<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/finance_functions.php';

$logId = (int) ($_GET['id'] ?? 0);
$receipt = finance_get_request_action_log_receipt_data($pdo, $logId);

if (!$receipt) {
    http_response_code(404);
    echo 'Receipt not available for this log entry.';
    exit;
}

function receipt_pdf_escape(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    return $text;
}

function receipt_pdf_line(float $x1, float $y1, float $x2, float $y2): string
{
    return sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
}

function receipt_pdf_text(float $x, float $y, string $font, int $size, string $text): string
{
    return "BT\n"
        . sprintf("/%s %d Tf\n", $font, $size)
        . sprintf("%.2F %.2F Td\n", $x, $y)
        . '(' . receipt_pdf_escape($text) . ") Tj\n"
        . "ET\n";
}

function receipt_pdf_value(mixed $value): string
{
    $text = trim((string) $value);
    return $text !== '' ? $text : '-';
}

function receipt_pdf_build(array $receipt): string
{
    $pageWidth = 595.00;
    $pageHeight = 842.00;
    $left = 52.00;
    $right = 543.00;
    $y = 790.00;

    $rows = [
        ['Reference / Request ID', receipt_pdf_value($receipt['reference'] ?? '')],
        ['Approval Date', receipt_pdf_value(date('M d, Y g:i A', strtotime((string) ($receipt['approval_date'] ?? 'now'))))],
        ['Module', receipt_pdf_value($receipt['module'] ?? '')],
        ['Description', receipt_pdf_value($receipt['description'] ?? '')],
        ['Amount Paid', 'PHP ' . finance_money($receipt['amount_paid'] ?? 0)],
        ['Remarks', receipt_pdf_value($receipt['remarks'] ?? '')],
        ['Payer / Source', receipt_pdf_value($receipt['payer_source'] ?? '')],
        ['Generated Date', receipt_pdf_value(date('M d, Y g:i A'))],
    ];

    $stream = "0 G 0 g\n";
    $stream .= receipt_pdf_text($left, $y, 'F2', 18, 'ServiBoard');
    $stream .= receipt_pdf_text($left, $y - 22, 'F1', 10, 'Financial Module');
    $stream .= receipt_pdf_text($left, $y - 54, 'F2', 20, 'Receipt');
    $stream .= receipt_pdf_line($left, $y - 68, $right, $y - 68);

    $y -= 108;
    foreach ($rows as [$label, $value]) {
        $stream .= receipt_pdf_text($left, $y, 'F2', 10, $label);
        $stream .= receipt_pdf_text($left + 170, $y, 'F1', 10, $value);
        $stream .= receipt_pdf_line($left, $y - 10, $right, $y - 10);
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

$pdf = receipt_pdf_build($receipt);
$filename = 'receipt_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($receipt['reference'] ?? ('log_' . $logId))) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
