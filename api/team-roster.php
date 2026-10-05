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
    $db->exec("CREATE TABLE IF NOT EXISTS tb_team_meta (
        team TEXT PRIMARY KEY, responsibility TEXT, contact TEXT, sort_order INTEGER
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS tb_team_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT, team TEXT, name TEXT, phone TEXT
    )");
    seedIfEmpty($db);
    return $db;
}

// One-time seed from the original team-assignments roster, only runs while the tables are
// empty — never touches rows again after that, so live edits (adds/deletes) are permanent.
function seedIfEmpty($db) {
    $count = (int)$db->query("SELECT COUNT(*) FROM tb_team_meta")->fetchColumn();
    if ($count > 0) return;

    $seed = json_decode(<<<'JSON'
{"teams": [{"team": "Food Distribution", "responsibility": "All food pickup, setup and distribution to attendees — the largest crew, since this covers the whole event.", "contact": "", "sort_order": 0}, {"team": "Refills/Logistics", "responsibility": "Keeps food and supplies refilled and flowing during service.", "contact": "", "sort_order": 1}, {"team": "Kitchen Management", "responsibility": "Runs the kitchen operation end to end.", "contact": "", "sort_order": 2}, {"team": "Transport/Shopping", "responsibility": "Pickup, drop-off and shopping runs.", "contact": "", "sort_order": 3}, {"team": "Artist Food", "responsibility": "Makes sure performing artists are fed.", "contact": "", "sort_order": 4}, {"team": "Pujo Bhog Coordinator", "responsibility": "Coordinates bhog preparation offered to Maa Durga.", "contact": "", "sort_order": 5}, {"team": "Puja Management", "responsibility": "Puja/Bhog Prepartion", "contact": "Sanjukta De, Malati Roy,Moumita Maitra, Seema Balan", "sort_order": 6}, {"team": "Mondop Management", "responsibility": "Anouncement, Sihooor Khela, Dhunichi", "contact": "Anuradha, Rwitika", "sort_order": 7}, {"team": "Bhog Distribution Team", "responsibility": "Bhog Distribution Team", "contact": "biswas.kamal@gmail.com", "sort_order": 8}, {"team": "Reception", "responsibility": "", "contact": "", "sort_order": 9}, {"team": "Auditorium Seating  &  guest management", "responsibility": "", "contact": "", "sort_order": 10}, {"team": "Cultural Team", "responsibility": "Cultral Program Management", "contact": "Moumita,Anuradha Chattaraj, Ayan", "sort_order": 11}, {"team": "Artist Management", "responsibility": "Artish Pickup/Drop off and cordination", "contact": "Kuntal, Pratik, Highnya,Gautam,Souvik", "sort_order": 12}, {"team": "Decoration Team", "responsibility": "Hall Decoration", "contact": "Vasundara Chakraborty,Anirban Bhattacharya", "sort_order": 13}, {"team": "Floor Management", "responsibility": "Cordinatnte all floor activities/Assign volunteers/Provide help to teams when needed", "contact": "Satish, Nihar Paul, Anirban Bhattacharya", "sort_order": 14}, {"team": "Pendal Team", "responsibility": "Pendal Setup/Dismentle and Decoraions", "contact": "Anirban, Suman,Arnab,Joydeep,Subrata,Nihar", "sort_order": 15}, {"team": "Art Competition", "responsibility": "", "contact": "", "sort_order": 16}, {"team": "Coupon -Cash counter", "responsibility": "", "contact": "", "sort_order": 17}], "members": [{"team": "Food Distribution", "name": "Uttam Das", "phone": ""}, {"team": "Food Distribution", "name": "Raju Debnath", "phone": ""}, {"team": "Food Distribution", "name": "Rakesh Sarkar", "phone": ""}, {"team": "Food Distribution", "name": "Kashinath Roy", "phone": ""}, {"team": "Food Distribution", "name": "Amit Mukhapadhyay", "phone": ""}, {"team": "Food Distribution", "name": "Suman Banik", "phone": ""}, {"team": "Food Distribution", "name": "Nihar Paul", "phone": ""}, {"team": "Food Distribution", "name": "Mainak", "phone": ""}, {"team": "Food Distribution", "name": "Anjan Singh", "phone": ""}, {"team": "Food Distribution", "name": "Diptendu", "phone": ""}, {"team": "Food Distribution", "name": "Samrat Mandal", "phone": ""}, {"team": "Food Distribution", "name": "Santanu", "phone": ""}, {"team": "Food Distribution", "name": "Kuntal Roy", "phone": ""}, {"team": "Food Distribution", "name": "Biswajit Kumar Dek", "phone": ""}, {"team": "Refills/Logistics", "name": "Rahul Deb Mukherji", "phone": ""}, {"team": "Refills/Logistics", "name": "Kamal Biswas", "phone": ""}, {"team": "Kitchen Management", "name": "Sanjit Das", "phone": ""}, {"team": "Kitchen Management", "name": "Gautam De", "phone": ""}, {"team": "Transport/Shopping", "name": "Nobendu Roy", "phone": ""}, {"team": "Transport/Shopping", "name": "Dhiman Biswas", "phone": ""}, {"team": "Transport/Shopping", "name": "Raju Debnath", "phone": ""}, {"team": "Artist Food", "name": "Pratik Datta", "phone": ""}, {"team": "Pujo Bhog Coordinator", "name": "Anuradha Chattaraj", "phone": ""}, {"team": "Pujo Bhog Coordinator", "name": "Ami Goyal", "phone": ""}, {"team": "Pujo Bhog Coordinator", "name": "Malati Roy", "phone": ""}, {"team": "Pujo Bhog Coordinator", "name": "Swati Bhattacharje", "phone": ""}, {"team": "Puja Management", "name": "Sanjukta De", "phone": ""}, {"team": "Puja Management", "name": "Malati Roy", "phone": ""}, {"team": "Puja Management", "name": "Seema Bala", "phone": ""}, {"team": "Puja Management", "name": "Moumita Maitra", "phone": ""}, {"team": "Puja Management", "name": "MadhushreeChattarjee", "phone": ""}, {"team": "Puja Management", "name": "Nipa Das", "phone": ""}, {"team": "Puja Management", "name": "Sumi Debnath", "phone": ""}, {"team": "Puja Management", "name": "Bindia Goyel", "phone": ""}, {"team": "Puja Management", "name": "Moumita Das", "phone": ""}, {"team": "Puja Management", "name": "Monalisa", "phone": ""}, {"team": "Puja Management", "name": "Manisha Patil", "phone": "(914) 482-5392"}, {"team": "Puja Management", "name": "Monisha", "phone": "(732) 896-8302"}, {"team": "Puja Management", "name": "Shreyasee Tiwari", "phone": "(469) 230-2958"}, {"team": "Puja Management", "name": "Tomali Chakraborty", "phone": "(401) 363 5440"}, {"team": "Puja Management", "name": "Bijoya Banik", "phone": ""}, {"team": "Puja Management", "name": "Mousimi Datta", "phone": ""}, {"team": "Puja Management", "name": "Basabdatta Chatterjee", "phone": "615 434 4458"}, {"team": "Mondop Management", "name": "Anuradha Chattaraj", "phone": ""}, {"team": "Mondop Management", "name": "Rwitika Chakraborty", "phone": ""}, {"team": "Mondop Management", "name": "Rahul", "phone": ""}, {"team": "Mondop Management", "name": "Nobina Roy", "phone": ""}, {"team": "Bhog Distribution Team", "name": "Anuradha Chattaraj", "phone": ""}, {"team": "Bhog Distribution Team", "name": "Rahul Choudhary", "phone": "(935) 146-8888"}, {"team": "Bhog Distribution Team", "name": "Avijit Mitra", "phone": "(732) 824-2709"}, {"team": "Bhog Distribution Team", "name": "Kamal Biswas", "phone": "908-358 8312"}, {"team": "Reception", "name": "Anirban Bhattacharjee", "phone": ""}, {"team": "Reception", "name": "Arnab Bhattarcharjee", "phone": ""}, {"team": "Reception", "name": "Debajit Mukherjee", "phone": ""}, {"team": "Reception", "name": "Gungoon De", "phone": ""}, {"team": "Reception", "name": "Mili Roy", "phone": ""}, {"team": "Reception", "name": "Anirban", "phone": ""}, {"team": "Reception", "name": "Anish", "phone": ""}, {"team": "Reception", "name": "Avishek Anand", "phone": ""}, {"team": "Auditorium Seating  &  guest management", "name": "Nihar Paul", "phone": ""}, {"team": "Auditorium Seating  &  guest management", "name": "Anirban Bhattacharjee", "phone": ""}, {"team": "Auditorium Seating  &  guest management", "name": "Arnab Bhattarcharjee", "phone": ""}, {"team": "Auditorium Seating  &  guest management", "name": "Debajit Mukherjee", "phone": ""}, {"team": "Auditorium Seating  &  guest management", "name": "Satish", "phone": ""}, {"team": "Auditorium Seating  &  guest management", "name": "Suman Banik", "phone": ""}, {"team": "Cultural Team", "name": "Anuradha Chattaraj", "phone": ""}, {"team": "Cultural Team", "name": "Moumita", "phone": ""}, {"team": "Cultural Team", "name": "Ayan", "phone": ""}, {"team": "Cultural Team", "name": "Soumen", "phone": ""}, {"team": "Cultural Team", "name": "Mousumi", "phone": ""}, {"team": "Cultural Team", "name": "Rishiraj Chakraborty", "phone": ""}, {"team": "Cultural Team", "name": "Swati", "phone": ""}, {"team": "Cultural Team", "name": "Annesha Bhattacharya", "phone": ""}, {"team": "Cultural Team", "name": "Malancha Chakraborty", "phone": ""}, {"team": "Cultural Team", "name": "Sourabh Mukherjee", "phone": ""}, {"team": "Cultural Team", "name": "Bonyshree Bose", "phone": ""}, {"team": "Artist Management", "name": "Kuntal Roy", "phone": ""}, {"team": "Artist Management", "name": "Gautam De", "phone": ""}, {"team": "Artist Management", "name": "Nobendu Roy", "phone": ""}, {"team": "Artist Management", "name": "Pratik Datta", "phone": ""}, {"team": "Artist Management", "name": "Souvik Roy", "phone": ""}, {"team": "Artist Management", "name": "Himghnya Bhattarcharya", "phone": ""}, {"team": "Artist Management", "name": "Suman Banik", "phone": ""}, {"team": "Artist Management", "name": "Satish", "phone": ""}, {"team": "Artist Management", "name": "Nihar Paul", "phone": ""}, {"team": "Decoration Team", "name": "Vasundara Chakraborty", "phone": ""}, {"team": "Decoration Team", "name": "Anirban Bhattacharya", "phone": ""}, {"team": "Decoration Team", "name": "Debleena", "phone": ""}, {"team": "Decoration Team", "name": "Nisha", "phone": ""}, {"team": "Decoration Team", "name": "Kashinath Roy", "phone": ""}, {"team": "Decoration Team", "name": "Joydeep Chakraborty", "phone": ""}, {"team": "Decoration Team", "name": "Rwitika Chakraborty", "phone": ""}, {"team": "Floor Management", "name": "Anirban Bhattacharya", "phone": ""}, {"team": "Floor Management", "name": "Arnab Bhattarcharya", "phone": ""}, {"team": "Floor Management", "name": "Satish", "phone": ""}, {"team": "Floor Management", "name": "Nihar Paul", "phone": ""}, {"team": "Floor Management", "name": "Suman Banik", "phone": ""}, {"team": "Floor Management", "name": "Sanjeeban Chattarjee", "phone": ""}, {"team": "Pendal Team", "name": "Anirban Bhattacharya", "phone": ""}, {"team": "Pendal Team", "name": "Arnab Bhattarcharya", "phone": ""}, {"team": "Pendal Team", "name": "Satish", "phone": ""}, {"team": "Pendal Team", "name": "Nihar Paul", "phone": ""}, {"team": "Pendal Team", "name": "Suman", "phone": ""}, {"team": "Pendal Team", "name": "Uttam Das", "phone": ""}, {"team": "Pendal Team", "name": "Raju Debnath", "phone": ""}, {"team": "Pendal Team", "name": "Rakesh Sarkar", "phone": ""}, {"team": "Pendal Team", "name": "Nobendu Roy", "phone": ""}, {"team": "Pendal Team", "name": "Gautam De", "phone": ""}, {"team": "Pendal Team", "name": "Kashinath Roy", "phone": ""}, {"team": "Pendal Team", "name": "Anuradha Chattaraj", "phone": ""}, {"team": "Pendal Team", "name": "Amit Mukhapadhyay", "phone": ""}, {"team": "Pendal Team", "name": "Dhiman Biswas", "phone": ""}, {"team": "Pendal Team", "name": "Pradip Nair", "phone": ""}, {"team": "Pendal Team", "name": "Suman Banik", "phone": ""}, {"team": "Pendal Team", "name": "Ajoy Rathi", "phone": ""}, {"team": "Pendal Team", "name": "Ami Goyal", "phone": ""}, {"team": "Pendal Team", "name": "Mainak", "phone": ""}, {"team": "Pendal Team", "name": "Malati Roy", "phone": ""}, {"team": "Pendal Team", "name": "Pratik Datta", "phone": ""}, {"team": "Pendal Team", "name": "Sanjit", "phone": ""}, {"team": "Art Competition", "name": "Nisha", "phone": ""}, {"team": "Art Competition", "name": "Pallavi", "phone": ""}, {"team": "Art Competition", "name": "Bijoya", "phone": ""}, {"team": "Coupon -Cash counter", "name": "Kuntal Roy", "phone": ""}, {"team": "Coupon -Cash counter", "name": "Pradip Nair", "phone": ""}, {"team": "Coupon -Cash counter", "name": "Sanjeeban Chattarjee", "phone": ""}]}
JSON
    , true);

    $db->beginTransaction();
    $metaStmt = $db->prepare("INSERT INTO tb_team_meta (team, responsibility, contact, sort_order) VALUES (:team, :resp, :contact, :sort)");
    foreach ($seed['teams'] as $t) {
        $metaStmt->execute([':team' => $t['team'], ':resp' => $t['responsibility'], ':contact' => $t['contact'], ':sort' => $t['sort_order']]);
    }
    $memStmt = $db->prepare("INSERT INTO tb_team_members (team, name, phone) VALUES (:team, :name, :phone)");
    foreach ($seed['members'] as $m) {
        $memStmt->execute([':team' => $m['team'], ':name' => $m['name'], ':phone' => $m['phone']]);
    }
    $db->commit();
}

$action = isset($_post->action) ? (string)$_post->action : 'list';
$db = openDB();

switch ($action) {

    case 'list': {
        $teams = $db->query("SELECT team, responsibility, contact FROM tb_team_meta ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
        $memStmt = $db->prepare("SELECT id, name, phone FROM tb_team_members WHERE team = :team ORDER BY name COLLATE NOCASE ASC");

        $order = [];
        $out = [];
        foreach ($teams as $t) {
            $order[] = $t['team'];
            $memStmt->execute([':team' => $t['team']]);
            $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);
            $out[$t['team']] = [
                'responsibility' => $t['responsibility'],
                'contact'        => $t['contact'],
                'members'        => array_map(function($m) {
                    return ['id' => (int)$m['id'], 'name' => $m['name'], 'phone' => $m['phone']];
                }, $members),
            ];
        }
        echo json_encode(['ok' => true, 'order' => $order, 'teams' => $out]);
        break;
    }

    // Anyone can add themselves (or someone else) to a team — no login on this page, matches
    // the same trust model as the role-confirmation feature already on it.
    case 'add': {
        $team = mb_substr(isset($_post->team) ? (string)$_post->team : '', 0, 100);
        $name = mb_substr(trim(isset($_post->name) ? (string)$_post->name : ''), 0, 200);
        $phone = mb_substr(isset($_post->phone) ? (string)$_post->phone : '', 0, 50);
        if (!$team || !$name) { echo json_encode(['err' => 'Name is required']); exit; }

        $chk = $db->prepare("SELECT COUNT(*) FROM tb_team_meta WHERE team = :team");
        $chk->execute([':team' => $team]);
        if (!$chk->fetchColumn()) { echo json_encode(['err' => 'Unknown team']); exit; }

        $stmt = $db->prepare("INSERT INTO tb_team_members (team, name, phone) VALUES (:team, :name, :phone)");
        $stmt->execute([':team' => $team, ':name' => $name, ':phone' => $phone]);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        break;
    }

    case 'delete': {
        $id = isset($_post->id) ? (int)$_post->id : 0;
        if ($id <= 0) { echo json_encode(['err' => 'Missing id']); exit; }
        $stmt = $db->prepare("DELETE FROM tb_team_members WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
