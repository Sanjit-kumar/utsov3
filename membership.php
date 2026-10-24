<?php
// ─── UTSOV Membership & Network Data — consolidated view of the master
//     UtsovMemberAndFriends spreadsheet's 9 tabs, imported 2026-10-22.
//     Shares the admin.php login session (same constant/cookie), so anyone
//     already signed into the Admin Portal is signed in here too. ──────────────
define('ADMIN_SESSION', 'utsov_adm_26');
define('ADMIN_DB',      dirname(__FILE__) . '/api/db/admin.db');
session_start();

function adm_db() {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . ADMIN_DB);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}
function adm_logged_in() { return !empty($_SESSION[ADMIN_SESSION]['email']); }
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['logout'])) { session_destroy(); header('Location: membership.php'); exit; }
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
            header('Location: membership.php'); exit;
        }
    } catch(\Exception $e){}
    $loginErr = true;
}
$auth    = adm_logged_in();
$curUser = isset($_SESSION[ADMIN_SESSION]) ? $_SESSION[ADMIN_SESSION] : null;

// ─── Load data ─────────────────────────────────────────────────────────────
$members = $friends = $gen2 = $patrons = $recentLoyal = $known2025 = $vendors = $allNetwork = $sheet6 = array();
if ($auth) {
    try {
        $db = new PDO('sqlite:' . dirname(__FILE__) . '/api/db/members.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $members = $db->query("SELECT * FROM tb_members ORDER BY first_name COLLATE NOCASE ASC, last_name COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch(\Exception $e){}
    try {
        $db = new PDO('sqlite:' . dirname(__FILE__) . '/api/db/membership-data.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $friends     = $db->query("SELECT * FROM tb_friends ORDER BY first_name COLLATE NOCASE ASC, last_name COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
        $gen2        = $db->query("SELECT * FROM tb_gen2 ORDER BY first_name COLLATE NOCASE ASC, last_name COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
        $patrons     = $db->query("SELECT * FROM tb_patrons ORDER BY email COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
        $recentLoyal = $db->query("SELECT * FROM tb_recent_loyal ORDER BY email COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
        $known2025   = $db->query("SELECT * FROM tb_known_2025 ORDER BY email COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
        $vendors     = $db->query("SELECT * FROM tb_vendors ORDER BY value COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
        $allNetwork  = $db->query("SELECT * FROM tb_all_network ORDER BY email COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
        $sheet6      = $db->query("SELECT * FROM tb_sheet6_network ORDER BY email COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch(\Exception $e){}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Membership &amp; Network Data — UTSOV Admin</title>
<meta name="robots" content="noindex,nofollow">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#eef0f6;--card:#fff;--bdr:#e2e5f0;--bdr2:#d0d5e8;
  --text:#0f172a;--sub:#374151;--muted:#6b7280;--muted2:#9ca3af;
  --red:#8B1A1A;--red-dk:#5C1010;--red-lt:#fef2f2;--red-mid:#fee2e2;
  --gold:#B8860B;--gold-lt:#fdf7e0;
  --green:#059669;--green-lt:#ecfdf5;
  --blue:#1d4ed8;--blue-lt:#eff6ff;
  --sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
  --sh-md:0 4px 20px rgba(0,0,0,.1);
  --f:'Segoe UI',system-ui,sans-serif;
}
body{font-family:var(--f);background:var(--bg);color:var(--text);min-height:100vh;font-size:13.5px;line-height:1.5}

.lw{display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#fff5f5 0%,#fff8ec 50%,#f0f4ff 100%)}
.lb{background:var(--card);border:1px solid var(--bdr);border-radius:22px;padding:3rem 2.5rem;width:380px;text-align:center;box-shadow:var(--sh-md)}
.lb-logo{font-size:2.6rem;font-weight:800;color:var(--red);letter-spacing:.1em;margin-bottom:.15rem}
.lb-sub{color:var(--muted);font-size:.75rem;letter-spacing:.1em;text-transform:uppercase;margin-bottom:2.5rem}
.lb input[type=password],.lb input[type=email]{width:100%;background:#f9fafb;border:1.5px solid var(--bdr);border-radius:10px;padding:.85rem 1.1rem;color:var(--text);font-size:.9rem;outline:none;margin-bottom:1rem;font-family:var(--f)}
.lb input:focus{border-color:var(--red);background:#fff}
.lb button{width:100%;background:var(--red);color:#fff;border:none;border-radius:10px;padding:.9rem;font-size:.9rem;font-weight:700;cursor:pointer;letter-spacing:.05em;font-family:var(--f)}
.lb button:hover{background:var(--red-dk)}
.lb-err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#dc2626;font-size:.78rem;padding:.65rem .9rem;margin-bottom:1rem;text-align:left}
.lb-lock{font-size:2.5rem;margin-bottom:1rem}

.hdr{background:linear-gradient(90deg,#3d0808,var(--red));border-bottom:2px solid rgba(201,168,76,.5);padding:.9rem 2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;box-shadow:0 2px 16px rgba(0,0,0,.2);flex-wrap:wrap;gap:.6rem}
.hdr-logo{font-size:1.25rem;font-weight:800;color:#E8C97A;letter-spacing:.1em}
.hdr-logo em{font-style:normal;font-size:.65rem;font-weight:400;color:rgba(255,255,255,.35);margin-left:.7rem;letter-spacing:.12em;text-transform:uppercase}
.hdr-r{display:flex;align-items:center;gap:1.2rem}
.hdr-ts{font-size:.7rem;color:rgba(255,255,255,.65)}
.hdr-ts a{color:#E8C97A;text-decoration:none}
.hdr-ts a:hover{text-decoration:underline}
.btn-lo{font-size:.72rem;color:rgba(255,255,255,.65);background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:6px;padding:.35rem .9rem;cursor:pointer;text-decoration:none}
.btn-lo:hover{color:#fff;background:rgba(255,255,255,.2)}

.main{max-width:1600px;margin:0 auto;padding:1.6rem 1.75rem 5rem}
.page-note{background:var(--gold-lt);border:1px solid #f1d98a;border-radius:10px;padding:.8rem 1.1rem;font-size:.78rem;color:#6b5000;margin-bottom:1.3rem;line-height:1.6}

.tabs-nav{display:flex;flex-wrap:wrap;border-bottom:1px solid var(--bdr);margin-bottom:1.1rem;gap:.1rem}
.tb{background:none;border:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;color:var(--muted);font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:.65rem 1.1rem;cursor:pointer;font-family:var(--f)}
.tb:hover{color:var(--text)}
.tb.on{color:var(--red);border-bottom-color:var(--red)}
.tp{display:none}.tp.on{display:block}
.tt{display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem;flex-wrap:wrap;gap:.6rem}
.ttl{display:flex;align-items:baseline;gap:.75rem;flex-wrap:wrap}
.tt-title{font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.tt-cnt{font-size:.68rem;color:var(--muted2)}
.tt-desc{font-size:.72rem;color:var(--muted2);font-style:italic}
.srch{background:#f9fafb;border:1px solid var(--bdr);border-radius:7px;padding:.38rem .8rem;color:var(--text);font-size:.8rem;outline:none;width:220px;font-family:var(--f)}
.tw{overflow-x:auto;overflow-y:auto;max-height:600px;border:1px solid var(--bdr);border-radius:12px;background:var(--card);box-shadow:var(--sh)}
table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:.8rem}
thead th{background:#f8f9fc;color:var(--muted);font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:.75rem 1rem;text-align:left;cursor:pointer;user-select:none;border-bottom:1px solid var(--bdr);position:sticky;top:0;z-index:2}
thead th:hover{color:var(--text)}
thead th::after{content:' ⇅';opacity:.25;font-size:.55rem}
thead th.sa::after{content:' ↑';opacity:1}thead th.sd::after{content:' ↓';opacity:1}
tbody tr{border-bottom:1px solid rgba(0,0,0,.04)}
tbody tr:hover{background:#fafbfd}
td{padding:.55rem 1rem;vertical-align:top;max-width:260px;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
td.nw{white-space:nowrap}
.empty td{text-align:center;color:var(--muted2);padding:3rem;font-style:italic}
.tt-r{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.add-btn{background:var(--red);color:#fff;border:none;border-radius:7px;padding:.4rem .95rem;font-size:.7rem;font-weight:700;cursor:pointer;letter-spacing:.04em;font-family:var(--f);white-space:nowrap}
.add-btn:hover{background:var(--red-dk)}
.add-form{display:none;background:#fafbfd;border:1px solid var(--bdr);border-radius:10px;padding:.9rem 1rem;margin-bottom:.9rem;gap:.7rem;flex-wrap:wrap;align-items:flex-end}
.add-form.show{display:flex}
.add-form .af-field{display:flex;flex-direction:column;gap:.3rem;flex:1;min-width:150px}
.add-form label{font-size:.6rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--muted)}
.add-form input[type=text],.add-form input[type=email],.add-form input[type=date]{padding:.5rem .7rem;border:1.5px solid var(--bdr);border-radius:7px;font-size:.8rem;font-family:var(--f)}
.add-form .af-check{flex-direction:row;align-items:center;gap:.4rem;flex:0 0 auto}
.add-form .af-actions{display:flex;gap:.5rem;flex:0 0 auto}
.af-save{background:var(--green);color:#fff;border:none;border-radius:7px;padding:.55rem 1.1rem;font-size:.75rem;font-weight:700;cursor:pointer;font-family:var(--f)}
.af-save:disabled{opacity:.6;cursor:not-allowed}
.af-cancel{background:#fff;color:var(--muted);border:1px solid var(--bdr);border-radius:7px;padding:.55rem 1.1rem;font-size:.75rem;font-weight:700;cursor:pointer;font-family:var(--f)}
.af-err{color:#dc2626;font-size:.72rem;width:100%}
@media(max-width:600px){.main{padding:1rem}}
</style>
</head>
<body>

<?php if (!$auth): ?>
<div class="lw">
  <div class="lb">
    <div class="lb-lock">🔐</div>
    <div class="lb-logo">UTSOV</div>
    <div class="lb-sub">Membership &amp; Network Data</div>
    <?php if ($loginErr): ?><div class="lb-err">&#9888; Incorrect email or password. Please try again.</div><?php endif; ?>
    <form method="POST" action="membership.php" autocomplete="off">
      <input type="email" name="email" placeholder="Email address" autofocus required>
      <input type="password" name="pwd" placeholder="Password" required>
      <button type="submit">Sign In &rarr;</button>
    </form>
  </div>
</div>
<?php else: ?>

<header class="hdr">
  <div class="hdr-logo">UTSOV <em>Membership &amp; Network Data</em></div>
  <div class="hdr-r">
    <span class="hdr-ts"><a href="admin.php">&larr; Admin Portal</a></span>
    <span class="hdr-ts"><?php echo esc($curUser['name']); ?></span>
    <a class="btn-lo" href="membership.php?logout=1" onclick="return confirm('Sign out?')">Sign Out</a>
  </div>
</header>

<div class="main">

<div class="page-note">
  Everything from the <b>UtsovMemberAndFriends</b> master spreadsheet, imported 2026-10-22, in one place. The first 3 tabs have real name/contact details; the rest are email-only lists used for past mailings and mail-merge batches (the last two — <b>All-2017-2025</b> and <b>Sheet6</b> — are raw split-column mail-merge exports, flattened here into one deduplicated email list per sheet since the original column layout had no meaning beyond chunking recipients).
</div>

<div class="tabs-nav">
  <button class="tb on" id="tab-members" onclick="tab('members',this)">Members (<?php echo count($members); ?>)</button>
  <button class="tb" id="tab-friends" onclick="tab('friends',this)">Friends (<?php echo count($friends); ?>)</button>
  <button class="tb" id="tab-gen2" onclick="tab('gen2',this)">Gen2 (<?php echo count($gen2); ?>)</button>
  <button class="tb" id="tab-patrons" onclick="tab('patrons',this)">Repeating Patrons (<?php echo count($patrons); ?>)</button>
  <button class="tb" id="tab-recent" onclick="tab('recent',this)">Recent Loyal Combined (<?php echo count($recentLoyal); ?>)</button>
  <button class="tb" id="tab-known" onclick="tab('known',this)">2025 Known Network (<?php echo count($known2025); ?>)</button>
  <button class="tb" id="tab-vendors" onclick="tab('vendors',this)">Vendors (<?php echo count($vendors); ?>)</button>
  <button class="tb" id="tab-allnet" onclick="tab('allnet',this)">All-2017-2025 (<?php echo count($allNetwork); ?>)</button>
  <button class="tb" id="tab-sheet6" onclick="tab('sheet6',this)">Sheet6 (<?php echo count($sheet6); ?>)</button>
</div>

<!-- MEMBERS -->
<div class="tp on" id="tp-members">
  <div class="tt">
    <div class="ttl"><span class="tt-title">Members</span><span class="tt-cnt"><?php echo count($members); ?> records &bull; full details, also shown on admin.php</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('members')">&#43; Add Member</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-members',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-members">
    <div class="af-field"><label>First Name *</label><input type="text" data-key="firstName"></div>
    <div class="af-field"><label>Last Name</label><input type="text" data-key="lastName"></div>
    <div class="af-field"><label>Email</label><input type="email" data-key="email"></div>
    <div class="af-field"><label>Phone</label><input type="text" data-key="phone"></div>
    <div class="af-field"><label>Member Since</label><input type="date" data-key="memberSince"></div>
    <div class="af-field"><label>Remarks</label><input type="text" data-key="remarks"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('members','af-members','members')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('members')">Cancel</button>
    </div>
    <div class="af-err" id="af-members-err"></div>
  </div>
  <div class="tw"><table id="tr-members">
    <thead><tr><th onclick="srt('tr-members',0)">#</th><th onclick="srt('tr-members',1)">First Name</th><th onclick="srt('tr-members',2)">Last Name</th><th onclick="srt('tr-members',3)">Email</th><th onclick="srt('tr-members',4)">Phone</th><th onclick="srt('tr-members',5)">Member Since</th><th onclick="srt('tr-members',6)">Remarks</th></tr></thead>
    <tbody>
    <?php if (!$members): ?><tr class="empty"><td colspan="7">No members on file.</td></tr>
    <?php else: foreach ($members as $m): ?>
    <tr><td class="nw"><?php echo esc($m['id']); ?></td><td><?php echo esc($m['first_name']); ?></td><td><?php echo esc($m['last_name']); ?></td><td><?php echo esc($m['email']); ?></td><td class="nw"><?php echo esc($m['phone']); ?></td><td class="nw"><?php echo esc($m['member_since']); ?></td><td><?php echo esc($m['remarks']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- FRIENDS -->
<div class="tp" id="tp-friends">
  <div class="tt">
    <div class="ttl"><span class="tt-title">Friends</span><span class="tt-cnt"><?php echo count($friends); ?> records</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('friends')">&#43; Add Friend</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-friends',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-friends">
    <div class="af-field"><label>First Name *</label><input type="text" data-key="first_name"></div>
    <div class="af-field"><label>Last Name</label><input type="text" data-key="last_name"></div>
    <div class="af-field"><label>Email</label><input type="email" data-key="email"></div>
    <div class="af-field"><label>Phone</label><input type="text" data-key="phone"></div>
    <div class="af-field af-check"><label style="margin:0">Notice Board</label><input type="checkbox" data-key="notice_board" data-checkval="X"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('friends','af-friends','friends')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('friends')">Cancel</button>
    </div>
    <div class="af-err" id="af-friends-err"></div>
  </div>
  <div class="tw"><table id="tr-friends">
    <thead><tr><th onclick="srt('tr-friends',0)">#</th><th onclick="srt('tr-friends',1)">First Name</th><th onclick="srt('tr-friends',2)">Last Name</th><th onclick="srt('tr-friends',3)">Email</th><th onclick="srt('tr-friends',4)">Phone</th><th onclick="srt('tr-friends',5)">Notice Board</th></tr></thead>
    <tbody>
    <?php if (!$friends): ?><tr class="empty"><td colspan="6">No friends on file.</td></tr>
    <?php else: foreach ($friends as $r): ?>
    <tr><td class="nw"><?php echo esc($r['id']); ?></td><td><?php echo esc($r['first_name']); ?></td><td><?php echo esc($r['last_name']); ?></td><td><?php echo esc($r['email']); ?></td><td class="nw"><?php echo esc($r['phone']); ?></td><td class="nw"><?php echo esc($r['notice_board']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- GEN2 -->
<div class="tp" id="tp-gen2">
  <div class="tt">
    <div class="ttl"><span class="tt-title">Gen2</span><span class="tt-cnt"><?php echo count($gen2); ?> records &bull; next-gen members</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('gen2')">&#43; Add Gen2</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-gen2',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-gen2">
    <div class="af-field"><label>First Name *</label><input type="text" data-key="first_name"></div>
    <div class="af-field"><label>Last Name</label><input type="text" data-key="last_name"></div>
    <div class="af-field"><label>Email</label><input type="email" data-key="email"></div>
    <div class="af-field"><label>Phone</label><input type="text" data-key="phone"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('gen2','af-gen2','gen2')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('gen2')">Cancel</button>
    </div>
    <div class="af-err" id="af-gen2-err"></div>
  </div>
  <div class="tw"><table id="tr-gen2">
    <thead><tr><th onclick="srt('tr-gen2',0)">#</th><th onclick="srt('tr-gen2',1)">First Name</th><th onclick="srt('tr-gen2',2)">Last Name</th><th onclick="srt('tr-gen2',3)">Email</th><th onclick="srt('tr-gen2',4)">Phone</th></tr></thead>
    <tbody>
    <?php if (!$gen2): ?><tr class="empty"><td colspan="5">No Gen2 records on file.</td></tr>
    <?php else: foreach ($gen2 as $r): ?>
    <tr><td class="nw"><?php echo esc($r['id']); ?></td><td><?php echo esc($r['first_name']); ?></td><td><?php echo esc($r['last_name']); ?></td><td><?php echo esc($r['email']); ?></td><td class="nw"><?php echo esc($r['phone']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- REPEATING PATRONS -->
<div class="tp" id="tp-patrons">
  <div class="tt">
    <div class="ttl"><span class="tt-title">Repeating Patrons</span><span class="tt-cnt"><?php echo count($patrons); ?> emails &bull; years attended since 2021</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('patrons')">&#43; Add Patron</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-patrons',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-patrons">
    <div class="af-field"><label>Email *</label><input type="email" data-key="email"></div>
    <div class="af-field"><label>Years Attended</label><input type="text" data-key="years_attended" placeholder="e.g. 2022, 2023, 2024"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('patrons','af-patrons','patrons')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('patrons')">Cancel</button>
    </div>
    <div class="af-err" id="af-patrons-err"></div>
  </div>
  <div class="tw"><table id="tr-patrons">
    <thead><tr><th onclick="srt('tr-patrons',0)">#</th><th onclick="srt('tr-patrons',1)">Email</th><th onclick="srt('tr-patrons',2)">Years Attended</th></tr></thead>
    <tbody>
    <?php if (!$patrons): ?><tr class="empty"><td colspan="3">No patron records on file.</td></tr>
    <?php else: foreach ($patrons as $r): ?>
    <tr><td class="nw"><?php echo esc($r['id']); ?></td><td><?php echo esc($r['email']); ?></td><td><?php echo esc($r['years_attended']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- RECENT LOYAL COMBINED -->
<div class="tp" id="tp-recent">
  <div class="tt">
    <div class="ttl"><span class="tt-title">Recent Loyal — All Combined</span><span class="tt-cnt"><?php echo count($recentLoyal); ?> unique emails</span><span class="tt-desc">Union of members, friends, Gen2 &amp; repeating patrons through 2025</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('recent')">&#43; Add Email</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-recent',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-recent">
    <div class="af-field"><label>Email *</label><input type="email" data-key="email"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('recent_loyal','af-recent','recent')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('recent')">Cancel</button>
    </div>
    <div class="af-err" id="af-recent-err"></div>
  </div>
  <div class="tw"><table id="tr-recent">
    <thead><tr><th onclick="srt('tr-recent',0)">#</th><th onclick="srt('tr-recent',1)">Email</th></tr></thead>
    <tbody>
    <?php if (!$recentLoyal): ?><tr class="empty"><td colspan="2">No records on file.</td></tr>
    <?php else: foreach ($recentLoyal as $r): ?>
    <tr><td class="nw"><?php echo esc($r['id']); ?></td><td><?php echo esc($r['email']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- 2025 KNOWN NETWORK -->
<div class="tp" id="tp-known">
  <div class="tt">
    <div class="ttl"><span class="tt-title">2025 — Known Network</span><span class="tt-cnt"><?php echo count($known2025); ?> unique emails</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('known')">&#43; Add Email</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-known',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-known">
    <div class="af-field"><label>Email *</label><input type="email" data-key="email"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('known_2025','af-known','known')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('known')">Cancel</button>
    </div>
    <div class="af-err" id="af-known-err"></div>
  </div>
  <div class="tw"><table id="tr-known">
    <thead><tr><th onclick="srt('tr-known',0)">#</th><th onclick="srt('tr-known',1)">Email</th></tr></thead>
    <tbody>
    <?php if (!$known2025): ?><tr class="empty"><td colspan="2">No records on file.</td></tr>
    <?php else: foreach ($known2025 as $r): ?>
    <tr><td class="nw"><?php echo esc($r['id']); ?></td><td><?php echo esc($r['email']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- VENDORS -->
<div class="tp" id="tp-vendors">
  <div class="tt">
    <div class="ttl"><span class="tt-title">Vendors</span><span class="tt-cnt"><?php echo count($vendors); ?> records &bull; 2017–2024</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('vendors')">&#43; Add Vendor</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-vendors',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-vendors">
    <div class="af-field"><label>Email / Note *</label><input type="text" data-key="value"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('vendors','af-vendors','vendors')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('vendors')">Cancel</button>
    </div>
    <div class="af-err" id="af-vendors-err"></div>
  </div>
  <div class="tw"><table id="tr-vendors">
    <thead><tr><th onclick="srt('tr-vendors',0)">#</th><th onclick="srt('tr-vendors',1)">Email / Note</th></tr></thead>
    <tbody>
    <?php if (!$vendors): ?><tr class="empty"><td colspan="2">No vendor records on file.</td></tr>
    <?php else: foreach ($vendors as $r): ?>
    <tr><td class="nw"><?php echo esc($r['id']); ?></td><td><?php echo esc($r['value']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- ALL-2017-2025 -->
<div class="tp" id="tp-allnet">
  <div class="tt">
    <div class="ttl"><span class="tt-title">All-2017-2025</span><span class="tt-cnt"><?php echo count($allNetwork); ?> unique emails</span><span class="tt-desc">Flattened from a raw mail-merge split-column export — not individual member records</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('allnet')">&#43; Add Email</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-allnet',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-allnet">
    <div class="af-field"><label>Email *</label><input type="email" data-key="email"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('all_network','af-allnet','allnet')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('allnet')">Cancel</button>
    </div>
    <div class="af-err" id="af-allnet-err"></div>
  </div>
  <div class="tw"><table id="tr-allnet">
    <thead><tr><th onclick="srt('tr-allnet',0)">#</th><th onclick="srt('tr-allnet',1)">Email</th></tr></thead>
    <tbody>
    <?php if (!$allNetwork): ?><tr class="empty"><td colspan="2">No records on file.</td></tr>
    <?php else: foreach ($allNetwork as $r): ?>
    <tr><td class="nw"><?php echo esc($r['id']); ?></td><td><?php echo esc($r['email']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- SHEET6 -->
<div class="tp" id="tp-sheet6">
  <div class="tt">
    <div class="ttl"><span class="tt-title">Sheet6</span><span class="tt-cnt"><?php echo count($sheet6); ?> unique emails</span><span class="tt-desc">Flattened from a raw mail-merge split-column export — not individual member records</span></div>
    <div class="tt-r">
      <button type="button" class="add-btn" onclick="toggleAdd('sheet6')">&#43; Add Email</button>
      <input class="srch" type="text" placeholder="&#128269; Search..." oninput="filt('tr-sheet6',this.value)">
    </div>
  </div>
  <div class="add-form" id="af-sheet6">
    <div class="af-field"><label>Email *</label><input type="email" data-key="email"></div>
    <div class="af-actions">
      <button type="button" class="af-save" onclick="submitAdd('sheet6_network','af-sheet6','sheet6')">Save</button>
      <button type="button" class="af-cancel" onclick="toggleAdd('sheet6')">Cancel</button>
    </div>
    <div class="af-err" id="af-sheet6-err"></div>
  </div>
  <div class="tw"><table id="tr-sheet6">
    <thead><tr><th onclick="srt('tr-sheet6',0)">#</th><th onclick="srt('tr-sheet6',1)">Email</th></tr></thead>
    <tbody>
    <?php if (!$sheet6): ?><tr class="empty"><td colspan="2">No records on file.</td></tr>
    <?php else: foreach ($sheet6 as $r): ?>
    <tr><td class="nw"><?php echo esc($r['id']); ?></td><td><?php echo esc($r['email']); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

</div>

<script>
function tab(n,b){
  document.querySelectorAll('.tp').forEach(function(p){p.classList.remove('on');});
  document.querySelectorAll('.tb').forEach(function(x){x.classList.remove('on');});
  document.getElementById('tp-'+n).classList.add('on'); b.classList.add('on');
}
// Auto-activate tab from ?tab= query param (used after an Add-form save reloads
// the page, so the admin lands back on the tab they were adding to).
(function(){
  var m = location.search.match(/[?&]tab=([^&]+)/);
  if (m) { var btn = document.getElementById('tab-'+m[1]); if (btn) btn.click(); }
})();
function filt(id,q){
  q=q.toLowerCase();
  var rows=document.getElementById(id).tBodies[0].rows;
  for(var i=0;i<rows.length;i++) rows[i].style.display=(q===''||rows[i].textContent.toLowerCase().indexOf(q)!==-1)?'':'none';
}
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

// ─── Add-entry forms ───────────────────────────────────────────────────────
function toggleAdd(tabKey){
  var form = document.getElementById('af-'+tabKey);
  form.classList.toggle('show');
  if (form.classList.contains('show')) {
    var first = form.querySelector('input');
    if (first) first.focus();
  }
}
// sheet: backend key ('members' -> api/members.php, anything else -> api/membership-data.php)
// formId: the .add-form element's id, read via each input's data-key
// tabKey: used to reload back onto the right tab after a successful save
function submitAdd(sheet, formId, tabKey){
  var form = document.getElementById(formId);
  var errEl = document.getElementById(formId+'-err');
  errEl.style.display = 'none';
  var data = {};
  var hasRequired = false;
  form.querySelectorAll('[data-key]').forEach(function(el){
    var key = el.dataset.key;
    if (el.type === 'checkbox') {
      data[key] = el.checked ? (el.dataset.checkval || '1') : '';
    } else {
      data[key] = el.value.trim();
    }
  });
  // Every form's first field is its required one (marked with * in the label).
  var firstKey = form.querySelector('[data-key]').dataset.key;
  if (!data[firstKey]) {
    errEl.textContent = 'Please fill in the required field (marked with *).';
    errEl.style.display = 'block';
    return;
  }
  var saveBtn = form.querySelector('.af-save');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving…';

  var url, payload;
  if (sheet === 'members') {
    url = 'api/members.php';
    payload = { action: 'import', members: [data] };
  } else {
    url = 'api/membership-data.php';
    payload = { action: 'import', sheet: sheet, rows: [data] };
  }
  fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(function(r){ return r.json(); })
    .then(function(resp){
      if (!resp || resp.ok !== true || !resp.added) throw new Error((resp && resp.err) || 'Save failed');
      window.location.href = 'membership.php?tab=' + tabKey;
    })
    .catch(function(e){
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save';
      errEl.textContent = 'Could not save — ' + e.message;
      errEl.style.display = 'block';
    });
}
</script>

<?php endif; ?>
</body>
</html>
