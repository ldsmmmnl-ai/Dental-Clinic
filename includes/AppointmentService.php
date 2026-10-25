<?php
// =============================================================================
//  AppointmentService.php — OOP Service Class for Appointment Operations
// =============================================================================
//  Demonstrates:
//    • Encapsulation  — query logic is private; callers get clean results.
//    • Constructor Injection — PDO passed in, no global $conn dependency.
//    • Method cohesion — each method does exactly one thing.
//
//  Usage:
//      require_once '../../includes/AppointmentService.php';
//      $svc   = new AppointmentService($conn);
//      $total = $svc->count(['status' => 'pending']);
//      $rows  = $svc->getList(['status' => 'pending'], 20, 0);
// =============================================================================

class AppointmentService
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
     * Return the total number of appointments matching $filters.
     *
     * @param  array $filters  Keys: status, date, doctor_id, type, search
     * @return int
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS c
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            $where
        ");
        $stmt->execute($params);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    }

    // =========================================================================
    //  READ: LIST (paginated)
    // =========================================================================

    /**
     * Return a paginated list of appointments.
     * Columns include patient_name, service_name, doctor_name,
     * bill_id, and bill_status — matching what the list template expects.
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

        // ORDER BY groups: 0 = upcoming pending/confirmed (soonest on top),
        //                  1 = pending/confirmed whose date already passed (still open),
        //                  2 = completed / cancelled / no-show at the bottom (most recent first).
        $stmt = $this->conn->prepare("
            SELECT
                a.*,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                COALESCE((SELECT GROUP_CONCAT(ms_x.service_name ORDER BY ms_x.id SEPARATOR ', ') FROM `appointment_services` ms_x WHERE ms_x.`appointment_id` = a.id), s.service_name) AS service_name,
                d.full_name                            AS doctor_name,
                d.id                                   AS doctor_id,
                b.id                                   AS bill_id,
                b.status                               AS bill_status
            FROM appointments a
            LEFT JOIN patients p  ON a.patient_id = p.id
            LEFT JOIN services s  ON a.service_id  = s.id
            LEFT JOIN doctors  d  ON a.doctor_id   = d.id
            LEFT JOIN (
                SELECT b1.id, b1.appointment_id, b1.status
                FROM bills b1
                INNER JOIN (
                    SELECT appointment_id, MAX(id) AS max_id
                    FROM bills
                    GROUP BY appointment_id
                ) latest ON b1.id = latest.max_id
            ) b ON b.appointment_id = a.id
            $where
            ORDER BY
                CASE
                    WHEN a.status IN ('pending','confirmed') AND a.appointment_date >= CURDATE() THEN 0
                    WHEN a.status IN ('pending','confirmed') THEN 1
                    ELSE 2
                END ASC,
                CASE WHEN a.status IN ('pending','confirmed') AND a.appointment_date >= CURDATE() THEN a.appointment_date END ASC,
                CASE WHEN a.status IN ('pending','confirmed') AND a.appointment_date >= CURDATE() THEN a.appointment_time END ASC,
                a.appointment_date DESC, a.appointment_time DESC, a.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    //  READ: SINGLE RECORD
    // =========================================================================

    /**
     * Fetch one appointment by ID with patient and doctor info.
     * Returns null when not found.
     *
     * @param  int       $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                a.*,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                p.patient_code,
                d.full_name AS doctor_name
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN doctors  d ON a.doctor_id  = d.id
            WHERE a.id = ?
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
     * Return recent appointments for one patient (used on patient profile pages).
     *
     * @param  int $patientId
     * @param  int $limit
     * @return array
     */
    public function getForPatient(int $patientId, int $limit = 10): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.*, d.full_name AS doctor_name
            FROM appointments a
            LEFT JOIN doctors d ON a.doctor_id = d.id
            WHERE a.patient_id = ?
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
            LIMIT ?
        ");
        $stmt->execute([$patientId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    //  READ: TODAY'S SCHEDULE
    // =========================================================================

    /**
     * Return all appointments scheduled for today, sorted by time.
     *
     * @return array
     */
    public function getTodaySchedule(): array
    {
        $stmt = $this->conn->prepare("
            SELECT
                a.*,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                p.patient_code,
                d.full_name AS doctor_name
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN doctors  d ON a.doctor_id  = d.id
            WHERE a.appointment_date = CURDATE()
            ORDER BY a.appointment_time ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    //  WRITE: CREATE
    // =========================================================================

    /**
     * Insert a new appointment row and return the new ID.
     *
     * @param  array $data
     * @return int
     */
    public function create(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO appointments
                (patient_id, doctor_id, service_id, appointment_date,
                 appointment_time, type, reason, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['patient_id'],
            $data['doctor_id']          ?? null,
            $data['service_id']         ?? null,
            $data['appointment_date'],
            $data['appointment_time'],
            $data['type']               ?? 'scheduled',
            $data['reason']             ?? null,
            $data['status']             ?? 'pending',
            $data['notes']              ?? null,
        ]);
        return (int) $this->conn->lastInsertId();
    }

    // =========================================================================
    //  WRITE: UPDATE STATUS
    // =========================================================================

    /**
     * Change the status of one appointment.
     * Only whitelisted status values are accepted.
     *
     * @param  int    $id
     * @param  string $status
     * @return bool
     */
    public function updateStatus(int $id, string $status): bool
    {
        $allowed = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "UPDATE appointments SET status = ? WHERE id = ?"
        );
        return $stmt->execute([$status, $id]);
    }

    // =========================================================================
    //  PRIVATE: Filter builder
    // =========================================================================

    /**
     * Build a WHERE clause and parameters array from a filter array.
     *
     * @param  array $filters  Keys: status, date, doctor_id, type, search
     * @return array  [string $where, array $params]
     */
    private function buildWhere(array $filters): array
    {
        $where  = "WHERE 1=1";
        $params = [];

        $allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
        if (!empty($filters['status']) &&
            in_array($filters['status'], $allowedStatuses, true)) {
            $where   .= " AND a.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['date']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
            $where   .= " AND a.appointment_date = ?";
            $params[] = $filters['date'];
        }

        if (!empty($filters['doctor_id'])) {
            $where   .= " AND a.doctor_id = ?";
            $params[] = (int) $filters['doctor_id'];
        }

        $allowedTypes = ['walk-in', 'scheduled'];
        if (!empty($filters['type']) &&
            in_array($filters['type'], $allowedTypes, true)) {
            $where   .= " AND a.type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['search'])) {
            $like    = '%' . $filters['search'] . '%';
            $where  .= " AND (
                            p.first_name                                        LIKE ? OR
                            p.last_name                                         LIKE ? OR
                            a.appointment_code                                  LIKE ? OR
                            CONCAT(p.first_name, ' ', p.last_name)             LIKE ? OR
                            CONCAT(p.last_name, ', ', p.first_name)            LIKE ? OR
                            CONCAT(p.last_name, ' ', p.first_name)             LIKE ?
                         )";
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        }

        return [$where, $params];
    }
}