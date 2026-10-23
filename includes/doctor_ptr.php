<?php
// doctor_ptr.php — makes sure the `doctors` table has a `ptr_number` column.
//
// The printable prescription shows the dentist's PRC license no. AND PTR no.
// (just like the clinic's paper prescription pad). Older databases don't have the
// PTR column yet, so this adds it the first time it is needed — no manual SQL required.
// (database/migration_ptr_number.sql does the same thing for people who prefer to run it by hand.)

function ensure_doctor_ptr_column(PDO $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $exists = $conn->query("SHOW COLUMNS FROM `doctors` LIKE 'ptr_number'")->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            $conn->exec("ALTER TABLE `doctors` ADD COLUMN `ptr_number` VARCHAR(50) DEFAULT NULL AFTER `license_number`");
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('ensure_doctor_ptr_column: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}