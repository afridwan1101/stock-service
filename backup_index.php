<?php
// =======================================
//  STOCK SERVICE - index.php (SSO Protected)
// =======================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'path'     => '/',
        'secure'   => false, // true on HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------- CONFIG ----------------
$SSO_FRONTEND = 'http://localhost:3000';
$SSO_BACKEND  = 'http://localhost:5000';

// ---------------- BASE URL ----------------
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    ? 'https'
    : 'http';

// 🔑 CLEAN base app URL (NO query string)
$BASE_URL = $protocol . '://' . $_SERVER['HTTP_HOST']
          . strtok($_SERVER['REQUEST_URI'], '?');

// ---------------- LOGOUT ----------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {

    session_destroy();

    // ✅ always return to CLEAN app URL
    header("Location: $SSO_FRONTEND?returnUrl=" . urlencode($BASE_URL));
    exit;
}

// -------- TOKEN CALLBACK (SSO) ----------
if (isset($_GET['token']) && empty($_SESSION['token_consumed'])) {

    $_SESSION['token_consumed'] = true;
    $token = $_GET['token'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "$SSO_BACKEND/api/validate-token",
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['token' => $token]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);

        if (!empty($data['username'])) {
            $_SESSION['user_id'] = $data['username'];
            $_SESSION['role']    = $data['role'] ?? 'user';

            // ✅ remove token from URL
            header("Location: $BASE_URL");
            exit;
        }
    }

    // ❌ invalid token
    session_destroy();
    header("Location: $SSO_FRONTEND");
    exit;
}

// ------------- AUTH CHECK ---------------
if (empty($_SESSION['user_id'])) {
    header("Location: $SSO_FRONTEND?returnUrl=" . urlencode($BASE_URL));
    exit;
}

const DB_HOST = '34.128.98.14';
const DB_NAME = 'db-oracle';
const DB_USER = 'it.ridwan';
const DB_PASS = 'Bb;gWmvDQ4=!';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("DB Connection Error: " . $e->getMessage());
}

class DistributionService {
    public function __construct(private PDO $pdo) {}

    public function distributeItem(array $data): void {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO USAGE_ITEM (COMPANY_ID, EMPLOYEE_ID, TGL_TRX, DISPLAY_NAME, KET) 
                VALUES ('PT-DEF', :emp, NOW(), :name, :notes)
            ");
            $stmt->execute([
                ':emp' => $data['employee_id'],
                ':name' => 'System User', 
                ':notes' => $data['notes']
            ]);
            $usageId = $this->pdo->lastInsertId();

            $item = $this->getItemInfo($data['item_id']);

            $stmtDtl = $this->pdo->prepare("
                INSERT INTO USAGE_ITEM_DTL (USAGE_ID, ITEM_ID, ITEM_NO, ITEM_DESC, QTY, UOM) 
                VALUES (:uid, :iid, :ino, :idesc, :qty, :uom)
            ");
            $stmtDtl->execute([
                ':uid' => $usageId,
                ':iid' => $item['ITEM_ID'],
                ':ino' => $item['ITEM_NO'],
                ':idesc' => $item['ITEM_DESC'],
                ':qty' => $data['qty'],
                ':uom' => $item['UOM']
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function returnItem(array $data): void {
        $this->pdo->beginTransaction();
        try {

            $stmt = $this->pdo->prepare("
                INSERT INTO RETURN_ITEM (COMPANY_ID, EMPLOYEE_ID, TGL_RETURN, DISPLAY_NAME, TYPE_RETURN, KET) 
                VALUES ('PT-DEF', :emp, NOW(), 'System User', 'RETRIEVAL', :notes)
            ");
            $stmt->execute([':emp' => $data['employee_id'], ':notes' => $data['notes']]);
            $retId = $this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare("SELECT * FROM ITEM_MASTER WHERE ITEM_NO = ?");
            $stmtItem->execute([$data['item_no']]);
            $item = $stmtItem->fetch();

            $stmtDtl = $this->pdo->prepare("
                INSERT INTO RETURN_ITEM_DTL (RETURN_ID, ITEM_ID, ITEM_NO, ITEM_DESC, QTY, UOM) 
                VALUES (:rid, :iid, :ino, :idesc, :qty, :uom)
            ");
            $stmtDtl->execute([
                ':rid' => $retId,
                ':iid' => $item['ITEM_ID'],
                ':ino' => $item['ITEM_NO'],
                ':idesc' => $item['ITEM_DESC'],
                ':qty' => $data['qty'],
                ':uom' => $item['UOM']
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function getItemInfo($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM ITEM_MASTER WHERE ITEM_ID = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getEmployeePossessions(): array {
        return $this->pdo->query("
            SELECT v.*, p.FIRST_NAME 
            FROM v_employee_asset_possession v
            JOIN EMPLOYEE_TBL e ON v.EMPLOYEE_ID = e.EMPLOYEE_ID
            JOIN PERSON_TBL p ON e.PERSON_ID = p.PERSON_ID
            ORDER BY p.FIRST_NAME ASC
        ")->fetchAll();
    }

    public function getEmployees(): array {
        return $this->pdo->query("
            SELECT e.EMPLOYEE_ID, p.FIRST_NAME, p.LAST_NAME 
            FROM EMPLOYEE_TBL e 
            JOIN PERSON_TBL p ON e.PERSON_ID = p.PERSON_ID 
            ORDER BY p.FIRST_NAME
        ")->fetchAll();
    }

    public function getItems(): array {
        return $this->pdo->query("SELECT ITEM_ID, ITEM_DESC, ITEM_NO FROM ITEM_MASTER ORDER BY ITEM_DESC")->fetchAll();
    }
}

$service = new DistributionService($pdo);
$msg = ''; 
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['act_distribute'])) {
            $service->distributeItem($_POST);
            $msg = "Item Distributed Successfully";
            $msgType = 'success';
        } elseif (isset($_POST['act_return'])) {
            $service->returnItem($_POST);
            $msg = "Item Retrieved (Returned) Successfully";
            $msgType = 'success';
        }
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
        $msgType = 'danger';
    }
}

$possessions = $service->getEmployeePossessions();
$employees = $service->getEmployees();
$items = $service->getItems();

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Inventory Distribution</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<!-- ✅ Logout Button in Top-Right Corner -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1050;">
    <a href="?action=logout" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-box-arrow-right"></i> Logout
    </a>
</div>

<div class="container py-4">
    <h3 class="mb-4 text-success fw-bold">🤝 Asset Distribution & Retrieval</h3>

    <?php if($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-success h-100">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-box-seam me-2"></i> Distribute Asset (Outgoing)
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="act_distribute" value="1">
                        <div class="mb-3">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select Employee...</option>
                                <?php foreach($employees as $e): ?>
                                    <option value="<?= h($e['EMPLOYEE_ID']) ?>">
                                        <?= h($e['FIRST_NAME'] . ' ' . $e['LAST_NAME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Item to Issue</label>
                            <select name="item_id" class="form-select" required>
                                <option value="">Select Item...</option>
                                <?php foreach($items as $i): ?>
                                    <option value="<?= h($i['ITEM_ID']) ?>">
                                        <?= h($i['ITEM_DESC']) ?> (<?= h($i['ITEM_NO']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label>Qty</label><input type="number" step="0.01" name="qty" class="form-control" value="1" required></div>
                            <div class="col-6"><label>Notes</label><input type="text" name="notes" class="form-control" placeholder="Reason"></div>
                        </div>
                        <button class="btn btn-success w-100">Assign to Employee</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold">🎒 Employee Possession List</span>
                    <span class="badge bg-secondary">Retrieve/Return items here</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Item</th>
                                <th class="text-center">Held Qty</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!$possessions): ?><tr><td colspan="4" class="text-center p-4">No assets currently held by employees.</td></tr><?php endif; ?>
                            <?php foreach($possessions as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= h($row['FIRST_NAME']) ?></div>
                                    <div class="small text-muted"><?= h($row['EMPLOYEE_ID']) ?></div>
                                </td>
                                <td>
                                    <div><?= h($row['ITEM_DESC']) ?></div>
                                    <div class="small text-muted"><?= h($row['ITEM_NO']) ?></div>
                                </td>
                                <td class="text-center fw-bold fs-5"><?= number_format((float)$row['CURRENT_POSSESSION'], 0) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" 
                                            data-bs-target="#returnModal" 
                                            onclick="setupReturn('<?= h($row['EMPLOYEE_ID']) ?>', '<?= h($row['ITEM_NO']) ?>', '<?= h($row['ITEM_DESC']) ?>', <?= h($row['CURRENT_POSSESSION']) ?>)">
                                        <i class="bi bi-arrow-return-left"></i> Return
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="act_return" value="1">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Retrieve Asset (Return)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label>Employee ID</label>
                    <input type="text" name="employee_id" id="ret_emp_id" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label>Item</label>
                    <input type="text" id="ret_item_name" class="form-control" readonly>
                    <input type="hidden" name="item_no" id="ret_item_no">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label>Return Qty</label>
                        <input type="number" step="0.01" name="qty" id="ret_qty" class="form-control" max="" required>
                        <div class="form-text">Max: <span id="ret_max"></span></div>
                    </div>
                    <div class="col-6">
                        <label>Condition/Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Good, Broken" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger">Confirm Return</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setupReturn(empId, itemNo, itemName, maxQty) {
    document.getElementById('ret_emp_id').value = empId;
    document.getElementById('ret_item_no').value = itemNo;
    document.getElementById('ret_item_name').value = itemName;
    document.getElementById('ret_qty').value = maxQty;
    document.getElementById('ret_qty').setAttribute('max', maxQty);
    document.getElementById('ret_max').innerText = maxQty;
}
</script>
</body>
</html>