<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->query("SET time_zone = '+08:00'");

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// ===== HEADER ROW =====
$headers = [
    'A1' => 'Control No',
    'B1' => 'Sender',
    'C1' => 'Department',
    'D1' => 'Title',
    'E1' => 'Status',
    'F1' => 'Created At',
    'G1' => 'Ended At'
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

// ===== HEADER STYLE =====
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '343A40'], // dark gray
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
];

$sheet->getStyle('A1:G1')->applyFromArray($headerStyle);

// ===== DATA =====
$sql = "
    SELECT 
        t.control_number,
        u.name AS user_name,
        d.department_name AS department_name,
        t.title,
        t.status,
        t.created_at,
        t.ended_at
    FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    JOIN departments d ON t.department_id = d.department_id
    ORDER BY t.ticket_id ASC
";

$result = $conn->query($sql);
$rowNum = 2;

while ($row = $result->fetch_assoc()) {
    $sheet->setCellValue("A$rowNum", $row['control_number']);
    $sheet->setCellValue("B$rowNum", $row['user_name']);
    $sheet->setCellValue("C$rowNum", $row['department_name']);
    $sheet->setCellValue("D$rowNum", $row['title']);
    $sheet->setCellValue("E$rowNum", ucfirst($row['status']));
    $sheet->setCellValue("F$rowNum", $row['created_at']);
    $sheet->setCellValue("G$rowNum", $row['ended_at'] ?? 'N/A');
    $rowNum++;
}

// ===== AUTO WIDTH =====
foreach (range('A', 'J') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ===== DOWNLOAD =====
$filename = 'tickets_report_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>