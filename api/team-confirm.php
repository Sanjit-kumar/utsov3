<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

function openDB() {
    $db = new PDO("sqlite:" . getDBPath("capture"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS tb_team_confirmations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team TEXT, name TEXT, confirmed_at TEXT, ipaddress TEXT,
        UNIQUE(team, name)
    )");
    return $db;
}

$action = isset($_post->action) ? (string)$_post->action : 'list';
$db = openDB();

switch ($action) {
    case 'confirm': {
        $team = mb_substr(isset($_post->team) ? (string)$_post->team : '', 0, 100);
        $name = mb_substr(isset($_post->name) ? (string)$_post->name : '', 0, 200);
        if (!$team || !$name) { echo json_encode(['err' => 'Missing team or name']); exit; }
        $stmt = $db->prepare("INSERT OR IGNORE INTO tb_team_confirmations (team, name, confirmed_at, ipaddress) VALUES (:team, :name, :at, :ip)");
        $stmt->execute([':team' => $team, ':name' => $name, ':at' => date("Y-m-d H:i:s"), ':ip' => get_client_ip()]);
        echo json_encode(['ok' => true]);
        break;
    }
    case 'list':
    default: {
        $rows = $db->query("SELECT team, name, confirmed_at FROM tb_team_confirmations")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'confirmations' => $rows]);
        break;
    }
}
