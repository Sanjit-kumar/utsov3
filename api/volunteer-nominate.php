<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

function openNominationsDB() {
    $db = new PDO("sqlite:" . dirname(__FILE__) . '/db/volunteer-nominations.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS tb_nominations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT,
        submitted_by TEXT,
        submitted_by_team TEXT,
        volunteer_name TEXT,
        volunteer_email TEXT,
        note TEXT,
        status TEXT DEFAULT 'pending',
        ipaddress TEXT
    )");
    // Self-migrating: add volunteer_phone if this db predates the phone field.
    $cols = $db->query("PRAGMA table_info(tb_nominations)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('volunteer_phone', $cols)) {
        $db->exec("ALTER TABLE tb_nominations ADD COLUMN volunteer_phone TEXT");
    }
    return $db;
}

function sanitizeHeader($value) {
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

$action = isset($_post->action) ? (string)$_post->action : 'add';

switch ($action) {
    case 'add': {
        $submittedBy     = mb_substr(isset($_post->submittedBy) ? (string)$_post->submittedBy : '', 0, 200);
        $submittedByTeam = mb_substr(isset($_post->submittedByTeam) ? (string)$_post->submittedByTeam : '', 0, 200);
        $volunteers      = isset($_post->volunteers) && is_array($_post->volunteers) ? $_post->volunteers : [];

        if ($submittedBy === '' || $submittedByTeam === '' || count($volunteers) === 0) {
            echo json_encode(['err' => 'Missing required fields']);
            break;
        }

        $db = openNominationsDB();
        $now = date('Y-m-d H:i:s');
        $ip = get_client_ip();
        $ins = $db->prepare(
            "INSERT INTO tb_nominations
                (date, submitted_by, submitted_by_team, volunteer_name, volunteer_email, volunteer_phone, note, status, ipaddress)
             VALUES
                (:date, :submitted_by, :submitted_by_team, :name, :email, :phone, :note, 'pending', :ip)"
        );

        $added = [];
        foreach ($volunteers as $v) {
            $name  = mb_substr(isset($v->name) ? (string)$v->name : '', 0, 200);
            $email = mb_substr(isset($v->email) ? (string)$v->email : '', 0, 254);
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
            $phone = mb_substr(isset($v->phone) ? (string)$v->phone : '', 0, 50);
            $note  = mb_substr(isset($v->note) ? (string)$v->note : '', 0, 1000);
            if ($name === '' && $email === '') continue;

            $ins->execute([
                ':date' => $now, ':submitted_by' => $submittedBy, ':submitted_by_team' => $submittedByTeam,
                ':name' => $name, ':email' => $email, ':phone' => $phone, ':note' => $note, ':ip' => $ip,
            ]);
            $added[] = ['name' => $name, 'email' => $email, 'phone' => $phone, 'note' => $note];
        }

        if (count($added) > 0) {
            $safeBy = htmlspecialchars($submittedBy, ENT_QUOTES, 'UTF-8');
            $safeTeam = htmlspecialchars($submittedByTeam, ENT_QUOTES, 'UTF-8');
            $rows = '';
            foreach ($added as $a) {
                $rows .= "<tr><td style='padding:4px 16px 4px 0'>" . htmlspecialchars($a['name'], ENT_QUOTES, 'UTF-8') . "</td>"
                       . "<td style='padding:4px 16px 4px 0'>" . htmlspecialchars($a['email'], ENT_QUOTES, 'UTF-8') . "</td>"
                       . "<td style='padding:4px 16px 4px 0'>" . htmlspecialchars($a['phone'], ENT_QUOTES, 'UTF-8') . "</td>"
                       . "<td style='padding:4px 0'>" . htmlspecialchars($a['note'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
            }
            $mailHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";
            $subject = "UTSOV Volunteer Nominations: " . count($added) . " from " . $submittedByTeam;
            $body = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:650px;margin:0 auto'>"
                . "<h2 style='color:#8B1A1A'>Volunteer Recognition Nominations</h2>"
                . "<p>Submitted by <b>{$safeBy}</b> ({$safeTeam})</p>"
                . "<table style='border-collapse:collapse;font-size:14px'>"
                . "<tr><th style='text-align:left;padding:4px 16px 4px 0'>Name</th><th style='text-align:left;padding:4px 16px 4px 0'>Email</th><th style='text-align:left;padding:4px 16px 4px 0'>Phone</th><th style='text-align:left;padding:4px 0'>Note</th></tr>"
                . $rows
                . "</table></body></html>";
            notifyAdmins(sanitizeHeader($subject), $body, $mailHeaders);
        }

        echo json_encode(['ok' => true, 'added' => count($added)]);
        break;
    }

    // Read-only pull for EB review (used to build the CSV export, same pattern as
    // the guest email list) -- no auth gate, same posture as this project's other
    // internal read endpoints (checkin.php's roster/exportList).
    case 'list': {
        $db = openNominationsDB();
        $rows = $db->query("SELECT * FROM tb_nominations ORDER BY date ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $rows]);
        break;
    }

    // Admin-side correction tool -- lets Sanjit fix/add a note on an existing
    // nomination without re-submitting it (e.g. filling in a note after the fact).
    case 'updateNote': {
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        $note = mb_substr(isset($_post->note) ? (string)$_post->note : '', 0, 1000);
        if ($id <= 0) { echo json_encode(['err' => 'Missing id']); break; }
        $db = openNominationsDB();
        $upd = $db->prepare("UPDATE tb_nominations SET note = :note WHERE id = :id");
        $upd->execute([':note' => $note, ':id' => $id]);
        echo json_encode(['ok' => true]);
        break;
    }

    // General admin-side field correction (name/email/phone/team typos found after
    // submission) -- whitelisted columns only, same low-risk posture as updateNote.
    case 'updateField': {
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        $field = isset($_post->field) ? (string)$_post->field : '';
        $value = mb_substr(isset($_post->value) ? (string)$_post->value : '', 0, 1000);
        $allowed = ['volunteer_name', 'volunteer_email', 'volunteer_phone', 'submitted_by', 'submitted_by_team', 'note'];
        if ($id <= 0 || !in_array($field, $allowed, true)) { echo json_encode(['err' => 'Invalid id or field']); break; }
        if ($field === 'volunteer_email') {
            $value = filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
        }
        $db = openNominationsDB();
        $upd = $db->prepare("UPDATE tb_nominations SET {$field} = :value WHERE id = :id");
        $upd->execute([':value' => $value, ':id' => $id]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
