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

// ===== Function to format seconds into days, hours, minutes =====
function formatDuration($minutes) {
    if ($minutes === null || $minutes == 0) return '-';

    $days = floor($minutes / 1440); // 1 day = 1440 minutes
    $hours = floor(($minutes % 1440) / 60);
    $mins = $minutes % 60;

    $parts = [];
    if ($days > 0) $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
    if ($hours > 0) $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
    if ($mins > 0) $parts[] = $mins . ' minute' . ($mins > 1 ? 's' : '');

    return implode(', ', $parts);
}

// ===== Spreadsheet setup =====
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// ===== HEADER ROW =====
$headers = [
    'A1' => 'Ticket Number',
    'B1' => 'Department',
    'C1' => 'Sender Name',
    'D1' => 'Title',
    'E1' => 'Issue',
    'F1' => 'Status',
    'G1' => 'Duration',
    'H1' => 'Rating',
    'I1' => 'Reason',
    'J1' => 'Comments',
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
        'startColor' => ['rgb' => '343A40'],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
];

$sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

// ===== DATA =====
$sql = "
    SELECT 
        t.control_number,
        d.department_name AS department_name,
        t.title,
        t.issue,
        t.status,
        t.time_spent,              
        tf.rating,                 
        tf.reason_title,
        tf.comments,
        u.name AS user_name,          
        m.name AS manager_name,       
        t.created_at
    FROM tickets t
    JOIN users u 
        ON t.user_id = u.user_id
    LEFT JOIN users m 
        ON t.manager_id = m.user_id
    JOIN departments d 
        ON t.department_id = d.department_id
    LEFT JOIN ticket_feedback tf       
        ON tf.ticket_id = t.ticket_id  
    ORDER BY t.ticket_id ASC;
";

$result = $conn->query($sql);
$rowNum = 2;

while ($row = $result->fetch_assoc()) {
    $sheet->setCellValue("A$rowNum", $row['control_number']);
    $sheet->setCellValue("B$rowNum", $row['department_name']);
    $sheet->setCellValue("C$rowNum", $row['user_name']);
    $sheet->setCellValue("D$rowNum", $row['title']);
    $sheet->setCellValue("E$rowNum", $row['issue']);
    $sheet->setCellValue("F$rowNum", ucfirst(str_replace('_', ' ', $row['status'])));
    
    // ===== Convert raw seconds to days, hours, minutes =====
    $formatted_time = formatDuration($row['time_spent']);
    $sheet->setCellValue("G$rowNum", $formatted_time);

    $sheet->setCellValue("H$rowNum", $row['rating']);
    $sheet->setCellValue("I$rowNum", $row['reason_title']);
    $sheet->setCellValue("J$rowNum", $row['comments']);
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
