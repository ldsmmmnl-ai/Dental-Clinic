<?php
// =============================================================================
//  multi_service.php — lets ONE appointment / walk-in / bill have MANY services.
// =============================================================================
//  • Three tables are created automatically the first time any page loads
//    (nothing to import):   appointment_services, bill_services, bill_payments
//  • bill_payments = PAYMENT HISTORY (one row per payment: date, amount, method, ref).
//  • The service list is switched ONCE to the doctor's 8 services (names only, no prices).
//  • The old single `service_id` column is still filled with the FIRST selected
//    service, so reports, analytics and older pages keep working.
//  • Each link row stores a snapshot of the service name + price at that time.
// =============================================================================

function ms_ensure_tables(PDO $conn): void
{
    try {
        // One light check per request; only creates what is missing.
        $have = (int)$conn->query("SELECT COUNT(*) FROM information_schema.tables
                                   WHERE table_schema = DATABASE()
                                     AND table_name IN ('bill_services','appointment_services','bill_payments','dental_record_services')")->fetchColumn();
        if ($have < 4) {
            $conn->exec("CREATE TABLE IF NOT EXISTS `bill_services` (
                `id`           INT AUTO_INCREMENT PRIMARY KEY,
                `bill_id`      INT           NOT NULL,
                `service_id`   INT           DEFAULT NULL,
                `service_name` VARCHAR(150)  NOT NULL,
                `price`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                KEY `idx_bs_bill` (`bill_id`),
                CONSTRAINT `fk_bs_bill` FOREIGN KEY (`bill_id`) REFERENCES `bills`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            $conn->exec("CREATE TABLE IF NOT EXISTS `appointment_services` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `appointment_id` INT           NOT NULL,
                `service_id`     INT           DEFAULT NULL,
                `service_name`   VARCHAR(150)  NOT NULL,
                `price`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                KEY `idx_as_appt` (`appointment_id`),
                CONSTRAINT `fk_as_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Payment history: ONE ROW PER PAYMENT. amount is negative for a cancelled / corrected payment.
            $conn->exec("CREATE TABLE IF NOT EXISTS `bill_payments` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `bill_id`        INT           NOT NULL,
                `amount`         DECIMAL(10,2) NOT NULL,
                `payment_method` VARCHAR(20)   DEFAULT NULL,
                `payment_ref`    VARCHAR(100)  DEFAULT NULL,
                `note`           VARCHAR(255)  DEFAULT NULL,
                `paid_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_by`     INT           DEFAULT NULL,
                KEY `idx_bp_bill` (`bill_id`, `paid_at`),
                CONSTRAINT `fk_bp_bill` FOREIGN KEY (`bill_id`) REFERENCES `bills`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Services of one dental record / visit (a visit can have MANY services).
            // Own try/catch: if the foreign key cannot be made, fall back to a plain table.
            try {
                $conn->exec("CREATE TABLE IF NOT EXISTS `dental_record_services` (
                    `id`               INT AUTO_INCREMENT PRIMARY KEY,
                    `dental_record_id` INT           NOT NULL,
                    `service_id`       INT           DEFAULT NULL,
                    `service_name`     VARCHAR(150)  NOT NULL,
                    `price`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    KEY `idx_drs_record` (`dental_record_id`),
                    CONSTRAINT `fk_drs_record` FOREIGN KEY (`dental_record_id`) REFERENCES `dental_records`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            } catch (Throwable $e_drs) {
                try {
                    $conn->exec("CREATE TABLE IF NOT EXISTS `dental_record_services` (
                        `id`               INT AUTO_INCREMENT PRIMARY KEY,
                        `dental_record_id` INT           NOT NULL,
                        `service_id`       INT           DEFAULT NULL,
                        `service_name`     VARCHAR(150)  NOT NULL,
                        `price`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        KEY `idx_drs_record` (`dental_record_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
                } catch (Throwable $e_drs2) { error_log('[MULTI-SERVICE] dental_record_services: ' . $e_drs2->getMessage()); }
            }
        }
        ms_migrate_service_list($conn);
        ms_ensure_payment_columns($conn);
        ms_drop_old_payment_triggers($conn);
    } catch (Throwable $e) {
        error_log('[MULTI-SERVICE] could not create tables: ' . $e->getMessage());
    }
}

/**
 * ONE-TIME: an earlier version of payment history may have left a DATABASE TRIGGER on the `bills`
 * table that writes into `bill_payments` by itself. The pages now write the history, so that old
 * trigger would record every payment TWICE. This finds and removes any such trigger (once).
 */
function ms_drop_old_payment_triggers(PDO $conn): void
{
    try {
        $done = $conn->query("SELECT 1 FROM settings WHERE setting_key = 'bill_payment_triggers_v1' LIMIT 1");
        if ($done && $done->fetchColumn()) return;
        $q = $conn->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                           WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'bills'
                             AND ACTION_STATEMENT LIKE '%bill_payments%'");
        $ok = true;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (!preg_match('/^[A-Za-z0-9_$]+$/', $name)) continue;
            try { $conn->exec("DROP TRIGGER IF EXISTS `$name`"); error_log("[MULTI-SERVICE] removed old payment trigger $name"); }
            catch (Throwable $e) { $ok = false; error_log("[MULTI-SERVICE] could not drop trigger $name: " . $e->getMessage()); }
        }
        if ($ok) $conn->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bill_payment_triggers_v1', 'done')");
    } catch (Throwable $e) {
        error_log('[MULTI-SERVICE] trigger check: ' . $e->getMessage());
    }
}

/**
 * ONE-TIME check: if a `bill_payments` table already existed from an earlier version, make sure it has
 * every column payment history needs (older copies may miss some). Runs once (marker in `settings`).
 */
function ms_ensure_payment_columns(PDO $conn): void
{
    try {
        $done = $conn->query("SELECT 1 FROM settings WHERE setting_key = 'bill_payments_cols_v1' LIMIT 1");
        if ($done && $done->fetchColumn()) return;
        $have = [];
        foreach ($conn->query("SHOW COLUMNS FROM `bill_payments`")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;
        $want = [
            'payment_method' => "VARCHAR(20) NULL DEFAULT NULL",
            'payment_ref'    => "VARCHAR(100) NULL DEFAULT NULL",
            'note'           => "VARCHAR(255) NULL DEFAULT NULL",
            'created_by'     => "INT NULL DEFAULT NULL",
        ];
        foreach ($want as $col => $def) {
            if (!isset($have[$col])) $conn->exec("ALTER TABLE `bill_payments` ADD COLUMN `$col` $def");
        }
        $conn->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bill_payments_cols_v1', 'done')");
    } catch (Throwable $e) {
        error_log('[MULTI-SERVICE] payment columns check: ' . $e->getMessage());
    }
}

/**
 * ONE-TIME switch of the service list to the doctor's 8 services (names only, no prices).
 *  • Old services are RENAMED, so old appointments / bills / records keep pointing at them.
 *  • Services the doctor did not list (Whitening, X-Ray, Fluoride) are switched OFF, not deleted.
 *  • Catalog prices are set to 0 — the fee is typed in on each bill.
 * A marker row in `settings` makes sure this runs only once.
 */
function ms_migrate_service_list(PDO $conn): void
{
    try {
        $done = $conn->query("SELECT 1 FROM settings WHERE setting_key = 'services_doctor_list_v1' LIMIT 1");
        if ($done && $done->fetchColumn()) return;

        $conn->beginTransaction();
        $conn->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('services_doctor_list_v1', 'done')");

        $rename = [
            'Dental Checkup'           => 'Consultation / Check-up',
            'Dental Cleaning'          => 'Oral Prophylaxis',
            'Tooth Filling'            => 'Tooth Restoration',
            'Root Canal'               => 'Root Canal Treatment',
            'Orthodontic Consultation' => 'Orthodontic Treatment',
            'Dentures'                 => 'Prosthodontic Treatment',
        ];
        $exists = $conn->prepare("SELECT id FROM services WHERE service_name = ? LIMIT 1");
        $ren    = $conn->prepare("UPDATE services SET service_name = ? WHERE service_name = ?");
        foreach ($rename as $old => $new) {
            $exists->execute([$new]);
            $has_new = $exists->fetchColumn();
            $exists->closeCursor();
            if (!$has_new) $ren->execute([$new, $old]);
        }

        $final = [   // name => [duration in minutes, description]
            'Consultation / Check-up'  => [30, 'General oral examination'],
            'Oral Prophylaxis'         => [60, 'Cleaning / scaling'],
            'Tooth Restoration'        => [45, 'Fillings and other restorations'],
            'Tooth Extraction'         => [45, 'Tooth removal'],
            'Root Canal Treatment'     => [90, 'Endodontic treatment'],
            'Odontectomy / Surgery'    => [60, 'Oral surgery'],
            'Orthodontic Treatment'    => [30, 'Braces and alignment'],
            'Prosthodontic Treatment'  => [60, 'Dentures, crowns and bridges'],
        ];
        $ins = $conn->prepare("INSERT INTO services (service_name, description, duration_minutes, price, is_active) VALUES (?,?,?,0,1)");
        foreach ($final as $name => [$mins, $desc]) {
            $exists->execute([$name]);
            $has = $exists->fetchColumn();
            $exists->closeCursor();
            if (!$has) $ins->execute([$name, $desc, $mins]);
        }

        // Everything the doctor listed → active, no catalog price. Everything else → switched off (history is kept).
        $names = array_keys($final);
        $in    = implode(',', array_fill(0, count($names), '?'));
        $conn->prepare("UPDATE services SET is_active = 1, price = 0 WHERE service_name IN ($in)")->execute($names);
        $conn->prepare("UPDATE services SET is_active = 0 WHERE service_name NOT IN ($in)")->execute($names);

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();   // duplicate marker = another request already did it
        error_log('[MULTI-SERVICE] service list switch: ' . $e->getMessage());
    }
}

/**
 * PAYMENT HISTORY — write one row. Call it every time money is added to / taken off a bill.
 * $amount > 0 = payment received, $amount < 0 = payment cancelled / corrected.
 * $paid_date = 'Y-m-d' (the date the patient really paid). Today → exact time; other day → that date.
 */
function ms_log_payment(PDO $conn, int $bill_id, float $amount, ?string $method = null, ?string $ref = null,
                        ?string $note = null, ?int $user_id = null, ?string $paid_date = null): void
{
    if (abs($amount) < 0.005 || $bill_id <= 0) return;
    try {
        $when = date('Y-m-d H:i:s');
        if ($paid_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date) && $paid_date !== date('Y-m-d')) {
            $when = $paid_date . ' 00:00:00';
        }
        // DUPLICATE GUARD — this is called right AFTER the bill was updated. If the history already adds
        // up to the bill's amount paid, something else (an old DB trigger) already logged this payment:
        // fill in that row's details instead of adding a second one.
        $chk = $conn->prepare("SELECT b.amount_paid, (SELECT COALESCE(SUM(p.amount),0) FROM bill_payments p WHERE p.bill_id = b.id) AS hist
                               FROM bills b WHERE b.id = ? LIMIT 1");
        $chk->execute([$bill_id]);
        $cr = $chk->fetch(PDO::FETCH_ASSOC);
        $chk->closeCursor();
        if ($cr && abs((float)$cr['hist'] - (float)$cr['amount_paid']) < 0.005) {
            $last = $conn->prepare("SELECT id, amount FROM bill_payments WHERE bill_id = ? ORDER BY id DESC LIMIT 1");
            $last->execute([$bill_id]);
            $lr = $last->fetch(PDO::FETCH_ASSOC);
            $last->closeCursor();
            if ($lr && abs((float)$lr['amount'] - round($amount, 2)) < 0.005) {
                try {
                    $conn->prepare("UPDATE bill_payments SET payment_method = ?, payment_ref = ?, note = ?, paid_at = ?, created_by = ? WHERE id = ?")
                         ->execute([$method ?: null, $ref ?: null, $note ?: null, $when, $user_id ?: null, $lr['id']]);
                } catch (Throwable $e2) {
                    $conn->prepare("UPDATE bill_payments SET payment_method = ?, paid_at = ? WHERE id = ?")->execute([$method ?: null, $when, $lr['id']]);
                }
                return;
            }
        }
        try {
            $conn->prepare("INSERT INTO bill_payments (bill_id, amount, payment_method, payment_ref, note, paid_at, created_by)
                            VALUES (?,?,?,?,?,?,?)")
                 ->execute([$bill_id, round($amount, 2), $method ?: null, $ref ?: null, $note ?: null, $when, $user_id ?: null]);
        } catch (Throwable $e1) {
            // Older table without some columns → save the essentials so the history line is never lost
            error_log('[PAYMENT HISTORY] full insert failed, retrying basic: ' . $e1->getMessage());
            $conn->prepare("INSERT INTO bill_payments (bill_id, amount, payment_method, paid_at) VALUES (?,?,?,?)")
                 ->execute([$bill_id, round($amount, 2), $method ?: null, $when]);
        }
    } catch (Throwable $e) {
        error_log('[PAYMENT HISTORY] ' . $e->getMessage());
    }
}

/**
 * Safety net: makes the history add up to the bill's "amount paid".
 * Covers bills paid before payment history existed (one "earlier payments" row) and any page
 * that changes a bill without logging it. Called when a bill or receipt is opened.
 */
function ms_reconcile_payments(PDO $conn, int $bill_id): void
{
    try {
        $b = $conn->prepare("SELECT amount_paid, payment_method, payment_ref, created_at, updated_at FROM bills WHERE id = ? LIMIT 1");
        $b->execute([$bill_id]);
        $bill = $b->fetch(PDO::FETCH_ASSOC);
        $b->closeCursor();
        if (!$bill) return;
        $s = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS t, COUNT(*) AS n FROM bill_payments WHERE bill_id = ?");
        $s->execute([$bill_id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $s->closeCursor();
        $diff = round((float)$bill['amount_paid'] - (float)$row['t'], 2);
        if (abs($diff) < 0.005) return;
        $when = $bill['updated_at'] ?: ($bill['created_at'] ?: date('Y-m-d H:i:s'));
        $note = ((int)$row['n'] === 0) ? 'Payment recorded before payment history was added' : 'Adjustment';
        $conn->prepare("INSERT INTO bill_payments (bill_id, amount, payment_method, payment_ref, note, paid_at) VALUES (?,?,?,?,?,?)")
             ->execute([$bill_id, $diff, $bill['payment_method'] ?: null, $bill['payment_ref'] ?: null, $note, $when]);
    } catch (Throwable $e) {
        error_log('[PAYMENT HISTORY RECONCILE] ' . $e->getMessage());
    }
}

/** Clean a list of posted ids → unique positive ints, original order kept. */
function ms_clean_ids($raw): array
{
    if (!is_array($raw)) $raw = ($raw === null || $raw === '') ? [] : [$raw];
    $out = [];
    foreach ($raw as $v) {
        $i = (int)$v;
        if ($i > 0 && !in_array($i, $out, true)) $out[] = $i;
    }
    return $out;
}

/** Selected service ids from a POST (new `service_ids[]`, or the old single `service_id`). */
function ms_post_ids(): array
{
    $ids = ms_clean_ids($_POST['service_ids'] ?? []);
    if (!$ids && !empty($_POST['service_id'])) $ids = ms_clean_ids([$_POST['service_id']]);
    return $ids;
}

/** id, service_name, price for the given ids (only ones that exist), in the same order. */
function ms_fetch_services(PDO $conn, array $ids): array
{
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $conn->prepare("SELECT id, service_name, price FROM services WHERE id IN ($in)");
    $st->execute($ids);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(int)$r['id']] = $r;
    $st->closeCursor();
    $out = [];
    foreach ($ids as $i) if (isset($by[$i])) $out[] = $by[$i];
    return $out;
}

/** Replace the service rows of one appointment / bill. Returns the saved services. */
function ms_save(PDO $conn, string $table, string $fk, int $fk_id, array $ids): array
{
    if (!in_array($table, ['bill_services', 'appointment_services', 'dental_record_services'], true)) return [];
    if (!in_array($fk, ['bill_id', 'appointment_id', 'dental_record_id'], true)) return [];
    $rows = ms_fetch_services($conn, $ids);
    $conn->prepare("DELETE FROM `$table` WHERE `$fk` = ?")->execute([$fk_id]);
    $ins = $conn->prepare("INSERT INTO `$table` (`$fk`, service_id, service_name, price) VALUES (?,?,?,?)");
    foreach ($rows as $r) $ins->execute([$fk_id, (int)$r['id'], $r['service_name'], (float)$r['price']]);
    return $rows;
}

/** Service rows of one appointment / bill. Falls back to the old single service_id. */
function ms_get(PDO $conn, string $table, string $fk, int $fk_id, ?int $fallback_service_id = null): array
{
    if (!in_array($table, ['bill_services', 'appointment_services', 'dental_record_services'], true)) return [];
    if (!in_array($fk, ['bill_id', 'appointment_id', 'dental_record_id'], true)) return [];
    try {
        $st = $conn->prepare("SELECT service_id, service_name, price FROM `$table` WHERE `$fk` = ? ORDER BY id ASC");
        $st->execute([$fk_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
    } catch (Throwable $e) { $rows = []; }
    if (!$rows && $fallback_service_id) {
        $st = $conn->prepare("SELECT id AS service_id, service_name, price FROM services WHERE id = ? LIMIT 1");
        $st->execute([$fallback_service_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
    }
    return $rows;
}

/**
 * SQL snippet: all service names of a row joined with ", ".
 * Use inside SELECT, e.g.   ms_names_sql('bill_services','bill_id','b.id','s.service_name') . ' AS service_name'
 * $fallback = the old single-service column, used when the row has no link rows yet.
 */
function ms_names_sql(string $table, string $fk, string $outer_id, string $fallback = 'NULL'): string
{
    return "COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') "
         . "FROM `$table` ms_x WHERE ms_x.`$fk` = $outer_id), $fallback)";
}

/**
 * Check-box list of services (replaces the old single-choice dropdown).
 * $opts: name (default service_ids[]), prefix (unique id), amount_target (id of an input
 *        that should be set to the total when ticking), compact (bool)
 */
function ms_checkbox_list(array $services, array $selected_ids, array $opts = []): string
{
    static $assets_done = false;
    $name   = $opts['name']   ?? 'service_ids[]';
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $opts['prefix'] ?? 'ms');
    $target = $opts['amount_target'] ?? '';
    $show_price = $opts['show_price'] ?? false;   // doctor: service names only, no prices
    $selected_ids = array_map('intval', $selected_ids);

    $h = '';
    if (!$assets_done) {
        $assets_done = true;
        $h .= <<<'HTML'
<style>
.ms-box{border:1px solid var(--border,#e2e8f0);border-radius:10px;background:var(--white,#fff);max-height:230px;overflow-y:auto;padding:4px;}
.ms-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;cursor:pointer;margin:0;font-size:0.85rem;font-weight:500;color:var(--gray-700,#334155);}
.ms-item:hover{background:var(--gray-50,#f8fafc);}
.ms-item input{width:18px;height:18px;flex-shrink:0;cursor:pointer;accent-color:var(--primary,#2563eb);}
.ms-item .ms-name{flex:1;}
.ms-item .ms-price{font-weight:700;color:var(--gray-600,#475569);white-space:nowrap;}
.ms-item.ms-on{background:var(--blue-50,#eff6ff);}
.ms-total{display:flex;justify-content:space-between;font-size:0.8rem;margin-top:6px;color:var(--gray-500,#64748b);}
.ms-total b{color:var(--gray-900,#0f172a);}
</style>
<script>
window.msRecalc = window.msRecalc || function (el) {
    var box = el.closest('.ms-box'); if (!box) return;
    var prefix = box.getAttribute('data-prefix');
    var n = 0, total = 0;
    box.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
        var row = cb.closest('.ms-item');
        if (cb.checked) { n++; total += parseFloat(cb.getAttribute('data-price')) || 0; row.classList.add('ms-on'); }
        else row.classList.remove('ms-on');
    });
    var c = document.getElementById(prefix + '_count'), t = document.getElementById(prefix + '_total');
    if (c) c.textContent = n;
    if (t) t.textContent = total.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    var target = box.getAttribute('data-target');
    if (target && n > 0) {
        var inp = document.getElementById(target);
        if (inp) { inp.value = total.toFixed(2); inp.dispatchEvent(new Event('input', {bubbles: true})); }
        if (typeof window.updateBalance === 'function') window.updateBalance();
    }
};
</script>
HTML;
    }

    $count = 0; $total = 0.0;
    $h .= '<div class="ms-box" data-prefix="' . $prefix . '"' . ($target ? ' data-target="' . htmlspecialchars($target) . '"' : '') . '>';
    foreach ($services as $s) {
        $s['price'] = (float)($s['price'] ?? 0);
        $on = in_array((int)$s['id'], $selected_ids, true);
        if ($on) { $count++; $total += (float)$s['price']; }
        $h .= '<label class="ms-item' . ($on ? ' ms-on' : '') . '">'
            . '<input type="checkbox" name="' . htmlspecialchars($name) . '" value="' . (int)$s['id'] . '" data-price="' . (float)$s['price'] . '"'
            . ($on ? ' checked' : '') . ' onchange="msRecalc(this)">'
            . '<span class="ms-name">' . htmlspecialchars($s['service_name']) . '</span>'
            . ($show_price ? '<span class="ms-price">₱' . number_format((float)$s['price'], 2) . '</span>' : '')
            . '</label>';
    }
    $h .= '</div>';
    $h .= '<div class="ms-total"><span><b id="' . $prefix . '_count">' . $count . '</b> selected</span>'
        . ($show_price ? '<span>Total: <b>₱<span id="' . $prefix . '_total">' . number_format($total, 2) . '</span></b></span>' : '')
        . '</div>';
    return $h;
}