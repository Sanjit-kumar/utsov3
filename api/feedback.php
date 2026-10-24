<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

function openFeedbackDB() {
    $db = new PDO("sqlite:" . dirname(__FILE__) . '/db/feedback.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS tb_feedback (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT,
        overall_rating INTEGER,
        food_rating INTEGER,
        cultural_rating INTEGER,
        organization_rating INTEGER,
        venue_rating INTEGER,
        loved_most TEXT,
        improve TEXT,
        recommend TEXT,
        name TEXT,
        email TEXT,
        ipaddress TEXT
    )");
    return $db;
}

function sanitizeHeader($value) {
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

function clampRating($v) {
    $v = (int)$v;
    return ($v >= 0 && $v <= 5) ? $v : 0;
}

$action = isset($_post->action) ? (string)$_post->action : 'add';

switch ($action) {
    case 'add': {
        $a = isset($_post->answers) ? $_post->answers : null;
        if (!$a) { echo json_encode(['err' => 'Missing answers']); break; }

        $overall      = clampRating(isset($a->overall) ? $a->overall : 0);
        $food         = clampRating(isset($a->food) ? $a->food : 0);
        $cultural     = clampRating(isset($a->cultural) ? $a->cultural : 0);
        $organization = clampRating(isset($a->organization) ? $a->organization : 0);
        $venue        = clampRating(isset($a->venue) ? $a->venue : 0);
        $loved        = mb_substr(isset($a->loved) ? (string)$a->loved : '', 0, 3000);
        $improve      = mb_substr(isset($a->improve) ? (string)$a->improve : '', 0, 3000);
        $recommend    = mb_substr(isset($a->recommend) ? (string)$a->recommend : '', 0, 100);
        $name         = mb_substr(isset($a->name) ? (string)$a->name : '', 0, 200);
        $email        = mb_substr(isset($a->email) ? (string)$a->email : '', 0, 254);
        $email        = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';

        // Silently drop completely blank submissions (no rating, no text at all) -- matches
        // the same bot-guard pattern used in volunteers.php.
        if (!$overall && !$food && !$cultural && !$organization && !$venue && !$loved && !$improve && !$recommend) {
            echo json_encode(['ok' => true]);
            break;
        }

        $db = openFeedbackDB();
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare(
            "INSERT INTO tb_feedback
                (date, overall_rating, food_rating, cultural_rating, organization_rating, venue_rating,
                 loved_most, improve, recommend, name, email, ipaddress)
             VALUES
                (:date, :overall, :food, :cultural, :organization, :venue,
                 :loved, :improve, :recommend, :name, :email, :ip)"
        );
        $ins->execute([
            ':date' => $now, ':overall' => $overall, ':food' => $food, ':cultural' => $cultural,
            ':organization' => $organization, ':venue' => $venue, ':loved' => $loved,
            ':improve' => $improve, ':recommend' => $recommend, ':name' => $name, ':email' => $email,
            ':ip' => get_client_ip(),
        ]);

        // Admin notification -- lightweight summary, same pattern as volunteers.php.
        $emojiMap = [1=>'😞',2=>'🙁',3=>'😐',4=>'🙂',5=>'🤩'];
        $safeName = htmlspecialchars($name ?: 'Anonymous', ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeLoved = nl2br(htmlspecialchars($loved, ENT_QUOTES, 'UTF-8'));
        $safeImprove = nl2br(htmlspecialchars($improve, ENT_QUOTES, 'UTF-8'));
        $mailHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";
        $subject = "UTSOV Feedback: {$overall}/5 from " . ($name ?: 'Anonymous');
        $body = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
            . "<h2 style='color:#8B1A1A'>New Feedback " . (isset($emojiMap[$overall]) ? $emojiMap[$overall] : '') . "</h2>"
            . "<table style='border-collapse:collapse;font-size:14px'>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Overall:</b></td><td>{$overall}/5</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Food & Dinner:</b></td><td>{$food}/5</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Cultural Program:</b></td><td>{$cultural}/5</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Organization:</b></td><td>{$organization}/5</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Venue & Decor:</b></td><td>{$venue}/5</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Recommend:</b></td><td>" . htmlspecialchars($recommend, ENT_QUOTES, 'UTF-8') . "</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>From:</b></td><td>{$safeName}" . ($email ? " ({$safeEmail})" : '') . "</td></tr>"
            . ($loved ? "<tr><td style='padding:4px 16px 4px 0;vertical-align:top'><b>Loved most:</b></td><td>{$safeLoved}</td></tr>" : '')
            . ($improve ? "<tr><td style='padding:4px 16px 4px 0;vertical-align:top'><b>Could improve:</b></td><td>{$safeImprove}</td></tr>" : '')
            . "</table></body></html>";
        notifyAdmins(sanitizeHeader($subject), $body, $mailHeaders);

        echo json_encode(['ok' => true]);
        break;
    }

    // Read-only summary for the committee -- average ratings + counts, no raw PII dump.
    case 'summary': {
        $db = openFeedbackDB();
        $rows = $db->query("SELECT overall_rating, food_rating, cultural_rating, organization_rating, venue_rating, recommend FROM tb_feedback")->fetchAll(PDO::FETCH_ASSOC);
        $n = count($rows);
        $avg = function($col) use ($rows, $n) {
            if ($n === 0) return 0;
            $sum = 0; $cnt = 0;
            foreach ($rows as $r) { if ((int)$r[$col] > 0) { $sum += (int)$r[$col]; $cnt++; } }
            return $cnt > 0 ? round($sum / $cnt, 2) : 0;
        };
        $recommendCounts = [];
        foreach ($rows as $r) {
            $k = $r['recommend'] ?: '(blank)';
            $recommendCounts[$k] = (isset($recommendCounts[$k]) ? $recommendCounts[$k] : 0) + 1;
        }
        echo json_encode([
            'ok' => true,
            'totalResponses' => $n,
            'avgOverall' => $avg('overall_rating'),
            'avgFood' => $avg('food_rating'),
            'avgCultural' => $avg('cultural_rating'),
            'avgOrganization' => $avg('organization_rating'),
            'avgVenue' => $avg('venue_rating'),
            'recommendCounts' => $recommendCounts,
        ]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
