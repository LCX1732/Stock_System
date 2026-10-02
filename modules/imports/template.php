<?php

declare(strict_types=1);

/**
 * AL - Parts Import Template
 *
 * ดาวน์โหลดไฟล์ Excel Template สำหรับนำเข้าข้อมูลอะไหล่
 *
 * คอลัมน์ที่ระบบรองรับ:
 * 1. รหัสอะไหล่
 * 2. ชื่ออะไหล่
 * 3. หมวดหมู่
 * 4. หน่วยนับ
 * 5. ขั้นต่ำ
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/auth.php';

if (function_exists('require_login')) {
    require_login();
}

/**
 * Composer autoload
 */
$autoloadCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

$autoloadLoaded = false;

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded || !class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    http_response_code(500);
    exit('ไม่พบ PhpSpreadsheet กรุณาตรวจสอบ Composer / vendor/autoload.php');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();

/**
 * =========================================================
 * SHEET 1 : ข้อมูลอะไหล่
 * =========================================================
 */
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('ข้อมูลอะไหล่');

$headers = [
    'รหัสอะไหล่',
    'ชื่ออะไหล่',
    'หมวดหมู่',
    'หน่วยนับ',
    'ขั้นต่ำ',
];

$sheet->fromArray($headers, null, 'A1');

/**
 * พื้นที่กรอกข้อมูลล่วงหน้า
 * สามารถพิมพ์ข้อมูลลงตารางนี้ได้เลย
 */
$blankRows = 30;

for ($row = 2; $row <= $blankRows + 1; $row++) {
    $sheet->fromArray(['', '', '', '', null], null, 'A' . $row);
}

/**
 * Header style
 */
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '2F4156'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'D9E2EC'],
        ],
    ],
];

$sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
$sheet->getRowDimension(1)->setRowHeight(25);

/**
 * Sample row style
 */
$sheet->getStyle('A2:E31')->applyFromArray([
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'E5E7EB'],
        ],
    ],
]);

/**
 * Number format
 */
$sheet->getStyle('E2:E31')
    ->getNumberFormat()
    ->setFormatCode('0');

/**
 * Column widths
 */
$widths = [
    'A' => 22,
    'B' => 36,
    'C' => 24,
    'D' => 18,
    'E' => 14,
];

foreach ($widths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

/**
 * Freeze header
 */
$sheet->freezePane('A2');

/**
 * =========================================================
 * คำแนะนำใต้ตาราง
 * =========================================================
 */
$guideStartRow = 34;

$sheet->mergeCells("A{$guideStartRow}:E{$guideStartRow}");
$sheet->setCellValue("A{$guideStartRow}", 'คำแนะนำการกรอกข้อมูล');

$sheet->getStyle("A{$guideStartRow}:E{$guideStartRow}")->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 12,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '567C8D'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_LEFT,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$instructions = [
    ['1.', 'รหัสอะไหล่', 'กรอกรหัสอะไหล่ที่ต้องการนำเข้า และควรไม่ซ้ำกับข้อมูลในระบบ'],
    ['2.', 'ชื่ออะไหล่', 'กรอกชื่ออะไหล่'],
    ['3.', 'หมวดหมู่', 'กรอกชื่อหมวดหมู่ หากไม่มีในระบบ ระบบสามารถสร้าง Master Data ให้ตอนนำเข้า'],
    ['4.', 'หน่วยนับ', 'กรอกหน่วย เช่น ชิ้น, กล่อง, ชุด'],
    ['5.', 'ขั้นต่ำ', 'กรอกจำนวนเต็มตั้งแต่ 0 ขึ้นไป'],
    ['6.', 'ก่อนนำเข้า', 'ตรวจสอบข้อมูลให้ครบถ้วน แล้วบันทึกไฟล์เป็น .xlsx ก่อนอัปโหลด'],
    ['7.', 'ไฟล์ที่รองรับ', '.xlsx, .xls และ .csv ขนาดไม่เกิน 20 MB'],
];

$sheet->fromArray($instructions, null, 'A35');

$sheet->getStyle('A35:C41')->applyFromArray([
    'alignment' => [
        'vertical' => Alignment::VERTICAL_TOP,
        'wrapText' => true,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'D9E2EC'],
        ],
    ],
]);

$sheet->getStyle('A35:A41')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->getColumnDimension('A')->setWidth(10);
$sheet->getColumnDimension('B')->setWidth(24);
$sheet->getColumnDimension('C')->setWidth(85);

$sheet->getRowDimension($guideStartRow)->setRowHeight(24);

/**
 * ตั้งค่าหน้ากระดาษสำหรับพิมพ์
 */
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

$sheet->getPageMargins()
    ->setTop(0.35)
    ->setRight(0.35)
    ->setBottom(0.35)
    ->setLeft(0.35);

$sheet->getPageSetup()->setPrintArea('A1:E41');



/**
 * Auto filter
 */
$sheet->setAutoFilter('A1:E31');

/**
 * =========================================================
 * SHEET 2 : คำแนะนำ
 * =========================================================
 */
$guide = $spreadsheet->createSheet();
$guide->setTitle('คำแนะนำ');

$guideRows = [
    ['หัวข้อ', 'รายละเอียด'],
    ['รหัสอะไหล่', 'รหัสประจำอะไหล่ ควรไม่ซ้ำกันในระบบ'],
    ['ชื่ออะไหล่', 'ชื่อของอะไหล่'],
    ['หมวดหมู่', 'ชื่อหมวดหมู่ของอะไหล่ หากไม่มี ระบบสามารถสร้าง Master Data ให้ตอนนำเข้าได้'],
    ['หน่วยนับ', 'หน่วยของอะไหล่ เช่น ชิ้น, กล่อง, ชุด'],
    ['ขั้นต่ำ', 'จำนวนขั้นต่ำของสต๊อก ต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป'],
    ['ไฟล์ที่รองรับ', '.xlsx, .xls และ .csv'],
    ['ขนาดไฟล์สูงสุด', '20 MB'],
    ['หมายเหตุ', 'ห้ามเปลี่ยนชื่อหัวคอลัมน์ของชีตข้อมูลอะไหล่'],
    ['หมายเหตุ', 'สามารถลบแถวตัวอย่างออกก่อนนำเข้าได้'],
];

$guide->fromArray($guideRows, null, 'A1');

$guide->getStyle('A1:B1')->applyFromArray($headerStyle);

$guide->getStyle('A1:B10')->applyFromArray([
    'alignment' => [
        'vertical' => Alignment::VERTICAL_TOP,
        'wrapText' => true,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'E5E7EB'],
        ],
    ],
]);

$guide->getColumnDimension('A')->setWidth(24);
$guide->getColumnDimension('B')->setWidth(90);

$guide->freezePane('A2');

/**
 * =========================================================
 * DOWNLOAD
 * =========================================================
 */
$filename = 'AL_parts_import_template_' . date('Ymd_His') . '.xlsx';

if (ob_get_length() !== false && ob_get_length() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
exit;
