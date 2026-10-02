<?php

declare(strict_types=1);

/**
 * =========================================================
 * AL - DATABASE CONNECTION
 * =========================================================
 *
 * Database:
 *   parts_stock
 *
 * Driver:
 *   mysqli
 *
 * Environment:
 *   XAMPP / MariaDB
 *
 * =========================================================
 */

require_once __DIR__ . '/app.php';


/**
 * =========================================================
 * MYSQLI REPORTING
 * =========================================================
 *
 * ให้ mysqli โยน Exception เมื่อเกิด Database Error
 * เพื่อให้ระบบจัดการ Error ได้เป็นมาตรฐาน
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/**
 * =========================================================
 * DATABASE CONNECTION CLASS
 * =========================================================
 */

final class Database
{
    private static ?mysqli $connection = null;


    /**
     * -----------------------------------------------------
     * Get Database Connection
     * -----------------------------------------------------
     */
    public static function connection(): mysqli
    {
        if (self::$connection instanceof mysqli) {
            return self::$connection;
        }

        try {

            self::$connection = new mysqli(
                DB_HOST,
                defined('DB_USER') ? DB_USER : 'root',
                defined('DB_PASS') ? DB_PASS : '',
                DB_NAME,
                DB_PORT
            );

            /**
             * UTF-8
             */
            self::$connection->set_charset(DB_CHARSET);

            return self::$connection;

        } catch (mysqli_sql_exception $e) {

            /**
             * Development
             */
            if (APP_DEBUG === true) {

                throw new RuntimeException(
                    'ไม่สามารถเชื่อมต่อฐานข้อมูลได้: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            /**
             * Production
             *
             * ไม่แสดงรายละเอียด Database
             * ให้ผู้ใช้เห็น
             */

            error_log(
                '[DATABASE ERROR] ' .
                $e->getMessage()
            );

            throw new RuntimeException(
                'ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ',
                0,
                $e
            );
        }
    }


    /**
     * -----------------------------------------------------
     * Close Connection
     * -----------------------------------------------------
     */
    public static function close(): void
    {
        if (self::$connection instanceof mysqli) {

            self::$connection->close();

            self::$connection = null;
        }
    }
}


/**
 * =========================================================
 * GLOBAL DATABASE CONNECTION
 * =========================================================
 *
 * เรียกใช้ในไฟล์ต่าง ๆ ได้ด้วย:
 *
 * $db = db();
 *
 */

function db(): mysqli
{
    return Database::connection();
}