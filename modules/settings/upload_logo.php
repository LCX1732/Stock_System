<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

require_login();
require_permission('settings.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /AL/modules/settings/index.php');
    exit;
}

$conn = db();

try {

    /* =========================================================
       FILE
       ========================================================= */

    if (
        !isset($_FILES['company_logo']) ||
        !is_array($_FILES['company_logo'])
    ) {
        throw new RuntimeException('ไม่พบไฟล์ Logo');
    }

    $file = $_FILES['company_logo'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {

        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE =>
                'ไฟล์มีขนาดใหญ่เกิน upload_max_filesize ของ PHP',

            UPLOAD_ERR_FORM_SIZE =>
                'ไฟล์มีขนาดใหญ่เกินกำหนดของฟอร์ม',

            UPLOAD_ERR_PARTIAL =>
                'ไฟล์ถูกอัปโหลดมาไม่ครบ',

            UPLOAD_ERR_NO_FILE =>
                'กรุณาเลือกไฟล์ Logo',

            UPLOAD_ERR_NO_TMP_DIR =>
                'ไม่พบโฟลเดอร์ temporary ของ PHP',

            UPLOAD_ERR_CANT_WRITE =>
                'PHP ไม่สามารถเขียนไฟล์ลงดิสก์ได้',

            UPLOAD_ERR_EXTENSION =>
                'PHP extension หยุดการอัปโหลดไฟล์',
        ];

        throw new RuntimeException(
            $uploadErrors[$file['error']]
            ?? 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์'
        );
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $fileSize = (int) ($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('ไฟล์อัปโหลดไม่ถูกต้อง');
    }


    /* =========================================================
       SIZE
       ========================================================= */

    if ($fileSize <= 0) {
        throw new RuntimeException('ไฟล์ที่เลือกไม่มีข้อมูล');
    }

    if ($fileSize > 2 * 1024 * 1024) {
        throw new RuntimeException(
            'ไฟล์ Logo ต้องมีขนาดไม่เกิน 2 MB'
        );
    }


    /* =========================================================
       EXTENSION
       ========================================================= */

    $extension = strtolower(
        pathinfo(
            $originalName,
            PATHINFO_EXTENSION
        )
    );

    $allowedExtensions = [
        'png',
        'jpg',
        'jpeg',
        'webp',
        'svg',
    ];

    if (!in_array(
        $extension,
        $allowedExtensions,
        true
    )) {
        throw new RuntimeException(
            'รองรับเฉพาะ PNG, JPG, JPEG, WEBP และ SVG เท่านั้น'
        );
    }


    /* =========================================================
       IMAGE VALIDATION
       ========================================================= */

    if ($extension !== 'svg') {

        $imageInfo = @getimagesize($tmpName);

        if ($imageInfo === false) {
            throw new RuntimeException(
                'ไฟล์ที่อัปโหลดไม่ใช่รูปภาพที่ถูกต้อง'
            );
        }

        $allowedMimeTypes = [
            'image/png',
            'image/jpeg',
            'image/webp',
        ];

        $mimeType = (string) (
            $imageInfo['mime'] ?? ''
        );

        if (!in_array(
            $mimeType,
            $allowedMimeTypes,
            true
        )) {
            throw new RuntimeException(
                'ชนิดไฟล์รูปภาพไม่รองรับ'
            );
        }

    } else {

        $svgContent = @file_get_contents($tmpName);

        if ($svgContent === false) {
            throw new RuntimeException(
                'ไม่สามารถอ่านไฟล์ SVG ได้'
            );
        }

        if (stripos($svgContent, '<svg') === false) {
            throw new RuntimeException(
                'ไฟล์ SVG ไม่ถูกต้อง'
            );
        }

        if (
            preg_match('/<script\b/i', $svgContent) ||
            preg_match('/javascript\s*:/i', $svgContent)
        ) {
            throw new RuntimeException(
                'ไฟล์ SVG ไม่สามารถมี JavaScript ได้'
            );
        }
    }


    /* =========================================================
       UPLOAD DIRECTORY
       ========================================================= */

    $uploadDir = __DIR__ . '/uploads';

    if (!is_dir($uploadDir)) {

        if (!mkdir(
            $uploadDir,
            0755,
            true
        )) {
            throw new RuntimeException(
                'ไม่สามารถสร้างโฟลเดอร์ uploads ได้'
            );
        }
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException(
            'โฟลเดอร์ uploads ไม่สามารถเขียนไฟล์ได้'
        );
    }


    /* =========================================================
       NEW FILE NAME
       ========================================================= */

    $filename =
        'logo_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(6)) .
        '.' .
        $extension;

    $destination =
        $uploadDir .
        DIRECTORY_SEPARATOR .
        $filename;


    /* =========================================================
       MOVE FILE
       ========================================================= */

    if (!move_uploaded_file(
        $tmpName,
        $destination
    )) {
        throw new RuntimeException(
            'ไม่สามารถบันทึกไฟล์ Logo ได้'
        );
    }


    /* =========================================================
       DATABASE PATH
       ========================================================= */

    $relativePath =
        'modules/settings/uploads/' .
        $filename;


    /* =========================================================
       GET OLD LOGO
       ========================================================= */

    $oldLogo = '';

    $stmt = $conn->prepare("
        SELECT setting_value
        FROM settings
        WHERE setting_key = 'company_logo'
        LIMIT 1
    ");

    if (!$stmt) {
        @unlink($destination);

        throw new RuntimeException(
            'ไม่สามารถตรวจสอบ Logo เดิมได้: ' .
            $conn->error
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $oldLogo = (string) (
            $row['setting_value'] ?? ''
        );
    }

    $result->free();
    $stmt->close();


    /* =========================================================
       SAVE DATABASE
       ========================================================= */

    $stmt = $conn->prepare("
        UPDATE settings
        SET setting_value = ?
        WHERE setting_key = 'company_logo'
    ");

    if (!$stmt) {
        @unlink($destination);

        throw new RuntimeException(
            'ไม่สามารถเตรียมคำสั่งบันทึก Logo ได้: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        's',
        $relativePath
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        @unlink($destination);

        throw new RuntimeException(
            'บันทึก Logo ลงฐานข้อมูลไม่สำเร็จ: ' .
            $error
        );
    }

    $stmt->close();


    /* =========================================================
       IF SETTING DOES NOT EXIST
       ========================================================= */

    $stmt = $conn->prepare("
        SELECT id
        FROM settings
        WHERE setting_key = 'company_logo'
        LIMIT 1
    ");

    if (!$stmt) {
        @unlink($destination);

        throw new RuntimeException(
            'ตรวจสอบ setting company_logo ไม่สำเร็จ'
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    $exists = (bool) $result->fetch_assoc();

    $result->free();
    $stmt->close();


    if (!$exists) {

        $stmt = $conn->prepare("
            INSERT INTO settings (
                setting_key,
                setting_value
            )
            VALUES (
                'company_logo',
                ?
            )
        ");

        if (!$stmt) {
            @unlink($destination);

            throw new RuntimeException(
                'ไม่สามารถเพิ่ม setting company_logo ได้'
            );
        }

        $stmt->bind_param(
            's',
            $relativePath
        );

        if (!$stmt->execute()) {

            $error = $stmt->error;

            $stmt->close();

            @unlink($destination);

            throw new RuntimeException(
                'เพิ่ม Logo ลงฐานข้อมูลไม่สำเร็จ: ' .
                $error
            );
        }

        $stmt->close();
    }


    /* =========================================================
       DELETE OLD LOGO
       ========================================================= */

    if ($oldLogo !== '') {

        $oldPrefix =
            'modules/settings/uploads/';

        if (
            strpos(
                $oldLogo,
                $oldPrefix
            ) === 0
        ) {

            $oldFile =
                __DIR__ .
                '/uploads/' .
                basename($oldLogo);

            if (
                is_file($oldFile) &&
                realpath($oldFile) !==
                realpath($destination)
            ) {
                @unlink($oldFile);
            }
        }
    }


    /* =========================================================
       SUCCESS
       ========================================================= */

    set_flash(
        'success',
        'อัปโหลด Logo บริษัทเรียบร้อยแล้ว'
    );

    header('Location: /AL/modules/settings/index.php');
    exit;


} catch (Throwable $e) {

    set_flash(
        'error',
        $e->getMessage()
    );

    header('Location: /AL/modules/settings/index.php');
    exit;
}