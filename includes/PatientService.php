<?php
// =============================================================================
//  PatientService.php — OOP Service Class for Patient Operations
// =============================================================================
//  Demonstrates:
//    • Encapsulation  — all SQL and patient logic live inside this class,
//                       hidden from the pages that use it.
//    • Constructor Injection — the PDO connection is injected, not global.
//    • Single Responsibility — this class handles ONLY patient data access.
//
//  Usage (in any module file):
//      require_once '../../includes/PatientService.php';
//      $patientService = new PatientService($conn);
//      $patients = $patientService->getList(['search' => 'Cruz'], 20, 0);
// =============================================================================

class PatientService
{
    // ── Private property: only this class can touch $conn ─────────────────────
    private PDO $conn;

    // ── Constructor: PDO connection is injected (not fetched from a global) ───
    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    // =========================================================================
    //  READ: COUNT
    // =========================================================================

    /**
     * Return the total number of active patients matching $filters.
     *
     * @param  array $filters  Keys: search, gender, blood_type
     * @return int
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS c FROM patients p $where"
        );
        $stmt->execute($params);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    }

    // =========================================================================
    //  READ: LIST (paginated)
    // =========================================================================

    /**
     * Return a paginated array of active patient rows.
     * Each row includes all patients columns + total_visits (completed appts).
     *
     * @param  array $filters  Keys: search, gender, blood_type
     * @param  int   $limit    Rows per page
     * @param  int   $offset   Pagination offset
     * @param  string $order   'newest' (default) = most recently registered first,
     *                         'oldest' = earliest registered first.
     *                         Ties (same timestamp) fall back to id so the order is stable.
     * @return array
     */
    public function getList(array $filters = [], int $limit = 20, int $offset = 0, string $order = 'newest'): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $params[] = $limit;
        $params[] = $offset;

        // Whitelisted — never interpolate user input into ORDER BY directly.
        $orderBy = ($order === 'oldest')
            ? 'p.created_at ASC, p.id ASC'
            : 'p.created_at DESC, p.id DESC';

        $stmt = $this->conn->prepare("
            SELECT p.*, COUNT(a.id) AS total_visits
            FROM patients p
            LEFT JOIN appointments a
                ON a.patient_id = p.id AND a.status = 'completed'
            $where
            GROUP BY p.id
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    //  READ: SINGLE RECORD
    // =========================================================================

    /**
     * Fetch one patient by primary key. Returns null when not found.
     *
     * @param  int       $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM patients WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // =========================================================================
    //  READ: SEARCH (autocomplete)
    // =========================================================================

    /**
     * Quick search by name, patient code, or phone — for autocomplete widgets.
     *
     * @param  string $query
     * @param  int    $limit
     * @return array
     */
    public function search(string $query, int $limit = 30): array
    {
        $like = '%' . $query . '%';
        $stmt = $this->conn->prepare("
            SELECT id, patient_code, first_name, last_name, phone, email
            FROM patients
            WHERE is_active = TRUE
              AND (
                  first_name   LIKE ? OR
                  last_name    LIKE ? OR
                  patient_code LIKE ? OR
                  phone        LIKE ?
              )
            ORDER BY last_name, first_name
            LIMIT ?
        ");
        $stmt->execute([$like, $like, $like, $like, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    //  READ: STATS (for the stats bar on the list page)
    // =========================================================================

    /**
     * Return aggregate stats across all active patients.
     * Keys: total, males, females, new_this_month, incomplete_count
     *
     * @return array
     */
    public function getStats(): array
    {
        $row = $this->conn->query("
            SELECT
                COUNT(*)                                                               AS total,
                SUM(CASE WHEN gender = 'male'   THEN 1 ELSE 0 END)                   AS males,
                SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END)                   AS females,
                SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN 1
                         ELSE 0 END)                                                  AS new_this_month,
                SUM(CASE WHEN is_incomplete = TRUE THEN 1 ELSE 0 END)                AS incomplete_count
            FROM patients
            WHERE is_active = TRUE
        ")->fetch(PDO::FETCH_ASSOC);

        return $row ?: [
            'total'          => 0,
            'males'          => 0,
            'females'        => 0,
            'new_this_month' => 0,
            'incomplete_count' => 0,
        ];
    }

    // =========================================================================
    //  READ: BLOOD TYPES (for filter pills)
    // =========================================================================

    /**
     * Return the distinct blood type values present in the patients table.
     *
     * @return array<string>
     */
    public function getBloodTypes(): array
    {
        return $this->conn->query("
            SELECT DISTINCT blood_type
            FROM patients
            WHERE is_active = TRUE
              AND blood_type IS NOT NULL
              AND blood_type != ''
            ORDER BY blood_type
        ")->fetchAll(PDO::FETCH_COLUMN);
    }

    // =========================================================================
    //  WRITE: CREATE
    // =========================================================================

    /**
     * Insert a new patient row and return the new auto-increment ID.
     *
     * @param  array $data  Associative array matching the patients columns.
     * @return int          The new patient ID.
     */
    public function create(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO patients
                (patient_code, first_name, last_name, date_of_birth, gender,
                 phone, email, address, blood_type,
                 emergency_contact_name, emergency_contact_phone,
                 medical_history, allergies)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['patient_code'],
            $data['first_name'],
            $data['last_name'],
            $data['date_of_birth']           ?? null,
            $data['gender']                  ?? null,
            $data['phone']                   ?? null,
            $data['email']                   ?? null,
            $data['address']                 ?? null,
            $data['blood_type']              ?? null,
            $data['emergency_contact_name']  ?? null,
            $data['emergency_contact_phone'] ?? null,
            $data['medical_history']         ?? null,
            $data['allergies']               ?? null,
        ]);
        return (int) $this->conn->lastInsertId();
    }

    // =========================================================================
    //  WRITE: UPDATE
    // =========================================================================

    /**
     * Update an existing patient record.
     *
     * @param  int   $id
     * @param  array $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE patients
            SET first_name               = ?,
                last_name                = ?,
                date_of_birth            = ?,
                gender                   = ?,
                phone                    = ?,
                email                    = ?,
                address                  = ?,
                blood_type               = ?,
                emergency_contact_name   = ?,
                emergency_contact_phone  = ?,
                medical_history          = ?,
                allergies                = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['first_name'],
            $data['last_name'],
            $data['date_of_birth']           ?? null,
            $data['gender']                  ?? null,
            $data['phone']                   ?? null,
            $data['email']                   ?? null,
            $data['address']                 ?? null,
            $data['blood_type']              ?? null,
            $data['emergency_contact_name']  ?? null,
            $data['emergency_contact_phone'] ?? null,
            $data['medical_history']         ?? null,
            $data['allergies']               ?? null,
            $id,
        ]);
    }

    // =========================================================================
    //  WRITE: ARCHIVE / RESTORE (soft-delete)
    // =========================================================================

    /**
     * Soft-delete a patient (sets is_active = FALSE).
     *
     * @param  int  $id
     * @return bool
     */
    public function archive(int $id): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE patients SET is_active = FALSE WHERE id = ?"
        );
        return $stmt->execute([$id]);
    }

    /**
     * Restore a previously archived patient (sets is_active = TRUE).
     *
     * @param  int  $id
     * @return bool
     */
    public function restore(int $id): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE patients SET is_active = TRUE WHERE id = ?"
        );
        return $stmt->execute([$id]);
    }

    // =========================================================================
    //  PRIVATE: Filter builder
    // =========================================================================

    /**
     * Build the WHERE clause and parameter array from a filter array.
     * Kept private — callers pass a clean $filters array, not raw SQL.
     *
     * @param  array $filters
     * @return array  [string $where, array $params]
     */
    private function buildWhere(array $filters): array
    {
        $where  = "WHERE p.is_active = TRUE";
        $params = [];

        if (!empty($filters['search'])) {
            $like    = '%' . $filters['search'] . '%';
            $where  .= " AND (
                            p.first_name                                        LIKE ? OR
                            p.last_name                                         LIKE ? OR
                            p.patient_code                                      LIKE ? OR
                            p.phone                                             LIKE ? OR
                            CONCAT(p.first_name, ' ', p.last_name)             LIKE ? OR
                            CONCAT(p.last_name, ', ', p.first_name)            LIKE ? OR
                            CONCAT(p.last_name, ' ', p.first_name)             LIKE ?
                         )";
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
        }

        if (!empty($filters['gender']) &&
            in_array($filters['gender'], ['male', 'female', 'other'], true)) {
            $where   .= " AND p.gender = ?";
            $params[] = $filters['gender'];
        }

        if (!empty($filters['blood_type'])) {
            $where   .= " AND p.blood_type = ?";
            $params[] = $filters['blood_type'];
        }

        return [$where, $params];
    }
}