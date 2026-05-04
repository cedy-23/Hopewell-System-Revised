<?php
session_start();

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->query("SET time_zone = '+08:00'");

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// ================= DURATION FUNCTION =================
function formatDuration($start, $end) {
    if (!$start || !$end) return '-';

    $start_ts = strtotime($start);
    $end_ts = strtotime($end);

    if (!$start_ts || !$end_ts || $end_ts < $start_ts) return '-';

    $diff = $end_ts - $start_ts;

    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $mins = floor(($diff % 3600) / 60);

    $parts = [];
    if ($days > 0) $parts[] = "$days d";
    if ($hours > 0) $parts[] = "$hours h";
    if ($mins > 0) $parts[] = "$mins m";

    return !empty($parts) ? implode(' ', $parts) : '0 m';
}

// ================= SPREADSHEET =================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// ================= HEADER =================
$headers = [
    'Control No',
    'Sender',
    'Department',
    'Title',
    'Issue',
    'Status',
    'Queue No',
    'Assigned Staff',
    'Manager',
    'Attempts',
    'Rating',
    'Reason',
    'Comments',
    'Duration',
    'Created At',
    'Ended At'
];

$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

// ================= HEADER STYLE =================
$sheet->getStyle('A1:P1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '16A34A']
    ],
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN]
    ]
]);

// ================= DATA =================
$sql = "
    SELECT 
        t.control_number,
        u.name AS sender,
        d.department_name,
        t.title,
        t.issue,
        t.status,
        t.queue_number,
        s.name AS staff_name,
        m.name AS manager_name,
        t.accept_attempts,
        tf.rating,
        tf.reason_title,
        tf.comments,
        t.created_at,
        t.ended_at
    FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    LEFT JOIN users s ON t.assigned_staff_id = s.user_id
    LEFT JOIN users m ON t.manager_id = m.user_id
    JOIN departments d ON t.department_id = d.department_id
    LEFT JOIN ticket_feedback tf ON tf.ticket_id = t.ticket_id
    ORDER BY t.ticket_id DESC
";

$result = $conn->query($sql);

if (!$result) {
    die("Query failed: " . $conn->error);
}

$rowNum = 2;

// ================= ROW DATA =================
while ($row = $result->fetch_assoc()) {

    $duration = formatDuration($row['created_at'], $row['ended_at']);

    $sheet->setCellValue("A$rowNum", $row['control_number']);
    $sheet->setCellValue("B$rowNum", $row['sender']);
    $sheet->setCellValue("C$rowNum", $row['department_name']);
    $sheet->setCellValue("D$rowNum", $row['title']);
    $sheet->setCellValue("E$rowNum", $row['issue']);
    $sheet->setCellValue("F$rowNum", ucfirst($row['status']));
    $sheet->setCellValue("G$rowNum", $row['queue_number'] ?? '-');
    $sheet->setCellValue("H$rowNum", $row['staff_name'] ?? 'Unassigned');
    $sheet->setCellValue("I$rowNum", $row['manager_name'] ?? '-');
    $sheet->setCellValue("J$rowNum", $row['accept_attempts']);
    $sheet->setCellValue("K$rowNum", $row['rating'] ?? '-');
    $sheet->setCellValue("L$rowNum", $row['reason_title'] ?? '-');
    $sheet->setCellValue("M$rowNum", $row['comments'] ?? '-');

    // ✅ DURATION ADDED
    $sheet->setCellValue("N$rowNum", $duration);

    $sheet->setCellValue("O$rowNum", $row['created_at']);
    $sheet->setCellValue("P$rowNum", $row['ended_at'] ?? 'N/A');

    $rowNum++;
}

// ================= AUTO WIDTH =================
foreach (range('A','P') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ================= OUTPUT =================
$filename = "general_report_" . date('Y-m-d_His') . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>