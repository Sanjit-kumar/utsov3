<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

// Every other sheet from the UtsovMemberAndFriends master spreadsheet (the "Members"
// sheet itself lives separately in members.db / api/members.php, already in production
// use for admin.php's Members tab). This file is a single generic store for the
// remaining 8 tabs, keyed by a whitelisted $TABLES config so one import/list action
// pair can serve all of them without per-sheet duplicate code.
$TABLES = [
    'friends'       => ['table' => 'tb_friends',       'cols' => ['first_name','last_name','email','phone','notice_board']],
    'gen2'          => ['table' => 'tb_gen2',           'cols' => ['first_name','last_name','email','phone']],
    'patrons'       => ['table' => 'tb_patrons',        'cols' => ['email','years_attended']],
    'recent_loyal'  => ['table' => 'tb_recent_loyal',   'cols' => ['email']],
    'known_2025'    => ['table' => 'tb_known_2025',     'cols' => ['email']],
    'vendors'       => ['table' => 'tb_vendors',        'cols' => ['value']],
    'all_network'   => ['table' => 'tb_all_network',    'cols' => ['email']],
    'sheet6_network'=> ['table' => 'tb_sheet6_network', 'cols' => ['email']],
];

function openMembershipDataDB() {
    global $TABLES;
    $db = new PDO("sqlite:" . dirname(__FILE__) . '/db/membership-data.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($TABLES as $key => $cfg) {
        $colDefs = implode(', ', array_map(function($c){ return "$c TEXT"; }, $cfg['cols']));
        $db->exec("CREATE TABLE IF NOT EXISTS {$cfg['table']} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT,
            $colDefs
        )");
    }
    return $db;
}

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

$action = isset($_post->action) ? (string)$_post->action : 'list';
$sheet  = isset($_post->sheet) ? (string)$_post->sheet : '';

if (!isset($TABLES[$sheet])) { echo json_encode(['err' => 'Unknown sheet']); exit; }
$cfg = $TABLES[$sheet];
$tbl = $cfg['table'];

switch ($action) {
    // Bulk import -- additive (no upsert), used as a one-off admin seed/refresh from
    // the master spreadsheet rather than a public submission flow.
    case 'import': {
        $rows = isset($_post->rows) && is_array($_post->rows) ? $_post->rows : [];
        if (count($rows) === 0) { echo json_encode(['err' => 'No rows provided']); break; }
        $db = openMembershipDataDB();
        $now = date('Y-m-d H:i:s');
        $placeholders = implode(', ', array_map(function($c){ return ":$c"; }, $cfg['cols']));
        $colList = implode(', ', $cfg['cols']);
        $ins = $db->prepare("INSERT INTO $tbl (created_at, $colList) VALUES (:created_at, $placeholders)");
        $added = 0;
        foreach ($rows as $r) {
            $params = [':created_at' => $now];
            $hasData = false;
            foreach ($cfg['cols'] as $c) {
                $v = isset($r->$c) ? (string)$r->$c : '';
                $v = mb_substr($v, 0, 500);
                if ($v !== '') $hasData = true;
                $params[":$c"] = $v;
            }
            if (!$hasData) continue;
            $ins->execute($params);
            $added++;
        }
        echo json_encode(['ok' => true, 'added' => $added]);
        break;
    }

    case 'list': {
        $db = openMembershipDataDB();
        $rows = $db->query("SELECT * FROM $tbl ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $rows]);
        break;
    }

    case 'delete': {
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        if ($id <= 0) { echo json_encode(['err' => 'Missing id']); break; }
        $db = openMembershipDataDB();
        $db->prepare("DELETE FROM $tbl WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
