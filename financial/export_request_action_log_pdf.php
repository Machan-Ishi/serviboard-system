<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/require_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/finance_functions.php';

$rows = finance_get_request_action_logs($pdo, 1000);

function request_log_pdf_escape(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    return $text;
}

function request_log_pdf_text(string $text, int $limit = 40): string
{
    $plain = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($plain === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($plain) > $limit) {
            $plain = mb_substr($plain, 0, max(1, $limit - 1)) . '…';
        }
    } elseif (strlen($plain) > $limit) {
        $plain = substr($plain, 0, max(1, $limit - 3)) . '...';
    }

    return $plain;
}

function request_log_pdf_currency(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return '-';
    }

    return 'PHP ' . number_format((float) $amount, 2);
}

function request_log_pdf_line(float $x1, float $y1, float $x2, float $y2): string
{
    return sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
}

function request_log_pdf_cell(float $x, float $y, string $font, int $size, string $text): string
{
    return "BT\n"
        . sprintf("/%s %d Tf\n", $font, $size)
        . sprintf("%.2F %.2F Td\n", $x, $y)
        . '(' . request_log_pdf_escape($text) . ") Tj\n"
        . "ET\n";
}

function request_log_pdf_build(array $rows): string
{
    $pageWidth = 842.00;
    $pageHeight = 595.00;
    $marginLeft = 28.00;
    $marginRight = 28.00;
    $topY = 560.00;
    $bottomY = 36.00;
    $rowHeight = 24.00;
    $usableWidth = $pageWidth - $marginLeft - $marginRight;

    $columns = [
        ['label' => 'Approval Date', 'width' => 112.00, 'limit' => 22],
        ['label' => 'Module', 'width' => 70.00, 'limit' => 12],
        ['label' => 'Action', 'width' => 74.00, 'limit' => 10],
        ['label' => 'Description', 'width' => 220.00, 'limit' => 34],
        ['label' => 'Amount', 'width' => 92.00, 'limit' => 18],
        ['label' => 'Remarks', 'width' => $usableWidth - (112.00 + 70.00 + 74.00 + 220.00 + 92.00), 'limit' => 34],
    ];

    $headerRenderer = static function (float $y) use ($marginLeft, $usableWidth, $columns): string {
        $content = "0.72 G 0.72 g 0.8 w\n";
        $content .= request_log_pdf_line($marginLeft, $y + 8, $marginLeft + $usableWidth, $y + 8);
        $content .= request_log_pdf_line($marginLeft, $y - 12, $marginLeft + $usableWidth, $y - 12);

        $x = $marginLeft + 8;
        foreach ($columns as $column) {
            $content .= request_log_pdf_cell($x, $y - 3, 'F2', 9, $column['label']);
            $x += $column['width'];
        }

        return $content;
    };

    $pages = [];
    $pageNumber = 1;
    $y = $topY;
    $stream = "0 G 0 g\n";
    $stream .= request_log_pdf_cell($marginLeft, $y, 'F2', 16, 'Request Action Log');
    $stream .= request_log_pdf_cell($marginLeft, $y - 18, 'F1', 9, 'Generated on ' . date('M d, Y g:i A'));
    $stream .= request_log_pdf_cell($pageWidth - 95, $y - 18, 'F1', 9, 'Page ' . $pageNumber);
    $y -= 42;
    $stream .= $headerRenderer($y);
    $y -= 28;

    foreach ($rows as $row) {
        if (($y - $rowHeight) < $bottomY) {
            $pages[] = $stream;
            $pageNumber++;
            $y = $topY;
            $stream = "0 G 0 g\n";
            $stream .= request_log_pdf_cell($marginLeft, $y, 'F2', 16, 'Request Action Log');
            $stream .= request_log_pdf_cell($marginLeft, $y - 18, 'F1', 9, 'Generated on ' . date('M d, Y g:i A'));
            $stream .= request_log_pdf_cell($pageWidth - 95, $y - 18, 'F1', 9, 'Page ' . $pageNumber);
            $y -= 42;
            $stream .= $headerRenderer($y);
            $y -= 28;
        }

        $values = [
            request_log_pdf_text(date('M d, Y g:i A', strtotime((string) ($row['approved_at'] ?? 'now'))), 22),
            request_log_pdf_text((string) ($row['module'] ?? '-'), 12),
            request_log_pdf_text((string) ($row['action'] ?? '-'), 10),
            request_log_pdf_text((string) ($row['description'] ?? '-'), 34),
            request_log_pdf_text(request_log_pdf_currency($row['amount'] ?? null), 18),
            request_log_pdf_text((string) ($row['remarks'] ?? '-'), 34),
        ];

        $stream .= "0.20 G 0.6 w\n";
        $stream .= request_log_pdf_line($marginLeft, $y - 8, $marginLeft + $usableWidth, $y - 8);

        $x = $marginLeft + 8;
        foreach ($columns as $index => $column) {
            $font = $index === 4 ? 'F2' : 'F1';
            $stream .= request_log_pdf_cell($x, $y, $font, 9, $values[$index]);
            $x += $column['width'];
        }

        $y -= $rowHeight;
    }

    if ($stream !== '') {
        $pages[] = $stream;
    }

    $objects = [];
    $pageObjectNumbers = [];
    $contentObjectNumbers = [];

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = '';
    $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

    $nextObject = 5;
    foreach ($pages as $pageStream) {
        $contentObjectNumber = $nextObject++;
        $pageObjectNumber = $nextObject++;
        $contentObjectNumbers[] = $contentObjectNumber;
        $pageObjectNumbers[] = $pageObjectNumber;

        $objects[$contentObjectNumber] = "<< /Length " . strlen($pageStream) . " >>\nstream\n" . $pageStream . "endstream";
        $objects[$pageObjectNumber] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents {$contentObjectNumber} 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>";
    }

    $kids = implode(' ', array_map(static fn (int $n): string => $n . ' 0 R', $pageObjectNumbers));
    $objects[2] = "<< /Type /Pages /Kids [ {$kids} ] /Count " . count($pageObjectNumbers) . " >>";

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $maxObject = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObject; $i++) {
        $offset = $offsets[$i] ?? 0;
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

$pdf = request_log_pdf_build($rows);
$filename = 'request_action_log_' . date('Ymd_His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
