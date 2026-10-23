<?php
// =============================================================================
//  BillingService.php — OOP Service Class for Billing Operations
// =============================================================================
//  Demonstrates:
//    • Encapsulation  — payment logic (partial/paid state transition) lives
//                       inside the class, not scattered across module pages.
//    • Constructor Injection — no global $conn usage.
//    • Reusability    — getForPatient() used by patient profile view;
//                       getSummary() used by both list and dashboard.
//
//  Usage:
//      require_once '../../includes/BillingService.php';
//      $billingService = new BillingService($conn);
//      $bills   = $billingService->getList(['status' => 'unpaid'], 20, 0);
//      $summary = $billingService->getSummary();
// =============================================================================

class BillingService
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    // =========================================================================
    //  READ: COUNT
    // =========================================================================

    /**
     * Return the total number of bills matching $filters.
     *
     * @param  array $filters  Keys: status, search, date_from, date_to
     * @return int
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS c
            FROM bills b
            LEFT JOIN patients p ON b.patient_id = p.id
            $where
        ");
        $stmt->execute($params);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    }

    // =========================================================================
    //  READ: LIST (paginated)
    // =========================================================================

    /**
     * Return a paginated list of bills with patient, service, and appointment info.
     *
     * @param  array $filters
     * @param  int   $limit
     * @param  int   $offset
     * @return array
     */
    public function getList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->conn->prepare("
            SELECT
                b.*,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                p.patient_code,
                p.phone,
                COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `bill_services` ms_x WHERE ms_x.`bill_id` = b.id), s.service_name) AS service_name,
                a.appointment_code
            FROM bills b
            LEFT JOIN patients     p ON b.patient_id     = p.id
            LEFT JOIN services     s ON b.service_id     = s.id
            LEFT JOIN appointments a ON b.appointment_id = a.id
            $where
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    //  READ: SINGLE RECORD
    // =========================================================================

    /**
     * Fetch one bill by ID with full patient info.
     * Returns null when not found.
     *
     * @param  int       $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                b.*,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                p.patient_code,
                p.phone AS patient_phone,
                p.email AS patient_email,
                COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `bill_services` ms_x WHERE ms_x.`bill_id` = b.id), s.service_name) AS service_name,
                (b.amount_due - b.amount_paid) AS balance
            FROM bills b
            LEFT JOIN patients p ON b.patient_id = p.id
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // =========================================================================
    //  READ: FOR A SPECIFIC PATIENT
    // =========================================================================

    /**
     * Return all bills for one patient, newest first.
     *
     * @param  int $patientId
     * @return array
     */
    public function getForPatient(int $patientId): array
    {
        $stmt = $this->conn->prepare("
            SELECT b.*, COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `bill_services` ms_x WHERE ms_x.`bill_id` = b.id), s.service_name) AS service_name,
                   (b.amount_due - b.amount_paid) AS balance
            FROM bills b
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.patient_id = ?
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    //  READ: SUMMARY TOTALS
    // =========================================================================

    /**
     * Return aggregate billing summary for the dashboard / list header.
     * Keys: total_bills, total_due, total_paid, total_outstanding,
     *       unpaid_count, partial_count, paid_count
     *
     * @return array
     */
    public function getSummary(): array
    {
        $row = $this->conn->query("
            SELECT
                COUNT(*)                                        AS total_bills,
                COALESCE(SUM(amount_due),  0)                  AS total_due,
                COALESCE(SUM(amount_paid), 0)                  AS total_paid,
                COALESCE(SUM(amount_due) - SUM(amount_paid), 0) AS total_outstanding,
                COUNT(CASE WHEN status = 'unpaid'  THEN 1 END) AS unpaid_count,
                COUNT(CASE WHEN status = 'partial' THEN 1 END) AS partial_count,
                COUNT(CASE WHEN status = 'paid'    THEN 1 END) AS paid_count
            FROM bills
        ")->fetch(PDO::FETCH_ASSOC);

        return $row ?: [
            'total_bills'       => 0,
            'total_due'         => 0,
            'total_paid'        => 0,
            'total_outstanding' => 0,
            'unpaid_count'      => 0,
            'partial_count'     => 0,
            'paid_count'        => 0,
        ];
    }

    // =========================================================================
    //  READ: EXPORT DATA (CSV)
    // =========================================================================

    /**
     * Return all matching bills (no pagination) for CSV export.
     * Same filters as getList(), no LIMIT/OFFSET applied.
     *
     * @param  array $filters
     * @return array
     */
    public function getExportData(array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->conn->prepare("
            SELECT
                b.bill_code,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                p.patient_code,
                COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `bill_services` ms_x WHERE ms_x.`bill_id` = b.id), s.service_name) AS service_name,
                b.amount_due,
                b.amount_paid,
                (b.amount_due - b.amount_paid)         AS balance,
                b.payment_method,
                b.payment_ref,
                b.status,
                DATE_FORMAT(b.created_at, '%Y-%m-%d')  AS date_created
            FROM bills b
            LEFT JOIN patients     p ON b.patient_id     = p.id
            LEFT JOIN services     s ON b.service_id     = s.id
            LEFT JOIN appointments a ON b.appointment_id = a.id
            $where
            ORDER BY b.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    //  WRITE: CREATE
    // =========================================================================

    /**
     * Insert a new bill row and return the new ID.
     *
     * @param  array $data
     * @return int
     */
    public function create(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO bills
                (bill_code, patient_id, appointment_id, service_id,
                 amount_due, amount_paid, status, payment_method, payment_ref, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['bill_code'],
            $data['patient_id'],
            $data['appointment_id']  ?? null,
            $data['service_id']      ?? null,
            $data['amount_due'],
            $data['amount_paid']     ?? 0,
            $data['status']          ?? 'unpaid',
            $data['payment_method']  ?? null,
            $data['payment_ref']     ?? null,
            $data['notes']           ?? null,
        ]);
        return (int) $this->conn->lastInsertId();
    }

    // =========================================================================
    //  WRITE: RECORD PAYMENT
    // =========================================================================

    /**
     * Add a payment to a bill and automatically update the status.
     * Status transitions: unpaid → partial → paid.
     *
     * @param  int    $billId
     * @param  float  $amount
     * @param  string $method  e.g. 'cash', 'gcash', 'card'
     * @param  string $ref     Optional reference number
     * @return bool
     */
    public function recordPayment(
        int    $billId,
        float  $amount,
        string $method = 'cash',
        string $ref    = ''
    ): bool {
        $bill = $this->findById($billId);
        if (!$bill) return false;

        $newPaid   = (float) $bill['amount_paid'] + $amount;
        $newStatus = $newPaid >= (float) $bill['amount_due'] ? 'paid' : 'partial';

        ms_reconcile_payments($this->conn, $billId);   // keep payment history in step
        $stmt = $this->conn->prepare("
            UPDATE bills
            SET amount_paid    = ?,
                status         = ?,
                payment_method = ?,
                payment_ref    = ?
            WHERE id = ?
        ");
        $ok = $stmt->execute([$newPaid, $newStatus, $method, $ref ?: null, $billId]);
        if ($ok) ms_log_payment($this->conn, $billId, $amount, $method, $ref ?: null);
        return $ok;
    }

    // =========================================================================
    //  PRIVATE: Filter builder
    // =========================================================================

    /**
     * Build a WHERE clause and parameters array from a filter array.
     *
     * @param  array $filters
     * @return array  [string $where, array $params]
     */
    private function buildWhere(array $filters): array
    {
        $where  = "WHERE 1=1";
        $params = [];

        $allowedStatuses = ['unpaid', 'partial', 'paid'];
        if (!empty($filters['status']) &&
            in_array($filters['status'], $allowedStatuses, true)) {
            $where   .= " AND b.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $like    = '%' . $filters['search'] . '%';
            $where  .= " AND (
                            p.first_name                                        LIKE ? OR
                            p.last_name                                         LIKE ? OR
                            b.bill_code                                         LIKE ? OR
                            p.patient_code                                      LIKE ? OR
                            CONCAT(p.first_name, ' ', p.last_name)             LIKE ? OR
                            CONCAT(p.last_name, ', ', p.first_name)            LIKE ? OR
                            CONCAT(p.last_name, ' ', p.first_name)             LIKE ?
                         )";
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
        }

        if (!empty($filters['date_from']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
            $where   .= " AND DATE(b.created_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
            $where   .= " AND DATE(b.created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        return [$where, $params];
    }
}