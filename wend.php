<?php
// ================================================================
//  XAMPP PROJECT HUB v2.0
//  Coloque em: C:/xampp/htdocs/index.php
// ================================================================

define('SETTINGS_FILE', __DIR__ . '/hub_settings.json');
define('HTDOCS_PATH',   __DIR__);
define('SELF_FILE',     basename(__FILE__));

const SKIP_DIRS = ['.','..','cgi-bin','webalizer','xampp','phpmyadmin',
                   'dashboard','.git','.vscode','.idea','node_modules'];

function defaultShortcuts(): array {
    $base = [
        ['name'=>'Claude.ai',   'url'=>'https://claude.ai'],
        ['name'=>'Claude Code', 'url'=>'https://claude.ai/code'],
        ['name'=>'GitHub',      'url'=>''],
        ['name'=>'CodePen',     'url'=>''],
        ['name'=>'Hostoo',      'url'=>'https://app.hostoo.io/auth/login'],
        ['name'=>'Gmail',       'url'=>'https://mail.google.com'],
    ];
    while (count($base) < 20) $base[] = ['name'=>'','url'=>''];
    return $base;
}

function loadSettings(): array {
    $def = ['name'=>'','github'=>'','codepen'=>'','shortcuts'=>defaultShortcuts()];
    if (!file_exists(SETTINGS_FILE)) return $def;
    $d = json_decode(file_get_contents(SETTINGS_FILE), true);
    if (!is_array($d)) return $def;
    if (!isset($d['shortcuts'])||!is_array($d['shortcuts'])) $d['shortcuts']=defaultShortcuts();
    while (count($d['shortcuts'])<20) $d['shortcuts'][]=['name'=>'','url'=>''];
    $d['shortcuts'] = array_slice($d['shortcuts'],0,20);
    return array_merge($def,$d);
}

function saveSettings(array $s): bool {
    return file_put_contents(SETTINGS_FILE,
        json_encode($s, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) !== false;
}

function getLocalIP(): string {
    $hostname = gethostname();
    if ($hostname) {
        $ip = gethostbyname($hostname);
        if ($ip && $ip !== $hostname && $ip !== '127.0.0.1') return $ip;
    }
    if (strtoupper(substr(PHP_OS,0,3))==='WIN') {
        $out = @shell_exec('ipconfig');
        if ($out && preg_match_all('/IPv4[^:]*:\s*([\d\.]+)/',$out,$m))
            foreach ($m[1] as $ip)
                if ($ip!=='127.0.0.1' && !str_starts_with($ip,'169.254')) return $ip;
    }
    $out = @shell_exec("ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \\K[\\d.]+'");
    if ($out){$ip=trim($out);if($ip)return $ip;}
    $out = @shell_exec('hostname -I 2>/dev/null');
    if ($out) foreach(explode(' ',trim($out)) as $ip)
        if(filter_var($ip,FILTER_VALIDATE_IP)&&$ip!=='127.0.0.1') return $ip;
    return '127.0.0.1';
}

function scanProjects(): array {
    $projects=[];
    if(!is_dir(HTDOCS_PATH)) return $projects;
    foreach(scandir(HTDOCS_PATH) as $entry){
        if(in_array(strtolower($entry),array_map('strtolower',SKIP_DIRS))) continue;
        if($entry===SELF_FILE) continue;
        $path=HTDOCS_PATH.DIRECTORY_SEPARATOR.$entry;
        if(!is_dir($path)) continue;
        $count=0;$exts=[];
        foreach(scandir($path) as $f){
            if($f==='.'||$f==='..') continue;
            $fp=$path.DIRECTORY_SEPARATOR.$f;
            if(is_file($fp)){
                $count++;
                $ext=strtolower(pathinfo($fp,PATHINFO_EXTENSION));
                if($ext)$exts[$ext]=($exts[$ext]??0)+1;
            }
        }
        arsort($exts);
        $projects[]=['name'=>$entry,'fileCount'=>$count,'extensions'=>$exts];
    }
    usort($projects,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    return $projects;
}

function deepScanProject(string $name): array {
    $path=HTDOCS_PATH.DIRECTORY_SEPARATOR.$name;
    if(!is_dir($path)) return['error'=>'Projeto não encontrado'];
    $exts=[];$total=0;$limit=2000;
    $rii=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path,RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach($rii as $file){
        if(!$file->isFile()) continue;
        if(++$total>$limit){$exts['_truncated']=true;break;}
        $ext=strtolower($file->getExtension());
        if($ext)$exts[$ext]=($exts[$ext]??0)+1;
    }
    arsort($exts);
    return['name'=>$name,'fileCount'=>$total,'extensions'=>$exts,'limited'=>$total>$limit];
}

/* ── POST API ── */
if($_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $body=json_decode(file_get_contents('php://input'),true)??[];
    $action=$body['action']??'';
    if($action==='save_settings'){
        $s=loadSettings();
        if(isset($body['name']))    $s['name']=htmlspecialchars(trim($body['name']),ENT_QUOTES);
        if(isset($body['github']))  $s['github']=filter_var(trim($body['github']),FILTER_SANITIZE_URL);
        if(isset($body['codepen'])) $s['codepen']=filter_var(trim($body['codepen']),FILTER_SANITIZE_URL);
        if(isset($body['shortcuts'])&&is_array($body['shortcuts'])){
            $sc=[];
            foreach(array_slice($body['shortcuts'],0,20) as $sh)
                $sc[]=['name'=>htmlspecialchars(trim($sh['name']??''),ENT_QUOTES),
                        'url'=>filter_var(trim($sh['url']??''),FILTER_SANITIZE_URL)];
            while(count($sc)<20) $sc[]=['name'=>'','url'=>''];
            $s['shortcuts']=$sc;
        }
        echo json_encode(['ok'=>saveSettings($s),'settings'=>$s]);
        exit;
    }
    if($action==='open_folder'){
        $name=basename($body['project']??'');
        if(!$name){echo json_encode(['error'=>'nome inválido']);exit;}
        $path=HTDOCS_PATH.DIRECTORY_SEPARATOR.$name;
        if(!is_dir($path)){echo json_encode(['error'=>'pasta não encontrada']);exit;}
        $os=strtoupper(substr(PHP_OS,0,3));
        if($os==='WIN'){
            $winpath = rtrim($path, '\\/');
            @shell_exec('cmd /c start "" explorer.exe "' . $winpath . '"');
            echo json_encode(['ok'=>true,'path'=>$winpath]);
        } elseif($os==='DAR'){
            @shell_exec('open '.escapeshellarg($path).' &');
            echo json_encode(['ok'=>true]);
        } else {
            @shell_exec('xdg-open '.escapeshellarg($path).' &');
            echo json_encode(['ok'=>true]);
        }
        exit;
    }
    if($action==='deep_scan'){
        $name=basename($body['project']??'');
        if(!$name){echo json_encode(['error'=>'nome inválido']);exit;}
        echo json_encode(deepScanProject($name));
        exit;
    }
    echo json_encode(['error'=>'unknown action']);
    exit;
}

/* ── Page data ── */
$settings=loadSettings();
if(empty($settings['shortcuts'][2]['url'])&&$settings['github'])
    $settings['shortcuts'][2]['url']=$settings['github'];
if(empty($settings['shortcuts'][3]['url'])&&$settings['codepen'])
    $settings['shortcuts'][3]['url']=$settings['codepen'];

$localIP    = getLocalIP();
$port       = ($_SERVER['SERVER_PORT']??'80')!=='80' ? ':'.$_SERVER['SERVER_PORT'] : '';
$projects   = scanProjects();
$totalFiles = array_sum(array_column($projects,'fileCount'));
$projectsJSON = json_encode($projects,JSON_UNESCAPED_UNICODE);
$settingsJSON  = json_encode($settings,JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Hub <?= $settings['name'] ? '· '.htmlspecialchars($settings['name']) : '' ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>👾</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0e1628;--bg2:#152035;--bg3:#1c2a44;
  --glass:rgba(21,32,53,.86);--glass2:rgba(255,255,255,.05);
  --border:rgba(120,190,255,.16);--border2:rgba(120,190,255,.32);
  --cyan:#00d4ff;--cyan2:#00a8cc;--purple:#a855f7;--purple2:#7c3aed;
  --green:#22d3a0;--orange:#f97316;--red:#f43f5e;
  --text:#dde8f8;--muted:#7a96bc;--muted2:#4a6285;
  --r:14px;--r2:20px;
  --shadow:0 8px 32px rgba(0,0,0,.38);
  --glow-c:0 0 28px rgba(0,212,255,.2);--glow-p:0 0 28px rgba(168,85,247,.2);
  --fh:'Syne',sans-serif;--fn:'Outfit',sans-serif;--fm:'Space Mono',monospace;
}
html{scroll-behavior:smooth}
body{font-family:var(--fm);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");
  background-size:200px;pointer-events:none;opacity:.4}
body::after{content:'';position:fixed;inset:0;z-index:0;
  background-image:linear-gradient(rgba(0,212,255,.022) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(0,212,255,.022) 1px,transparent 1px);
  background-size:48px 48px;pointer-events:none}
.orb{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;z-index:0}
.orb-1{width:520px;height:520px;top:-140px;left:-140px;background:radial-gradient(circle,rgba(0,212,255,.18),transparent 70%)}
.orb-2{width:420px;height:420px;bottom:-100px;right:-100px;background:radial-gradient(circle,rgba(168,85,247,.2),transparent 70%)}
.orb-3{width:300px;height:300px;top:42%;left:56%;background:radial-gradient(circle,rgba(34,211,160,.1),transparent 70%)}
.wrap{position:relative;z-index:1;max-width:1440px;margin:0 auto;padding:22px 20px 60px}

/* HEADER */
.header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
  padding:17px 24px;margin-bottom:14px;background:var(--glass);border:1px solid var(--border2);
  border-radius:var(--r2);backdrop-filter:blur(24px);box-shadow:var(--shadow),var(--glow-c)}
.header-brand{display:flex;align-items:center;gap:12px}
.brand-icon{font-size:2.1rem;filter:drop-shadow(0 0 12px rgba(0,212,255,.5));animation:float 3s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
.brand-h1{font-family:var(--fh);font-size:1.35rem;font-weight:800;
  background:linear-gradient(135deg,var(--cyan),var(--purple));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1.1}
.brand-sub{font-size:.63rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase}
.hw{font-family:var(--fn);font-size:.98rem;font-weight:700;color:var(--cyan);
  text-shadow:0 0 16px rgba(0,212,255,.4)}
.hacts{display:flex;gap:7px;align-items:center}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;
  font-size:.76rem;font-family:var(--fm);font-weight:700;cursor:pointer;text-decoration:none;
  border:none;outline:none;transition:all .2s;letter-spacing:.02em;white-space:nowrap;
  position:relative;overflow:hidden}
.btn::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,.06);opacity:0;transition:opacity .2s}
.btn:hover::after{opacity:1}
.btn:active{transform:scale(.96)}
.btn-c{background:linear-gradient(135deg,var(--cyan2),var(--cyan));color:#000;box-shadow:0 4px 16px rgba(0,212,255,.3)}
.btn-p{background:linear-gradient(135deg,var(--purple2),var(--purple));color:#fff;box-shadow:0 4px 16px rgba(168,85,247,.3)}
.btn-g{background:var(--glass2);color:var(--cyan);border:1px solid var(--border2)}
.btn-g:hover{border-color:var(--cyan);box-shadow:var(--glow-c)}
.btn-sm{padding:5px 12px;font-size:.7rem}
.btn-exp{padding:5px 10px;font-size:.7rem;font-weight:600;color:var(--muted);
  border:1px solid var(--border);background:var(--glass2)}
.btn-exp:hover{color:var(--cyan);border-color:var(--cyan);box-shadow:var(--glow-c)}

/* DOCK */
.dock{display:flex;align-items:center;gap:7px;padding:8px 15px;margin-bottom:14px;
  background:rgba(11,18,36,.93);border:1px solid var(--border);border-radius:12px;
  backdrop-filter:blur(20px);box-shadow:var(--shadow);overflow-x:auto;scrollbar-width:none}
.dock::-webkit-scrollbar{display:none}
.dock-lbl{font-size:.58rem;color:var(--muted2);white-space:nowrap;font-family:var(--fm);
  letter-spacing:.1em;text-transform:uppercase;flex-shrink:0}
.dock-sep{width:1px;height:18px;background:var(--border2);flex-shrink:0;margin:0 3px}
.pill{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;border-radius:20px;
  background:var(--bg3);border:1px solid var(--border);color:var(--text);
  font-size:.74rem;font-family:var(--fn);font-weight:600;text-decoration:none;
  white-space:nowrap;flex-shrink:0;transition:all .2s;cursor:pointer}
.pill:hover{border-color:var(--cyan);color:var(--cyan);background:rgba(0,212,255,.07);
  box-shadow:0 0 12px rgba(0,212,255,.16);transform:translateY(-1px)}
.dock-hint{font-size:.58rem;color:var(--muted2);white-space:nowrap;font-family:var(--fm);
  flex-shrink:0;padding-left:8px;border-left:1px solid var(--border)}

/* TAB NAV */
.tab-nav{display:flex;gap:5px;margin-bottom:20px;background:var(--bg2);
  border:1px solid var(--border);border-radius:12px;padding:4px;width:fit-content}
.tab-btn{padding:8px 22px;border-radius:8px;font-size:.8rem;font-family:var(--fn);
  font-weight:700;cursor:pointer;border:none;background:transparent;color:var(--muted);
  transition:all .22s;display:flex;align-items:center;gap:7px}
.tab-btn.on{background:linear-gradient(135deg,var(--cyan2),var(--purple2));color:#fff;
  box-shadow:0 4px 14px rgba(0,212,255,.22)}
.tab-btn:not(.on):hover{color:var(--text);background:var(--glass2)}

/* GLASS CARD */
.card{background:var(--glass);border:1px solid var(--border2);border-radius:var(--r2);
  backdrop-filter:blur(20px);box-shadow:var(--shadow),inset 0 1px 0 rgba(255,255,255,.06);
  transition:border-color .25s,box-shadow .25s,transform .25s}
.card:hover{border-color:rgba(120,190,255,.44);box-shadow:var(--shadow),var(--glow-c);transform:translateY(-2px)}

/* STATS */
.stats-hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:13px;margin-bottom:20px}
.stat-card{padding:21px 23px;position:relative;overflow:hidden}
.stat-card::before{content:attr(data-icon);position:absolute;right:11px;bottom:5px;
  font-size:4rem;opacity:.07;pointer-events:none;transition:opacity .3s,transform .3s}
.stat-card:hover::before{opacity:.14;transform:scale(1.15) rotate(-8deg)}
.stat-lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.13em;color:var(--muted);
  margin-bottom:9px;font-family:var(--fm)}
.stat-val{font-family:var(--fn);font-size:2.8rem;font-weight:800;line-height:1;color:#fff;
  letter-spacing:-.03em;text-shadow:0 0 30px rgba(0,212,255,.35),0 2px 6px rgba(0,0,0,.35)}
.stat-sub{font-size:.7rem;color:var(--muted);margin-top:6px}
.ip-card{grid-column:span 2}
@media(max-width:620px){.ip-card{grid-column:span 1}}
.ip-row{display:flex;align-items:center;flex-wrap:wrap;gap:18px;margin-top:9px}
.ip-item{display:flex;flex-direction:column;gap:3px}
.ip-lbl{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-family:var(--fm)}
.ip-val{font-family:var(--fm);font-size:.98rem;font-weight:700;color:var(--cyan);
  text-shadow:0 0 12px rgba(0,212,255,.3)}
.ip-val.wan{color:var(--purple);text-shadow:0 0 12px rgba(168,85,247,.3)}

/* SECTION TITLE */
.sec-title{font-family:var(--fm);font-size:.65rem;font-weight:700;letter-spacing:.18em;
  text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:9px;margin-bottom:13px}
.sec-title::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--border2),transparent)}

/* SIDEBAR */
.sbar{display:grid;grid-template-columns:repeat(auto-fill,minmax(265px,1fr));gap:14px;margin-bottom:20px}
.sc{padding:19px 21px}
.sc-hd{display:flex;align-items:center;gap:10px;margin-bottom:13px}
.sc-ico{width:35px;height:35px;border-radius:9px;display:flex;align-items:center;
  justify-content:center;font-size:1.05rem;flex-shrink:0}
.sc-ico.wx {background:linear-gradient(135deg,#0369a1,#0ea5e9);border:1px solid #0ea5e950}
.sc-ico.fc {background:linear-gradient(135deg,#0f4c81,#3b82f6);border:1px solid #3b82f650}
.sc-ico.qt {background:linear-gradient(135deg,var(--purple2),var(--purple));border:1px solid #a855f750}
.sc-ico.sk {background:linear-gradient(135deg,#f97316,#ef4444);border:1px solid #f9731650}
.sc-title{font-family:var(--fn);font-size:.93rem;font-weight:700}
.sc-sub{font-size:.65rem;color:var(--muted);margin-top:2px}

/* WEATHER */
.wx-loc{font-size:.63rem;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);
  font-family:var(--fm);margin-bottom:9px}
.wx-main{display:flex;align-items:center;gap:13px;margin-bottom:11px}
.wx-icon{font-size:2.5rem;line-height:1;filter:drop-shadow(0 0 8px rgba(0,200,255,.3))}
.wx-temp{font-family:var(--fn);font-size:2.9rem;font-weight:800;color:#fff;letter-spacing:-.03em;line-height:1}
.wx-temp sup{font-size:.9rem;font-weight:600;color:var(--muted);vertical-align:super}
.wx-desc{font-size:.76rem;color:var(--text);margin-top:2px;font-family:var(--fn)}
.wx-dets{display:flex;gap:13px;flex-wrap:wrap}
.wx-det{font-size:.7rem;color:var(--muted);font-family:var(--fm)}
.wx-det strong{color:var(--text)}
.fc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:7px}
.fc-day{background:var(--bg3);border:1px solid var(--border);border-radius:10px;
  padding:10px 6px;text-align:center;transition:border-color .2s}
.fc-day:hover{border-color:var(--border2)}
.fc-dn{font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);
  margin-bottom:4px;font-family:var(--fm)}
.fc-di{font-size:1.45rem;margin-bottom:4px}
.fc-hi{font-family:var(--fn);font-size:.83rem;font-weight:700;color:var(--text)}
.fc-lo{font-family:var(--fn);font-size:.7rem;color:var(--muted)}

/* MUSIC CARD */
.music-btn{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;
  text-decoration:none;font-family:var(--fn);font-size:.82rem;font-weight:600;
  transition:all .2s;position:relative;overflow:hidden;border:1px solid transparent}
.music-btn:hover{transform:translateX(3px)}
.music-arrow{margin-left:auto;font-size:.8rem;opacity:.5;transition:opacity .2s,transform .2s}
.music-btn:hover .music-arrow{opacity:1;transform:translateX(3px)}
.spotify-btn{background:rgba(29,185,84,.1);color:#1db954;border-color:rgba(29,185,84,.25)}
.spotify-btn:hover{background:rgba(29,185,84,.18);border-color:rgba(29,185,84,.5);
  box-shadow:0 0 16px rgba(29,185,84,.2)}
.ytm-btn{background:rgba(255,0,0,.08);color:#ff4444;border-color:rgba(255,0,0,.2)}
.ytm-btn:hover{background:rgba(255,0,0,.14);border-color:rgba(255,0,0,.4);
  box-shadow:0 0 16px rgba(255,68,68,.18)}

/* QUOTE */
.qt-txt{font-family:var(--fn);font-size:.88rem;font-weight:600;line-height:1.55;
  color:var(--text);margin-bottom:9px;position:relative;padding-left:15px}
.qt-txt::before{content:'"';position:absolute;left:0;top:-2px;font-size:1.7rem;
  color:var(--purple);line-height:1;opacity:.7}
.qt-auth{font-size:.67rem;color:var(--muted);text-align:right;font-style:italic}

/* PROJECT CARDS */
.pgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(305px,1fr));gap:15px;margin-bottom:22px}
.pcard{padding:19px;display:flex;flex-direction:column;gap:12px;position:relative;overflow:hidden}
.pcard::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--cyan),var(--purple));opacity:0;transition:opacity .3s}
.pcard:hover::before{opacity:1}
.pcard:hover{box-shadow:var(--shadow),0 0 38px rgba(0,212,255,.1)}
.phd{display:flex;align-items:flex-start;justify-content:space-between;gap:9px}
.pico{width:39px;height:39px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,var(--bg3),var(--bg2));border:1px solid var(--border2);
  display:flex;align-items:center;justify-content:center;font-size:1.1rem}
.pname{font-family:var(--fn);font-size:.96rem;font-weight:700;color:var(--text);word-break:break-word;line-height:1.3}
.pmeta{font-size:.72rem;color:var(--muted);margin-top:2px;font-family:var(--fm)}
.etags{display:flex;flex-wrap:wrap;gap:4px}
.etag{padding:2px 7px;border-radius:4px;font-size:.61rem;font-weight:700;
  background:var(--bg3);border:1px solid var(--border);color:var(--muted);
  letter-spacing:.04em;font-family:var(--fm)}
.etag.php  {border-color:rgba(139,92,246,.4);color:#a78bfa}
.etag.html {border-color:rgba(249,115,22,.4);color:#fb923c}
.etag.css  {border-color:rgba(59,130,246,.4);color:#60a5fa}
.etag.js   {border-color:rgba(234,179,8,.4);color:#fbbf24}
.etag.ts   {border-color:rgba(56,189,248,.4);color:#38bdf8}
.etag.json {border-color:rgba(34,211,160,.4);color:#34d399}
.etag.sql  {border-color:rgba(244,63,94,.4);color:#fb7185}
.etag.py   {border-color:rgba(99,102,241,.4);color:#818cf8}
.etag.md   {border-color:rgba(156,163,175,.4);color:#9ca3af}
.pacts{display:flex;gap:6px;flex-wrap:wrap;margin-top:auto;padding-top:12px;border-top:1px solid var(--border)}

/* RESOURCES */
.res-nav{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:16px}
.rtab{padding:6px 15px;border-radius:20px;font-size:.74rem;font-family:var(--fn);font-weight:600;
  cursor:pointer;border:1px solid var(--border);background:var(--bg3);color:var(--muted);
  transition:all .2s;white-space:nowrap}
.rtab.on{background:rgba(0,212,255,.1);border-color:var(--cyan);color:var(--cyan)}
.rtab:hover:not(.on){color:var(--text);border-color:var(--border2)}
.res-hd{font-family:var(--fn);font-size:1.18rem;font-weight:800;color:var(--text);margin-bottom:3px}
.res-desc{font-size:.78rem;color:var(--muted);margin-bottom:16px;line-height:1.5}
.rgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(272px,1fr));gap:12px}
.rcard{padding:15px 17px;background:var(--glass);border:1px solid var(--border);
  border-radius:var(--r);backdrop-filter:blur(14px);display:flex;flex-direction:column;gap:6px;
  transition:border-color .2s,box-shadow .2s,transform .2s}
.rcard:hover{border-color:var(--border2);box-shadow:var(--glow-c);transform:translateY(-1px)}
.rcard-top{display:flex;justify-content:space-between;align-items:flex-start;gap:7px}
.rname{font-family:var(--fn);font-size:.91rem;font-weight:700;color:var(--text)}
.rorg{font-size:.65rem;color:var(--muted);font-family:var(--fm)}
.rrank{font-family:var(--fn);font-size:1.05rem;font-weight:800;color:var(--cyan);opacity:.4;flex-shrink:0}
.rdesc{font-size:.74rem;line-height:1.54;color:var(--muted);flex:1}
.rfoot{display:flex;justify-content:space-between;align-items:center;margin-top:4px}
.rbadge{font-size:.59rem;padding:2px 7px;border-radius:4px;background:var(--bg3);
  border:1px solid var(--border);color:var(--muted);font-family:var(--fm)}
.rbadge.hot{border-color:rgba(0,212,255,.4);color:var(--cyan);background:rgba(0,212,255,.06)}
.rlink{font-size:.67rem;color:var(--cyan);text-decoration:none;font-family:var(--fm);transition:opacity .2s}
.rlink:hover{text-decoration:underline;opacity:.8}

/* SETTINGS */
.ov{position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.76);backdrop-filter:blur(6px);
  display:flex;align-items:center;justify-content:center;padding:18px;
  opacity:0;pointer-events:none;transition:opacity .3s}
.ov.open{opacity:1;pointer-events:all}
.spanel{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r2);
  padding:26px 28px;width:100%;max-width:640px;max-height:92vh;overflow-y:auto;
  box-shadow:0 24px 80px rgba(0,0,0,.7),var(--glow-p);
  transform:translateY(22px);transition:transform .35s cubic-bezier(.2,.8,.3,1);position:relative}
.ov.open .spanel{transform:translateY(0)}
.stitle{font-family:var(--fh);font-size:1.22rem;font-weight:800;
  background:linear-gradient(135deg,var(--cyan),var(--purple));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:18px}
.fg{margin-bottom:14px}
.flbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);display:block;margin-bottom:5px}
.finp{width:100%;padding:8px 12px;background:var(--bg3);border:1px solid var(--border2);
  border-radius:8px;color:var(--text);font-family:var(--fm);font-size:.8rem;
  outline:none;transition:border-color .2s,box-shadow .2s}
.finp:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(0,212,255,.1)}
.finp::placeholder{color:var(--muted2)}
.sc-sec{font-family:var(--fn);font-size:.8rem;font-weight:700;color:var(--text);
  margin:20px 0 11px;padding-top:16px;border-top:1px solid var(--border);
  display:flex;align-items:center;gap:7px}
.sc-rows{display:flex;flex-direction:column;gap:6px}
.sc-row{display:grid;grid-template-columns:20px 1fr 1fr;gap:6px;align-items:center}
.sc-num{font-family:var(--fm);font-size:.62rem;color:var(--muted2);text-align:center}
.sclose{position:absolute;top:14px;right:15px;background:none;border:none;
  color:var(--muted);font-size:1.35rem;cursor:pointer;line-height:1;transition:color .2s,transform .2s}
.sclose:hover{color:var(--text);transform:rotate(90deg)}
.sfooter{display:flex;gap:9px;justify-content:flex-end;margin-top:20px}

/* MODAL */
.mov{position:fixed;inset:0;z-index:900;background:rgba(5,10,22,.82);backdrop-filter:blur(8px);
  display:flex;align-items:center;justify-content:center;padding:18px;
  opacity:0;pointer-events:none;transition:opacity .3s}
.mov.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r2);
  padding:24px;width:100%;max-width:550px;max-height:88vh;overflow-y:auto;
  box-shadow:0 24px 80px rgba(0,0,0,.7),var(--glow-c);
  transform:scale(.94) translateY(18px);transition:transform .3s cubic-bezier(.2,.8,.3,1);position:relative}
.mov.open .modal{transform:scale(1) translateY(0)}
.mhd{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;gap:11px}
.mtitle{font-family:var(--fn);font-size:1.12rem;font-weight:800}
.msub{font-size:.68rem;color:var(--muted);margin-top:3px}
.mclose{background:none;border:none;color:var(--muted);font-size:1.35rem;cursor:pointer;
  flex-shrink:0;margin-top:2px;transition:color .2s,transform .2s}
.mclose:hover{color:var(--text);transform:rotate(90deg)}
.ext-ana{display:flex;flex-direction:column;gap:8px}
.erow{display:flex;flex-direction:column;gap:4px}
.erow-hd{display:flex;justify-content:space-between;font-size:.72rem}
.erow-name{font-weight:700;font-family:var(--fm)}
.erow-cnt{color:var(--muted)}
.ebar-w{height:6px;background:var(--bg3);border-radius:3px;overflow:hidden}
.ebar{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--cyan),var(--purple));
  transition:width 1s cubic-bezier(.4,0,.2,1)}
.mstats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}
.mstat{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:12px 14px}
.mslbl{font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted)}
.msval{font-family:var(--fn);font-size:1.9rem;font-weight:800;letter-spacing:-.02em;
  color:#fff;margin-top:4px;text-shadow:0 0 18px rgba(0,212,255,.32)}

/* SHORTCUT MODAL */
.ssteps{display:flex;flex-direction:column;gap:10px;margin-top:13px}
.sstep{display:flex;gap:12px;align-items:flex-start;padding:12px 14px;
  background:var(--bg3);border:1px solid var(--border);border-radius:10px}
.snum{width:25px;height:25px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--cyan2),var(--purple2));
  color:#fff;font-weight:700;font-size:.76rem;display:flex;align-items:center;justify-content:center}
.stxt{font-size:.77rem;line-height:1.55;color:var(--text)}
.stxt strong{color:var(--cyan)}

/* TOAST */
#toast{position:fixed;bottom:24px;right:24px;z-index:9999;
  background:var(--bg2);border:1px solid var(--border2);border-radius:10px;
  padding:10px 17px;font-size:.76rem;font-family:var(--fm);color:var(--text);
  box-shadow:0 8px 32px rgba(0,0,0,.6),var(--glow-c);
  transform:translateY(18px);opacity:0;transition:all .3s cubic-bezier(.2,.8,.3,1);
  display:flex;align-items:center;gap:8px;max-width:300px}
#toast.show{transform:translateY(0);opacity:1}

/* FOOTER */
.footer{text-align:center;padding:16px;font-size:.63rem;color:var(--muted2);
  border-top:1px solid var(--border);margin-top:38px}
.footer span{color:var(--muted)}

/* ANIM */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.fu{opacity:0;animation:fadeUp .5s ease forwards}
.d1{animation-delay:.05s}.d2{animation-delay:.1s}.d3{animation-delay:.15s}
.d4{animation-delay:.2s}.d5{animation-delay:.25s}

::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--muted2)}

@media(max-width:760px){
  .wrap{padding:12px 11px 50px}
  .header{padding:12px 14px}
  .brand-h1{font-size:1.05rem}
  .pgrid,.rgrid,.sbar{grid-template-columns:1fr}
  .tab-nav{width:100%}
  .tab-btn{flex:1;justify-content:center;padding:8px 10px;font-size:.74rem}
  .modal,.spanel{padding:16px}
  .sc-row{grid-template-columns:16px 1fr 1fr}
  .ip-card{grid-column:span 1}
}
</style>
</head>
<body>
<div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
<div id="toast"><span id="ti">✓</span><span id="tm"></span></div>

<!-- SETTINGS -->
<div class="ov" id="sovl">
<div class="spanel">
  <button class="sclose" onclick="closeSett()">✕</button>
  <div class="stitle">⚙️ Configurações</div>
  <div class="fg"><label class="flbl">Seu primeiro nome</label>
    <input class="finp" id="cfg-name" type="text" placeholder="ex: Rafael" maxlength="40"></div>
  <div class="fg"><label class="flbl">Link do GitHub</label>
    <input class="finp" id="cfg-gh" type="url" placeholder="https://github.com/seuperfil"></div>
  <div class="fg"><label class="flbl">Link do CodePen</label>
    <input class="finp" id="cfg-cp" type="url" placeholder="https://codepen.io/seuperfil"></div>
  <div class="sc-sec">⚡ Atalhos Rápidos
    <span style="color:var(--muted);font-weight:400;font-size:.72rem">(deixe em branco para ocultar)</span></div>
  <div class="sc-rows" id="sc-rows"></div>
  <div class="sfooter">
    <button class="btn btn-g btn-sm" onclick="closeSett()">Cancelar</button>
    <button class="btn btn-c btn-sm" onclick="saveSett()">💾 Salvar</button>
  </div>
</div></div>

<!-- PROJECT DETAIL MODAL -->
<div class="mov" id="movl">
<div class="modal">
  <div class="mhd">
    <div><div class="mtitle" id="mtitle"></div><div class="msub" id="msub"></div></div>
    <button class="mclose" onclick="closeModal()">✕</button>
  </div>
  <div class="mstats">
    <div class="mstat"><div class="mslbl">Total de arquivos</div><div class="msval" id="mtot">…</div></div>
    <div class="mstat"><div class="mslbl">Tipos distintos</div><div class="msval" id="mtyp">…</div></div>
  </div>
  <div style="margin-top:16px">
    <div class="sec-title" style="font-size:.63rem">Distribuição por extensão</div>
    <div class="ext-ana" id="mana"></div>
  </div>
</div></div>

<!-- SHORTCUT CREATION MODAL -->
<div class="mov" id="skovl">
<div class="modal">
  <div class="mhd">
    <div><div class="mtitle">👾 Criar Atalho na Área de Trabalho</div>
         <div class="msub">Siga as instruções do seu navegador</div></div>
    <button class="mclose" onclick="closeSK()">✕</button>
  </div>
  <p style="font-size:.76rem;color:var(--muted);line-height:1.6;margin-bottom:4px">
    Navegadores não permitem criação direta de atalhos. Escolha um método:</p>
  <div class="ssteps">
    <div class="sstep"><div class="snum">1</div><div class="stxt"><strong>Chrome / Edge:</strong> Menu (⋮) → "Mais ferramentas" → <strong>"Criar atalho…"</strong> → marque "Abrir como janela" → Criar.</div></div>
    <div class="sstep"><div class="snum">2</div><div class="stxt"><strong>Firefox:</strong> Arraste o 🔒 da barra de endereços direto para a <strong>Área de Trabalho</strong>.</div></div>
    <div class="sstep"><div class="snum">3</div><div class="stxt"><strong>Favoritos:</strong> Pressione <strong>Ctrl+D</strong> e salve na Barra de Favoritos.</div></div>
    <div class="sstep"><div class="snum">4</div><div class="stxt"><strong>Mobile:</strong> Compartilhar → <strong>"Adicionar à tela inicial"</strong>.</div></div>
  </div>
  <div style="margin-top:13px;text-align:center">
    <button class="btn btn-c btn-sm" onclick="copyHub()">📋 Copiar URL do Hub</button>
  </div>
</div></div>

<!-- MAIN -->
<div class="wrap">

  <!-- HEADER -->
  <header class="header fu d1">
    <div class="header-brand">
      <div class="brand-icon">👾</div>
      <div><div class="brand-h1">Project Hub</div><div class="brand-sub">XAMPP · Central de Projetos Locais</div></div>
    </div>
    <div class="hw" id="hw">
      <?= $settings['name'] ? 'Bem-vindo, '.htmlspecialchars($settings['name']).' 👾' : 'Bem-vindo ao Project Hub 👾' ?>
    </div>
    <div class="hacts">
      <button class="btn btn-g btn-sm" onclick="openSK()" title="Criar atalho">🖥️</button>
      <button class="btn btn-g btn-sm" onclick="openSett()">⚙️ Config</button>
    </div>
  </header>

  <!-- DOCK -->
  <div class="dock fu d2">
    <span class="dock-lbl">⚡ ATALHOS</span>
    <div class="dock-sep"></div>
    <span id="pills" style="display:contents"></span>
    <div class="dock-sep"></div>
    <span class="dock-hint">Para adicionar mais atalhos, acesse ⚙️ Config</span>
  </div>

  <!-- TABS -->
  <div class="tab-nav fu d2">
    <button class="tab-btn on" id="tbp" onclick="switchTab('p')">🗂️ Projetos</button>
    <button class="tab-btn"    id="tbr" onclick="switchTab('r')">📚 Recursos</button>
  </div>

  <!-- TAB PROJETOS -->
  <div id="tabp">

    <div class="stats-hero fu d3">
      <div class="card stat-card" data-icon="📁">
        <div class="stat-lbl">Projetos encontrados</div>
        <div class="stat-val"><?= count($projects) ?></div>
        <div class="stat-sub">pastas no htdocs</div>
      </div>
      <div class="card stat-card" data-icon="🗂️">
        <div class="stat-lbl">Total de arquivos</div>
        <div class="stat-val"><?= number_format($totalFiles) ?></div>
        <div class="stat-sub">arquivos diretos por projeto</div>
      </div>
      <div class="card stat-card ip-card" data-icon="🌐">
        <div class="stat-lbl">Endereços de acesso</div>
        <div class="ip-row">
          <div class="ip-item"><span class="ip-lbl">Localhost</span>
            <span class="ip-val">127.0.0.1<?= $port ?></span></div>
          <div class="ip-item"><span class="ip-lbl">Rede Local (LAN)</span>
            <span class="ip-val"><?= htmlspecialchars($localIP) ?><?= $port ?></span></div>
          <div class="ip-item"><span class="ip-lbl">IP Global (WAN)</span>
            <span class="ip-val wan" id="gip">carregando…</span></div>
        </div>
      </div>
    </div>

    <div class="sec-title fu d3">🌤️ Informações &amp; Tempo</div>
    <div class="sbar fu d4">

      <!-- Weather Now -->
      <div class="card sc">
        <div class="sc-hd">
          <div class="sc-ico wx">🌡️</div>
          <div><div class="sc-title">Clima Agora</div><div class="sc-sub" id="wxsub">Detectando…</div></div>
        </div>
        <div id="wxb"><div style="color:var(--muted);font-size:.76rem;padding:8px 0">Carregando dados meteorológicos…</div></div>
      </div>

      <!-- Forecast -->
      <div class="card sc">
        <div class="sc-hd">
          <div class="sc-ico fc">📅</div>
          <div><div class="sc-title">Próximos Dias</div><div class="sc-sub">Temperatura mín / máx</div></div>
        </div>
        <div id="fcb"><div style="color:var(--muted);font-size:.76rem;padding:8px 0">Carregando previsão…</div></div>
      </div>

      <!-- Quote -->
      <div class="card sc">
        <div class="sc-hd">
          <div class="sc-ico qt">💬</div>
          <div><div class="sc-title">Para o dev que persiste</div><div class="sc-sub">Reflexões reais</div></div>
        </div>
        <div class="qt-txt" id="qtxt">carregando…</div>
        <div class="qt-auth" id="qauth"></div>
        <button class="btn btn-g btn-sm" onclick="nextQ()" style="margin-top:9px">→ Próxima</button>
      </div>

      <!-- Shortcut helper -->
      <div class="card sc">
        <div class="sc-hd">
          <div class="sc-ico sk">🖥️</div>
          <div><div class="sc-title">Atalho no Desktop</div><div class="sc-sub">Acesse o Hub com 1 clique</div></div>
        </div>
        <p style="font-size:.75rem;color:var(--muted);line-height:1.58;margin-bottom:12px">
          Fixe o Project Hub como atalho para abrir direto do desktop sem digitar URL.</p>
        <button class="btn btn-g btn-sm" onclick="openSK()">📌 Ver instruções</button>
      </div>

      <!-- Music -->
      <div class="card sc">
        <div class="sc-hd">
          <div class="sc-ico" style="background:linear-gradient(135deg,#1a1a2e,#16213e);border:1px solid rgba(255,255,255,.12)">🎵</div>
          <div><div class="sc-title">Música</div><div class="sc-sub">Streaming de áudio</div></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;margin-top:2px">

          <!-- Spotify -->
          <a href="https://open.spotify.com" target="_blank" rel="noopener" class="music-btn spotify-btn">
            <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
              <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
            </svg>
            <span>Spotify</span>
            <span class="music-arrow">→</span>
          </a>

          <!-- YouTube Music -->
          <a href="https://music.youtube.com" target="_blank" rel="noopener" class="music-btn ytm-btn">
            <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
              <path d="M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L16.2 12l-6.516 3.54z"/>
            </svg>
            <span>YouTube Music</span>
            <span class="music-arrow">→</span>
          </a>

        </div>
      </div>

    </div>

    <div class="sec-title fu d4">
      📂 Projetos no htdocs
      <span style="font-size:.68rem;color:var(--cyan);font-weight:700;letter-spacing:0"><?= count($projects) ?> encontrados</span>
    </div>

    <?php if(empty($projects)): ?>
    <div class="card" style="padding:38px;text-align:center;color:var(--muted)">
      <div style="font-size:2.8rem;margin-bottom:11px">📭</div>
      <div style="font-family:var(--fn);font-size:.98rem;font-weight:700">Nenhum projeto encontrado</div>
      <div style="font-size:.78rem;margin-top:7px">Crie pastas dentro do <code style="color:var(--cyan)">htdocs</code> para vê-las aqui.</div>
    </div>
    <?php else: ?>
    <div class="pgrid fu d5" id="pgrid">
      <?php foreach($projects as $i=>$proj):
        $extKeys=array_keys($proj['extensions']);$mainExt=$extKeys[0]??'';
        $icons=['php'=>'🐘','html'=>'🌐','js'=>'⚡','ts'=>'🔷','css'=>'🎨','py'=>'🐍',
                'sql'=>'🗃️','json'=>'📋','md'=>'📝','vue'=>'💚','jsx'=>'⚛️','tsx'=>'⚛️',
                'scss'=>'💅','sass'=>'💅'];
        $icon=$icons[$mainExt]??'📁';
      ?>
      <div class="card pcard">
        <div class="phd">
          <div style="display:flex;gap:10px;align-items:flex-start;min-width:0">
            <div class="pico"><?= $icon ?></div>
            <div style="min-width:0">
              <div class="pname" title="<?= htmlspecialchars($proj['name']) ?>"><?= htmlspecialchars($proj['name']) ?></div>
              <div class="pmeta"><?= $proj['fileCount'] ?> arquivo<?= $proj['fileCount']!==1?'s':'' ?><?= $mainExt?' · '.strtoupper($mainExt).' principal':'' ?></div>
            </div>
          </div>
          <button class="btn btn-exp" onclick="openModal(<?= $i ?>)">🔍 Expandir</button>
          <button class="btn btn-exp" onclick="openFolder('<?= addslashes(htmlspecialchars($proj['name'])) ?>')" title="Abrir pasta no explorador">📂</button>
        </div>
        <?php if(!empty($proj['extensions'])): ?>
        <div class="etags">
          <?php foreach(array_slice($proj['extensions'],0,6,true) as $ext=>$cnt): ?>
            <span class="etag <?= htmlspecialchars($ext) ?>">.<?= htmlspecialchars($ext) ?> <span style="opacity:.6">(<?= $cnt ?>)</span></span>
          <?php endforeach; ?>
          <?php if(count($proj['extensions'])>6): ?><span class="etag">+<?= count($proj['extensions'])-6 ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="pacts">
          <a class="btn btn-c btn-sm" href="http://localhost<?= $port ?>/<?= rawurlencode($proj['name']) ?>/" target="_blank">🏠 Localhost</a>
          <a class="btn btn-p btn-sm" href="http://<?= htmlspecialchars($localIP) ?><?= $port ?>/<?= rawurlencode($proj['name']) ?>/" target="_blank">📡 Rede</a>
          <button class="btn btn-g btn-sm" onclick="copyLink('http://<?= htmlspecialchars($localIP) ?><?= $port ?>/<?= rawurlencode($proj['name']) ?>/')">📋 Copiar</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div><!-- /tabp -->

  <!-- TAB RECURSOS -->
  <div id="tabr" style="display:none">
    <div class="res-nav">
      <button class="rtab on"  onclick="switchRes('ias',this)">🤖 Melhores IAs</button>
      <button class="rtab"     onclick="switchRes('ap',this)">📚 Aprender</button>
      <button class="rtab"     onclick="switchRes('lib',this)">📦 Bibliotecas</button>
      <button class="rtab"     onclick="switchRes('fer',this)">🛠️ Ferramentas</button>
      <button class="rtab"     onclick="switchRes('esc',this)">📈 Escalonamento</button>
      <button class="rtab"     onclick="switchRes('rep',this)">🐙 Repos GitHub</button>
      <button class="rtab"     onclick="switchRes('tec',this)">🧠 Aprendizado</button>
    </div>
    <div id="rcont"></div>
  </div>

  <footer class="footer">
    <span>👾 Project Hub v2.0</span> · XAMPP · htdocs: <span><?= htmlspecialchars(HTDOCS_PATH) ?></span>
  </footer>
</div>

<script>
/* ─── PHP data ─── */
const PROJS = <?= $projectsJSON ?>;
const LIP   = "<?= htmlspecialchars($localIP) ?>";
const PORT  = "<?= $port ?>";
let   CFG   = <?= $settingsJSON ?>;

/* ─── Resources data ─── */
const RES = {
  ias:{title:'Melhores IAs do Mercado',icon:'🤖',
    desc:'Ranking das IAs mais poderosas e utilizadas por desenvolvedores em 2025.',
    items:[
      {rank:1,name:'Claude 3.5 Sonnet',org:'Anthropic',desc:'Referência em raciocínio, código e análise. Preferida por devs para tarefas complexas e contexto extenso.',url:'https://claude.ai',badge:'Raciocínio',hot:1},
      {rank:2,name:'ChatGPT-4o',org:'OpenAI',desc:'O mais popular do mundo. Multimodal com voz, imagem e código. Ecossistema imenso de integrações.',url:'https://chat.openai.com',badge:'Versátil',hot:1},
      {rank:3,name:'Gemini 1.5 Pro',org:'Google',desc:'Janela de contexto de 1M tokens. Integração nativa com Google Workspace e busca em tempo real.',url:'https://gemini.google.com',badge:'Contexto longo'},
      {rank:4,name:'Grok 2',org:'xAI',desc:'Acesso a dados do X/Twitter em tempo real. Direto ao ponto, sem filtros excessivos.',url:'https://grok.x.ai',badge:'Tempo real'},
      {rank:5,name:'Perplexity',org:'Perplexity AI',desc:'Busca + IA integrados. Excelente para pesquisa com fontes citadas e resumo de conteúdo web.',url:'https://perplexity.ai',badge:'Pesquisa',hot:1},
      {rank:6,name:'DeepSeek R1',org:'DeepSeek',desc:'Open source, nível GPT-4 em matemática e código. Gratuito e pode rodar localmente.',url:'https://chat.deepseek.com',badge:'Open Source'},
      {rank:7,name:'Mistral Large',org:'Mistral AI',desc:'Europeu, multilíngue, muito rápido. Forte em raciocínio lógico, código e idiomas latinos.',url:'https://chat.mistral.ai',badge:'Europeu'},
      {rank:8,name:'GitHub Copilot',org:'Microsoft',desc:'IA de código no VS Code. Sugestões em tempo real, chat e geração de testes automatizados.',url:'https://github.com/features/copilot',badge:'Código'},
      {rank:9,name:'LLaMA 3.1',org:'Meta',desc:'Open source, pode rodar localmente via Ollama. Base de muitos modelos e fine-tunings customizados.',url:'https://llama.meta.com',badge:'Local'},
      {rank:10,name:'Cohere Command R+',org:'Cohere',desc:'Especializado em RAG, busca semântica e aplicações empresariais com documentos longos.',url:'https://cohere.com',badge:'Empresarial'},
      {rank:11,name:'Qwen 2.5',org:'Alibaba',desc:'Forte em código e matemática. Open source com versões que rodam localmente com qualidade surpreendente.',url:'https://qwenlm.github.io',badge:'Open Source'},
      {rank:12,name:'Gemma 3',org:'Google',desc:'Modelo leve open source. Ideal para rodar localmente com desempenho acima do esperado para seu tamanho.',url:'https://ai.google.dev/gemma',badge:'Leve · Local'},
    ]},
  ap:{title:'Aprender e Praticar Programação',icon:'📚',
    desc:'Os melhores recursos para aprender programação do zero ao avançado, com foco em prática real e gratuidade.',
    items:[
      {name:'The Odin Project',desc:'Currículo completo e gratuito de web dev. Do zero ao emprego, com projetos reais e comunidade ativa no Discord.',url:'https://theodinproject.com',badge:'Web Dev · Gratuito',hot:1},
      {name:'freeCodeCamp',desc:'Certificações gratuitas em Web, Python, Data Science e Machine Learning com projetos práticos.',url:'https://freecodecamp.org',badge:'Full Stack · Gratuito',hot:1},
      {name:'CS50 — Harvard',desc:'Melhor introdução à Ciência da Computação. Gratuito no edX e muito valorizado pelo mercado.',url:'https://cs50.harvard.edu',badge:'CS Fundamentos'},
      {name:'roadmap.sh',desc:'Mapas visuais completos de carreira: Frontend, Backend, DevOps, IA, Full Stack e mais.',url:'https://roadmap.sh',badge:'Guia de carreira',hot:1},
      {name:'Frontend Mentor',desc:'Desafios reais de frontend com designs prontos para implementar. Excelente para construir portfólio.',url:'https://frontendmentor.io',badge:'Frontend · Desafios'},
      {name:'Exercism',desc:'Exercícios em 70+ linguagens com mentoria humana gratuita. Ótimo para dominar uma nova linguagem.',url:'https://exercism.org',badge:'Prática · Mentoria'},
      {name:'LeetCode',desc:'O padrão ouro para entrevistas técnicas. Algoritmos, estruturas de dados e desafios reais de big techs.',url:'https://leetcode.com',badge:'Algoritmos'},
      {name:'HackerRank',desc:'Desafios de código e preparação para entrevistas. Usado por recrutadores para avaliar candidatos.',url:'https://hackerrank.com',badge:'Entrevistas'},
      {name:'MDN Web Docs',desc:'A referência definitiva para HTML, CSS e JavaScript. Mantido pela Mozilla. Sempre a mais precisa.',url:'https://developer.mozilla.org',badge:'Referência',hot:1},
      {name:'Codecademy',desc:'Cursos interativos para iniciantes em dezenas de linguagens. Interface amigável e feedback instantâneo.',url:'https://codecademy.com',badge:'Iniciantes'},
      {name:'Khan Academy',desc:'Matemática, algoritmos e ciência da computação de forma didática e completamente gratuita.',url:'https://khanacademy.org',badge:'Fundamentos'},
      {name:'dev.to',desc:'Comunidade de devs com artigos, tutoriais e discussões reais de quem está na profissão.',url:'https://dev.to',badge:'Comunidade'},
    ]},
  lib:{title:'Melhores Bibliotecas e Frameworks',icon:'📦',
    desc:'As bibliotecas mais utilizadas e respeitadas pela comunidade, organizadas por área de desenvolvimento.',
    items:[
      {name:'React',desc:'A biblioteca UI mais popular do mundo. Componentes reutilizáveis, hooks e ecossistema imenso.',url:'https://react.dev',badge:'Frontend · JS',hot:1},
      {name:'Vue.js',desc:'Framework progressivo com curva suave. Muito produtivo para projetos pequenos e médios.',url:'https://vuejs.org',badge:'Frontend · JS'},
      {name:'TailwindCSS',desc:'CSS utilitário atomic. Produtividade absurda sem sair do HTML. Padrão em novos projetos.',url:'https://tailwindcss.com',badge:'CSS',hot:1},
      {name:'Next.js',desc:'React com SSR, SSG, rotas de API. O padrão para fullstack moderno com React.',url:'https://nextjs.org',badge:'Fullstack · React',hot:1},
      {name:'Express.js',desc:'Framework minimalista para Node.js. A base de incontáveis APIs REST no mundo.',url:'https://expressjs.com',badge:'Backend · Node'},
      {name:'FastAPI',desc:'APIs Python modernas com validação automática e documentação Swagger embutida.',url:'https://fastapi.tiangolo.com',badge:'Backend · Python',hot:1},
      {name:'Django',desc:'Framework Python completo "batteries included". Ideal para aplicações robustas.',url:'https://djangoproject.com',badge:'Backend · Python'},
      {name:'Prisma',desc:'ORM TypeScript moderno com queries type-safe e migrations automáticas.',url:'https://prisma.io',badge:'ORM · TypeScript'},
      {name:'Three.js',desc:'3D no navegador. WebGL simplificado para animações e experiências imersivas.',url:'https://threejs.org',badge:'3D · WebGL'},
      {name:'D3.js',desc:'Visualização de dados poderosa. SVG + dados = gráficos interativos incríveis.',url:'https://d3js.org',badge:'Data Viz'},
      {name:'Pandas',desc:'Padrão ouro para análise e manipulação de dados em Python. Toda data science usa.',url:'https://pandas.pydata.org',badge:'Data · Python'},
      {name:'TensorFlow',desc:'Biblioteca de Machine Learning e Deep Learning do Google. Usada em pesquisa e produção.',url:'https://tensorflow.org',badge:'AI / ML'},
    ]},
  fer:{title:'Ferramentas de Desenvolvimento',icon:'🛠️',
    desc:'As ferramentas mais utilizadas e recomendadas por desenvolvedores profissionais para produtividade e qualidade.',
    items:[
      {name:'VS Code',desc:'O editor mais popular do mundo. Extensível, leve, gratuito e com ecossistema imenso.',url:'https://code.visualstudio.com',badge:'Editor',hot:1},
      {name:'Git',desc:'Controle de versão essencial. Todo projeto sério usa Git. Aprenda isso primeiro.',url:'https://git-scm.com',badge:'Versionamento',hot:1},
      {name:'Docker',desc:'Containerização que elimina "funciona na minha máquina". Padrão absoluto da indústria.',url:'https://docker.com',badge:'DevOps',hot:1},
      {name:'Postman',desc:'Teste e documentação de APIs. Collections, environments e automação de testes.',url:'https://postman.com',badge:'API Testing'},
      {name:'Figma',desc:'Design de interfaces colaborativo no browser. Padrão de mercado para UI/UX.',url:'https://figma.com',badge:'Design · UI/UX'},
      {name:'GitHub Copilot',desc:'IA no editor para sugestões contextuais. Aumenta produtividade de 30 a 50%.',url:'https://github.com/features/copilot',badge:'IA · Código'},
      {name:'TablePlus',desc:'GUI para bancos de dados: MySQL, PostgreSQL, SQLite. Interface limpa e rápida.',url:'https://tableplus.com',badge:'Banco de dados'},
      {name:'Warp',desc:'Terminal moderno com IA embutida, autocomplete inteligente e UX superior.',url:'https://warp.dev',badge:'Terminal'},
      {name:'Bruno',desc:'Alternativa open source ao Postman, local-first. Sem conta obrigatória.',url:'https://usebruno.com',badge:'API Testing'},
      {name:'Cloudflare',desc:'DNS, CDN, proteção DDoS, Workers e Pages. Plano gratuito generoso.',url:'https://cloudflare.com',badge:'Infraestrutura'},
      {name:'Notion',desc:'Documentação, roadmap e wiki. Excelente para organizar projetos e times.',url:'https://notion.so',badge:'Produtividade'},
      {name:'Vercel',desc:'Deploy de frontend com preview automático por PR. Zero config para Next.js.',url:'https://vercel.com',badge:'Deploy',hot:1},
    ]},
  esc:{title:'Ferramentas de Escalonamento',icon:'📈',
    desc:'Tecnologias para escalar projetos do protótipo à produção com alta disponibilidade e performance.',
    items:[
      {name:'Kubernetes',desc:'Orquestração de containers. Padrão para escalar aplicações em produção com auto-healing.',url:'https://kubernetes.io',badge:'Orquestração',hot:1},
      {name:'Docker Swarm',desc:'Alternativa mais simples ao K8s para clusters menores. Faz parte do Docker.',url:'https://docs.docker.com/engine/swarm',badge:'Orquestração'},
      {name:'Redis',desc:'Cache em memória ultra rápido. Sessions, filas, pub/sub e dados de alta leitura.',url:'https://redis.io',badge:'Cache · Filas',hot:1},
      {name:'Nginx',desc:'Proxy reverso, load balancer e servidor web de alta performance. 34% da web usa.',url:'https://nginx.org',badge:'Load Balancer'},
      {name:'PostgreSQL',desc:'Banco relacional mais avançado do mundo open source. Alta concorrência.',url:'https://postgresql.org',badge:'Banco de dados',hot:1},
      {name:'RabbitMQ',desc:'Message broker para comunicação assíncrona entre microserviços. Confiável.',url:'https://rabbitmq.com',badge:'Mensageria'},
      {name:'Apache Kafka',desc:'Streaming de eventos em tempo real para volumes massivos. LinkedIn e Uber usam.',url:'https://kafka.apache.org',badge:'Streaming'},
      {name:'Terraform',desc:'Infraestrutura como código. Provisione qualquer cloud com arquivos declarativos.',url:'https://terraform.io',badge:'IaC'},
      {name:'Grafana',desc:'Dashboards de monitoramento. Conecta a Prometheus, InfluxDB e dezenas de fontes.',url:'https://grafana.com',badge:'Monitoramento'},
      {name:'Railway',desc:'Deploy de backends e bancos com zero configuração. Muito mais simples que AWS.',url:'https://railway.app',badge:'Deploy',hot:1},
      {name:'Cloudflare Workers',desc:'Edge computing: código roda próximo ao usuário em +300 localidades mundo.',url:'https://workers.cloudflare.com',badge:'Edge'},
      {name:'Coolify',desc:'Self-hosted alternativo ao Heroku. Deploy na sua VPS com interface visual.',url:'https://coolify.io',badge:'Self-hosted'},
    ]},
  rep:{title:'Repositórios GitHub por Área',icon:'🐙',
    desc:'Os melhores repositórios do GitHub por tipo de programação para aprender, usar e se inspirar.',
    items:[
      {name:'shadcn/ui',desc:'Componentes React copiáveis com Radix UI e Tailwind. Padrão de componentes modernos 2024/25.',url:'https://github.com/shadcn-ui/ui',badge:'Frontend · React',hot:1},
      {name:'vercel/next.js',desc:'Código fonte do Next.js. Excelente para aprender padrões de framework fullstack moderno.',url:'https://github.com/vercel/next.js',badge:'Fullstack'},
      {name:'tiangolo/fastapi',desc:'FastAPI: código limpo, bem documentado. Ótimo para aprender boas práticas em Python.',url:'https://github.com/tiangolo/fastapi',badge:'Backend · Python'},
      {name:'nestjs/nest',desc:'Framework Node.js modular. Padrão enterprise em TypeScript.',url:'https://github.com/nestjs/nest',badge:'Backend · Node'},
      {name:'huggingface/transformers',desc:'Biblioteca de modelos de IA: BERT, GPT, LLaMA e centenas de outros modelos.',url:'https://github.com/huggingface/transformers',badge:'AI / ML',hot:1},
      {name:'ollama/ollama',desc:'Rode LLMs localmente com um comando. LLaMA, Mistral, Gemma e muito mais.',url:'https://github.com/ollama/ollama',badge:'IA · Local',hot:1},
      {name:'langchain-ai/langchain',desc:'Framework para apps com LLMs. Agentes, RAG, chains e ferramentas de AI Engineering.',url:'https://github.com/langchain-ai/langchain',badge:'AI · LLM'},
      {name:'public-apis/public-apis',desc:'Lista curada de APIs públicas gratuitas para qualquer tipo de projeto.',url:'https://github.com/public-apis/public-apis',badge:'APIs',hot:1},
      {name:'airbnb/javascript',desc:'Guia de estilo JavaScript do Airbnb. Padrão de qualidade de código em centenas de empresas.',url:'https://github.com/airbnb/javascript',badge:'JavaScript'},
      {name:'EbookFoundation/free-programming-books',desc:'Livros de programação gratuitos em dezenas de idiomas e linguagens.',url:'https://github.com/EbookFoundation/free-programming-books',badge:'Aprendizado'},
      {name:'awesome-selfhosted',desc:'Apps que você pode hospedar no seu servidor. Alternativas open source para qualquer serviço pago.',url:'https://github.com/awesome-selfhosted/awesome-selfhosted',badge:'Self-hosted'},
      {name:'sindresorhus/awesome',desc:'A lista das listas awesome. Ponto de partida para qualquer recurso em qualquer área.',url:'https://github.com/sindresorhus/awesome',badge:'Curadoria'},
    ]},
  tec:{title:'Técnicas de Aprendizado para Programação',icon:'🧠',
    desc:'Métodos comprovados para aprender programação de forma mais eficiente, profunda e duradoura.',
    items:[
      {name:'Técnica Feynman',desc:'Explique o conceito como se fosse ensinar para uma criança. Onde você travar é onde está a lacuna de entendimento.',url:'https://fs.blog/feynman-learning-technique',badge:'Compreensão',hot:1},
      {name:'Projeto-Primeiro',desc:'Escolha o que quer construir ANTES de aprender. O projeto guia e mantém a motivação quando fica difícil.',url:'https://www.freecodecamp.org/news/project-based-learning',badge:'Motivação · Prático',hot:1},
      {name:'Repetição Espaçada',desc:'Use Anki. Revise no intervalo certo antes de esquecer. Cientificamente comprovado para retenção longa.',url:'https://apps.ankiweb.net',badge:'Memória · Longo prazo'},
      {name:'Rubber Duck Debugging',desc:'Explique o problema em voz alta para um objeto. O ato de articular frequentemente revela a solução.',url:'https://rubberduckdebugging.com',badge:'Debugging'},
      {name:'Leitura de Código Real',desc:'Leia projetos open source no GitHub. Não há forma melhor de aprender padrões que código de engenheiros sêniors.',url:'https://github.com/explore',badge:'Avançado'},
      {name:'Build → Break → Fix',desc:'Construa algo, quebre de propósito e conserte. Entender falhas é tão valioso quanto construir.',url:'https://dev.to',badge:'Experimentação'},
      {name:'Regra dos 20 Minutos',desc:'Tente resolver sozinho por 20 min antes de buscar ajuda. Esse esforço desenvolve capacidade real de pensar.',url:'https://roadmap.sh',badge:'Autonomia'},
      {name:'Code Review Ativo',desc:'Revise PRs públicos no GitHub. Você aprende padrões que livros não ensinam e desenvolve visão crítica.',url:'https://github.com',badge:'Colaboração'},
      {name:'Ensine Para Aprender',desc:'Escreva posts, faça tutoriais, explique no Discord. Quem ensina consolida o aprendizado de forma irreversível.',url:'https://dev.to',badge:'Comunidade',hot:1},
      {name:'Pomodoro + Código',desc:'25min de foco total, 5min de pausa. Evita burnout e mantém concentração alta em sessões longas.',url:'https://pomofocus.io',badge:'Produtividade'},
      {name:'Leia a Documentação Oficial',desc:'Devs mediocres fogem da doc. Devs bons a leem primeiro. É sempre a mais precisa e atualizada.',url:'https://developer.mozilla.org',badge:'Disciplina'},
      {name:'Refatoração Deliberada',desc:'Volte a projetos antigos e melhore com o que aprendeu. Identificar essa diferença é a melhor medida de crescimento.',url:'https://refactoring.com',badge:'Qualidade'},
    ]},
};

/* ─── ICON MAP ─── */
const ICONS_MAP={
  'claude.ai':'🤖','openai.com':'🧠','chat.openai.com':'🧠','github.com':'🐙',
  'codepen.io':'✏️','mail.google.com':'📧','google.com':'🔍','youtube.com':'▶️',
  'discord.com':'💬','slack.com':'💼','notion.so':'📝','vercel.com':'▲',
  'railway.app':'🚂','hostoo.io':'🚀','figma.com':'🎨','localhost':'🏠',
  '127.0.0.1':'🏠','stackoverflow.com':'💡','npmjs.com':'📦','twitter.com':'🐦','x.com':'🐦',
};
function dicon(url){
  if(!url)return'🔗';
  try{const h=new URL(url).hostname.replace(/^www\./,'');
    for(const[k,v]of Object.entries(ICONS_MAP))if(h.includes(k))return v;
  }catch{}return'🔗';
}

/* ─── DOCK ─── */
function renderDock(){
  const c=document.getElementById('pills');c.innerHTML='';let n=0;
  CFG.shortcuts.forEach(s=>{
    if(!s.name||!s.url)return;
    const a=document.createElement('a');
    a.className='pill';a.href=s.url;a.target='_blank';a.rel='noopener noreferrer';
    a.innerHTML=`<span>${dicon(s.url)}</span>${esc(s.name)}`;
    c.appendChild(a);n++;
  });
  if(!n){const sp=document.createElement('span');
    sp.style.cssText='font-size:.7rem;color:var(--muted2);font-family:var(--fm)';
    sp.textContent='Nenhum atalho configurado — adicione em ⚙️ Config';c.appendChild(sp);}
}

/* ─── SETTINGS ─── */
function renderSCRows(){
  const c=document.getElementById('sc-rows');c.innerHTML='';
  CFG.shortcuts.forEach((s,i)=>{
    const r=document.createElement('div');r.className='sc-row';
    r.innerHTML=`<span class="sc-num">${String(i+1).padStart(2,'0')}</span>
      <input class="finp" style="font-size:.72rem;padding:6px 9px" id="sn${i}" type="text" placeholder="Nome" maxlength="28" value="${esc(s.name||'')}">
      <input class="finp" style="font-size:.72rem;padding:6px 9px" id="su${i}" type="url"  placeholder="https://" value="${esc(s.url||'')}">`;
    c.appendChild(r);
  });
}
function openSett(){
  document.getElementById('cfg-name').value=CFG.name||'';
  document.getElementById('cfg-gh').value=CFG.github||'';
  document.getElementById('cfg-cp').value=CFG.codepen||'';
  renderSCRows();
  document.getElementById('sovl').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeSett(){document.getElementById('sovl').classList.remove('open');document.body.style.overflow='';}
document.getElementById('sovl').addEventListener('click',e=>{if(e.target===document.getElementById('sovl'))closeSett();});

async function saveSett(){
  const shortcuts=[];
  for(let i=0;i<20;i++)
    shortcuts.push({name:document.getElementById(`sn${i}`)?.value.trim()||'',
                    url:document.getElementById(`su${i}`)?.value.trim()||''});
  const pl={action:'save_settings',
    name:document.getElementById('cfg-name').value.trim(),
    github:document.getElementById('cfg-gh').value.trim(),
    codepen:document.getElementById('cfg-cp').value.trim(),shortcuts};
  try{
    const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(pl)});
    const d=await r.json();
    if(d.ok){CFG=d.settings;applySettings();closeSett();toast('Configurações salvas! ✨','💾');}
    else toast('Erro ao salvar. Verifique permissões.','⚠️');
  }catch{toast('Erro de rede ao salvar.','⚠️');}
}
function applySettings(){
  document.getElementById('hw').textContent=CFG.name?`Bem-vindo, ${CFG.name} 👾`:'Bem-vindo ao Project Hub 👾';
  document.title=CFG.name?`Project Hub · ${CFG.name}`:'Project Hub';
  renderDock();
}

/* ─── TABS ─── */
function switchTab(t){
  document.getElementById('tabp').style.display=t==='p'?'block':'none';
  document.getElementById('tabr').style.display=t==='r'?'block':'none';
  document.getElementById('tbp').classList.toggle('on',t==='p');
  document.getElementById('tbr').classList.toggle('on',t==='r');
  if(t==='r'&&!curRes)switchRes('ias',document.querySelector('.rtab'));
}

/* ─── RESOURCES ─── */
let curRes=null;
function switchRes(k,btn){
  document.querySelectorAll('.rtab').forEach(b=>b.classList.remove('on'));
  if(btn)btn.classList.add('on');
  if(curRes===k)return;curRes=k;
  const r=RES[k];
  let h=`<div class="res-hd">${r.icon} ${r.title}</div><div class="res-desc">${r.desc}</div><div class="rgrid">`;
  r.items.forEach(item=>{
    const bc=item.hot?'rbadge hot':'rbadge';
    const rank=item.rank?`<span class="rrank">#${item.rank}</span>`:'';
    h+=`<div class="rcard">
      <div class="rcard-top"><div class="rname">${esc(item.name)}</div>${rank}</div>
      ${item.org?`<div class="rorg">${esc(item.org)}</div>`:''}
      <div class="rdesc">${esc(item.desc)}</div>
      <div class="rfoot"><span class="${bc}">${esc(item.badge)}${item.hot?' 🔥':''}</span>
        <a class="rlink" href="${esc(item.url)}" target="_blank" rel="noopener">🔗 Acessar →</a>
      </div></div>`;
  });
  h+='</div>';
  document.getElementById('rcont').innerHTML=h;
}

/* ─── WEATHER ─── */
const WXI={113:'☀️',116:'⛅',119:'☁️',122:'☁️',143:'🌫️',176:'🌦️',179:'🌨️',
  182:'🌧️',185:'🌧️',200:'⛈️',227:'🌨️',230:'❄️',248:'🌫️',260:'🌫️',
  263:'🌦️',266:'🌧️',281:'🌧️',284:'🌧️',293:'🌦️',296:'🌦️',299:'🌧️',
  302:'🌧️',305:'🌧️',308:'🌧️',311:'🌧️',314:'🌧️',317:'🌨️',320:'🌨️',
  323:'❄️',326:'❄️',329:'❄️',332:'❄️',335:'❄️',338:'❄️',350:'🌧️',
  353:'🌦️',356:'🌧️',359:'🌧️',362:'🌨️',365:'🌨️',368:'🌨️',371:'❄️',
  374:'🌨️',377:'🌨️',386:'⛈️',389:'⛈️',392:'⛈️',395:'❄️'};
const DPT=['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];

(async()=>{
  try{
    const r=await fetch('https://wttr.in/?format=j1&lang=pt');
    const d=await r.json();
    const area=d.nearest_area?.[0];
    const city=area?.areaName?.[0]?.value||'—';
    const country=area?.country?.[0]?.value||'';
    const cc=d.current_condition?.[0];
    const code=parseInt(cc?.weatherCode||113);
    const icon=WXI[code]||'🌤️';
    const temp=cc?.temp_C||'—';
    const fl=cc?.FeelsLikeC||'—';
    const hum=cc?.humidity||'—';
    const wind=cc?.windspeedKmph||'—';
    const desc=(cc?.lang_pt?.[0]?.value||cc?.weatherDesc?.[0]?.value||'');
    document.getElementById('wxsub').textContent=`${city}${country?', '+country:''}`;
    document.getElementById('wxb').innerHTML=`
      <div class="wx-loc">${city}${country?', '+country:''}</div>
      <div class="wx-main">
        <div class="wx-icon">${icon}</div>
        <div><div class="wx-temp">${temp}<sup>°C</sup></div><div class="wx-desc">${desc}</div></div>
      </div>
      <div class="wx-dets">
        <span class="wx-det">💧 <strong>${hum}%</strong></span>
        <span class="wx-det">🌬️ <strong>${wind} km/h</strong></span>
        <span class="wx-det">🌡️ Sensação: <strong>${fl}°C</strong></span>
      </div>`;
    const fc=d.weather?.slice(0,3).map(day=>{
      const dt=new Date(day.date);
      const dn=DPT[dt.getDay()];
      const fi=WXI[parseInt(day.hourly?.[4]?.weatherCode||113)]||'🌤️';
      return`<div class="fc-day"><div class="fc-dn">${dn}</div><div class="fc-di">${fi}</div>
        <div class="fc-hi">${day.maxtempC}°</div><div class="fc-lo">${day.mintempC}°</div></div>`;
    }).join('')||'';
    document.getElementById('fcb').innerHTML=`<div class="fc-grid">${fc}</div>`;
  }catch{
    document.getElementById('wxb').innerHTML='<div style="font-size:.74rem;color:var(--muted)">Não foi possível carregar o clima.</div>';
    document.getElementById('fcb').innerHTML='<div style="font-size:.74rem;color:var(--muted)">Previsão indisponível.</div>';
    document.getElementById('wxsub').textContent='Indisponível';
  }
})();

/* ─── GLOBAL IP ─── */
(async()=>{
  try{const r=await fetch('https://api.ipify.org?format=json');
    const d=await r.json();document.getElementById('gip').textContent=d.ip;}
  catch{document.getElementById('gip').textContent='indisponível';}
})();

/* ─── QUOTES ─── */
const QS=[
  {t:'Programar não é sobre digitar código. É sobre entender um problema tão bem que você consegue ensiná-lo para uma máquina.',a:'— Princípio da computação'},
  {t:'O melhor código que já escrevi foi aquele que deletei. Simplicidade é o resultado de muita luta.',a:'— Sabedoria de sêniors'},
  {t:'Você não aprende a programar lendo sobre programação. Aprende errando, debugando e errando de novo.',a:'— Experiência real'},
  {t:'Todo sistema complexo que funciona evoluiu de um sistema simples que funcionava. Comece simples.',a:'— John Gall'},
  {t:'A diferença entre um dev junior e um sênior não é o que eles sabem. É o quanto cada um sabe que não sabe.',a:'— Reflexão da carreira'},
  {t:'Resolver um bug às 2h da manhã e ver aquele teste passar é uma das sensações mais satisfatórias que um programador pode sentir.',a:'— Toda pessoa dev'},
  {t:'Seu código de hoje vai envergonhar você daqui a dois anos. Isso significa que você está crescendo.',a:'— Mentalidade de evolução'},
  {t:'A habilidade mais subestimada em programação é saber nomear variáveis. Código é lido mais do que escrito.',a:'— Clean Code'},
  {t:'Cada erro que você não entende é uma vulnerabilidade esperando para aparecer em produção.',a:'— Cultura de debugging'},
  {t:'Stack Overflow não te torna um programador pior. Saber o que pesquisar é uma habilidade em si.',a:'— Comunidade dev'},
  {t:'Não existe código perfeito. Existe código que funciona agora e código que vai precisar de refatoração depois.',a:'— Pragmatismo'},
  {t:'Construir algo do zero e ver funcionar no browser é o tipo de magia que nunca cansa.',a:'— Dev frontend'},
];
let qi=Math.floor(Math.random()*QS.length);
function renderQ(){const q=QS[qi];document.getElementById('qtxt').textContent=q.t;document.getElementById('qauth').textContent=q.a;}
function nextQ(){qi=(qi+1)%QS.length;renderQ();}

/* ─── TOAST ─── */
let _tt;
function toast(msg,icon='✓'){
  clearTimeout(_tt);
  document.getElementById('ti').textContent=icon;
  document.getElementById('tm').textContent=msg;
  document.getElementById('toast').classList.add('show');
  _tt=setTimeout(()=>document.getElementById('toast').classList.remove('show'),2800);
}

/* ─── UTILS ─── */
function copyLink(url){
  navigator.clipboard.writeText(url).then(()=>toast('Link copiado!','📋')).catch(()=>{
    const ta=document.createElement('textarea');ta.value=url;document.body.appendChild(ta);
    ta.select();document.execCommand('copy');document.body.removeChild(ta);toast('Link copiado!','📋');
  });
}
function copyHub(){copyLink(window.location.href.split('?')[0]);closeSK();}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

/* ─── OPEN FOLDER ─── */
async function openFolder(name){
  try{
    const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},
                           body:JSON.stringify({action:'open_folder',project:name})});
    const d=await r.json();
    if(d.ok) toast('Pasta aberta no explorador!','📂');
    else toast('Erro: '+( d.error||'falha ao abrir'),'⚠️');
  }catch{ toast('Erro ao comunicar com o servidor.','⚠️'); }
}

/* ─── MODAL ─── */
function renderExt(p){
  document.getElementById('mtitle').textContent='📁 '+p.name;
  document.getElementById('msub').textContent='Análise completa — todos os subdiretórios';
  document.getElementById('mtot').textContent=p.fileCount>2000?'2000+':p.fileCount;
  const entries=Object.entries(p.extensions||{}).filter(([k])=>k!=='_truncated');
  document.getElementById('mtyp').textContent=entries.length;
  const ana=document.getElementById('mana');
  ana.innerHTML=p.limited?'<p style="color:var(--orange);font-size:.7rem;margin-bottom:8px">⚠️ Projeto muito grande — mostrando os primeiros 2000 arquivos.</p>':'';
  if(!entries.length){ana.innerHTML+='<p style="color:var(--muted);font-size:.76rem">Nenhum arquivo encontrado.</p>';return;}
  const mx=Math.max(...entries.map(([,v])=>v),1);
  entries.forEach(([ext,cnt])=>{
    const pct=Math.round((cnt/mx)*100);
    const row=document.createElement('div');row.className='erow';
    row.innerHTML=`<div class="erow-hd"><span class="erow-name etag ${ext}" style="background:none;border:none;padding:0">.${ext}</span>
      <span class="erow-cnt">${cnt} arquivo${cnt!==1?'s':''}</span></div>
      <div class="ebar-w"><div class="ebar" style="width:0%" data-p="${pct}%"></div></div>`;
    ana.appendChild(row);
  });
  requestAnimationFrame(()=>document.querySelectorAll('.ebar').forEach(b=>b.style.width=b.dataset.p));
}

async function openModal(idx){
  const p=PROJS[idx];
  document.getElementById('mtitle').textContent='📁 '+p.name;
  document.getElementById('msub').textContent='Escaneando arquivos…';
  document.getElementById('mtot').textContent='…';
  document.getElementById('mtyp').textContent='…';
  document.getElementById('mana').innerHTML='<p style="color:var(--muted);font-size:.76rem">🔍 Analisando estrutura…</p>';
  document.getElementById('movl').classList.add('open');
  document.body.style.overflow='hidden';
  try{
    const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},
                           body:JSON.stringify({action:'deep_scan',project:p.name})});
    const d=await r.json();
    if(d.error)throw new Error(d.error);
    renderExt(d);
  }catch(e){
    document.getElementById('mana').innerHTML=`<p style="color:var(--red);font-size:.76rem">Erro: ${e.message}</p>`;
  }
}
function closeModal(){document.getElementById('movl').classList.remove('open');document.body.style.overflow='';}
document.getElementById('movl').addEventListener('click',e=>{if(e.target===document.getElementById('movl'))closeModal();});

function openSK() {document.getElementById('skovl').classList.add('open');document.body.style.overflow='hidden';}
function closeSK(){document.getElementById('skovl').classList.remove('open');document.body.style.overflow='';}
document.getElementById('skovl').addEventListener('click',e=>{if(e.target===document.getElementById('skovl'))closeSK();});

document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal();closeSett();closeSK();}});

/* ─── INIT ─── */
renderQ();
renderDock();
document.querySelectorAll('.pcard').forEach((c,i)=>{
  c.style.opacity='0';c.style.animation=`fadeUp .48s ease ${.04+i*.04}s forwards`;
});
</script>
</body>
</html>
