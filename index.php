<?php
// =======================================
//  STOCK SERVICE - index.php (SSO Protected)
// =======================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'path'     => '/',
        'secure'   => false, // ← set true in production with HTTPS
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
$SSO_FRONTEND = 'http://localhost:3000';
$SSO_BACKEND  = 'http://localhost:5000';

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$BASE_URL = $protocol . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

// ---------------- LOGOUT ----------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
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
            $_SESSION['fname'] = $data['fname'] ?? $data['username'];
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

// ---------------- DATABASE ----------------
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
    die("DB Connection Error: " . h($e->getMessage()));
}

// ---------------- SERVICE ----------------
class DistributionService {
    public function __construct(private PDO $pdo) {}

    public function distributeItems(array $data): void {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new InvalidArgumentException("No items provided.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO USAGE_ITEM (COMPANY_ID, EMPLOYEE_ID, TGL_TRX, DISPLAY_NAME, KET) 
                VALUES ('PT-DEF', :emp, NOW(), 'System User', :notes)
            ");
            $stmtHeader->execute([
                ':emp'   => $data['employee_id'],
                ':notes' => $data['notes'] ?? '',
            ]);
            $usageId = $this->pdo->lastInsertId();

            $stmtDtl = $this->pdo->prepare("
                INSERT INTO USAGE_ITEM_DTL (USAGE_ID, ITEM_ID, ITEM_NO, ITEM_DESC, QTY, UOM) 
                VALUES (:uid, :iid, :ino, :idesc, :qty, :uom)
            ");

            foreach ($data['items'] as $itemInput) {
                if (empty($itemInput['item_id']) || empty($itemInput['qty'])) continue;

                $item = $this->getItemInfo($itemInput['item_id']);
                if (!$item) {
                    throw new RuntimeException("Item ID {$itemInput['item_id']} not found.");
                }

                $stmtDtl->execute([
                    ':uid'    => $usageId,
                    ':iid'    => $item['ITEM_ID'],
                    ':ino'    => $item['ITEM_NO'],
                    ':idesc'  => $item['ITEM_DESC'],
                    ':qty'    => (float) $itemInput['qty'],
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
                VALUES ('PT-DEF', :emp, NOW(), 'System User', 'RETRIEVAL', :notes)
            ");
            $stmt->execute([
                ':emp'   => $employeeId,
                ':notes' => $notes
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
                ':rid'   => $returnId,
                ':iid'   => $item['ITEM_ID'],
                ':ino'   => $item['ITEM_NO'],
                ':idesc' => $item['ITEM_DESC'],
                ':qty'   => $qty,
                ':uom'   => $item['UOM']
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

// Handle POST (only process if not a redirect result)
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
            $service->distributeItems([
                'employee_id' => $_POST['employee_id'],
                'notes'       => $_POST['notes'] ?? '',
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
$items = $service->getItems();
?>

<!DOCTYPE html>
<html lang="en-US">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Inventory Distribution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <!-- Ionicons -->
  <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
  <!-- AdminLTE -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <!-- Google Font: Source Sans Pro -->
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">

  <style>
    body { font-size: 0.875rem; background-color: #f8f9fa; }
    .nav-tabs .nav-link.active { font-weight: bold; border-top: 3px solid #0d6efd; background-color: #fff; border-bottom-color: transparent; }
    .table-matrix td { padding: 0.25rem; vertical-align: middle; }
    .form-check-input { cursor: pointer; }
    .select2-container { width: 100% !important; }
    .modal-dialog { max-width: 95%; margin: 1.75rem auto; }
    .side-menu { width: 100%; }
    @media (min-width: 992px) {
      .side-menu { min-width: 25%; max-width: 25%; }
      .tab-content { width: 75%; }
    }
    .pagination { margin-bottom: 0; }
    .context-header { background-color: #e9ecef; border-left: 4px solid #0d6efd; }

    /* Employee Search Dropdown */
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
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        display: none;
    }
    #employee_results .list-group-item {
        cursor: pointer;
        padding: 0.5rem 0.75rem;
    }
    #employee_results .list-group-item:hover {
        background-color: #e9ecef;
    }
  </style>

  <style>
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
    .main-sidebar .nav-sidebar > .nav-item > .nav-link,
    .main-sidebar .nav-header {
      color: rgba(255, 255, 255, 0.85);
    }
    .main-sidebar .nav-sidebar > .nav-item > .nav-link:hover {
      background-color: rgba(255, 255, 255, 0.1);
      color: #fff;
    }
    .main-sidebar .nav-sidebar > .nav-item > .nav-link.active,
    .main-sidebar .nav-sidebar > .nav-item > .nav-link.active:hover {
      background-color: rgba(255, 255, 255, 0.2);
      color: #fff;
    }
  </style>

</head>
<body class="hold-transition sidebar-mini layout-navbar-fixed layout-fixed sidebar-collapse">
<div class="wrapper">
  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item">
        <span class="nav-link" style="color: black; font-weight: bold; font-size: 15px;">
          Happy, <span id="currentDay"></span>
        </span>
      </li>
    </ul>
    <ul class="navbar-nav ml-auto">
      <li class="nav-item dropdown">
        <a href="#" class="d-inline-block" role="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="text-decoration: none;">
          <i class="fas fa-user" style="color: #0d6efd; margin-right: 8px;"></i>
          <span style="color: #000000;"><?= h(getGreeting()) ?>, <?= h($_SESSION['fname'] ?? 'Guest') ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
          <li>
            <a class="dropdown-item" href="/account">
              <i class="fas fa-user-circle me-2" style="color: #0d6efd;"></i> My Account
            </a>
          </li>
          <li>
            <a class="dropdown-item" href="/settings">
              <i class="fas fa-cog me-2" style="color: #198754;"></i> Settings
            </a>
          </li>
          <li>
            <a class="dropdown-item" href="#" onclick="handleLogout(); return false;">
              <i class="fas fa-sign-out-alt me-2" style="color: #dc3545;"></i> Logout
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </nav>

  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <a href="#" class="brand-link">
      <img src="https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEino1q43LiUDhvzaUKJx82I0jXa30TpJqhGexeJnoji_0zf3Pjog4aW099h1HkfXjso-LnNqizIlBYKaBeChFVH67LsLUcQ-cG_S92GC63DydTpSJ51gnakLJaYdi43EPARUrw_J2HtK5BA7y0ETAgVUJoINVjkxAhGJNmfRVvfFMYJkLzNp5J8uqrDhWs/s325/logoapp-red.png"
          alt="AdminLTE Logo" 
          class="brand-image img-circle elevation-3" 
          style="opacity: .8">
      <span class="brand-text font-weight-light">Inventory Distribution</span>
    </a>
    <div class="sidebar">
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
          <li class="nav-item has-treeview menu-open">
            <a href="#" class="nav-link active">
              <i class="nav-icon fas fa-home"></i>
              <p>Dashboard</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
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
                <input type="text" id="employee_search" class="form-control" placeholder="Type name or ID..." autocomplete="off" required>
                <input type="hidden" name="employee_id" id="employee_id" required>
                <div id="employee_results" class="list-group"></div>
              </div>

              <!-- DISTRIBUTION: Condition Dropdown -->
              <div class="mb-3">
                <label class="form-label">Distribution Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Reason for assignment">
              </div>

              <div id="items-container">
                <?php for ($i = 0; $i < 3; $i++): ?>
                  <div class="row g-2 mb-2 item-row">
                    <div class="col-7">
                      <select name="items[<?= $i ?>][item_id]" class="form-select">
                        <option value="">Select Item...</option>
                        <?php foreach($items as $itm): ?>
                          <option value="<?= h($itm['ITEM_ID']) ?>">
                            <?= h($itm['ITEM_DESC']) ?> (<?= h($itm['ITEM_NO']) ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-4">
                      <input type="number" min="1" name="items[<?= $i ?>][qty]" class="form-control" placeholder="Qty">
                    </div>
                    <div class="col-1 d-flex align-items-center">
                      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">✕</button>
                    </div>
                  </div>
                <?php endfor; ?>
              </div>

              <div class="d-grid gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addRow()">+ Add Another Item</button>
                <button type="submit" class="btn btn-success">Assign Selected Items</button>
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
                  <tr><td colspan="4" class="text-center p-4">No assets currently held by employees.</td></tr>
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
                      <td class="text-center fw-bold fs-5"><?= number_format((float)$row['CURRENT_POSSESSION'], 0) ?></td>
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
                    <a class="btn btn-sm btn-outline-secondary" href="?page=<?= $page - 1 ?>">Previous</a>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled>Previous</button>
                  <?php endif; ?>
                  <span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?></span>
                  <?php if ($page < $totalPages): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="?page=<?= $page + 1 ?>">Next</a>
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
              <input type="number" min="1" name="qty" id="ret_qty" class="form-control" required>
              <div class="form-text">Max: <span id="ret_max"></span></div>
            </div>
            <!-- RETURN: Condition Dropdown -->
            <div class="col-6">
              <label>Condition/Notes</label>
              <input type="text" name="notes" class="form-control" placeholder="e.g. Good, Broken" required>
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
let rowIndex = 3;

function addRow() {
  const container = document.getElementById('items-container');
  const newRow = document.createElement('div');
  newRow.className = 'row g-2 mb-2 item-row';
  newRow.innerHTML = `
    <div class="col-7">
      <select name="items[${rowIndex}][item_id]" class="form-select">
        <option value="">Select Item...</option>
        <?php foreach($items as $itm): ?>
          <option value="<?= h($itm['ITEM_ID']) ?>"><?= h($itm['ITEM_DESC']) ?> (<?= h($itm['ITEM_NO']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-4">
      <input type="number" min="0.01" step="0.01" name="items[${rowIndex}][qty]" class="form-control" placeholder="Qty">
    </div>
    <div class="col-1 d-flex align-items-center">
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">✕</button>
    </div>
  `;
  container.appendChild(newRow);
  rowIndex++;
}

function removeRow(button) {
  const rows = document.querySelectorAll('.item-row');
  if (rows.length > 1) {
    button.closest('.item-row').remove();
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

// EMPLOYEE SEARCH FUNCTIONALITY
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
                    div.innerHTML = `
                        <div><strong>${emp.FIRST_NAME} ${emp.LAST_NAME}</strong></div>
                        <div class="small text-muted">${emp.EMPLOYEE_ID}</div>
                    `;
                    div.addEventListener('click', () => {
                        employeeSearch.value = `${emp.FIRST_NAME} ${emp.LAST_NAME}`;
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

// Hide dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (!employeeSearch.contains(e.target) && !resultsBox.contains(e.target)) {
        resultsBox.style.display = 'none';
    }
});

// Prevent form submission if employee not selected
document.getElementById('distributeForm').addEventListener('submit', function(e) {
    if (!employeeIdInput.value) {
        e.preventDefault();
        alert('Please select an employee from the search results.');
    }
});

/* prevent mouse-wheel auto increment */
document.addEventListener('wheel', function (e) {
    if (document.activeElement.type === 'number') {
        document.activeElement.blur();
    }
});

const days = ["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];
document.getElementById('currentDay').textContent = days[new Date().getDay()];

function handleLogout() {
  localStorage.clear();
  sessionStorage.clear();
  window.location.href = '?action=logout';
}
</script>
</body>
</html>