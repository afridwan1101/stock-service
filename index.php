<?php
// =======================================
//  STOCK SERVICE - index.php (SSO Protected)
// =======================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------- HELPER FUNCTIONS ----------------
function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// ---------------- GREETING HELPER ----------------
function getGreeting() {
    $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $hour = (int) $dt->format('H');

    if ($hour >= 18) {
        return "Good Evening";
    } elseif ($hour >= 12) {
        return "Good Afternoon";
    } else {
        return "Good Morning";
    }
}

// ---------------- CONFIG ----------------
// $SSO_FRONTEND = 'http://localhost:3000';
// $SSO_BACKEND  = 'http://localhost:5000';
$SSO_FRONTEND = 'https://sso.ceresnl.com';
$SSO_BACKEND  = 'https://sso.ceresnl.com:50443';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$BASE_URL = $protocol . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
$APP_BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?: '/';

// ---------------- LOGOUT ----------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {

    // Optional: call SSO logout API here
    // file_get_contents(...) or cURL

    // Destroy PHP session
    $_SESSION = [];
    session_destroy();

    // Remove PHP session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // Redirect to login
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
            $_SESSION['fname']   = $data['fname'] ?? $data['username'];
            header("Location: $BASE_URL");
            exit;
        }
    }

    session_destroy();
    header("Location: $SSO_FRONTEND");
    exit;
}

// ------------- AUTH CHECK ---------------
if (empty($_SESSION['user_id'])) {
    header("Location: $SSO_FRONTEND?returnUrl=" . urlencode($BASE_URL));
    exit;
}

// ---------------- MY ACCOUNT VIEW (SESSION ONLY) ----------------
if (isset($_GET['view']) && $_GET['view'] === 'my-account') {
    $email = $_SESSION['user_id'];
    $name  = $_SESSION['fname'] ?? $email;
    $role  = $_SESSION['role'] ?? 'user';
    $statusText = 'Active';
    $statusClass = 'success';
    ?>
<!DOCTYPE html>
<html lang="en-US">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <style>
    .profile-card {
        max-width: 600px;
        margin: 2rem auto;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    }

    .profile-header {
        background: linear-gradient(135deg, #083652, #1a5c8c);
        color: white;
        padding: 1.5rem;
        text-align: center;
        border-radius: 0.5rem 0.5rem 0 0;
    }

    .profile-body {
        padding: 2rem;
    }

    .profile-item {
        margin-bottom: 1rem;
    }

    .profile-label {
        font-weight: bold;
        color: #555;
    }

    .main-sidebar {
        background-color: #083652 !important;
    }

    .main-sidebar .brand-link {
        background-color: #083652 !important;
        color: #fff !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .main-sidebar .sidebar {
        background-color: #083652 !important;
    }

    .main-sidebar .nav-sidebar>.nav-item>.nav-link,
    .main-sidebar .nav-header {
        color: rgba(255, 255, 255, 0.85);
    }

    .main-sidebar .nav-sidebar>.nav-item>.nav-link:hover {
        background-color: rgba(255, 255, 255, 0.1);
        color: #fff;
    }

    .main-sidebar .nav-sidebar>.nav-item>.nav-link.active,
    .main-sidebar .nav-sidebar>.nav-item>.nav-link.active:hover {
        background-color: rgba(255, 255, 255, 0.2);
        color: #fff;
    }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-navbar-fixed layout-fixed">
    <div class="wrapper">
        <nav class="main-header navbar navbar-expand navbar-white navbar-light">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                        <i class="fas fa-bars"></i>
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link" data-toggle="dropdown" href="#">
                        <i class="fas fa-user text-primary"></i>
                        <span class="ml-1 text-dark"><?= h(getGreeting()) ?>, <?= h($name) ?></span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="<?= h($APP_BASE) ?>/?view=my-account" class="dropdown-item active">
                            <i class="fas fa-user-circle mr-2 text-primary"></i> My Account
                        </a>
                        <a href="<?= h($APP_BASE) ?>/settings" class="dropdown-item">
                            <i class="fas fa-cog mr-2 text-success"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="#" onclick="handleLogout();return false;" class="dropdown-item">
                            <i class="fas fa-sign-out-alt mr-2 text-danger"></i> Logout
                        </a>
                    </div>
                </li>
            </ul>
        </nav>
        <aside class="main-sidebar sidebar-dark-primary elevation-4">
            <a href="<?= h($APP_BASE) ?>/" class="brand-link">
                <img src="https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEino1q43LiUDhvzaUKJx82I0jXa30TpJqhGexeJnoji_0zf3Pjog4aW099h1HkfXjso-LnNqizIlBYKaBeChFVH67LsLUcQ-cG_S92GC63DydTpSJ51gnakLJaYdi43EPARUrw_J2HtK5BA7y0ETAgVUJoINVjkxAhGJNmfRVvfFMYJkLzNp5J8uqrDhWs/s325/logoapp-red.png"
                    alt="AdminLTE Logo" class="brand-image img-circle elevation-3" style="opacity: .8">
                <span class="brand-text font-weight-light">Inventory Distribution</span>
            </a>
            <div class="sidebar">
                <nav class="mt-2">
                    <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu"
                        data-accordion="false">
                        <li class="nav-item">
                            <a href="<?= h($APP_BASE) ?>/" class="nav-link">
                                <i class="nav-icon fas fa-home"></i>
                                <p>Dashboard</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="<?= h($APP_BASE) ?>/?view=my-account" class="nav-link active">
                                <i class="nav-icon fas fa-user"></i>
                                <p>My Account</p>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>
        <div class="content-wrapper p-4">
            <div class="container-fluid">
                <div class="profile-card card">
                    <div class="profile-header">
                        <h4><?= h($name) ?></h4>
                        <p class="mb-0"><?= h($email) ?></p>
                    </div>
                    <div class="profile-body">
                        <div class="profile-item"><span class="profile-label">Full Name:</span><br><?= h($name) ?></div>
                        <div class="profile-item"><span class="profile-label">Email:</span><br><?= h($email) ?></div>
                        <div class="profile-item"><span class="profile-label">Role:</span><br><?= h(ucfirst($role)) ?>
                        </div>
                        <div class="profile-item">
                            <span class="profile-label">Status:</span><br>
                            <span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script src="plugins/jquery/jquery.min.js"></script>
        <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script src="dist/js/adminlte.js"></script>
</body>

</html>
<?php
exit;
}

// ---------------- DATABASE ----------------
const DB_HOST = '34.128.98.14';
const DB_NAME = 'db-oracle';
const DB_USER = 'it.ridwan';
const DB_PASS = 'Bb;gWmvDQ4=!';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("DB Connection Error: " . h($e->getMessage()));
}

// ---------------- SERVICE ----------------
class DistributionService {
    public function __construct(private PDO $pdo) {}

    // VALIDATE & DISTRIBUTE WITH STOCK CHECK
    public function distributeItems(array $data): void {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new InvalidArgumentException("No items provided.");
        }
        if (empty($data['company_id'])) {
            throw new RuntimeException("Company ID is missing for employee.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO USAGE_ITEM (COMPANY_ID, EMPLOYEE_ID, TGL_TRX, DISPLAY_NAME, KET, SK_NUMBER, TYPE)
                VALUES (:company_id, :emp, NOW(), 'System User', :notes, :sk_number, :type)
            ");
            $stmtHeader->execute([
                ':company_id' => $data['company_id'],
                ':emp'        => $data['employee_id'],
                ':notes'      => $data['notes'] ?? '',
                ':sk_number'  => $data['sk_number'] ?? '',
                ':type'       => $data['type'] ?? '',
            ]);
            $usageId = $this->pdo->lastInsertId();

            $stmtDtl = $this->pdo->prepare("
                INSERT INTO USAGE_ITEM_DTL (USAGE_ID, ITEM_ID, ITEM_NO, ITEM_DESC, QTY, UOM)
                VALUES (:uid, :iid, :ino, :idesc, :qty, :uom)
            ");

            foreach ($data['items'] as $itemInput) {
                if (empty($itemInput['item_id']) || empty($itemInput['qty'])) continue;

                $itemId = $itemInput['item_id'];
                $requestedQty = (float)$itemInput['qty'];

                // 🔒 SERVER-SIDE STOCK VALIDATION
                $availStmt = $this->pdo->prepare("
                    SELECT 
                        COALESCE(SUM(rid.QTY), 0) - COALESCE(SUM(uid.QTY), 0) AS available
                    FROM ITEM_MASTER im
                    LEFT JOIN RECEIVE_ITEM_DTL rid ON im.ITEM_ID = rid.ITEM_ID
                    LEFT JOIN USAGE_ITEM_DTL uid ON im.ITEM_ID = uid.ITEM_ID
                    WHERE im.ITEM_ID = ?
                    GROUP BY im.ITEM_ID
                ");
                $availStmt->execute([$itemId]);
                $available = (float)($availStmt->fetchColumn() ?? 0);

                if ($requestedQty > $available) {
                    throw new RuntimeException("Insufficient stock for item ID $itemId. Available: $available, Requested: $requestedQty");
                }

                $item = $this->getItemInfo($itemId);
                if (!$item) {
                    throw new RuntimeException("Item ID {$itemId} not found.");
                }

                $stmtDtl->execute([
                    ':uid'    => $usageId,
                    ':iid'    => $item['ITEM_ID'],
                    ':ino'    => $item['ITEM_NO'],
                    ':idesc'  => $item['ITEM_DESC'],
                    ':qty'    => $requestedQty,
                    ':uom'    => $item['UOM']
                ]);
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function returnItem(array $data): void {
        $employeeId = $data['employee_id'];
        $itemNo     = $data['item_no'];
        $qty        = (int)$data['qty'];
        $notes      = $data['notes'] ?? '';

        if ($qty <= 0) {
            throw new RuntimeException("Return quantity must be greater than zero.");
        }

        $empStmt = $this->pdo->prepare("SELECT COMPANY_ID FROM EMPLOYEE_TBL WHERE EMPLOYEE_ID = ?");
        $empStmt->execute([$employeeId]);
        $companyId = $empStmt->fetchColumn();
        if (!$companyId) {
            throw new RuntimeException("Employee or company not found.");
        }

        $stmtCheck = $this->pdo->prepare("
            SELECT CURRENT_POSSESSION
            FROM v_employee_asset_possession
            WHERE EMPLOYEE_ID = ? AND ITEM_NO = ?
        ");
        $stmtCheck->execute([$employeeId, $itemNo]);
        $current = (int)$stmtCheck->fetchColumn();
        if ($current <= 0) {
            throw new RuntimeException("Employee does not hold this item.");
        }
        if ($qty > $current) {
            throw new RuntimeException("Return quantity exceeds held quantity.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO RETURN_ITEM
                (COMPANY_ID, EMPLOYEE_ID, TGL_RETURN, DISPLAY_NAME, TYPE_RETURN, KET)
                VALUES (:company_id, :emp, NOW(), 'System User', 'RETRIEVAL', :notes)
            ");
            $stmt->execute([
                ':company_id' => $companyId,
                ':emp'        => $employeeId,
                ':notes'      => $notes
            ]);
            $returnId = $this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare("
                SELECT ITEM_ID, ITEM_NO, ITEM_DESC, UOM
                FROM ITEM_MASTER
                WHERE ITEM_NO = ?
            ");
            $stmtItem->execute([$itemNo]);
            $item = $stmtItem->fetch();
            if (!$item) {
                throw new RuntimeException("Item not found.");
            }

            $stmtDtl = $this->pdo->prepare("
                INSERT INTO RETURN_ITEM_DTL
                (RETURN_ID, ITEM_ID, ITEM_NO, ITEM_DESC, QTY, UOM)
                VALUES (:rid, :iid, :ino, :idesc, :qty, :uom)
            ");
            $stmtDtl->execute([
                ':rid'    => $returnId,
                ':iid'    => $item['ITEM_ID'],
                ':ino'    => $item['ITEM_NO'],
                ':idesc'  => $item['ITEM_DESC'],
                ':qty'    => $qty,
                ':uom'    => $item['UOM']
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

    public function getEmployees(): array {
        return $this->pdo->query("
            SELECT e.EMPLOYEE_ID, p.FIRST_NAME, p.LAST_NAME, e.COMPANY_ID
            FROM EMPLOYEE_TBL e
            JOIN PERSON_TBL p ON e.PERSON_ID = p.PERSON_ID
            ORDER BY p.FIRST_NAME
        ")->fetchAll();
    }

    // GET ITEMS WITH AVAILABLE QUANTITY
    public function getItemsWithAvailability(): array {
        $sql = "
            SELECT 
                im.ITEM_ID,
                im.ITEM_NO,
                im.ITEM_DESC,
                im.UOM,
                COALESCE(SUM(rid.QTY), 0) AS total_received,
                COALESCE(SUM(uid.QTY), 0) AS total_used,
                (COALESCE(SUM(rid.QTY), 0) - COALESCE(SUM(uid.QTY), 0)) AS available_qty
            FROM ITEM_MASTER im
            LEFT JOIN RECEIVE_ITEM_DTL rid ON im.ITEM_ID = rid.ITEM_ID
            LEFT JOIN USAGE_ITEM_DTL uid ON im.ITEM_ID = uid.ITEM_ID
            GROUP BY im.ITEM_ID, im.ITEM_NO, im.ITEM_DESC, im.UOM
            HAVING available_qty > 0
            ORDER BY im.ITEM_DESC ASC
        ";
        return $this->pdo->query($sql)->fetchAll();
    }
}

$service = new DistributionService($pdo);

// ---------------- EMPLOYEE SEARCH API ----------------
if (isset($_GET['api']) && $_GET['api'] === 'search-employees') {
    header('Content-Type: application/json');
    $term = trim($_GET['q'] ?? '');
    $results = [];
    if (strlen($term) >= 2) {
        $stmt = $pdo->prepare("
            SELECT e.EMPLOYEE_ID, p.FIRST_NAME, p.LAST_NAME
            FROM EMPLOYEE_TBL e
            JOIN PERSON_TBL p ON e.PERSON_ID = p.PERSON_ID
            WHERE p.FIRST_NAME LIKE ? OR p.LAST_NAME LIKE ? OR e.EMPLOYEE_ID LIKE ?
            ORDER BY p.FIRST_NAME
            LIMIT 10
        ");
        $like = "%$term%";
        $stmt->execute([$like, $like, $like]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($results);
    exit;
}

// Handle flash message from redirect
$msg = '';
$msgType = '';
if (isset($_GET['msg'])) {
    $msg = h($_GET['msg']);
    $msgType = h($_GET['msgType'] ?? 'info');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['act_distribute_multi'])) {
            $items = [];
            foreach ($_POST['items'] ?? [] as $item) {
                if (!empty($item['item_id']) && !empty($item['qty']) && (float)$item['qty'] > 0) {
                    $items[] = $item;
                }
            }
            if (empty($items)) {
                throw new Exception("No valid items to distribute.");
            }
            if (empty($_POST['employee_id'])) {
                throw new Exception("Please select an employee.");
            }
            $empStmt = $pdo->prepare("SELECT COMPANY_ID FROM EMPLOYEE_TBL WHERE EMPLOYEE_ID = ?");
            $empStmt->execute([$_POST['employee_id']]);
            $empRow = $empStmt->fetch();
            if (!$empRow) {
                throw new Exception("Employee not found.");
            }
            $service->distributeItems([
                'employee_id' => $_POST['employee_id'],
                'company_id'  => $empRow['COMPANY_ID'],
                'notes'       => $_POST['notes'] ?? '',
                'sk_number'   => $_POST['sk_number'] ?? '',
                'type'        => $_POST['type'] ?? '',
                'items'       => $items
            ]);
            header("Location: $BASE_URL?msg=" . urlencode("Items Distributed Successfully") . "&msgType=success");
            exit;
        } elseif (isset($_POST['act_return'])) {
            $service->returnItem($_POST);
            header("Location: $BASE_URL?msg=" . urlencode("Item Retrieved (Returned) Successfully") . "&msgType=success");
            exit;
        }
    } catch (Exception $e) {
        $msg = "Error: " . h($e->getMessage());
        $msgType = 'danger';
    }
}

// =============== PAGINATION LOGIC ===============
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $limit;
$totalPossessions = $pdo->query("SELECT COUNT(*) FROM v_employee_asset_possession")->fetchColumn();
$totalPossessions = (int)$totalPossessions;
$totalPages = ceil($totalPossessions / $limit);
$stmt = $pdo->prepare("
    SELECT v.*, p.FIRST_NAME
    FROM v_employee_asset_possession v
    JOIN EMPLOYEE_TBL e ON v.EMPLOYEE_ID = e.EMPLOYEE_ID
    JOIN PERSON_TBL p ON e.PERSON_ID = p.PERSON_ID
    ORDER BY p.FIRST_NAME ASC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$possessions = $stmt->fetchAll();

$employees = $service->getEmployees();
$items = $service->getItemsWithAvailability(); // Use availability-aware method
?>

<!DOCTYPE html>
<html lang="en-US">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Inventory Distribution</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
    <style>
    body {
        font-size: 0.875rem;
        background-color: #f8f9fa;
    }

    .nav-tabs .nav-link.active {
        font-weight: bold;
        border-top: 3px solid #0d6efd;
        background-color: #fff;
        border-bottom-color: transparent;
    }

    .table-matrix td {
        padding: 0.25rem;
        vertical-align: middle;
    }

    .form-check-input {
        cursor: pointer;
    }

    .select2-container {
        width: 100% !important;
    }

    .modal-dialog {
        max-width: 95%;
        margin: 1.75rem auto;
    }

    .side-menu {
        width: 100%;
    }

    @media (min-width: 992px) {
        .side-menu {
            min-width: 25%;
            max-width: 25%;
        }

        .tab-content {
            width: 75%;
        }
    }

    .pagination {
        margin-bottom: 0;
    }

    .context-header {
        background-color: #e9ecef;
        border-left: 4px solid #0d6efd;
    }

    #employee_results {
        position: absolute;
        z-index: 1000;
        max-height: 200px;
        overflow-y: auto;
        width: 100%;
        background: white;
        border: 1px solid #ced4da;
        border-top: none;
        border-radius: 0 0 0.375rem 0.375rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        display: none;
    }

    #employee_results .list-group-item {
        cursor: pointer;
        padding: 0.5rem 0.75rem;
    }

    #employee_results .list-group-item:hover {
        background-color: #e9ecef;
    }

    .nav-item-aligned {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .nav-item-aligned .nav-link {
        padding: 0.5rem 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        color: inherit;
    }

    .nav-item-aligned .nav-link i {
        margin: 0;
        vertical-align: middle;
    }

    .main-sidebar {
        background-color: #083652 !important;
    }

    .main-sidebar .brand-link {
        background-color: #083652 !important;
        color: #fff !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .main-sidebar .sidebar {
        background-color: #083652 !important;
    }

    .main-sidebar .nav-sidebar>.nav-item>.nav-link,
    .main-sidebar .nav-header {
        color: rgba(255, 255, 255, 0.85);
    }

    .main-sidebar .nav-sidebar>.nav-item>.nav-link:hover {
        background-color: rgba(255, 255, 255, 0.1);
        color: #fff;
    }

    .main-sidebar .nav-sidebar>.nav-item>.nav-link.active,
    .main-sidebar .nav-sidebar>.nav-item>.nav-link.active:hover {
        background-color: rgba(255, 255, 255, 0.2);
        color: #fff;
    }

    .qty-error {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25) !important;
    }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-navbar-fixed layout-fixed sidebar-collapse">
    <div class="wrapper">
        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand navbar-white navbar-light">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                        <i class="fas fa-bars"></i>
                    </a>
                </li>
                <li class="nav-item d-none d-sm-inline-block">
                    <span class="nav-link font-weight-bold">
                        Happy, <span id="currentDay"></span>
                    </span>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link" data-toggle="dropdown" href="#">
                        <i class="fas fa-user text-primary"></i>
                        <span class="ml-1 text-dark">
                            <?= h(getGreeting()) ?>, <?= h($_SESSION['fname'] ?? 'Guest') ?>
                        </span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="<?= h($APP_BASE) ?>/?view=my-account" class="dropdown-item">
                            <i class="fas fa-user-circle mr-2 text-primary"></i> My Account
                        </a>
                        <a href="<?= h($APP_BASE) ?>/settings" class="dropdown-item">
                            <i class="fas fa-cog mr-2 text-success"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="#" onclick="handleLogout();return false;" class="dropdown-item">
                            <i class="fas fa-sign-out-alt mr-2 text-danger"></i> Logout
                        </a>
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link" data-toggle="dropdown" href="#">
                        <i class="fas fa-th text-danger"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right p-3" style="width:220px;">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <a href="https://dev.ceresnl.com/itam" target="_blank" class="text-dark">
                                    <i class="fas fa-file-alt fa-lg text-danger"></i>
                                    <div class="small mt-1">Itam</div>
                                </a>
                            </div>
                            <div class="col-6 mb-3">
                                <a href="https://dev.ceresnl.com/hrd" target="_blank" class="text-dark">
                                    <i class="fas fa-book-open fa-lg text-primary"></i>
                                    <div class="small mt-1">Recruitment</div>
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="https://dev.ceresnl.com/incoming" target="_blank" class="text-dark">
                                    <i class="fas fa-bookmark fa-lg text-success"></i>
                                    <div class="small mt-1">Incoming</div>
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="https://dev.ceresnl.com/outgoing" target="_blank" class="text-dark">
                                    <i class="fas fa-file-alt fa-lg text-warning"></i>
                                    <div class="small mt-1">Outgoing</div>
                                </a>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
        </nav>
        <!-- Main Sidebar Container -->
        <aside class="main-sidebar sidebar-dark-primary elevation-4">
            <a href="<?= h($APP_BASE) ?>/" class="brand-link">
                <img src="https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEino1q43LiUDhvzaUKJx82I0jXa30TpJqhGexeJnoji_0zf3Pjog4aW099h1HkfXjso-LnNqizIlBYKaBeChFVH67LsLUcQ-cG_S92GC63DydTpSJ51gnakLJaYdi43EPARUrw_J2HtK5BA7y0ETAgVUJoINVjkxAhGJNmfRVvfFMYJkLzNp5J8uqrDhWs/s325/logoapp-red.png"
                    alt="AdminLTE Logo" class="brand-image img-circle elevation-3" style="opacity: .8">
                <span class="brand-text font-weight-light">Inventory Distribution</span>
            </a>
            <div class="sidebar">
                <nav class="mt-2">
                    <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu"
                        data-accordion="false">
                        <li class="nav-item">
                            <a href="<?= h($APP_BASE) ?>/" class="nav-link active">
                                <i class="nav-icon fas fa-home"></i>
                                <p>Dashboard</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="<?= h($APP_BASE) ?>/?view=my-account" class="nav-link">
                                <i class="nav-icon fas fa-user"></i>
                                <p>My Account</p>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>
        <!-- Content Wrapper -->
        <div class="content-wrapper p-4">
            <?php if ($msg): ?>
            <div class="alert alert-<?= h($msgType) ?> alert-dismissible fade show" role="alert">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card shadow-sm border-success h-100">
                        <div class="card-header bg-success text-white">
                            <i class="bi bi-box-seam me-2"></i> Distribute Asset (Outgoing)
                        </div>
                        <div class="card-body">
                            <form method="POST" id="distributeForm">
                                <input type="hidden" name="act_distribute_multi" value="1">
                                <!-- SEARCH EMPLOYEE INPUT -->
                                <div class="mb-3">
                                    <label class="form-label">Search Employee</label>
                                    <input type="text" id="employee_search" class="form-control"
                                        placeholder="Type name or ID..." autocomplete="off" required>
                                    <input type="hidden" name="employee_id" id="employee_id" required>
                                    <div id="employee_results" class="list-group"></div>
                                </div>
                                <!-- TYPE SELECT -->
                                <div class="mb-3">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select" required>
                                        <option value="">Select Type...</option>
                                        <option value="New">New</option>
                                        <option value="Usage">Usage</option>
                                    </select>
                                </div>
                                <!-- DISTRIBUTION: Condition Dropdown -->
                                <div class="mb-3">
                                    <label class="form-label">Distribution Notes</label>
                                    <input type="text" name="notes" class="form-control"
                                        placeholder="e.g: Good Condition, Defect, Broken, etc.">
                                </div>
                                <!-- SK Number -->
                                <div class="mb-3">
                                    <label class="form-label">SK Number</label>
                                    <input type="text" name="sk_number" class="form-control"
                                        placeholder="e.g: 19.22.360">
                                </div>
                                <!-- ADD ITEM BUTTON -->
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addRow()">+
                                        Add Another Item</button>
                                </div>
                                <!-- ITEMS CONTAINER (STARTS EMPTY) -->
                                <div id="items-container">
                                    <!-- No default rows -->
                                </div>
                                <div class="mb-3">
                                    <div id="assignButtonContainer" style="display: none;">
                                        <button type="submit" class="btn btn-success">Submit</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- Employee Possession List -->
                <div class="col-md-8">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <span class="fw-bold">🎒 Employee Possession List</span>
                            <span class="badge bg-secondary">Retrieve/Return items here</span>
                        </div>
                        <div class="table-responsive">
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
                                    <?php if (empty($possessions)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center p-4">No assets currently held by employees.
                                        </td>
                                    </tr>
                                    <?php else: ?>
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
                                        <td class="text-center fw-bold fs-5">
                                            <?= number_format((float)$row['CURRENT_POSSESSION'], 0) ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                                data-bs-target="#returnModal"
                                                onclick="setupReturn('<?= h($row['EMPLOYEE_ID']) ?>', '<?= h($row['ITEM_NO']) ?>', '<?= h($row['ITEM_DESC']) ?>', <?= (float)$row['CURRENT_POSSESSION'] ?>)">
                                                <i class="fas fa-arrow-left"></i> Return
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- PAGINATION FOOTER -->
                        <?php if ($totalPossessions > 0): ?>
                        <?php
$start = $offset + 1;
$end = min($offset + $limit, $totalPossessions);
?>
                        <div class="card-footer py-2 px-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($page > 1): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                        href="<?= h($APP_BASE) ?>/?page=<?= $page - 1 ?>">Previous</a>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled>Previous</button>
                                    <?php endif; ?>
                                    <span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?></span>
                                    <?php if ($page < $totalPages): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                        href="<?= h($APP_BASE) ?>/?page=<?= $page + 1 ?>">Next</a>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled>Next</button>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small">
                                    Showing <?= $start ?> to <?= $end ?> of <?= $totalPossessions ?> entries
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Return Modal -->
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
                                <input type="number" min="1" name="qty" id="ret_qty" class="form-control"
                                    placeholder="e.g. 1" required>
                                <div class="form-text">Current Qty: <span id="ret_max"></span></div>
                            </div>
                            <div class="col-6">
                                <label>Condition/Notes</label>
                                <input type="text" name="notes" class="form-control" placeholder="e.g. Good, Broken"
                                    required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Confirm Return</button>
                    </div>
                </form>
            </div>
        </div>
        <footer class="main-footer">
            <strong>Development</strong>
            <span>All rights reserved.</span>
            <div class="float-right d-none d-sm-inline-block">
                <b>Version</b> 1.0.0
            </div>
        </footer>
    </div>
    <!-- Scripts -->
    <script src="plugins/jquery/jquery.min.js"></script>
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="dist/js/adminlte.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let rowIndex = 0;

    function validateQtyInRow(qtyInput) {
        const row = qtyInput.closest('.item-row');
        const select = row.querySelector('.item-select');
        const option = select.options[select.selectedIndex];
        const available = parseFloat(option.getAttribute('data-available')) || 0;
        let qty = parseInt(qtyInput.value) || 0;

        if (qty > available || qty <= 0) {
            qtyInput.classList.add('qty-error');
            return false;
        } else {
            qtyInput.classList.remove('qty-error');
            return true;
        }
    }

    function updateAssignButtonState() {
        const rows = document.querySelectorAll('.item-row');
        let hasValidRow = false;
        for (const row of rows) {
            const select = row.querySelector('.item-select');
            const qtyInput = row.querySelector('.qty-input');
            if (select && qtyInput) {
                const itemId = select.value.trim();
                if (itemId && validateQtyInRow(qtyInput)) {
                    hasValidRow = true;
                }
            }
        }
        document.getElementById('assignButtonContainer').style.display = hasValidRow ? 'block' : 'none';
    }

    function addRow() {
        const container = document.getElementById('items-container');
        const newRow = document.createElement('div');
        newRow.className = 'row g-2 mb-2 item-row';
        newRow.innerHTML = `
    <div class="col-7">
        <select name="items[${rowIndex}][item_id]" class="form-select item-select">
            <option value="">Select Item...</option>
            <?php foreach($items as $itm): ?>
            <option value="<?= h($itm['ITEM_ID']) ?>" data-available="<?= (float)$itm['available_qty'] ?>">
                <?= h($itm['ITEM_DESC']) ?> (<?= h($itm['ITEM_NO']) ?>) — Avail: <?= number_format((float)$itm['available_qty']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-4">
        <input type="number" min="1" step="1" name="items[${rowIndex}][qty]" class="form-control qty-input"
               placeholder="Qty" oninput="validateQtyInRow(this); updateAssignButtonState();">
    </div>
    <div class="col-1 d-flex align-items-center">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">✕</button>
    </div>
    `;
        container.appendChild(newRow);
        const newSelect = newRow.querySelector('.item-select');
        const newQty = newRow.querySelector('.qty-input');
        if (newSelect) {
            newSelect.addEventListener('change', () => {
                validateQtyInRow(newQty);
                updateAssignButtonState();
            });
        }
        if (newQty) {
            newQty.addEventListener('input', () => {
                validateQtyInRow(newQty);
                updateAssignButtonState();
            });
        }
        rowIndex++;
    }

    function removeRow(button) {
        const rows = document.querySelectorAll('.item-row');
        if (rows.length > 1) {
            button.closest('.item-row').remove();
            updateAssignButtonState();
        } else {
            alert("At least one item row is required.");
        }
    }

    function setupReturn(empId, itemNo, itemName, maxQty) {
        document.getElementById('ret_emp_id').value = empId;
        document.getElementById('ret_item_no').value = itemNo;
        document.getElementById('ret_item_name').value = itemName;
        document.getElementById('ret_max').textContent = maxQty;
        document.getElementById('ret_qty').value = '';
    }

    // Employee search
    const employeeSearch = document.getElementById('employee_search');
    const employeeIdInput = document.getElementById('employee_id');
    const resultsBox = document.getElementById('employee_results');
    let searchTimeout;
    employeeSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        if (query.length < 2) {
            resultsBox.style.display = 'none';
            return;
        }
        searchTimeout = setTimeout(() => {
            fetch(`?api=search-employees&q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(employees => {
                    resultsBox.innerHTML = '';
                    if (employees.length === 0) {
                        resultsBox.style.display = 'none';
                        return;
                    }
                    employees.forEach(emp => {
                        const div = document.createElement('div');
                        div.className = 'list-group-item';
                        div.innerHTML =
                            `<div><strong>${emp.FIRST_NAME} ${emp.LAST_NAME}</strong></div><div class="small text-muted">${emp.EMPLOYEE_ID}</div>`;
                        div.addEventListener('click', () => {
                            employeeSearch.value =
                                `${emp.FIRST_NAME} ${emp.LAST_NAME}`;
                            employeeIdInput.value = emp.EMPLOYEE_ID;
                            resultsBox.style.display = 'none';
                        });
                        resultsBox.appendChild(div);
                    });
                    resultsBox.style.display = 'block';
                })
                .catch(err => {
                    console.error('Search error:', err);
                    resultsBox.style.display = 'none';
                });
        }, 300);
    });

    document.addEventListener('click', (e) => {
        if (!employeeSearch.contains(e.target) && !resultsBox.contains(e.target)) {
            resultsBox.style.display = 'none';
        }
    });

    document.getElementById('distributeForm').addEventListener('submit', function(e) {
        if (!employeeIdInput.value) {
            e.preventDefault();
            alert('Please select an employee from the search results.');
            return;
        }
        let allValid = true;
        document.querySelectorAll('.qty-input').forEach(input => {
            if (!validateQtyInRow(input)) allValid = false;
        });
        if (!allValid) {
            e.preventDefault();
            alert('One or more items exceed available stock.');
        }
    });

    document.addEventListener('wheel', function(e) {
        if (document.activeElement.type === 'number') {
            document.activeElement.blur();
        }
    });

    const days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    document.getElementById('currentDay').textContent = days[new Date().getDay()];

    function handleLogout() {
        // $SSO_FRONTEND = 'http://localhost:3000';
        // $SSO_BACKEND = 'http://localhost:5000';

        $SSO_FRONTEND = 'https://sso.ceresnl.com  ';
        $SSO_BACKEND = 'https://sso.ceresnl.com:50443';

        const theme = localStorage.getItem('theme');
        localStorage.clear();
        sessionStorage.clear();

        if (theme) localStorage.setItem('theme', theme);

        fetch(`${$SSO_BACKEND}/api/logout`, {
                method: 'GET',
                credentials: 'include'
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                console.log('Logout successful:', data);
                window.location.href = data.redirect;
            })
            .catch(error => {
                console.error('Logout error:', error);
                alert('Failed to log out. Please try again.');
            });
    }
    </script>
</body>

</html>