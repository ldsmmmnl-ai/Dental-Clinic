<?php
// sidebar_badges.php — "NEW" counters shown in the sidebar (new patients, new appointments).
//
// How it works: every user keeps a bookmark per list = the highest record id they have already seen.
// Anything with a bigger id is "new". Opening Patient Records / Appointments moves the bookmark,
// so the badge clears. Ids are used instead of dates so a back-dated patient (the Add Patient form
// lets you pick a registration date) or a server time-zone difference can never hide a new record.
//
// The bookmarks live in a small table (nav_seen) that this file creates by itself the first time it runs.
// If anything fails (no CREATE permission, DB hiccup) the badges simply stay hidden — the sidebar still works.

// Loaded with require_once, so these functions are declared once. They sit at the top level
// (no function_exists wrapper) so VS Code's PHP checker can find them from sidebar.php.

// Which list each badge watches. Table names come from this fixed map only.
function sb_badge_tables(): array {
    return ['patients' => 'patients', 'appointments' => 'appointments'];
}

// Creates nav_seen and starts every user's bookmark at "everything up to now is already seen",
// so the very first load shows no old records as new. Runs once per login session.
function sb_badges_init(PDO $conn, int $uid): void {
    if (!empty($_SESSION['nav_seen_ready'])) return;

    $conn->exec("CREATE TABLE IF NOT EXISTS `nav_seen` (
        `user_id` INT         NOT NULL,
        `item`    VARCHAR(20) NOT NULL,
        `last_id` INT         NOT NULL DEFAULT 0,
        `seen_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`, `item`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (sb_badge_tables() as $item => $table) {
        $max = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM `$table`")->fetchColumn();
        $conn->prepare("INSERT IGNORE INTO nav_seen (user_id, item, last_id) VALUES (?, ?, ?)")
             ->execute([$uid, $item, $max]);
    }
    $_SESSION['nav_seen_ready'] = 1;
}

// Returns ['patients' => n, 'appointments' => n] — records added since this user last opened each list.
function sb_new_counts(PDO $conn, int $uid): array {
    $out = ['patients' => 0, 'appointments' => 0];
    if ($uid <= 0) return $out;
    try {
        sb_badges_init($conn, $uid);

        // Patients: active ones only, and not the ones this user registered themselves.
        $st = $conn->prepare(
            "SELECT COUNT(*) FROM patients p
              WHERE p.is_active = 1
                AND (p.registered_by IS NULL OR p.registered_by <> ?)
                AND p.id > (SELECT last_id FROM nav_seen WHERE user_id = ? AND item = 'patients')"
        );
        $st->execute([$uid, $uid]);
        $out['patients'] = (int)$st->fetchColumn();

        // Appointments: anything booked since last visit, except cancelled ones.
        $st = $conn->prepare(
            "SELECT COUNT(*) FROM appointments a
              WHERE a.status <> 'cancelled'
                AND a.id > (SELECT last_id FROM nav_seen WHERE user_id = ? AND item = 'appointments')"
        );
        $st->execute([$uid]);
        $out['appointments'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[sidebar_badges] ' . $e->getMessage());
        return ['patients' => 0, 'appointments' => 0];
    }
    return $out;
}

// Moves the bookmark to the newest record — call when the user opens that list.
function sb_mark_seen(PDO $conn, int $uid, string $item): void {
    $tables = sb_badge_tables();
    if ($uid <= 0 || !isset($tables[$item])) return;
    try {
        sb_badges_init($conn, $uid);
        $max = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM `" . $tables[$item] . "`")->fetchColumn();
        $conn->prepare(
            "INSERT INTO nav_seen (user_id, item, last_id, seen_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_id = ?, seen_at = NOW()"
        )->execute([$uid, $item, $max, $max]);
    } catch (Throwable $e) {
        error_log('[sidebar_badges] ' . $e->getMessage());
    }
}

// 1..99 as-is, anything bigger as "99+"
function sb_badge_label(int $n): string {
    return $n > 99 ? '99+' : (string)$n;
}
