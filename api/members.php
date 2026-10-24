<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

function openMembersDB() {
    $db = new PDO("sqlite:" . dirname(__FILE__) . '/db/members.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS tb_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT,
        first_name TEXT,
        last_name TEXT,
        email TEXT,
        phone TEXT,
        member_since TEXT,
        remarks TEXT,
        source TEXT
    )");
    return $db;
}

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

$action = isset($_post->action) ? (string)$_post->action : 'list';

switch ($action) {
    // Bulk import -- used to seed/refresh this table from the master membership
    // spreadsheet (admin-side tool, not public-facing). Each item is inserted as a
    // new row; call is intentionally additive (no upsert) since re-running it is a
    // one-off admin action, not something end users trigger.
    case 'import': {
        $members = isset($_post->members) && is_array($_post->members) ? $_post->members : [];
        if (count($members) === 0) {
            echo json_encode(['err' => 'No members provided']);
            break;
        }
        $db = openMembersDB();
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare(
            "INSERT INTO tb_members (created_at, first_name, last_name, email, phone, member_since, remarks, source)
             VALUES (:created_at, :first_name, :last_name, :email, :phone, :member_since, :remarks, :source)"
        );
        $added = 0;
        foreach ($members as $m) {
            $first = mb_substr(isset($m->firstName) ? (string)$m->firstName : '', 0, 100);
            $last = mb_substr(isset($m->lastName) ? (string)$m->lastName : '', 0, 100);
            $email = mb_substr(isset($m->email) ? (string)$m->email : '', 0, 254);
            $phone = mb_substr(isset($m->phone) ? (string)$m->phone : '', 0, 30);
            $memberSince = mb_substr(isset($m->memberSince) ? (string)$m->memberSince : '', 0, 20);
            $remarks = mb_substr(isset($m->remarks) ? (string)$m->remarks : '', 0, 500);
            $source = mb_substr(isset($m->source) ? (string)$m->source : 'import', 0, 100);
            if ($first === '' && $email === '') continue;
            $ins->execute([
                ':created_at' => $now, ':first_name' => $first, ':last_name' => $last,
                ':email' => $email, ':phone' => $phone, ':member_since' => $memberSince,
                ':remarks' => $remarks, ':source' => $source,
            ]);
            $added++;
        }
        echo json_encode(['ok' => true, 'added' => $added]);
        break;
    }

    // Read-only pull -- used by the admin portal's Members tab.
    case 'list': {
        $db = openMembersDB();
        $rows = $db->query("SELECT * FROM tb_members ORDER BY first_name COLLATE NOCASE ASC, last_name COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $rows]);
        break;
    }

    case 'updateField': {
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        $field = isset($_post->field) ? (string)$_post->field : '';
        $value = mb_substr(isset($_post->value) ? (string)$_post->value : '', 0, 500);
        $allowed = ['first_name', 'last_name', 'email', 'phone', 'member_since', 'remarks'];
        if ($id <= 0 || !in_array($field, $allowed, true)) { echo json_encode(['err' => 'Invalid id or field']); break; }
        $db = openMembersDB();
        $upd = $db->prepare("UPDATE tb_members SET {$field} = :value WHERE id = :id");
        $upd->execute([':value' => $value, ':id' => $id]);
        echo json_encode(['ok' => true]);
        break;
    }

    case 'delete': {
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        if ($id <= 0) { echo json_encode(['err' => 'Missing id']); break; }
        $db = openMembersDB();
        $db->prepare("DELETE FROM tb_members WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
