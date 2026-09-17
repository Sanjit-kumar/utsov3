<?php
// ─── UTSOV Admin Portal 2026 — Professional Dashboard ─────────────────────────
define('ADMIN_SESSION', 'utsov_adm_26');
define('ADMIN_DB',      dirname(__FILE__) . '/api/db/admin.db');
session_start();

$REG_DB = dirname(__FILE__) . '/api/db/registration.db';
$CAP_DB = dirname(__FILE__) . '/api/db/capture.db';

// Twilio reporting helpers (Balance/Usage/Messages API wrappers) - best-effort, never
// fatal if the files are missing or Twilio config isn't set up.
if (file_exists(dirname(__FILE__).'/api/twilio-config.php')) require(dirname(__FILE__).'/api/twilio-config.php');
if (file_exists(dirname(__FILE__).'/api/twilio-stats.php'))  require(dirname(__FILE__).'/api/twilio-stats.php');

// ─── Admin DB helpers ─────────────────────────────────────────────────────────
function adm_db() {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . ADMIN_DB);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}
function adm_logged_in() { return !empty($_SESSION[ADMIN_SESSION]['email']); }
function adm_is_super()  { return !empty($_SESSION[ADMIN_SESSION]['role']) && $_SESSION[ADMIN_SESSION]['role'] === 'superadmin'; }

// ─── Password Reset Handler ───────────────────────────────────────────────────
$resetOk = $resetErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_pwd'
    && adm_logged_in() && adm_is_super()) {
    $tEmail = isset($_POST['target_email']) ? trim(strtolower($_POST['target_email'])) : '';
    if ($tEmail) {
        try {
            $st = adm_db()->prepare('SELECT * FROM tb_admin_users WHERE email = ?');
            $st->execute(array($tEmail));
            $tUser = $st->fetch(PDO::FETCH_ASSOC);
            if ($tUser) {
                $chars  = 'abcdefghjkmnpqrstuvwxyz';
                $newPwd = substr($chars, rand(0, strlen($chars)-4), 3) . rand(10,99) . 'puja' . rand(10,99);
                adm_db()->prepare('UPDATE tb_admin_users SET password_hash = ? WHERE email = ?')
                        ->execute(array(password_hash($newPwd, PASSWORD_DEFAULT), $tEmail));
                $body = "Dear ".$tUser['name'].",\n\n"
                      . "Your UTSOV Admin Portal password has been reset by a super admin.\n\n"
                      . "Email:    ".$tEmail."\n"
                      . "Password: ".$newPwd."\n"
                      . "Portal:   https://utsov.org/admin.php\n\n"
                      . "Please keep your credentials secure.\n\n"
                      . "UTSOV Puja 2026 Team";
                @mail($tEmail, 'UTSOV Admin Portal - Password Reset', $body,
                      "From: noreply@utsov.org\r\nContent-Type: text/plain; charset=UTF-8");
                $resetOk = 'Password reset. New credentials emailed to '.$tEmail;
            } else { $resetErr = 'User not found.'; }
        } catch(\Exception $e) { $resetErr = 'Error: '.$e->getMessage(); }
    }
    header('Location: admin.php?tab=admins'); exit;
}

// ─── Delete Registration (superadmin only) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_reg'
    && adm_logged_in() && adm_is_super()) {
    $regId = isset($_POST['reg_id']) ? (int)$_POST['reg_id'] : 0;
    if ($regId > 0) {
        try {
            $db = new PDO("sqlite:".$GLOBALS['REG_DB']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->prepare('DELETE FROM tb_registrations WHERE id = ?')->execute(array($regId));
        } catch(\Exception $e) {}
    }
    header('Location: admin.php#tables-blk'); exit;
}

// ─── Mark Registration Paid (any logged-in admin) ──────────────────────────────
// For rows that never went through PayPal capture (e.g. Zelle/Venmo receipt uploads,
// which land as status='pending_review') - lets an admin confirm the manual review is
// done and flip the row to 'paid' so it's counted in revenue/passes and highlighted green.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_paid'
    && adm_logged_in()) {
    $regId = isset($_POST['reg_id']) ? (int)$_POST['reg_id'] : 0;
    if ($regId > 0) {
        try {
            $db = new PDO("sqlite:".$GLOBALS['REG_DB']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->prepare("UPDATE tb_registrations SET status='paid', updated_date=? WHERE id = ?")
               ->execute(array(date('Y-m-d H:i:s'), $regId));
        } catch(\Exception $e) {}
    }
    header('Location: admin.php#tables-blk'); exit;
}

// ─── CSV Export ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && adm_logged_in()) {
    $allowed = array('registrations','donations','contacts','volunteers');
    $target  = in_array($_GET['export'], $allowed) ? $_GET['export'] : '';
    // Only registrations/donations have a status column - contacts/volunteers silently ignore
    // a status filter rather than erroring, since there's nothing meaningful to filter by there.
    $statusFilterable = array('registrations','donations');
    $statusFilter = (in_array($target, $statusFilterable) && isset($_GET['status']) && $_GET['status'] !== '')
        ? $_GET['status'] : null;
    if ($target) {
        header('Content-Type: text/csv; charset=UTF-8');
        $fnameSuffix = $statusFilter ? '_'.preg_replace('/[^a-z0-9_]/i','',$statusFilter) : '';
        header('Content-Disposition: attachment; filename="utsov_'.$target.$fnameSuffix.'_'.date('Ymd_His').'.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        try {
            $db  = $target==='registrations' ? new PDO("sqlite:".$GLOBALS['REG_DB']) : new PDO("sqlite:".$GLOBALS['CAP_DB']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tbl = $target==='registrations' ? 'tb_registrations' : 'tb_'.$target;
            if ($statusFilter !== null) {
                $stmt = $db->prepare("SELECT * FROM $tbl WHERE status = :status ORDER BY id DESC");
                $stmt->bindValue(':status', $statusFilter, PDO::PARAM_STR);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = $db->query("SELECT * FROM $tbl ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            }
            if ($rows) {
                // Prepend a clean sequential row number (1..N of THIS export) as the first
                // column - the raw 'id' column further along is the true, permanent database
                // key, but it's an auto-increment shared across every status, so a filtered
                // export (e.g. "paid" only) shows a sparse, non-consecutive set of ids that
                // reads as confusing/broken. This gives readable numbering without losing
                // the real id for anyone who needs to cross-reference back to the admin table.
                $seq = 1;
                $numbered = array();
                foreach ($rows as $r) { $numbered[] = array_merge(array('#' => $seq++), $r); }
                fputcsv($out, array_keys($numbered[0]));
                foreach ($numbered as $r) fputcsv($out, $r);
            }
        } catch(\Exception $e) { fputcsv($out, array('Error: '.$e->getMessage())); }
        fclose($out); exit;
    }
}

// ─── Auth ────────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }
$loginErr = false;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['email'], $_POST['pwd'])) {
    $inEmail = trim(strtolower($_POST['email']));
    $inPwd   = trim($_POST['pwd']);
    try {
        $st = adm_db()->prepare('SELECT * FROM tb_admin_users WHERE email = ?');
        $st->execute(array($inEmail));
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($inPwd, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION[ADMIN_SESSION] = array('email'=>$user['email'],'name'=>$user['name'],'role'=>$user['role']);
            try { adm_db()->prepare('UPDATE tb_admin_users SET last_login=? WHERE email=?')
                         ->execute(array(date('Y-m-d H:i:s'),$user['email'])); } catch(\Exception $e){}
            header('Location: admin.php'); exit;
        }
    } catch(\Exception $e){}
    $loginErr = true;
}
$auth    = adm_logged_in();
$curUser = isset($_SESSION[ADMIN_SESSION]) ? $_SESSION[ADMIN_SESSION] : null;

// ─── Helpers ─────────────────────────────────────────────────────────────────
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// food_pref is stored as a JSON array (one entry per attendee) as of the per-attendee
// meal preference feature. Falls back to treating the whole value as one plain string
// for rows written before that existed.
function foodPrefList($raw) {
    if (empty($raw)) return [];
    $arr = json_decode($raw, true);
    if (!is_array($arr)) { $arr = [$raw]; }
    return array_values(array_filter($arr, function($v){ return $v !== null && $v !== ''; }));
}
$FOOD_SHORT_LABELS = ['vegetarian' => 'Veg', 'nonvegetarian' => 'Non-Veg', 'nodinner' => 'No Dinner'];
function foodPrefDisplay($raw) {
    global $FOOD_SHORT_LABELS;
    $list = foodPrefList($raw);
    if (!$list) return '—';
    $labels = array_map(function($f) use ($FOOD_SHORT_LABELS) {
        return isset($FOOD_SHORT_LABELS[$f]) ? $FOOD_SHORT_LABELS[$f] : esc($f);
    }, $list);
    return implode(', ', $labels);
}
function fmtDate($d){
    if (!$d) return '—';
    if (preg_match('/^(\d{4})(\d{2})(\d{2}):(\d{2})(\d{2})(\d{2})$/', $d, $m))
        return date('M j, Y g:ia', mktime((int)$m[4],(int)$m[5],(int)$m[6],(int)$m[2],(int)$m[3],(int)$m[1]));
    $ts = strtotime($d); return $ts ? date('M j, Y g:ia', $ts) : esc($d);
}
function getYear($d){
    if (!$d) return 'Unknown';
    $d = trim((string)$d);
    // Ymd:His (e.g. 20260703:165230 — volunteers.php format)
    if (preg_match('/^(\d{4})\d{4}:\d{6}$/', $d, $m)) return $m[1];
    // 14-digit YmdHis no colon (e.g. 20260703165230)
    if (preg_match('/^(\d{4})\d{10}$/', $d, $m) && (int)$m[1] >= 2000) return $m[1];
    // Unix timestamp (10-digit, post-2001)
    if (preg_match('/^\d{10}$/', $d)) { $ts=(int)$d; if($ts>978307200 && $ts<4102444800) return date('Y',$ts); }
    // Standard formats: Y-m-d H:i:s, m/d/Y, d-m-Y, etc.
    $ts = strtotime($d);
    if ($ts && $ts > 0 && $ts < 4102444800) return date('Y', $ts);
    // Last resort: grab first 20xx year found anywhere in the string
    if (preg_match('/\b(20\d{2})\b/', $d, $m)) return $m[1];
    return 'Unknown';
}
function sortDate($d){
    if (!$d) return '';
    if (preg_match('/^(\d{4})(\d{2})(\d{2}):(\d{2})(\d{2})(\d{2})$/', $d, $m))
        return $m[1].'-'.$m[2].'-'.$m[3].' '.$m[4].':'.$m[5].':'.$m[6];
    return $d;
}
function badge($s){
    $s=(string)$s;
    if ($s==='paid')            return '<span class="badge bdg-paid">PAID</span>';
    if ($s==='created')         return '<span class="badge bdg-pend">PENDING</span>';
    // Short label so it doesn't overflow the Status column's fixed width and clip the
    // Mark Paid button that follows it in the same cell (PENDING_REVIEW was too long).
    if ($s==='pending_review')  return '<span class="badge bdg-pend">REVIEW</span>';
    return '<span class="badge bdg-other">'.esc(strtoupper($s)).'</span>';
}

// ─── Defaults ────────────────────────────────────────────────────────────────
$regs=$dons=$conts=$vols=array();
$totRev=$totDon=$paidRegs=$paidDons=0;
$totPasses=$totAdults=$totKids6to12=$totKidsUnder6=$studentCount=$memberWanted=0;
$regsByYear=$donsByYear=$contsByYear=$volsByYear=array();
$topCities=$foodPrefs=$daysSel=$heardFrom=array();
$statusBreak=array('paid'=>0,'pending'=>0,'other'=>0);
$recentAct=array();
$bestRegYear=$bestRevYear=$bestDonYear='—';
$bestRegCount=0; $bestRevAmt=0.0; $bestDonAmt=0.0;
$avgDon=$avgTicket=$convRate=0;
$jsYears=$jsRegsCount=$jsRegRevenue='[]';
$jsDonsCount=$jsDonTotal=$jsContsCount=$jsVolsCount='[]';
$jsTopCityL=$jsTopCityD=$jsFoodL=$jsFoodD=$jsDaysL=$jsDaysD=$jsHeardL=$jsHeardD='[]';
$jsStatusD='[0,0,0]'; $jsAttendeeD='[0,0,0]';

// ─── Load & Aggregate ────────────────────────────────────────────────────────
if ($auth) {
    try {
        $rdb = new PDO("sqlite:".$REG_DB);
        $rdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $regs = $rdb->query(
            "SELECT id,date,updated_date,name,email,phone,city,qty,adults,kids_6to12,children,
                    total_amount,days_selection,food_pref,is_student,heard_from,status,
                    paypal_order_id,paypal_capture_id,ipaddress FROM tb_registrations ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch(\Exception $e){}
    try {
        $cdb = new PDO("sqlite:".$CAP_DB);
        $cdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try { $dons  = $cdb->query("SELECT id,date,updated_date,name,email,amount,status,paypal_order_id,paypal_capture_id,ipaddress FROM tb_donations ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(\Exception $e){}
        try { $conts = $cdb->query("SELECT id,date,name,email,phone,topic,message,ipaddress FROM tb_contacts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(\Exception $e){}
        try { $vols  = $cdb->query("SELECT id,date,name,email,phone,city,state,zip,address1,address2,message,memberrequest,ipaddress FROM tb_volunteers ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(\Exception $e){}
    } catch(\Exception $e){}

    // ─── Twilio SMS reporting (superadmin only - exposes account balance/spend) ───────
    // Counts/cost come from bucketing the live Messages list, NOT Twilio's Usage Records
    // API - confirmed live 2026-09-17 that Usage Records lags hours behind actual sends
    // (reports $0/0 count for messages already showing status=delivered). See
    // api/twilio-stats.php's twilioGetMessageStats() doc comment for details.
    $twilioBalance = null;
    $twilioStatsDefault = array(
        'ok' => false, 'messages' => array(),
        'today'     => array('count' => 0, 'cost' => 0.0, 'pending' => 0),
        'thisMonth' => array('count' => 0, 'cost' => 0.0, 'pending' => 0),
        'allTime'   => array('count' => 0, 'cost' => 0.0, 'pending' => 0),
    );
    $twilioStats = $twilioStatsDefault;
    $twilioReady = adm_is_super() && function_exists('\UtsovAPI\twilioGetBalance');
    if ($twilioReady) {
        try {
            $twilioBalance = \UtsovAPI\twilioGetBalance();
            $twilioStats   = \UtsovAPI\twilioGetMessageStats(1000);
        } catch(\Exception $e){}
    }

    // Distinct status values actually present, for the CSV export filter dropdowns - derived
    // from the data itself (not a hardcoded list) so a newly-appearing status (e.g. 'refunded')
    // shows up as a filterable option automatically, with no code change needed.
    $regStatusOptions = array_values(array_unique(array_filter(array_column($regs, 'status'))));
    sort($regStatusOptions);
    $donStatusOptions = array_values(array_unique(array_filter(array_column($dons, 'status'))));
    sort($donStatusOptions);

    foreach ($regs as $r) {
        $yr = getYear($r['date']);
        if (!isset($regsByYear[$yr])) $regsByYear[$yr]=array('count'=>0,'revenue'=>0,'paid'=>0);
        $regsByYear[$yr]['count']++;
        // Revenue only counts PAID rows - a 'created' row is an abandoned/incomplete PayPal
        // order (order was started but never captured) and represents no money actually
        // collected. Previously this summed every row regardless of status, inflating
        // Ticket Revenue by the dollar amount of every abandoned checkout attempt.
        if ($r['status']==='paid') {
            $regsByYear[$yr]['revenue'] += (float)$r['total_amount'];
            $totRev += (float)$r['total_amount'];
            $paidRegs++; $regsByYear[$yr]['paid']++;
            $totPasses   += (int)$r['qty'];
            $totAdults   += (int)(isset($r['adults'])     ? $r['adults']     : 0);
            $totKids6to12+= (int)(isset($r['kids_6to12']) ? $r['kids_6to12'] : 0);
            $totKidsUnder6+=(int)(isset($r['children'])   ? $r['children']   : 0);
        }
        if (!empty($r['is_student'])) $studentCount++;
        if ($r['status']==='paid') $statusBreak['paid']++;
        elseif ($r['status']==='created') $statusBreak['pending']++;
        else $statusBreak['other']++;
        if (!empty($r['city']))          { $c=trim($r['city']);      if(!isset($topCities[$c]))$topCities[$c]=0; $topCities[$c]++; }
        foreach (foodPrefList($r['food_pref']) as $fp) { if(!isset($foodPrefs[$fp]))$foodPrefs[$fp]=0; $foodPrefs[$fp]++; }
        if (!empty($r['days_selection'])){ $ds=$r['days_selection']; if(!isset($daysSel[$ds]))$daysSel[$ds]=0; $daysSel[$ds]++; }
        if (!empty($r['heard_from']))    { $hf=$r['heard_from'];     if(!isset($heardFrom[$hf]))$heardFrom[$hf]=0; $heardFrom[$hf]++; }
    }
    ksort($regsByYear); arsort($topCities); $topCities=array_slice($topCities,0,8,true);
    arsort($foodPrefs); arsort($daysSel); arsort($heardFrom);
    $avgTicket = $paidRegs>0 ? $totRev/$paidRegs : 0;
    $convRate  = count($regs)>0 ? round($paidRegs/count($regs)*100,1) : 0;

    foreach ($dons as $d) {
        $yr = getYear($d['date']);
        if (!isset($donsByYear[$yr])) $donsByYear[$yr]=array('count'=>0,'total'=>0);
        $donsByYear[$yr]['count']++;
        $donsByYear[$yr]['total'] += (float)$d['amount'];
        $totDon += (float)$d['amount'];
        if ($d['status']==='paid') $paidDons++;
    }
    ksort($donsByYear);
    $avgDon = $paidDons>0 ? $totDon/$paidDons : 0;

    foreach ($conts as $c){ $yr=getYear($c['date']); if(!isset($contsByYear[$yr]))$contsByYear[$yr]=0; $contsByYear[$yr]++; }
    ksort($contsByYear);
    foreach ($vols  as $v){ $yr=getYear($v['date']); if(!isset($volsByYear[$yr]))$volsByYear[$yr]=0; $volsByYear[$yr]++; if(!empty($v['memberrequest']))$memberWanted++; }
    ksort($volsByYear);

    foreach ($regsByYear as $yr=>$d){ if($d['count']>$bestRegCount){$bestRegCount=$d['count'];$bestRegYear=$yr;} if($d['revenue']>$bestRevAmt){$bestRevAmt=$d['revenue'];$bestRevYear=$yr;} }
    foreach ($donsByYear as $yr=>$d){ if($d['total']>$bestDonAmt){$bestDonAmt=$d['total'];$bestDonYear=$yr;} }

    // Recent activity
    foreach (array_slice($regs,0,8)  as $r) $recentAct[]=array('type'=>'reg','date'=>sortDate($r['date']),'name'=>$r['name'],'detail'=>$r['qty'].' pass(es) · $'.number_format($r['total_amount'],2),'status'=>$r['status']);
    foreach (array_slice($dons,0,8)  as $d) $recentAct[]=array('type'=>'don','date'=>sortDate($d['date']),'name'=>$d['name'],'detail'=>'Donated $'.number_format($d['amount'],2),'status'=>$d['status']);
    foreach (array_slice($conts,0,5) as $c) $recentAct[]=array('type'=>'msg','date'=>sortDate($c['date']),'name'=>$c['name'],'detail'=>'Topic: '.esc(mb_substr($c['topic'],0,50)),'status'=>'');
    foreach (array_slice($vols,0,5)  as $v) $recentAct[]=array('type'=>'vol','date'=>sortDate($v['date']),'name'=>$v['name'],'detail'=>'Volunteer'.(!empty($v['memberrequest'])?' · wants membership':''),'status'=>'');
    usort($recentAct, function($a,$b){ return strcmp($b['date'],$a['date']); });
    $recentAct = array_slice($recentAct,0,12);

    // Build chart JSON
    $allYears = array_unique(array_merge(array_keys($regsByYear),array_keys($donsByYear),array_keys($contsByYear),array_keys($volsByYear)));
    sort($allYears);
    $yrs = array_values($allYears);
    $mapCount = function($arr) use ($yrs){ return array_map(function($y) use ($arr){ return isset($arr[$y]) ? (is_array($arr[$y]) ? (int)$arr[$y]['count'] : (int)$arr[$y]) : 0; }, $yrs); };
    $mapFloat = function($arr,$k) use ($yrs){ return array_map(function($y) use ($arr,$k){ return isset($arr[$y]) ? round((float)$arr[$y][$k],2) : 0; }, $yrs); };

    $jsYears      = json_encode($yrs);
    $jsRegsCount  = json_encode($mapCount($regsByYear));
    $jsRegRevenue = json_encode($mapFloat($regsByYear,'revenue'));
    $jsDonsCount  = json_encode($mapCount($donsByYear));
    $jsDonTotal   = json_encode($mapFloat($donsByYear,'total'));
    $jsContsCount = json_encode($mapCount($contsByYear));
    $jsVolsCount  = json_encode($mapCount($volsByYear));
    $jsTopCityL   = json_encode(array_keys($topCities));
    $jsTopCityD   = json_encode(array_values($topCities));
    $jsFoodL      = json_encode(array_map(function($f) use ($FOOD_SHORT_LABELS){ return isset($FOOD_SHORT_LABELS[$f]) ? $FOOD_SHORT_LABELS[$f] : $f; }, array_keys($foodPrefs)));
    $jsFoodD      = json_encode(array_values($foodPrefs));
    $jsDaysL      = json_encode(array_keys($daysSel));
    $jsDaysD      = json_encode(array_values($daysSel));
    $jsHeardL     = json_encode(array_keys($heardFrom));
    $jsHeardD     = json_encode(array_values($heardFrom));
    $jsStatusD    = json_encode(array($statusBreak['paid'],$statusBreak['pending'],$statusBreak['other']));
    $jsAttendeeD  = json_encode(array($totAdults,$totKids6to12,$totKidsUnder6));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>UTSOV Admin 2026</title>
<meta name="robots" content="noindex,nofollow">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#eef0f6;--card:#fff;--bdr:#e2e5f0;--bdr2:#d0d5e8;
  --text:#0f172a;--sub:#374151;--muted:#6b7280;--muted2:#9ca3af;
  --red:#8B1A1A;--red-dk:#5C1010;--red-lt:#fef2f2;--red-mid:#fee2e2;
  --gold:#B8860B;--gold-lt:#fdf7e0;--gold-brd:#f59e0b;
  --green:#059669;--green-lt:#ecfdf5;
  --blue:#1d4ed8;--blue-lt:#eff6ff;
  --orange:#d97706;--orange-lt:#fffbeb;
  --purple:#7c3aed;--purple-lt:#f5f3ff;
  --teal:#0891b2;
  --sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
  --sh-md:0 4px 20px rgba(0,0,0,.1);
  --f:'Segoe UI',system-ui,sans-serif;
}
body{font-family:var(--f);background:var(--bg);color:var(--text);min-height:100vh;font-size:13.5px;line-height:1.5}

/* LOGIN */
.lw{display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#fff5f5 0%,#fff8ec 50%,#f0f4ff 100%)}
.lb{background:var(--card);border:1px solid var(--bdr);border-radius:22px;padding:3rem 2.5rem;width:380px;text-align:center;box-shadow:var(--sh-md)}
.lb-logo{font-size:2.6rem;font-weight:800;color:var(--red);letter-spacing:.1em;margin-bottom:.15rem}
.lb-sub{color:var(--muted);font-size:.75rem;letter-spacing:.1em;text-transform:uppercase;margin-bottom:2.5rem}
.lb input[type=password],.lb input[type=email]{width:100%;background:#f9fafb;border:1.5px solid var(--bdr);border-radius:10px;padding:.85rem 1.1rem;color:var(--text);font-size:.9rem;outline:none;margin-bottom:1rem;transition:border-color .2s;font-family:var(--f)}
.lb input[type=password]:focus,.lb input[type=email]:focus{border-color:var(--red);background:#fff}
.lb button{width:100%;background:var(--red);color:#fff;border:none;border-radius:10px;padding:.9rem;font-size:.9rem;font-weight:700;cursor:pointer;letter-spacing:.05em;transition:background .2s;font-family:var(--f)}
.lb button:hover{background:var(--red-dk)}
.lb-err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#dc2626;font-size:.78rem;padding:.65rem .9rem;margin-bottom:1rem;text-align:left}
.lb-lock{font-size:2.5rem;margin-bottom:1rem}

/* HEADER */
.hdr{background:linear-gradient(90deg,#3d0808,var(--red));border-bottom:2px solid rgba(201,168,76,.5);padding:.9rem 2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;box-shadow:0 2px 16px rgba(0,0,0,.2)}
.hdr-logo{font-size:1.25rem;font-weight:800;color:#E8C97A;letter-spacing:.1em}
.hdr-logo em{font-style:normal;font-size:.65rem;font-weight:400;color:rgba(255,255,255,.35);margin-left:.7rem;letter-spacing:.12em;text-transform:uppercase}
.hdr-r{display:flex;align-items:center;gap:1.2rem}
.hdr-ts{font-size:.67rem;color:rgba(255,255,255,.3);letter-spacing:.04em}
.btn-lo{font-size:.72rem;color:rgba(255,255,255,.65);background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:6px;padding:.35rem .9rem;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-lo:hover{color:#fff;background:rgba(255,255,255,.2)}

/* MAIN */
.main{max-width:1600px;margin:0 auto;padding:2rem 1.75rem 5rem}

/* SECTION HEADER */
.sh{display:flex;align-items:center;gap:.6rem;margin-bottom:1.1rem;margin-top:.25rem}
.sh-line{width:4px;height:16px;border-radius:2px;background:var(--red);flex-shrink:0}
.sh-title{font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}

/* KPI CARDS */
.kpi{display:grid;grid-template-columns:repeat(6,1fr);gap:1rem;margin-bottom:2.25rem}
.kc{background:var(--card);border:1px solid var(--bdr);border-radius:16px;padding:1.3rem 1.4rem 1.5rem;box-shadow:var(--sh);position:relative;overflow:hidden;cursor:default;transition:transform .15s,box-shadow .15s}
.kc:hover{transform:translateY(-2px);box-shadow:var(--sh-md)}
.kc::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--kc-c,#ddd);border-radius:0 0 3px 3px}
.kc-ico{position:absolute;top:.9rem;right:1rem;font-size:1.7rem;opacity:.12;line-height:1;pointer-events:none}
.kc-lbl{font-size:.6rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem}
.kc-val{font-size:2rem;font-weight:800;color:var(--kc-c,#222);letter-spacing:-.03em;line-height:1}
.kc-sub{font-size:.68rem;color:var(--muted2);margin-top:.4rem;line-height:1.4}
.kc.red  {--kc-c:var(--red)}
.kc.gold {--kc-c:var(--gold)}
.kc.grn  {--kc-c:var(--green)}
.kc.blu  {--kc-c:var(--blue)}
.kc.org  {--kc-c:var(--orange)}
.kc.pur  {--kc-c:var(--purple)}

/* HIGHLIGHT CARDS */
.hl-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem}
.hl{background:var(--card);border:1px solid var(--bdr);border-radius:14px;padding:1.1rem 1.4rem;box-shadow:var(--sh);display:flex;align-items:center;gap:1rem}
.hl-em{font-size:2.2rem;opacity:.9;flex-shrink:0}
.hl-lbl{font-size:.6rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:.25rem}
.hl-val{font-size:1.45rem;font-weight:800;color:var(--text)}
.hl-sub{font-size:.68rem;color:var(--muted2);margin-top:.15rem}

/* CHART CARDS */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;margin-bottom:1.1rem}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.1rem;margin-bottom:1.1rem}
.g21{display:grid;grid-template-columns:2fr 1fr;gap:1.1rem;margin-bottom:1.1rem}
.cc{background:var(--card);border:1px solid var(--bdr);border-radius:14px;padding:1.4rem 1.5rem;box-shadow:var(--sh)}
.cc-t{font-size:.82rem;font-weight:700;color:var(--text);margin-bottom:.15rem}
.cc-s{font-size:.68rem;color:var(--muted2);margin-bottom:1rem}
.cc canvas{display:block;width:100%!important}
.cc.tall canvas{max-height:290px}
.cc.mid  canvas{max-height:220px}
.cc.short canvas{max-height:175px}
.mb2{margin-bottom:2rem}

/* INSIGHT TILES */
.ins{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem}
.ic{background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:1rem 1.15rem;box-shadow:var(--sh);display:flex;align-items:flex-start;gap:.85rem}
.ic-dot{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0}
.ic-dot.red  {background:var(--red-lt);color:var(--red)}
.ic-dot.gold {background:var(--gold-lt);color:var(--gold)}
.ic-dot.grn  {background:var(--green-lt);color:var(--green)}
.ic-dot.blu  {background:var(--blue-lt);color:var(--blue)}
.ic-dot.org  {background:var(--orange-lt);color:var(--orange)}
.ic-dot.pur  {background:var(--purple-lt);color:var(--purple)}
.ic-lbl{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:.2rem}
.ic-val{font-size:1.05rem;font-weight:700;color:var(--text)}
.ic-hint{font-size:.67rem;color:var(--muted2);margin-top:.1rem}

/* ACTIVITY FEED */
.act-list{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.ai{display:flex;align-items:flex-start;gap:.85rem;padding:.8rem 1rem;background:#fafbfd;border:1px solid var(--bdr);border-radius:10px}
.ai-ico{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;flex-shrink:0;letter-spacing:.02em}
.ai-ico.reg{background:var(--red-mid);color:var(--red)}
.ai-ico.don{background:#fef9c3;color:#854d0e}
.ai-ico.msg{background:var(--blue-lt);color:var(--blue)}
.ai-ico.vol{background:var(--green-lt);color:var(--green)}
.ai-b{flex:1;min-width:0}
.ai-name{font-weight:600;font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ai-det{font-size:.72rem;color:var(--muted);margin-top:.1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ai-r{text-align:right;flex-shrink:0}
.ai-time{font-size:.63rem;color:var(--muted2);white-space:nowrap}

/* TABLES */
.tabs-nav{display:flex;border-bottom:1px solid var(--bdr);margin-bottom:1.1rem;gap:.1rem}
.tb{background:none;border:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;color:var(--muted);font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:.7rem 1.4rem;cursor:pointer;transition:all .2s;font-family:var(--f)}
.tb:hover{color:var(--text)}
.tb.on{color:var(--red);border-bottom-color:var(--red)}
.tp{display:none}.tp.on{display:block}
.tt{display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem;flex-wrap:wrap;gap:.6rem}
.ttl{display:flex;align-items:baseline;gap:.75rem}
.tt-title{font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.tt-cnt{font-size:.68rem;color:var(--muted2)}
.tt-r{display:flex;align-items:center;gap:.5rem}
.srch{background:#f9fafb;border:1px solid var(--bdr);border-radius:7px;padding:.38rem .8rem;color:var(--text);font-size:.8rem;outline:none;width:190px;transition:border-color .2s;font-family:var(--f)}
.srch:focus{border-color:var(--red);background:#fff}
.status-filter-sel{background:#f9fafb;border:1px solid var(--bdr);border-radius:7px;padding:.36rem .6rem;color:var(--text);font-size:.75rem;outline:none;font-family:var(--f);cursor:pointer}
.status-filter-sel:focus{border-color:var(--red);background:#fff}
.csv-btn{background:var(--green);color:#fff;border:none;border-radius:7px;padding:.38rem .9rem;font-size:.7rem;font-weight:700;cursor:pointer;letter-spacing:.04em;text-decoration:none;white-space:nowrap;font-family:var(--f)}
.csv-btn:hover{opacity:.85}
.del-btn{background:var(--red-lt);color:var(--red);border:1px solid var(--red-mid);border-radius:6px;padding:.3rem .5rem;font-size:.8rem;cursor:pointer;line-height:1}
.del-btn:hover{background:var(--red);color:#fff}
.paid-btn{background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:6px;padding:.15rem .4rem;font-size:.62rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;cursor:pointer;line-height:1.6;margin-left:.4rem}
.paid-btn:hover{background:#166534;color:#fff}
.tw{overflow-x:auto;overflow-y:auto;max-height:600px;border:1px solid var(--bdr);border-radius:12px;background:var(--card);box-shadow:var(--sh)}
table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:.8rem}
thead th{background:#f8f9fc;color:var(--muted);font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:.75rem 1rem;text-align:left;cursor:pointer;user-select:none;border-bottom:1px solid var(--bdr);position:sticky;top:0;z-index:2}
thead th:hover{color:var(--text)}
thead th::after{content:' ⇅';opacity:.25;font-size:.55rem}
thead th.sa::after{content:' ↑';opacity:1}thead th.sd::after{content:' ↓';opacity:1}
tbody tr{border-bottom:1px solid rgba(0,0,0,.04)}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#fafbfd}
/* Same light green as the PAID status badge (.bdg-paid) - real, completed transactions
   stand out at a glance without having to read the Status column. */
tbody tr.row-paid{background:#dcfce7}
tbody tr.row-paid:hover{background:#c3edc9}
td{padding:.6rem 1rem;vertical-align:top;max-width:220px;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
td.nw{white-space:nowrap}td.msg{white-space:normal;max-width:300px;color:var(--muted);font-size:.75rem;line-height:1.55}td.mono{font-family:monospace;font-size:.72rem;color:var(--muted)}
.empty td{text-align:center;color:var(--muted2);padding:3rem;font-style:italic}
.badge{display:inline-block;font-size:.58rem;font-weight:700;letter-spacing:.06em;padding:.2rem .5rem;border-radius:4px;text-transform:uppercase}
.bdg-paid{background:#dcfce7;color:#166534}.bdg-pend{background:#fef9c3;color:#854d0e}.bdg-other{background:#f3f4f6;color:#6b7280}
.yes{color:var(--green);font-weight:700}.amt{font-weight:600}

@media(max-width:1200px){.kpi{grid-template-columns:repeat(3,1fr)}.g3{grid-template-columns:1fr 1fr}.ins{grid-template-columns:1fr 1fr}.hl-row{grid-template-columns:1fr}.act-list{grid-template-columns:1fr}}
@media(max-width:800px){.kpi{grid-template-columns:1fr 1fr}.g2,.g21,.g3{grid-template-columns:1fr}.ins{grid-template-columns:1fr}}
@media(max-width:480px){.kpi{grid-template-columns:1fr}.main{padding:1rem 1rem 3rem}}
</style>
</head>
<body>

<?php if (!$auth): ?>
<div class="lw">
  <div class="lb">
    <div class="lb-lock">🔐</div>
    <div class="lb-logo">UTSOV</div>
    <div class="lb-sub">Admin Portal &mdash; Durga Puja 2026</div>
    <?php if ($loginErr): ?><div class="lb-err">&#9888; Incorrect email or password. Please try again.</div><?php endif; ?>
    <form method="POST" action="admin.php" autocomplete="off">
      <input type="email" name="email" placeholder="Email address" autofocus required>
      <input type="password" name="pwd" placeholder="Password" required>
      <button type="submit">Sign In &rarr;</button>
    </form>
  </div>
</div>
<?php else: ?>

<header class="hdr">
  <div class="hdr-logo">UTSOV <em>Admin Portal &bull; Durga Puja 2026</em></div>
  <div class="hdr-r">
    <span class="hdr-ts"><?php echo esc($curUser['name']); ?> &bull; <?php echo $curUser['role']==='superadmin'?'Super Admin':'Admin'; ?></span>
    <span class="hdr-ts"><?php echo date('D, M j Y \a\t g:ia T'); ?></span>
    <a class="btn-lo" href="admin.php?logout=1" onclick="return confirm('Sign out?')">Sign Out</a>
  </div>
</header>

<div class="main">

<!-- ═══ KPI CARDS ════════════════════════════════════════════════════════════ -->
<div class="kpi">
  <div class="kc red">
    <div class="kc-ico">🎭</div>
    <div class="kc-lbl">Registrations</div>
    <div class="kc-val"><?php echo count($regs); ?></div>
    <div class="kc-sub"><?php echo $paidRegs; ?> confirmed &middot; <?php echo (count($regs)-$paidRegs); ?> pending</div>
  </div>
  <div class="kc gold">
    <div class="kc-ico">💵</div>
    <div class="kc-lbl">Ticket Revenue</div>
    <div class="kc-val">$<?php echo number_format($totRev,0); ?></div>
    <div class="kc-sub"><?php echo $totPasses; ?> passes &middot; avg $<?php echo number_format($avgTicket,0); ?>/order</div>
  </div>
  <div class="kc grn">
    <div class="kc-ico">🎁</div>
    <div class="kc-lbl">Donations</div>
    <div class="kc-val">$<?php echo number_format($totDon,0); ?></div>
    <div class="kc-sub"><?php echo $paidDons; ?> received &middot; avg $<?php echo number_format($avgDon,0); ?></div>
  </div>
  <div class="kc blu">
    <div class="kc-ico">📊</div>
    <div class="kc-lbl">Conversion Rate</div>
    <div class="kc-val"><?php echo $convRate; ?>%</div>
    <div class="kc-sub"><?php echo $paidRegs; ?> of <?php echo count($regs); ?> completed payment</div>
  </div>
  <div class="kc org">
    <div class="kc-ico">✉️</div>
    <div class="kc-lbl">Contacts</div>
    <div class="kc-val"><?php echo count($conts); ?></div>
    <div class="kc-sub">Messages from community</div>
  </div>
  <div class="kc pur">
    <div class="kc-ico">🙋</div>
    <div class="kc-lbl">Volunteers</div>
    <div class="kc-val"><?php echo count($vols); ?></div>
    <div class="kc-sub"><?php echo $memberWanted; ?> want membership</div>
  </div>
</div>

<div id="tbl-anchor"></div>

<!-- ═══ YEAR HIGHLIGHTS ══════════════════════════════════════════════════════ -->
<?php if (count($regs)||count($dons)): ?>
<div class="mb2">
  <div class="sh"><div class="sh-line"></div><div class="sh-title">Year Highlights</div></div>
  <div class="hl-row">
    <div class="hl">
      <div class="hl-em">🏆</div>
      <div>
        <div class="hl-lbl">Most Active Year</div>
        <div class="hl-val"><?php echo $bestRegYear; ?></div>
        <div class="hl-sub"><?php echo $bestRegCount; ?> registration<?php echo $bestRegCount!=1?'s':''; ?> in that year</div>
      </div>
    </div>
    <div class="hl">
      <div class="hl-em">💰</div>
      <div>
        <div class="hl-lbl">Highest Revenue Year</div>
        <div class="hl-val"><?php echo $bestRevYear; ?></div>
        <div class="hl-sub">$<?php echo number_format($bestRevAmt,0); ?> in ticket sales</div>
      </div>
    </div>
    <div class="hl">
      <div class="hl-em">❤️</div>
      <div>
        <div class="hl-lbl">Best Donation Year</div>
        <div class="hl-val"><?php echo $bestDonYear; ?></div>
        <div class="hl-sub">$<?php echo number_format($bestDonAmt,0); ?> donated</div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ COMMUNITY GROWTH BY YEAR ════════════════════════════════════════════ -->
<div class="mb2">
  <div class="sh"><div class="sh-line"></div><div class="sh-title">Community Growth by Year</div></div>
  <div class="cc tall">
    <div class="cc-t">Annual Activity Overview</div>
    <div class="cc-s">Registrations &middot; Donations &middot; Contacts &middot; Volunteers &mdash; all data sources by year</div>
    <canvas id="cYearly"></canvas>
  </div>
</div>

<!-- ═══ FINANCIAL + STATUS ══════════════════════════════════════════════════ -->
<div class="mb2">
  <div class="sh"><div class="sh-line"></div><div class="sh-title">Financial Overview</div></div>
  <div class="g2">
    <div class="cc mid">
      <div class="cc-t">Revenue &amp; Donations by Year (USD)</div>
      <div class="cc-s">Ticket revenue vs total donations received per year</div>
      <canvas id="cFinancial"></canvas>
    </div>
    <div class="cc mid">
      <div class="cc-t">Registration Status Breakdown</div>
      <div class="cc-s">Payment confirmed vs pending vs other</div>
      <canvas id="cStatus"></canvas>
    </div>
  </div>
</div>

<!-- ═══ ATTENDEE INSIGHTS ════════════════════════════════════════════════════ -->
<div class="mb2">
  <div class="sh"><div class="sh-line"></div><div class="sh-title">Attendee Insights</div></div>
  <div class="g3">
    <div class="cc short">
      <div class="cc-t">Top Cities</div>
      <div class="cc-s">By number of registrations</div>
      <canvas id="cCities"></canvas>
    </div>
    <div class="cc short">
      <div class="cc-t">Food Preferences</div>
      <div class="cc-s">Selected by registrants</div>
      <canvas id="cFood"></canvas>
    </div>
    <div class="cc short">
      <div class="cc-t">Day Pass Popularity</div>
      <div class="cc-s">Which day combinations are most chosen</div>
      <canvas id="cDays"></canvas>
    </div>
  </div>
</div>

<!-- ═══ DISCOVERY + ATTENDEE BREAKDOWN ══════════════════════════════════════ -->
<div class="mb2">
  <div class="sh"><div class="sh-line"></div><div class="sh-title">Discovery &amp; Attendee Breakdown</div></div>
  <div class="g2">
    <div class="cc mid">
      <div class="cc-t">How Did You Hear About Us?</div>
      <div class="cc-s">Marketing channel attribution from registrations</div>
      <canvas id="cHeard"></canvas>
    </div>
    <div class="cc mid">
      <div class="cc-t">Attendee Age Groups</div>
      <div class="cc-s">Adults vs Kids 6-12 vs Under 6 (paid registrations)</div>
      <canvas id="cAttend"></canvas>
    </div>
  </div>
</div>

<!-- ═══ QUICK INSIGHTS ═══════════════════════════════════════════════════════ -->
<div class="mb2">
  <div class="sh"><div class="sh-line"></div><div class="sh-title">Quick Insights</div></div>
  <div class="ins">
    <div class="ic">
      <div class="ic-dot red">🎭</div>
      <div>
        <div class="ic-lbl">Avg Ticket Value</div>
        <div class="ic-val">$<?php echo number_format($avgTicket,2); ?></div>
        <div class="ic-hint">Per confirmed registration</div>
      </div>
    </div>
    <div class="ic">
      <div class="ic-dot gold">💰</div>
      <div>
        <div class="ic-lbl">Avg Donation</div>
        <div class="ic-val">$<?php echo number_format($avgDon,2); ?></div>
        <div class="ic-hint">Per confirmed donation</div>
      </div>
    </div>
    <div class="ic">
      <div class="ic-dot grn">👨‍👩‍👧</div>
      <div>
        <div class="ic-lbl">Total Attendees</div>
        <div class="ic-val"><?php echo $totPasses; ?> passes</div>
        <div class="ic-hint"><?php echo $totAdults; ?> adults &middot; <?php echo ($totKids6to12+$totKidsUnder6); ?> children</div>
      </div>
    </div>
    <div class="ic">
      <div class="ic-dot blu">📈</div>
      <div>
        <div class="ic-lbl">Combined Revenue</div>
        <div class="ic-val">$<?php echo number_format($totRev+$totDon,0); ?></div>
        <div class="ic-hint">Tickets + donations all-time</div>
      </div>
    </div>
    <div class="ic">
      <div class="ic-dot pur">🎓</div>
      <div>
        <div class="ic-lbl">Student Registrations</div>
        <div class="ic-val"><?php echo $studentCount; ?></div>
        <div class="ic-hint"><?php echo (count($regs)>0 ? round($studentCount/count($regs)*100,1) : 0); ?>% of all orders</div>
      </div>
    </div>
    <div class="ic">
      <div class="ic-dot org">🌍</div>
      <div>
        <div class="ic-lbl">Top City</div>
        <div class="ic-val"><?php $tc=array_keys($topCities); echo $tc ? esc($tc[0]) : '—'; ?></div>
        <div class="ic-hint"><?php $tv=array_values($topCities); echo $tv ? $tv[0].' registration'.($tv[0]!=1?'s':'') : 'No data yet'; ?></div>
      </div>
    </div>
    <div class="ic">
      <div class="ic-dot grn">🍽️</div>
      <div>
        <div class="ic-lbl">Top Food Choice</div>
        <div class="ic-val"><?php $ff=array_keys($foodPrefs); echo $ff ? esc(isset($FOOD_SHORT_LABELS[$ff[0]]) ? $FOOD_SHORT_LABELS[$ff[0]] : $ff[0]) : '—'; ?></div>
        <div class="ic-hint"><?php $fv=array_values($foodPrefs); echo $fv ? $fv[0].' attendee'.($fv[0]!=1?'s':'') : 'No data yet'; ?></div>
      </div>
    </div>
    <div class="ic">
      <div class="ic-dot red">🤝</div>
      <div>
        <div class="ic-lbl">Community Reach</div>
        <div class="ic-val"><?php echo (count($conts)+count($vols)); ?></div>
        <div class="ic-hint">Messages + volunteers combined</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ RECENT ACTIVITY ══════════════════════════════════════════════════════ -->
<?php if ($recentAct): ?>
<div class="mb2">
  <div class="sh"><div class="sh-line"></div><div class="sh-title">Recent Activity</div></div>
  <div class="act-list">
  <?php
  $typeLabel = array('reg'=>'TKT','don'=>'DON','msg'=>'MSG','vol'=>'VOL');
  foreach ($recentAct as $a):
  ?>
    <div class="ai">
      <div class="ai-ico <?php echo esc($a['type']); ?>"><?php echo esc($typeLabel[$a['type']]); ?></div>
      <div class="ai-b">
        <div class="ai-name"><?php echo esc($a['name']); ?></div>
        <div class="ai-det"><?php echo esc($a['detail']); ?><?php if($a['status']) echo ' &nbsp;'.badge($a['status']); ?></div>
      </div>
      <div class="ai-r">
        <div class="ai-time"><?php echo $a['date'] ? fmtDate($a['date']) : ''; ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ DATA TABLES ══════════════════════════════════════════════════════════ -->
<div id="tables-blk">
  <div class="sh"><div class="sh-line"></div><div class="sh-title">All Records</div></div>
  <div class="tabs-nav">
    <button class="tb on" id="tab-regs"  onclick="tab('regs',this)">Registrations (<?php echo count($regs); ?>)</button>
    <button class="tb"    id="tab-dons"  onclick="tab('dons',this)">Donations (<?php echo count($dons); ?>)</button>
    <button class="tb"    id="tab-conts" onclick="tab('conts',this)">Contacts (<?php echo count($conts); ?>)</button>
    <button class="tb"    id="tab-vols"  onclick="tab('vols',this)">Volunteers (<?php echo count($vols); ?>)</button>
    <?php if (adm_is_super()): ?>
    <button class="tb"    id="tab-admins" onclick="tab('admins',this)">Manage Admins</button>
    <button class="tb"    id="tab-twilio" onclick="tab('twilio',this)">Twilio / SMS</button>
    <?php endif; ?>
  </div>

  <!-- REGISTRATIONS -->
  <div class="tp on" id="tp-regs">
    <div class="tt">
      <div class="ttl"><span class="tt-title">Ticket Registrations</span><span class="tt-cnt"><?php echo count($regs); ?> records &bull; $<?php echo number_format($totRev,2); ?> total &bull; <?php echo $totPasses; ?> passes</span></div>
      <div class="tt-r"><input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-regs',this.value)">
        <select class="status-filter-sel" id="regStatusSel" onchange="updExport('regStatusSel','regCsvBtn','registrations')" title="Filter CSV export by status">
          <option value="">All statuses</option>
          <?php foreach ($regStatusOptions as $s): ?>
          <option value="<?php echo esc($s); ?>"><?php echo esc(strtoupper($s)); ?></option>
          <?php endforeach; ?>
        </select>
        <a class="csv-btn" id="regCsvBtn" href="admin.php?export=registrations">&#11015; CSV</a>
      </div>
    </div>
    <div class="tw"><table id="tr-regs">
      <thead><tr>
        <th onclick="srt('tr-regs',0)">#</th><th onclick="srt('tr-regs',1)">Date</th><th onclick="srt('tr-regs',2)">Name</th><th onclick="srt('tr-regs',3)">Email</th><th onclick="srt('tr-regs',4)">Phone</th><th onclick="srt('tr-regs',5)">City</th><th onclick="srt('tr-regs',6)">Passes</th><th onclick="srt('tr-regs',7)">Adults</th><th onclick="srt('tr-regs',8)">Kids 6-12</th><th onclick="srt('tr-regs',9)">Under 6</th><th onclick="srt('tr-regs',10)">Total</th><th onclick="srt('tr-regs',11)">Days</th><th onclick="srt('tr-regs',12)">Food</th><th onclick="srt('tr-regs',13)">Student</th><th onclick="srt('tr-regs',14)">Heard From</th><th onclick="srt('tr-regs',15)">Status</th><th onclick="srt('tr-regs',16)">PayPal Order</th><th onclick="srt('tr-regs',17)">Capture ID</th><th onclick="srt('tr-regs',18)">Updated</th><th onclick="srt('tr-regs',19)">IP</th><?php if (adm_is_super()): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$regs): ?><tr class="empty"><td colspan="<?php echo adm_is_super()?21:20; ?>">No registrations yet.</td></tr>
      <?php else: foreach ($regs as $r): ?>
      <tr<?php echo $r['status']==='paid' ? ' class="row-paid"' : ''; ?>>
        <td class="nw"><?php echo esc($r['id']); ?></td>
        <td class="nw"><?php echo fmtDate($r['date']); ?></td>
        <td><?php echo esc($r['name']); ?></td>
        <td><?php echo esc($r['email']); ?></td>
        <td class="nw"><?php echo esc($r['phone']); ?></td>
        <td><?php echo esc($r['city']); ?></td>
        <td class="nw"><?php echo esc($r['qty']); ?></td>
        <td class="nw"><?php echo esc(isset($r['adults'])?$r['adults']:''); ?></td>
        <td class="nw"><?php echo esc(isset($r['kids_6to12'])?$r['kids_6to12']:0); ?></td>
        <td class="nw"><?php echo esc(isset($r['children'])?$r['children']:0); ?></td>
        <td class="nw amt">$<?php echo number_format((float)$r['total_amount'],2); ?></td>
        <td class="nw"><?php echo esc($r['days_selection']); ?></td>
        <td class="nw"><?php echo foodPrefDisplay($r['food_pref']); ?></td>
        <td class="nw"><?php echo !empty($r['is_student'])?'<span class="yes">✓</span>':''; ?></td>
        <td><?php echo esc($r['heard_from']); ?></td>
        <td class="nw">
          <?php echo badge($r['status']); ?>
          <?php if ($r['status'] !== 'paid' && $r['status'] !== 'refunded'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Mark registration #<?php echo esc($r['id']); ?> (<?php echo esc($r['name']); ?>) as paid?');">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="reg_id" value="<?php echo esc($r['id']); ?>">
            <button type="submit" class="paid-btn" title="Mark as paid">Mark Paid</button>
          </form>
          <?php endif; ?>
        </td>
        <td class="mono"><?php echo esc($r['paypal_order_id']); ?></td>
        <td class="mono"><?php echo esc($r['paypal_capture_id']); ?></td>
        <td class="nw"><?php echo fmtDate($r['updated_date']); ?></td>
        <td class="mono"><?php echo esc($r['ipaddress']); ?></td>
        <?php if (adm_is_super()): ?>
        <td class="nw">
          <form method="post" style="display:inline" onsubmit="return confirm('Delete registration #<?php echo esc($r['id']); ?> (<?php echo esc($r['name']); ?>)? This cannot be undone.');">
            <input type="hidden" name="action" value="delete_reg">
            <input type="hidden" name="reg_id" value="<?php echo esc($r['id']); ?>">
            <button type="submit" class="del-btn" title="Delete registration">&#128465;</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- DONATIONS -->
  <div class="tp" id="tp-dons">
    <div class="tt">
      <div class="ttl"><span class="tt-title">Donations</span><span class="tt-cnt"><?php echo count($dons); ?> records &bull; $<?php echo number_format($totDon,2); ?> total &bull; <?php echo $paidDons; ?> confirmed</span></div>
      <div class="tt-r"><input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-dons',this.value)">
        <select class="status-filter-sel" id="donStatusSel" onchange="updExport('donStatusSel','donCsvBtn','donations')" title="Filter CSV export by status">
          <option value="">All statuses</option>
          <?php foreach ($donStatusOptions as $s): ?>
          <option value="<?php echo esc($s); ?>"><?php echo esc(strtoupper($s)); ?></option>
          <?php endforeach; ?>
        </select>
        <a class="csv-btn" id="donCsvBtn" href="admin.php?export=donations">&#11015; CSV</a>
      </div>
    </div>
    <div class="tw"><table id="tr-dons">
      <thead><tr><th onclick="srt('tr-dons',0)">#</th><th onclick="srt('tr-dons',1)">Date</th><th onclick="srt('tr-dons',2)">Name</th><th onclick="srt('tr-dons',3)">Email</th><th onclick="srt('tr-dons',4)">Amount</th><th onclick="srt('tr-dons',5)">Status</th><th onclick="srt('tr-dons',6)">PayPal Order</th><th onclick="srt('tr-dons',7)">Capture ID</th><th onclick="srt('tr-dons',8)">Updated</th><th onclick="srt('tr-dons',9)">IP</th></tr></thead>
      <tbody>
      <?php if (!$dons): ?><tr class="empty"><td colspan="10">No donations yet.</td></tr>
      <?php else: foreach ($dons as $d): ?>
      <tr>
        <td class="nw"><?php echo esc($d['id']); ?></td><td class="nw"><?php echo fmtDate($d['date']); ?></td><td><?php echo esc($d['name']); ?></td><td><?php echo esc($d['email']); ?></td>
        <td class="nw amt">$<?php echo number_format((float)$d['amount'],2); ?></td>
        <td class="nw"><?php echo badge($d['status']); ?></td>
        <td class="mono"><?php echo esc($d['paypal_order_id']); ?></td><td class="mono"><?php echo esc($d['paypal_capture_id']); ?></td>
        <td class="nw"><?php echo fmtDate($d['updated_date']); ?></td><td class="mono"><?php echo esc($d['ipaddress']); ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- CONTACTS -->
  <div class="tp" id="tp-conts">
    <div class="tt">
      <div class="ttl"><span class="tt-title">Contact Messages</span><span class="tt-cnt"><?php echo count($conts); ?> messages</span></div>
      <div class="tt-r"><input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-conts',this.value)"><a class="csv-btn" href="admin.php?export=contacts">&#11015; CSV</a></div>
    </div>
    <div class="tw"><table id="tr-conts">
      <thead><tr><th onclick="srt('tr-conts',0)">#</th><th onclick="srt('tr-conts',1)">Date</th><th onclick="srt('tr-conts',2)">Name</th><th onclick="srt('tr-conts',3)">Email</th><th onclick="srt('tr-conts',4)">Phone</th><th onclick="srt('tr-conts',5)">Topic</th><th onclick="srt('tr-conts',6)">Message</th><th onclick="srt('tr-conts',7)">IP</th></tr></thead>
      <tbody>
      <?php if (!$conts): ?><tr class="empty"><td colspan="8">No contact messages yet.</td></tr>
      <?php else: foreach ($conts as $c): ?>
      <tr><td class="nw"><?php echo esc($c['id']); ?></td><td class="nw"><?php echo fmtDate($c['date']); ?></td><td><?php echo esc($c['name']); ?></td><td><?php echo esc($c['email']); ?></td><td class="nw"><?php echo esc($c['phone']); ?></td><td class="nw"><?php echo esc($c['topic']); ?></td><td class="msg"><?php echo esc($c['message']); ?></td><td class="mono"><?php echo esc($c['ipaddress']); ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- VOLUNTEERS -->
  <div class="tp" id="tp-vols">
    <div class="tt">
      <div class="ttl"><span class="tt-title">Volunteer Sign-Ups</span><span class="tt-cnt"><?php echo count($vols); ?> sign-ups &bull; <?php echo $memberWanted; ?> want membership</span></div>
      <div class="tt-r"><input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-vols',this.value)"><a class="csv-btn" href="admin.php?export=volunteers">&#11015; CSV</a></div>
    </div>
    <div class="tw"><table id="tr-vols">
      <thead><tr><th onclick="srt('tr-vols',0)">#</th><th onclick="srt('tr-vols',1)">Date</th><th onclick="srt('tr-vols',2)">Name</th><th onclick="srt('tr-vols',3)">Email</th><th onclick="srt('tr-vols',4)">Phone</th><th onclick="srt('tr-vols',5)">City</th><th onclick="srt('tr-vols',6)">State</th><th onclick="srt('tr-vols',7)">ZIP</th><th onclick="srt('tr-vols',8)">Address</th><th onclick="srt('tr-vols',9)">Message</th><th onclick="srt('tr-vols',10)">Membership?</th><th onclick="srt('tr-vols',11)">IP</th></tr></thead>
      <tbody>
      <?php if (!$vols): ?><tr class="empty"><td colspan="12">No volunteer sign-ups yet.</td></tr>
      <?php else: foreach ($vols as $v): ?>
      <tr>
        <td class="nw"><?php echo esc($v['id']); ?></td><td class="nw"><?php echo fmtDate($v['date']); ?></td><td><?php echo esc($v['name']); ?></td><td><?php echo esc($v['email']); ?></td><td class="nw"><?php echo esc($v['phone']); ?></td>
        <td class="nw"><?php echo esc(isset($v['city'])?$v['city']:''); ?></td><td class="nw"><?php echo esc(isset($v['state'])?$v['state']:''); ?></td><td class="nw"><?php echo esc(isset($v['zip'])?$v['zip']:''); ?></td>
        <td><?php $a=trim((isset($v['address1'])?$v['address1']:'').' '.(isset($v['address2'])?$v['address2']:'')); echo esc($a); ?></td>
        <td class="msg"><?php echo esc($v['message']); ?></td>
        <td class="nw"><?php echo !empty($v['memberrequest'])?'<span class="yes">✓ Yes</span>':'No'; ?></td>
        <td class="mono"><?php echo esc($v['ipaddress']); ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
  <?php if (adm_is_super()): ?>
  <!-- MANAGE ADMINS -->
  <div class="tp" id="tp-admins">
    <div class="tt"><div class="ttl"><span class="tt-title">Manage Admin Users</span><span class="tt-cnt">Super Admin only</span></div></div>
    <?php if ($resetOk): ?><div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;color:#065f46;font-size:.82rem;">&#10003; <?php echo esc($resetOk); ?></div><?php endif; ?>
    <?php if ($resetErr): ?><div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;color:#dc2626;font-size:.82rem;">&#9888; <?php echo esc($resetErr); ?></div><?php endif; ?>
    <div class="tw"><table id="tr-admins">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last Login</th><th>Action</th></tr></thead>
      <tbody>
      <?php
      try {
        $admList = adm_db()->query("SELECT id,name,email,role,last_login FROM tb_admin_users ORDER BY role DESC,name ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($admList as $au):
      ?>
      <tr>
        <td><?php echo esc($au['name']); ?></td>
        <td><?php echo esc($au['email']); ?></td>
        <td><?php echo $au['role']==='superadmin' ? '<span class="badge" style="background:#fef3c7;color:#92400e;">SUPER ADMIN</span>' : '<span class="badge bdg-paid">ADMIN</span>'; ?></td>
        <td style="color:var(--muted);font-size:.75rem;"><?php echo $au['last_login'] ? esc($au['last_login']) : '&mdash;'; ?></td>
        <td>
          <?php if ($au['email'] !== (isset($curUser['email']) ? $curUser['email'] : '')): ?>
          <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('Reset password for <?php echo esc(addslashes($au['name'])); ?> and email them new credentials?')">
            <input type="hidden" name="action" value="reset_pwd">
            <input type="hidden" name="target_email" value="<?php echo esc($au['email']); ?>">
            <button type="submit" style="background:#dc2626;color:#fff;border:none;border-radius:6px;padding:.28rem .75rem;font-size:.7rem;font-weight:700;cursor:pointer;font-family:var(--f)">Reset &amp; Email</button>
          </form>
          <?php else: ?><span style="color:var(--muted2);font-size:.72rem;">Your account</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; } catch(\Exception $e){ echo '<tr class="empty"><td colspan="5">'.esc($e->getMessage()).'</td></tr>'; } ?>
      </tbody>
    </table></div>
    <p style="margin-top:.9rem;font-size:.72rem;color:var(--muted2);">Only super admins can reset passwords. A randomised password will be emailed to the user automatically. There is no self-service password reset — users must contact super admin.</p>
  </div>

  <!-- TWILIO / SMS -->
  <div class="tp" id="tp-twilio">
    <div class="tt"><div class="ttl"><span class="tt-title">Twilio SMS Usage &amp; Billing</span><span class="tt-cnt">Super Admin only &bull; live from Twilio's API</span></div></div>
    <?php if (!$twilioReady): ?>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#dc2626;font-size:.82rem;">&#9888; Twilio reporting isn't configured on this server (api/twilio-config.php / api/twilio-stats.php missing or unreadable).</div>
    <?php elseif (!$twilioBalance && !$twilioStats['ok']): ?>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#dc2626;font-size:.82rem;">&#9888; Couldn't reach the Twilio API right now (network issue or bad credentials). Numbers below may be blank — try refreshing the page.</div>
    <?php endif; ?>
    <div class="kpi" style="grid-template-columns:repeat(4,1fr);margin:1rem 0 1.5rem">
      <div class="kc blu">
        <div class="kc-ico">💳</div>
        <div class="kc-lbl">Account Balance</div>
        <div class="kc-val"><?php echo $twilioBalance ? '$'.number_format($twilioBalance['balance'],2) : '—'; ?></div>
        <div class="kc-sub"><?php echo $twilioBalance ? esc($twilioBalance['currency']).' remaining' : 'Unavailable'; ?></div>
      </div>
      <div class="kc grn">
        <div class="kc-ico">📤</div>
        <div class="kc-lbl">Sent Today</div>
        <div class="kc-val"><?php echo $twilioStats['ok'] ? $twilioStats['today']['count'] : '—'; ?></div>
        <div class="kc-sub"><?php echo $twilioStats['ok'] ? '$'.number_format($twilioStats['today']['cost'],4).($twilioStats['today']['pending'] ? ' + '.$twilioStats['today']['pending'].' pending' : '') : 'Unavailable'; ?></div>
      </div>
      <div class="kc gold">
        <div class="kc-ico">📅</div>
        <div class="kc-lbl">Sent This Month</div>
        <div class="kc-val"><?php echo $twilioStats['ok'] ? $twilioStats['thisMonth']['count'] : '—'; ?></div>
        <div class="kc-sub"><?php echo $twilioStats['ok'] ? '$'.number_format($twilioStats['thisMonth']['cost'],2).($twilioStats['thisMonth']['pending'] ? ' + '.$twilioStats['thisMonth']['pending'].' pending' : '') : 'Unavailable'; ?></div>
      </div>
      <div class="kc pur">
        <div class="kc-ico">📈</div>
        <div class="kc-lbl">All-Time Total</div>
        <div class="kc-val"><?php echo $twilioStats['ok'] ? $twilioStats['allTime']['count'] : '—'; ?></div>
        <div class="kc-sub"><?php echo $twilioStats['ok'] ? '$'.number_format($twilioStats['allTime']['cost'],2).' total'.($twilioStats['allTime']['pending'] ? ' + '.$twilioStats['allTime']['pending'].' pending' : '').(count($twilioStats['messages'])>=1000?' (last 1000)':'') : 'Unavailable'; ?></div>
      </div>
    </div>
    <div class="tw"><table id="tr-twilio">
      <thead><tr><th>Date</th><th>To</th><th>Status</th><th>Price</th><th>Error</th><th>Message</th></tr></thead>
      <tbody>
      <?php if (!$twilioStats['messages']): ?><tr class="empty"><td colspan="6">No recent messages found (or Twilio API unavailable).</td></tr>
      <?php else: foreach (array_slice($twilioStats['messages'],0,25) as $m): ?>
      <tr>
        <td class="nw"><?php echo isset($m['date_sent']) && $m['date_sent'] ? esc(date('M j, Y g:ia', strtotime($m['date_sent']))) : (isset($m['date_created']) ? esc(date('M j, Y g:ia', strtotime($m['date_created']))) : '—'); ?></td>
        <td class="mono"><?php echo esc(isset($m['to']) ? $m['to'] : ''); ?></td>
        <td class="nw"><?php
          $mst = isset($m['status']) ? $m['status'] : '';
          $ok  = in_array($mst, array('delivered','sent'));
          echo '<span class="badge '.($ok?'bdg-paid':'bdg-other').'">'.esc(strtoupper($mst)).'</span>';
        ?></td>
        <td class="nw"><?php echo isset($m['price']) && $m['price'] !== null ? '$'.number_format(abs((float)$m['price']),4) : 'Pending'; ?></td>
        <td style="color:#dc2626;font-size:.72rem;"><?php echo !empty($m['error_message']) ? esc($m['error_message']) : ''; ?></td>
        <td><?php echo esc(isset($m['body']) ? mb_substr($m['body'],0,80) : ''); ?></td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
    <p style="margin-top:.9rem;font-size:.72rem;color:var(--muted2);">Pulled live from Twilio's Balance/Usage/Messages APIs on every page load - not stored locally. Only counts messages actually attempted via this Twilio account (see the SMS opt-in checkbox at checkout).</p>
  </div>
  <?php endif; ?>

</div><!-- /tables -->

</div><!-- /main -->

<script>
// Move tables block to sit directly below the KPI cards
(function(){
  var blk = document.getElementById('tables-blk');
  var anchor = document.getElementById('tbl-anchor');
  if (blk && anchor) anchor.parentNode.insertBefore(blk, anchor.nextSibling);
})();

// ─── Tab switcher ──────────────────────────────────────────────────────────
function tab(n,b){
  document.querySelectorAll('.tp').forEach(function(p){p.classList.remove('on');});
  document.querySelectorAll('.tb').forEach(function(x){x.classList.remove('on');});
  document.getElementById('tp-'+n).classList.add('on'); b.classList.add('on');
}
// Auto-activate tab from ?tab= query param (e.g. after password reset redirect)
(function(){
  var m = location.search.match(/[?&]tab=([^&]+)/);
  if (m) { var btn = document.getElementById('tab-'+m[1]); if (btn) btn.click(); }
})();
// ─── Table filter ──────────────────────────────────────────────────────────
function filt(id,q){
  q=q.toLowerCase();
  var rows=document.getElementById(id).tBodies[0].rows;
  for(var i=0;i<rows.length;i++) rows[i].style.display=(q===''||rows[i].textContent.toLowerCase().indexOf(q)!==-1)?'':'none';
}
// ─── CSV export status filter ─────────────────────────────────────────────
// Keeps the CSV link's href in sync with the status dropdown - "All statuses" exports
// everything (no status param), any specific choice exports only matching rows.
function updExport(selId,linkId,target){
  var v=document.getElementById(selId).value;
  document.getElementById(linkId).href='admin.php?export='+target+(v?'&status='+encodeURIComponent(v):'');
}
// ─── Table sort ────────────────────────────────────────────────────────────
function srt(id,col){
  var tbl=document.getElementById(id),tb=tbl.tBodies[0],rows=Array.prototype.slice.call(tb.rows);
  var th=tbl.tHead.rows[0].cells[col],asc=th.classList.contains('sa');
  tbl.tHead.rows[0].querySelectorAll('th').forEach(function(t){t.classList.remove('sa','sd');});
  th.classList.add(asc?'sd':'sa');
  rows.sort(function(a,b){
    var av=a.cells[col]?a.cells[col].textContent.trim():'',bv=b.cells[col]?b.cells[col].textContent.trim():'';
    var an=parseFloat(av.replace(/[$,]/g,'')),bn=parseFloat(bv.replace(/[$,]/g,''));
    return (asc?-1:1)*((!isNaN(an)&&!isNaN(bn))?(an-bn):av.localeCompare(bv));
  });
  rows.forEach(function(r){tb.appendChild(r);});
}

// ─── Chart.js ──────────────────────────────────────────────────────────────
Chart.defaults.font.family = "'Segoe UI',system-ui,sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#6b7280';

var CR='#8B1A1A',CG='#C9A84C',CB='#1d4ed8',CV='#059669',CO='#d97706',CP='#7c3aed',CT='#0891b2';
var COLORS=[CR,CG,CB,CV,CO,CP,CT,'#64748b','#ec4899','#14b8a6'];
function hex(c,a){ return c+a; }

// PHP-injected data
var years    = <?php echo $jsYears; ?>;
var regsData = <?php echo $jsRegsCount; ?>;
var revData  = <?php echo $jsRegRevenue; ?>;
var donsData = <?php echo $jsDonsCount; ?>;
var donTot   = <?php echo $jsDonTotal; ?>;
var contsData= <?php echo $jsContsCount; ?>;
var volsData = <?php echo $jsVolsCount; ?>;
var cityL    = <?php echo $jsTopCityL; ?>;
var cityD    = <?php echo $jsTopCityD; ?>;
var foodL    = <?php echo $jsFoodL; ?>;
var foodD    = <?php echo $jsFoodD; ?>;
var daysL    = <?php echo $jsDaysL; ?>;
var daysD    = <?php echo $jsDaysD; ?>;
var heardL   = <?php echo $jsHeardL; ?>;
var heardD   = <?php echo $jsHeardD; ?>;
var statusD  = <?php echo $jsStatusD; ?>;
var attendD  = <?php echo $jsAttendeeD; ?>;

var opts = { responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'top', labels:{ boxWidth:12, padding:14 } } }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 }, grid:{ color:'rgba(0,0,0,.05)' } }, x:{ grid:{ display:false } } } };
function noGrid(o){ var c=JSON.parse(JSON.stringify(o)); if(c.scales&&c.scales.y)c.scales.y.grid={display:false}; return c; }

// 1. Annual Overview
new Chart(document.getElementById('cYearly'), {
  type:'bar',
  data:{ labels:years, datasets:[
    {label:'Registrations', data:regsData, backgroundColor:hex(CR,'bb'), borderColor:CR, borderWidth:1.5},
    {label:'Donations',     data:donsData, backgroundColor:hex(CG,'bb'), borderColor:CG, borderWidth:1.5},
    {label:'Contacts',      data:contsData, backgroundColor:hex(CB,'bb'), borderColor:CB, borderWidth:1.5},
    {label:'Volunteers',    data:volsData, backgroundColor:hex(CV,'bb'), borderColor:CV, borderWidth:1.5},
  ]},
  options:Object.assign({},opts,{ plugins:Object.assign({},opts.plugins,{ tooltip:{ mode:'index', intersect:false } }) })
});

// 2. Financial
new Chart(document.getElementById('cFinancial'), {
  type:'bar',
  data:{ labels:years, datasets:[
    {label:'Ticket Revenue ($)', data:revData, backgroundColor:hex(CR,'99'), borderColor:CR, borderWidth:1.5},
    {label:'Donations ($)',      data:donTot,  backgroundColor:hex(CG,'99'), borderColor:CG, borderWidth:1.5},
  ]},
  options:{ responsive:true, maintainAspectRatio:true,
    plugins:{ legend:{position:'top',labels:{boxWidth:12,padding:14}}, tooltip:{ callbacks:{ label:function(c){return c.dataset.label+': $'+c.raw.toLocaleString();} } } },
    scales:{ y:{ beginAtZero:true, ticks:{ callback:function(v){return '$'+v.toLocaleString();} }, grid:{color:'rgba(0,0,0,.05)'} }, x:{grid:{display:false}} }
  }
});

// 3. Status Doughnut
new Chart(document.getElementById('cStatus'), {
  type:'doughnut',
  data:{ labels:['Paid','Pending','Other'], datasets:[{ data:statusD, backgroundColor:[CV,CO,'#9ca3af'], borderWidth:3, borderColor:'#fff', hoverOffset:6 }] },
  options:{ responsive:true, cutout:'68%',
    plugins:{ legend:{position:'bottom',labels:{boxWidth:12,padding:16}},
      tooltip:{ callbacks:{ label:function(c){ var t=c.dataset.data.reduce(function(a,b){return a+b;},0); return c.label+': '+c.raw+(t?' ('+Math.round(c.raw/t*100)+'%)':''); } } }
    }
  }
});

// 4. Cities (horizontal bar)
if (cityL.length) new Chart(document.getElementById('cCities'), {
  type:'bar',
  data:{ labels:cityL, datasets:[{label:'Registrations', data:cityD, backgroundColor:cityL.map(function(_,i){return hex(COLORS[i%COLORS.length],'cc');}), borderWidth:0}] },
  options:{ indexAxis:'y', responsive:true, maintainAspectRatio:true,
    plugins:{ legend:{display:false} },
    scales:{ x:{beginAtZero:true, ticks:{precision:0}, grid:{color:'rgba(0,0,0,.05)'}}, y:{grid:{display:false}} }
  }
});

// 5. Food Doughnut
if (foodL.length) new Chart(document.getElementById('cFood'), {
  type:'doughnut',
  data:{ labels:foodL, datasets:[{ data:foodD, backgroundColor:COLORS.slice(0,foodL.length).map(function(c){return hex(c,'cc');}), borderWidth:3, borderColor:'#fff', hoverOffset:6 }] },
  options:{ responsive:true, cutout:'60%', plugins:{ legend:{position:'bottom',labels:{boxWidth:10,padding:12}} } }
});

// 6. Days (horizontal bar)
if (daysL.length) new Chart(document.getElementById('cDays'), {
  type:'bar',
  data:{ labels:daysL, datasets:[{label:'Orders', data:daysD, backgroundColor:hex(CP,'cc'), borderColor:CP, borderWidth:1}] },
  options:{ indexAxis:'y', responsive:true, plugins:{ legend:{display:false} }, scales:{ x:{beginAtZero:true,ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}}, y:{grid:{display:false}} } }
});

// 7. Heard From Pie
if (heardL.length) new Chart(document.getElementById('cHeard'), {
  type:'pie',
  data:{ labels:heardL, datasets:[{ data:heardD, backgroundColor:COLORS.slice(0,heardL.length).map(function(c){return hex(c,'dd');}), borderWidth:3, borderColor:'#fff', hoverOffset:6 }] },
  options:{ responsive:true, plugins:{ legend:{position:'bottom',labels:{boxWidth:10,padding:12}} } }
});

// 8. Attendee Breakdown
new Chart(document.getElementById('cAttend'), {
  type:'bar',
  data:{
    labels:['Adults','Kids (6-12)','Under 6'],
    datasets:[{ data:attendD, backgroundColor:[hex(CR,'cc'),hex(CG,'cc'),hex(CB,'cc')], borderColor:[CR,CG,CB], borderWidth:1.5, borderRadius:6 }]
  },
  options:{ responsive:true, plugins:{ legend:{display:false} }, scales:{ y:{beginAtZero:true,ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}}, x:{grid:{display:false}} } }
});
</script>
<?php endif; ?>
</body>
</html>
