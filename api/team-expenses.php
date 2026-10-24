<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

function openExpensesDB() {
    $db = new PDO("sqlite:" . dirname(__FILE__) . '/db/team-expenses.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS tb_expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT,
        team TEXT,
        expense_date TEXT,
        beneficiary TEXT,
        purpose TEXT,
        heads TEXT,
        amount REAL,
        paid_by TEXT,
        receipt TEXT,
        note TEXT,
        submitted_by TEXT,
        reimbursed INTEGER DEFAULT 0,
        reimbursed_date TEXT,
        ipaddress TEXT
    )");
    // Self-migrating: add receipt_file if this db predates receipt uploads.
    $cols = $db->query("PRAGMA table_info(tb_expenses)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('receipt_file', $cols)) {
        $db->exec("ALTER TABLE tb_expenses ADD COLUMN receipt_file TEXT");
    }
    // Self-migrating: add payment_method if this db predates that field.
    if (!in_array('payment_method', $cols)) {
        $db->exec("ALTER TABLE tb_expenses ADD COLUMN payment_method TEXT");
    }
    // Self-migrating: add receipt_hash (file content hash, used for duplicate detection).
    if (!in_array('receipt_hash', $cols)) {
        $db->exec("ALTER TABLE tb_expenses ADD COLUMN receipt_hash TEXT");
    }
    return $db;
}

function sanitizeHeader($value) {
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

// Looks for an existing expense that matches a submitted item either by identical
// receipt file content (hash) or by identical date+amount+purpose+paid-by -- the two
// ways the same out-of-pocket claim tends to get submitted twice (re-uploading the
// same photo, or re-typing the same line after a form refresh/second team member).
function findDuplicateMatches($db, $items) {
    $matches = [];
    $seenIds = [];
    $stmtHash = $db->prepare("SELECT * FROM tb_expenses WHERE receipt_hash = :h AND receipt_hash != '' LIMIT 1");
    $stmtDetail = $db->prepare(
        "SELECT * FROM tb_expenses
         WHERE expense_date = :date AND amount = :amount
           AND LOWER(TRIM(purpose)) = :purpose AND LOWER(TRIM(paid_by)) = :paidby
         LIMIT 1"
    );
    foreach ($items as $idx => $it) {
        $found = false;
        $hash = isset($it->receiptHash) ? (string)$it->receiptHash : '';
        if ($hash !== '') {
            $stmtHash->execute([':h' => $hash]);
            $found = $stmtHash->fetch(PDO::FETCH_ASSOC);
        }
        if (!$found) {
            $date = isset($it->date) ? (string)$it->date : '';
            $amount = isset($it->amount) && is_numeric($it->amount) ? round((float)$it->amount, 2) : 0;
            $purpose = mb_strtolower(trim(isset($it->purpose) ? (string)$it->purpose : ''));
            $paidBy = mb_strtolower(trim(isset($it->paidBy) ? (string)$it->paidBy : ''));
            if ($date !== '' && $purpose !== '' && $amount > 0) {
                $stmtDetail->execute([':date' => $date, ':amount' => $amount, ':purpose' => $purpose, ':paidby' => $paidBy]);
                $found = $stmtDetail->fetch(PDO::FETCH_ASSOC);
            }
        }
        if ($found && !in_array($found['id'], $seenIds, true)) {
            $seenIds[] = $found['id'];
            $matches[] = [
                'itemIndex' => $idx,
                'existing' => [
                    'id'           => (int)$found['id'],
                    'team'         => $found['team'],
                    'expense_date' => $found['expense_date'],
                    'purpose'      => $found['purpose'],
                    'amount'       => (float)$found['amount'],
                    'paid_by'      => $found['paid_by'],
                    'submitted_by' => $found['submitted_by'],
                    'created_at'   => $found['created_at'],
                ],
            ];
        }
    }
    return $matches;
}

// ── Multipart upload path (actual receipt image/PDF) -- checked before the JSON
// decode below, since php://input is empty for a multipart/form-data request. ──
if (isset($_POST['action']) && $_POST['action'] === 'uploadReceipt') {
    if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['err' => 'No file received. Please try again.']);
        exit;
    }
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $mimeType = mime_content_type($_FILES['receipt']['tmp_name']);
    if (!isset($allowedMimes[$mimeType])) {
        echo json_encode(['err' => 'Please upload a JPG, PNG, WEBP, or PDF file.']);
        exit;
    }
    if ($_FILES['receipt']['size'] > 8 * 1024 * 1024) {
        echo json_encode(['err' => 'File too large — please keep it under 8MB.']);
        exit;
    }
    $filename = date('Ymd_His') . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 16) . '.' . $allowedMimes[$mimeType];
    $dir = dirname(__FILE__) . '/uploads/expense-receipts';
    // 0755 (not 0750) -- unlike payment-screenshot receipts, these are meant to be
    // directly viewable via a link from the dashboard, so the web server user needs
    // read/traverse access regardless of its group.
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    else @chmod($dir, 0755);
    $hash = hash_file('sha256', $_FILES['receipt']['tmp_name']);
    if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $dir . '/' . $filename)) {
        echo json_encode(['err' => 'Could not save the file. Please try again.']);
        exit;
    }
    echo json_encode(['ok' => true, 'path' => 'uploads/expense-receipts/' . $filename, 'hash' => $hash]);
    exit;
}

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

$action = isset($_post->action) ? (string)$_post->action : 'add';

switch ($action) {
    case 'add': {
        $team = mb_substr(isset($_post->team) ? (string)$_post->team : '', 0, 100);
        $submittedBy = mb_substr(isset($_post->submittedBy) ? (string)$_post->submittedBy : '', 0, 200);
        $items = isset($_post->items) && is_array($_post->items) ? $_post->items : [];
        $force = !empty($_post->force);

        if ($team === '' || $submittedBy === '' || count($items) === 0) {
            echo json_encode(['err' => 'Missing required fields']);
            break;
        }

        $db = openExpensesDB();

        if (!$force) {
            $dupMatches = findDuplicateMatches($db, $items);
            if (count($dupMatches) > 0) {
                echo json_encode(['ok' => false, 'duplicate' => true, 'matches' => $dupMatches]);
                break;
            }
        }

        $now = date('Y-m-d H:i:s');
        $ip = get_client_ip();
        $ins = $db->prepare(
            "INSERT INTO tb_expenses
                (created_at, team, expense_date, beneficiary, purpose, heads, amount, paid_by, receipt, receipt_file, payment_method, receipt_hash, note, submitted_by, reimbursed, ipaddress)
             VALUES
                (:created_at, :team, :expense_date, :beneficiary, :purpose, :heads, :amount, :paid_by, :receipt, :receipt_file, :payment_method, :receipt_hash, :note, :submitted_by, 0, :ip)"
        );

        $added = [];
        $total = 0.0;
        foreach ($items as $it) {
            $date = mb_substr(isset($it->date) ? (string)$it->date : '', 0, 20);
            $beneficiary = mb_substr(isset($it->beneficiary) ? (string)$it->beneficiary : '', 0, 200);
            $purpose = mb_substr(isset($it->purpose) ? (string)$it->purpose : '', 0, 300);
            $heads = mb_substr(isset($it->heads) ? (string)$it->heads : '', 0, 20);
            $amount = isset($it->amount) && is_numeric($it->amount) ? round((float)$it->amount, 2) : 0;
            $paidBy = mb_substr(isset($it->paidBy) ? (string)$it->paidBy : '', 0, 200);
            $receipt = mb_substr(isset($it->receipt) ? (string)$it->receipt : '', 0, 50);
            $receiptFile = mb_substr(isset($it->receiptFile) ? (string)$it->receiptFile : '', 0, 300);
            $paymentMethod = mb_substr(isset($it->paymentMethod) ? (string)$it->paymentMethod : '', 0, 50);
            $receiptHash = mb_substr(isset($it->receiptHash) ? (string)$it->receiptHash : '', 0, 64);
            $note = mb_substr(isset($it->note) ? (string)$it->note : '', 0, 500);

            if ($purpose === '' && $amount <= 0) continue;

            $ins->execute([
                ':created_at' => $now, ':team' => $team, ':expense_date' => $date,
                ':beneficiary' => $beneficiary, ':purpose' => $purpose, ':heads' => $heads,
                ':amount' => $amount, ':paid_by' => $paidBy, ':receipt' => $receipt,
                ':receipt_file' => $receiptFile, ':payment_method' => $paymentMethod, ':receipt_hash' => $receiptHash,
                ':note' => $note, ':submitted_by' => $submittedBy, ':ip' => $ip,
            ]);
            $added[] = ['date' => $date, 'purpose' => $purpose, 'amount' => $amount, 'paidBy' => $paidBy];
            $total += $amount;
        }

        if (count($added) > 0) {
            $safeTeam = htmlspecialchars($team, ENT_QUOTES, 'UTF-8');
            $safeBy = htmlspecialchars($submittedBy, ENT_QUOTES, 'UTF-8');
            $rows = '';
            foreach ($added as $a) {
                $rows .= "<tr><td style='padding:4px 16px 4px 0'>" . htmlspecialchars($a['date'], ENT_QUOTES, 'UTF-8') . "</td>"
                       . "<td style='padding:4px 16px 4px 0'>" . htmlspecialchars($a['purpose'], ENT_QUOTES, 'UTF-8') . "</td>"
                       . "<td style='padding:4px 16px 4px 0'>\${$a['amount']}</td>"
                       . "<td style='padding:4px 0'>" . htmlspecialchars($a['paidBy'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
            }
            $mailHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";
            $subject = "UTSOV Expenses: " . count($added) . " new from {$team} (\$" . number_format($total, 2) . ")";
            $body = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:650px;margin:0 auto'>"
                . "<h2 style='color:#8B1A1A'>New Team Expenses Logged</h2>"
                . "<p>Team: <b>{$safeTeam}</b> &middot; Submitted by <b>{$safeBy}</b> &middot; Total: <b>\$" . number_format($total, 2) . "</b></p>"
                . "<table style='border-collapse:collapse;font-size:14px'>"
                . "<tr><th style='text-align:left;padding:4px 16px 4px 0'>Date</th><th style='text-align:left;padding:4px 16px 4px 0'>Purpose</th><th style='text-align:left;padding:4px 16px 4px 0'>Amount</th><th style='text-align:left;padding:4px 0'>Paid By</th></tr>"
                . $rows
                . "</table></body></html>";
            notifyAdmins(sanitizeHeader($subject), $body, $mailHeaders);
        }

        echo json_encode(['ok' => true, 'added' => count($added), 'total' => round($total, 2)]);
        break;
    }

    // Read-only pull for the dashboard view -- no auth gate, same posture as this
    // project's other internal read endpoints.
    case 'list': {
        $db = openExpensesDB();
        $rows = $db->query("SELECT * FROM tb_expenses ORDER BY team COLLATE NOCASE ASC, expense_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $rows]);
        break;
    }

    // Toggle reimbursed status for one expense line from the dashboard.
    case 'setReimbursed': {
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        $reimbursed = !empty($_post->reimbursed) ? 1 : 0;
        if ($id <= 0) { echo json_encode(['err' => 'Missing id']); break; }
        $db = openExpensesDB();
        $upd = $db->prepare("UPDATE tb_expenses SET reimbursed = :r, reimbursed_date = :d WHERE id = :id");
        $upd->execute([
            ':r' => $reimbursed,
            ':d' => $reimbursed ? date('Y-m-d H:i:s') : null,
            ':id' => $id,
        ]);
        echo json_encode(['ok' => true]);
        break;
    }

    // General admin-side field correction, same low-risk posture as the equivalent
    // tool on volunteer-nominate.php.
    case 'updateField': {
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        $field = isset($_post->field) ? (string)$_post->field : '';
        $value = mb_substr(isset($_post->value) ? (string)$_post->value : '', 0, 1000);
        $allowed = ['team', 'expense_date', 'beneficiary', 'purpose', 'heads', 'amount', 'paid_by', 'receipt', 'receipt_file', 'payment_method', 'note', 'submitted_by'];
        if ($id <= 0 || !in_array($field, $allowed, true)) { echo json_encode(['err' => 'Invalid id or field']); break; }
        if ($field === 'amount') {
            $value = is_numeric($value) ? round((float)$value, 2) : 0;
        }
        $db = openExpensesDB();
        $upd = $db->prepare("UPDATE tb_expenses SET {$field} = :value WHERE id = :id");
        $upd->execute([':value' => $value, ':id' => $id]);
        echo json_encode(['ok' => true]);
        break;
    }

    // Delete a single expense line (e.g. accidental/duplicate entry).
    case 'delete': {
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        if ($id <= 0) { echo json_encode(['err' => 'Missing id']); break; }
        $db = openExpensesDB();
        $db->prepare("DELETE FROM tb_expenses WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
