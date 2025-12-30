<?php

/**
 * 超轻量级PHP聊天系统
 * 
 * @version 0.0.2
 * @build 2025-12-29
 * @author Nickelodeon994
 * @link https://github.com/Nickelodeon994/Lightweight_PHP_Chat-Outpost-Chat
 * @license Apache-2.0
 * 
 * 更新日志：
 * - 0.0.1 (2025-12-28) 初始版本
 *   * 基础聊天功能
 *   * AI机器人集成(@哨哨)
 *   * 媒体文件支持(图片/视频)
 *   * 用户审批系统
 *   * 称号系统
 *
  * - 0.0.2 (2025-12-29) 
 *   * 仅对css部分进行优化
 *   * 标题现为Open·Chat
 */


/**
 * Copyright 2025 Nickelodeon994
 * 
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * 
 *     http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);
session_start();

const DATA_DIR = __DIR__ . '/data';
const MEDIA_DIR = DATA_DIR . '/media';
const USERS_FILE = DATA_DIR . '/users.json';
const CHATS_FILE = DATA_DIR . '/chats.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const SESSIONS_FILE = DATA_DIR . '/sessions.json';

const APP_NAME = 'Open·Chat';  
const DEFAULT_CHAT_HISTORY = 100;
const MAX_UPLOAD_SIZE = 10 * 1024 * 1024; 
const ALLOWED_IMAGE_EXT = ['jpg','jpeg','png','gif','webp'];
const ALLOWED_VIDEO_EXT = ['mp4','webm','ogg'];

$DEEPSEEK_API_KEY = 'sk-1145141919810';  //在这里填入你的API key
$DEEPSEEK_API_URL = 'https://api.deepseek.com/v1/chat/completions';  //默认为deepseek

const BOT_UID = 'BOT_SHAO';
const BOT_NAME = '哨哨';
const BOT_AVATAR = '/Outpost.png';  //机器人的头像

function ensure_setup(): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0775, true);
    $files = [
        USERS_FILE => [],
        CHATS_FILE => [],
        SETTINGS_FILE => [
            'chat_history_limit' => DEFAULT_CHAT_HISTORY,
            'allow_register' => true,
            'site_title' => APP_NAME,
            'theme' => 'auto',
            'bot_enabled' => true,
            'bot_context' => 5
        ],
        SESSIONS_FILE => []
    ];
    foreach ($files as $path => $init) {
        if (!file_exists($path)) file_put_contents($path, json_encode($init, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
ensure_setup();

function read_json(string $path): array {
    $fp = fopen($path, 'c+'); if (!$fp) return [];
    flock($fp, LOCK_SH); $content = stream_get_contents($fp); flock($fp, LOCK_UN); fclose($fp);
    if (!$content) return [];
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}
function write_json(string $path, array $data): bool {
    $fp = fopen($path, 'c+'); if (!$fp) return false;
    flock($fp, LOCK_EX); ftruncate($fp, 0);
    $ok = fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    fflush($fp); flock($fp, LOCK_UN); fclose($fp); return $ok;
}
function uid(): string { return strtoupper(bin2hex(random_bytes(4))); }
function now_ts(): int { return time(); }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function media_safe_name(string $original): string {
    $ext = pathinfo($original, PATHINFO_EXTENSION);
    $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    return date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
}
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $users = read_json(USERS_FILE);
    foreach ($users as $u) if (($u['uid'] ?? '') === $_SESSION['uid']) return $u;
    return null;
}
function save_user(array $user): bool {
    $users = read_json(USERS_FILE);
    $found = false;
    foreach ($users as &$u) {
        if (($u['uid'] ?? '') === $user['uid']) { $u = $user; $found = true; break; }
    }
    if (!$found) $users[] = $user;
    return write_json(USERS_FILE, $users);
}
function user_by_name(string $name): ?array {
    $users = read_json(USERS_FILE);
    foreach ($users as $u) if (mb_strtolower($u['username'] ?? '') === mb_strtolower($name)) return $u;
    return null;
}
function is_admin(?array $u): bool { return !empty($u) && (($u['role'] ?? '') === 'admin'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf(): bool {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}
function route(string $k, $d = '') { return $_GET[$k] ?? $_POST[$k] ?? $d; }

function ext_of_filename(string $name): string {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');
}
function is_image_file(string $tmpPath): bool {
    return @getimagesize($tmpPath) !== false;
}
function detect_media_type_by_upload(array $file): ?array {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    $ext = ext_of_filename($file['name']);
    if (is_image_file($file['tmp_name']) && in_array($ext, ALLOWED_IMAGE_EXT, true)) {
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? ($info['mime'] ?? 'image/' . $ext) : ('image/' . $ext);
        return ['type'=>'image','ext'=>$ext,'mime'=>$mime];
    }
    if (in_array($ext, ALLOWED_VIDEO_EXT, true)) {
        $mimeMap = ['mp4'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg'];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        return ['type'=>'video','ext'=>$ext,'mime'=>$mime];
    }
    return null;
}
function mime_by_extension(string $filename): string {
    $ext = ext_of_filename($filename);
    $map = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
        'mp4'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg'
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

if (route('action') === 'media') {
    $file = basename(route('file'));
    $path = MEDIA_DIR . '/' . $file;
    if (!is_file($path)) { 
        http_response_code(404); 
        echo 'Not found'; 
        exit; 
    }
    
    $mime = mime_by_extension($path);
    $filesize = filesize($path);
    $lastModified = filemtime($path);
    $etag = '"' . md5($file . $lastModified) . '"'; 
    
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $filesize);
    header('Cache-Control: public, max-age=31536000, immutable'); // 一年缓存
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    header('ETag: ' . $etag);
    
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    
    if ($ifNoneMatch === $etag || 
        ($ifModifiedSince && strtotime($ifModifiedSince) >= $lastModified)) {
        http_response_code(304); // Not Modified
        exit;
    }
    
    readfile($path);
    exit;
}


function call_deepseek(string $apiKey, string $apiUrl, array $messages, int $max_tokens = 512): ?string {
    $payload = [
        'model' => 'deepseek-chat',
        'messages' => $messages,
        'max_tokens' => $max_tokens,
        'temperature' => 0.8,
    ];
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        @file_put_contents(DATA_DIR . '/deepseek_error.log', date('c') . " HTTP {$code} ERR: {$err}\nRESP: {$resp}\n\n", FILE_APPEND);
        return null;
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) return null;
    if (!empty($data['choices'][0]['message']['content'])) {
        return trim($data['choices'][0]['message']['content']);
    }
    if (!empty($data['choices'][0]['text'])) {
        return trim($data['choices'][0]['text']);
    }
    return null;
}

function contains_bot_trigger(string $text): bool {
    return mb_strpos($text, '@哨哨') !== false || mb_strpos($text, '＠哨哨') !== false;
}
function get_bot_context(int $n): array {
    $all = read_json(CHATS_FILE);
    $collected = [];
    for ($i = count($all) - 1; $i >= 0 && count($collected) < $n; $i--) {
        $c = $all[$i];
        $role = ($c['uid'] ?? '') === BOT_UID ? 'assistant' : 'user';
        $text = trim(($c['text'] ?? '') . (isset($c['media']) ? ' [媒体]' : ''));
        if ($text === '') continue;
        array_unshift($collected, [
            'role' => $role,
            'content' => ($c['nickname'] ?? ($role === 'assistant' ? BOT_NAME : '用户')) . '：' . $text
        ]);
    }
    $messages = [];
    $messages[] = [
        'role' => 'system',
        'content' => '你是一个名为“哨哨”的中文聊天机器人，语气友好、简洁、有帮助，能根据上下文给出相关回复。'
    ];
    foreach ($collected as $m) {
        $messages[] = ['role' => $m['role'], 'content' => $m['content']];
    }
    return $messages;
}

$action = route('action', 'home');
$user = current_user();

if ($action === 'logout') {
    session_destroy(); header('Location: ?action=home'); exit;
}

if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $settings = read_json(SETTINGS_FILE);
    if (!(bool)($settings['allow_register'] ?? true)) {
        $msg = '注册服务维护中，敬请期待…';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        if ($username === '' || $password === '' || $nickname === '') {
            $msg = '请完整填写信息';
        } elseif (user_by_name($username)) {
            $msg = '用户名已存在';
        } else {
            $u = [
                'uid' => uid(),
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'nickname' => $nickname,
                'avatar' => '',
                'title_active' => '',
                'titles' => [],
                'role' => 'user',
                'status' => 'pending',
                'created_at' => now_ts(),
            ];
            save_user($u);
            $_SESSION['uid'] = $u['uid'];
            header('Location: ?action=home&welcome=pending'); exit;
        }
    }
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $u = user_by_name($username);
    if (!$u || !password_verify($password, $u['password'] ?? '')) {
        $msg = '用户名或密码错误';
    } else {
        $_SESSION['uid'] = $u['uid'];
        header('Location: ?action=chat'); exit;
    }
}

if ($action === 'profile_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) { header('Location: ?action=home'); exit; }
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $nickname = trim($_POST['nickname'] ?? '');
    if ($nickname !== '') $user['nickname'] = mb_substr($nickname, 0, 32);
    if (!empty($_FILES['avatar']['name'])) {
        $file = $_FILES['avatar'];
        if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= MAX_UPLOAD_SIZE) {
            $det = detect_media_type_by_upload($file);
            if ($det && $det['type'] === 'image') {
                $fname = media_safe_name($file['name']);
                move_uploaded_file($file['tmp_name'], MEDIA_DIR . '/' . $fname);
                $user['avatar'] = $fname;
            } else {
                $msg = '头像仅支持图片格式（jpg/png/gif/webp）';
            }
        } else {
            $msg = '头像上传失败或文件过大';
        }
    }
    save_user($user);
    header('Location: ?action=me&updated=1'); exit;
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) { header('Location: ?action=home'); exit; }
    if (($user['status'] ?? '') !== 'approved') { header('Location: ?action=home'); exit; }
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }

    $text = trim($_POST['text'] ?? '');
    $media = null;
    if (!empty($_FILES['media']['name'])) {
        $file = $_FILES['media'];
        if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= MAX_UPLOAD_SIZE) {
            $det = detect_media_type_by_upload($file);
            if ($det) {
                $fname = media_safe_name($file['name']);
                move_uploaded_file($file['tmp_name'], MEDIA_DIR . '/' . $fname);
                $media = ['type' => $det['type'], 'file' => $fname, 'mime' => $det['mime']];
            } else {
                $msg = '不支持的媒体类型：仅支持部分图片或视频';
            }
        } else {
            $msg = '媒体上传失败或文件过大';
        }
    }
    if ($text === '' && !$media) {
        $msg = '发送内容不能为空';
    } else {
        $chats = read_json(CHATS_FILE);
        $chats[] = [
            'id' => bin2hex(random_bytes(8)),
            'uid' => $user['uid'],
            'nickname' => $user['nickname'],
            'avatar' => $user['avatar'] ?? '',
            'title' => $user['title_active'] ?? '',
            'text' => $text,
            'media' => $media,
            'ts' => now_ts(),
        ];
        write_json(CHATS_FILE, $chats);

        if (contains_bot_trigger($text)) {
            $settings = read_json(SETTINGS_FILE);
            $bot_enabled = (bool)($settings['bot_enabled'] ?? true);
            $bot_context = max(1, (int)($settings['bot_context'] ?? 5));
            if (!$bot_enabled) {
                $reply = '哨哨正在维护，敬请期待～';
                $chats = read_json(CHATS_FILE);
                $chats[] = [
                    'id' => bin2hex(random_bytes(8)),
                    'uid' => BOT_UID,
                    'nickname' => BOT_NAME,
                    'avatar' => BOT_AVATAR,
                    'title' => '',
                    'text' => $reply,
                    'media' => null,
                    'ts' => now_ts(),
                ];
                write_json(CHATS_FILE, $chats);
            } else {
                $context_messages = get_bot_context($bot_context);
                $user_prompt = str_replace(['@哨哨','＠哨哨'], '', $text);
                $context_messages[] = ['role' => 'user', 'content' => $user['nickname'] . '：' . $user_prompt];
                global $DEEPSEEK_API_KEY, $DEEPSEEK_API_URL;
                $ai_reply = call_deepseek($DEEPSEEK_API_KEY, $DEEPSEEK_API_URL, $context_messages, 512);
                if ($ai_reply === null) {
                    $ai_reply = '服务器繁忙，请稍候再试';
                }
                $chats = read_json(CHATS_FILE);
                $chats[] = [
                    'id' => bin2hex(random_bytes(8)),
                    'uid' => BOT_UID,
                    'nickname' => BOT_NAME,
                    'avatar' => BOT_AVATAR,
                    'title' => '',
                    'text' => $ai_reply,
                    'media' => null,
                    'ts' => now_ts(),
                ];
                write_json(CHATS_FILE, $chats);
            }
        }

        header('Location: ?action=chat'); exit;
    }
}

if ($action === 'toggle_title' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) { header('Location: ?action=home'); exit; }
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $title = trim($_POST['title'] ?? '');
    $use = (bool)($_POST['use'] ?? false);
    $owned = $user['titles'] ?? [];
    if ($use && in_array($title, $owned, true)) $user['title_active'] = $title;
    elseif (!$use && ($user['title_active'] ?? '') === $title) $user['title_active'] = '';
    save_user($user);
    header('Location: ?action=my_titles'); exit;
}

function require_admin(): array {
    $u = current_user();
    if (!$u || !is_admin($u)) { header('Location: ?action=admin_login'); exit; }
    return $u;
}
if ($action === 'admin_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $u = user_by_name($username);
    if ($u && password_verify($password, $u['password'] ?? '') && is_admin($u)) {
        $_SESSION['uid'] = $u['uid'];
        header('Location: ?action=admin'); exit;
    } else {
        $msg = '管理员认证失败';
    }
}
if ($action === 'admin_approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $uidTarget = trim($_POST['uid'] ?? '');
    $users = read_json(USERS_FILE);
    foreach ($users as &$u) {
        if (($u['uid'] ?? '') === $uidTarget) { $u['status'] = 'approved'; break; }
    }
    write_json(USERS_FILE, $users);
    header('Location: ?action=admin&ok=1'); exit;
}
if ($action === 'admin_title' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $uidTarget = trim($_POST['uid'] ?? '');
    $title = trim($_POST['title'] ?? '');
    if ($title !== '') {
        $users = read_json(USERS_FILE);
        foreach ($users as &$u) {
            if (($u['uid'] ?? '') === $uidTarget) {
                $u['titles'] = array_values(array_unique(array_merge($u['titles'] ?? [], [$title])));
                break;
            }
        }
        write_json(USERS_FILE, $users);
    }
    header('Location: ?action=admin&ok=1'); exit;
}
if ($action === 'admin_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $limit = (int)($_POST['chat_history_limit'] ?? DEFAULT_CHAT_HISTORY);
    $allow = (bool)($_POST['allow_register'] ?? false);
    $title = trim($_POST['site_title'] ?? APP_NAME);
    $theme = trim($_POST['theme'] ?? 'auto');
    $bot_enabled = (bool)($_POST['bot_enabled'] ?? false);
    $bot_context = max(1, (int)($_POST['bot_context'] ?? 5));
    $settings = read_json(SETTINGS_FILE);
    $settings['chat_history_limit'] = max(10, min(1000, $limit));
    $settings['allow_register'] = $allow;
    $settings['site_title'] = $title !== '' ? $title : APP_NAME;
    $settings['theme'] = in_array($theme, ['auto','light','dark'], true) ? $theme : 'auto';
    $settings['bot_enabled'] = $bot_enabled;
    $settings['bot_context'] = $bot_context;
    write_json(SETTINGS_FILE, $settings);
    header('Location: ?action=admin&ok=1'); exit;
}

if ($action === 'chats') {
    if (!$user || ($user['status'] ?? '') !== 'approved') { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
    $since = (int)($_GET['since'] ?? 0);
    $limit = (int)(read_json(SETTINGS_FILE)['chat_history_limit'] ?? DEFAULT_CHAT_HISTORY);
    $all = read_json(CHATS_FILE);
    $slice = array_slice($all, max(0, count($all) - $limit));
    $res = [];
    foreach ($slice as $c) {
        if ($since === 0 || (int)($c['ts'] ?? 0) > $since) $res[] = $c;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['messages'=>$res, 'latest_ts'=> (int)(end($slice)['ts'] ?? 0)], JSON_UNESCAPED_UNICODE);
    exit;
}

$settings = read_json(SETTINGS_FILE);
$limit = (int)($settings['chat_history_limit'] ?? DEFAULT_CHAT_HISTORY);
$titleText = $settings['site_title'] ?? APP_NAME;
$prefTheme = $settings['theme'] ?? 'auto';

function render_head(string $pageTitle) {
    global $prefTheme, $titleText;
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($pageTitle) . ' - ' . e($titleText) . '</title>';
    echo '<meta name="description" content="前方的哨所 · Chat">';
    echo '<link rel="icon" href="/Outpost.png">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />';
    echo '<style>';
    ?>

    :root {
        --bg: #f0f4f9;
        --surface: #ffffff;
        --surface-elevated: #ffffff;
        --fg: #1a1d2b;
        --muted: #6c7280;
        --border: #e1e6ef;
        --border-light: #f0f4f9;
        
        --primary: #2563eb;
        --primary-hover: #1d4ed8;
        --primary-light: rgba(37, 99, 235, 0.08);
        --primary-gradient: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
        
        --accent: #06b6d4;
        --danger: #ef4444;
        --danger-hover: #dc2626;
        --ok: #10b981;
        --ok-hover: #059669;
        --warning: #f59e0b;
        
        --bubble-self: linear-gradient(135deg, #dbeafe 0%, #c7d2fe 100%);
        --bubble-other: #f8fafc;
        --bubble-border: #e2e8f0;
        
        --nav-height: 84px;
        --inputbar-height: 80px;
        --border-radius: 16px;
        --border-radius-sm: 12px;
        --border-radius-lg: 20px;
        
        --shadow-xs: 0 2px 4px rgba(0, 0, 0, 0.03);
        --shadow-sm: 0 4px 8px rgba(0, 0, 0, 0.05);
        --shadow-md: 0 8px 20px rgba(0, 0, 0, 0.08);
        --shadow-lg: 0 12px 28px rgba(0, 0, 0, 0.12);
        --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.15);
        
        --transition-fast: 0.15s cubic-bezier(0.4, 0, 0.2, 1);
        --transition-base: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --transition-bounce: 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        
        --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        --font-mono: 'SF Mono', Monaco, 'Cascadia Code', monospace;
    }

    html[data-theme="dark"] {
        --bg: #0a0e14;
        --surface: #121826;
        --surface-elevated: #1a2132;
        --fg: #f1f5f9;
        --muted: #94a3b8;
        --border: #1e293b;
        --border-light: #0f172a;
        
        --primary: #7aa2ff;
        --primary-hover: #5e8fff;
        --primary-light: rgba(122, 162, 255, 0.1);
        --primary-gradient: linear-gradient(135deg, #7aa2ff 0%, #5e8fff 100%);
        
        --accent: #22d3ee;
        --danger: #f87171;
        --danger-hover: #fb7185;
        --ok: #34d399;
        --ok-hover: #10b981;
        --warning: #fbbf24;
        
        --bubble-self: linear-gradient(135deg, #1e3a8a 0%, #3730a3 100%);
        --bubble-other: #0f172a;
        --bubble-border: #1e293b;
        
        --shadow-xs: 0 2px 4px rgba(0, 0, 0, 0.2);
        --shadow-sm: 0 4px 8px rgba(0, 0, 0, 0.3);
        --shadow-md: 0 8px 20px rgba(0, 0, 0, 0.4);
        --shadow-lg: 0 12px 28px rgba(0, 0, 0, 0.5);
        --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.6);
    }

    * {
        box-sizing: border-box;
        -webkit-tap-highlight-color: transparent;
        -webkit-touch-callout: none;
        -webkit-user-select: none;
        user-select: none;
    }
    
    html {
        font-size: 16px;
        scroll-behavior: smooth;
    }
    
    body {
        margin: 0;
        background: var(--bg);
        color: var(--fg);
        font-family: var(--font-family);
        line-height: 1.6;
        font-size: 15px;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    header {
        position: sticky;
        top: 0;
        background: rgba(var(--surface-rgb, 255, 255, 255), 0.85);
        backdrop-filter: blur(12px) saturate(180%);
        -webkit-backdrop-filter: blur(12px) saturate(180%);
        border-bottom: 1px solid var(--border-light);
        z-index: 120;
        transition: var(--transition-base);
    }
    
    header::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--surface);
        z-index: -1;
        opacity: 0.9;
    }
    
    .brand {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 18px;
    }
    
    .logo {
        width: 40px;
        height: 40px;
        border-radius: var(--border-radius-sm);
        background: var(--primary-gradient);
        background-image: url('/Outpost.png');
        background-size: cover;
        background-position: center;
        box-shadow: var(--shadow-sm);
        transition: var(--transition-base);
        transform: scale(1);
    }
    
    .logo:hover {
        transform: scale(1.05);
        box-shadow: var(--shadow-md);
    }
    
    .app-title {
        font-weight: 800;
        font-size: 20px;
        letter-spacing: -0.5px;
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
        cursor: pointer;
        transition: var(--transition-base);
    }
    
    .app-title:active {
        transform: scale(0.98);
    }
    
    .actions {
        margin-left: auto;
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .icon-btn {
        width: 42px;
        height: 42px;
        border-radius: var(--border-radius-sm);
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--surface-elevated);
        cursor: pointer;
        transition: var(--transition-fast);
        box-shadow: var(--shadow-xs);
        position: relative;
        overflow: hidden;
    }
    
    .icon-btn:hover {
        background: var(--primary-light);
        border-color: var(--primary);
        transform: translateY(-2px);
        box-shadow: var(--shadow-sm);
    }
    
    .icon-btn:active {
        transform: translateY(0);
        transition: var(--transition-fast);
    }
    
    .icon-btn i {
        color: var(--primary);
        font-size: 18px;
        transition: var(--transition-fast);
    }
    
    .icon-btn:hover i {
        color: var(--primary-hover);
    }

    /* ========== 主内容容器 ========== */
    .center {
        min-height: calc(100vh - 64px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    
    .container {
        max-width: 100%;
        margin: 0 auto;
    }

    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--border-radius-lg);
        padding: 24px;
        box-shadow: var(--shadow-md);
        width: 100%;
        max-width: 520px;
        transition: var(--transition-base);
        position: relative;
        overflow: hidden;
    }
    
    .card:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-4px);
    }
    
    .card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--primary-gradient);
        opacity: 0.7;
    }
    
    .title-xl {
        font-size: 24px;
        font-weight: 800;
        margin-bottom: 16px;
        color: var(--fg);
        letter-spacing: -0.5px;
    }

    .mobile-nav {
        position: fixed;
        left: 50%;
        transform: translateX(-50%);
        background: var(--surface-elevated);
        border: 1px solid var(--border);
        display: flex;
        justify-content: space-around;
        align-items: center;
        height: var(--nav-height);
        padding: 8px 12px;
        z-index: 110;
        box-shadow: var(--shadow-lg);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        transition: var(--transition-base);
        max-width: 100%;
        width: 420px;
    }
    
    .mobile-nav a {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        padding: 0 16px;
        font-size: 12px;
        color: var(--muted);
        text-decoration: none;
        transition: var(--transition-fast);
        border-radius: var(--border-radius-sm);
        position: relative;
    }
    
    .mobile-nav a .fa {
        font-size: 20px;
        margin-bottom: 4px;
        transition: var(--transition-fast);
    }
    
    .mobile-nav a.active {
        color: var(--primary);
        background: var(--primary-light);
    }
    
    .mobile-nav a.active .fa {
        transform: scale(1.1);
    }
    
    .mobile-nav a:not(.active):hover {
        color: var(--fg);
        background: rgba(0, 0, 0, 0.02);
    }
    
    .mobile-nav a:active {
        transform: scale(0.95);
    }
    
    html[data-theme="dark"] .mobile-nav a:not(.active):hover {
        background: rgba(255, 255, 255, 0.03);
    }

    .chat-page {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 64px);
        background: var(--bg);
    }
    
    .chat-top {
        padding: 14px 18px;
        border-bottom: 1px solid var(--border-light);
        background: var(--surface);
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 600;
        box-shadow: var(--shadow-xs);
    }
    
    .chat-list {
        flex: 1;
        overflow-y: auto;
        padding: 20px 16px calc(var(--nav-height) + 24px) 16px;
        display: flex;
        flex-direction: column;
        gap: 16px;
        scroll-behavior: smooth;
        background: var(--bg);
    }
    
    .chat-list::-webkit-scrollbar {
        width: 6px;
    }
    
    .chat-list::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .chat-list::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 3px;
    }
    
    .chat-list::-webkit-scrollbar-thumb:hover {
        background: var(--muted);
    }

    .chat-inputbar {
        position: fixed;
        left: 16px;
        right: 16px;
        bottom: calc(var(--nav-height) + 12px);
        padding: 12px;
        background: var(--surface-elevated);
        border: 1px solid var(--border);
        border-radius: var(--border-radius-lg);
        z-index: 115;
        height: var(--inputbar-height);
        box-shadow: var(--shadow-xl);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        transition: var(--transition-base);
    }
    
    @media (min-width: 840px) {
        .chat-inputbar {
            left: calc(50% - 420px);
            right: calc(50% - 420px);
            bottom: 24px;
            max-width: 840px;
        }
    }
    
    .row {
        display: flex;
        gap: 12px;
        align-items: center;
        height: 100%;
    }
    
    .input-text {
        width: 100%;
        padding: 14px 48px 14px 16px;
        border-radius: var(--border-radius);
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--fg);
        outline: none;
        height: 48px;
        font-size: 15px;
        transition: var(--transition-fast);
        font-family: var(--font-family);
    }
    
    .input-text:focus {
        box-shadow: 0 0 0 3px var(--primary-light);
        border-color: var(--primary);
    }
    
    .input-text::placeholder {
        color: var(--muted);
        opacity: 0.7;
    }
    
    .file-label {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: var(--muted);
        transition: var(--transition-fast);
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }
    
    .file-label:hover {
        color: var(--primary);
        background: var(--primary-light);
    }
    
    .file-label i {
        font-size: 18px;
    }
    
    .send-wrap {
        flex: 0 0 auto;
    }
    
    .send-btn {
        width: 48px;
        height: 48px;
        border-radius: var(--border-radius);
        border: none;
        background: var(--primary-gradient);
        color: #fff;
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition-fast);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: var(--shadow-sm);
        position: relative;
        overflow: hidden;
    }
    
    .send-btn:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    
    .send-btn:active {
        transform: translateY(0);
    }
    
    .send-btn::after {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: rgba(255, 255, 255, 0.1);
        transform: rotate(30deg);
        transition: var(--transition-base);
    }
    
    .send-btn:hover::after {
        transform: rotate(30deg) translate(20%, 20%);
    }
    
    .send-btn i {
        font-size: 18px;
        position: relative;
        z-index: 1;
    }

    .bubble {
        display: flex;
        gap: 14px;
        align-items: flex-start;
        animation: fadeInUp 0.4s var(--transition-bounce) forwards;
        opacity: 0;
        transform: translateY(20px);
        max-width: 100%;
    }
    
    @keyframes fadeInUp {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        background: var(--surface);
        flex: none;
        border: 2px solid var(--border);
        box-shadow: var(--shadow-sm);
        transition: var(--transition-fast);
    }
    
    .bubble:hover .avatar {
        transform: scale(1.05);
        border-color: var(--primary);
    }
    
    .bubble-body {
        max-width: calc(100% - 62px);
        flex: 1;
    }
    
    .meta {
        font-size: 13px;
        color: var(--muted);
        display: flex;
        gap: 8px;
        align-items: center;
        margin-bottom: 6px;
        flex-wrap: wrap;
    }
    
    .nick {
        font-weight: 700;
        color: var(--fg);
        font-size: 14px;
    }
    
    .uid {
        font-size: 11px;
        color: var(--muted);
        font-family: var(--font-mono);
        background: var(--primary-light);
        padding: 2px 6px;
        border-radius: 4px;
    }
    
    .title-badge {
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 999px;
        background: var(--primary-light);
        color: var(--primary);
        font-weight: 600;
        box-shadow: var(--shadow-xs);
        border: 1px solid rgba(37, 99, 235, 0.2);
    }
    
    .text {
        margin-top: 6px;
        padding: 12px 16px;
        border-radius: var(--border-radius);
        background: var(--bubble-other);
        border: 1px solid var(--bubble-border);
        white-space: pre-wrap;
        word-wrap: break-word;
        word-break: break-word;
        font-size: 15px;
        line-height: 1.5;
        box-shadow: var(--shadow-xs);
        transition: var(--transition-fast);
    }
    
    .bubble:hover .text {
        box-shadow: var(--shadow-sm);
    }
    
    .self .text {
        background: var(--bubble-self);
        border-color: rgba(37, 99, 235, 0.2);
        color: var(--fg);
    }
    
    .self {
        flex-direction: row-reverse;
    }
    
    .self .meta {
        justify-content: flex-end;
    }
    
    .self .bubble-body {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }

    .media {
        margin-top: 10px;
        border-radius: var(--border-radius);
        overflow: hidden;
        transition: var(--transition-base);
    }
    
    .media:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    
    .img-thumb, .video-thumb {
        width: 100%;
        max-width: 420px;
        height: auto;
        border-radius: var(--border-radius);
        border: 1px solid var(--border);
        cursor: zoom-in;
        transition: var(--transition-base);
        display: block;
    }
    
    .img-thumb:hover, .video-thumb:hover {
        transform: scale(1.01);
    }
    
    .video-thumb {
        background: var(--surface);
        position: relative;
    }
    
    .video-thumb::before {
        content: '\f144';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 48px;
        color: var(--primary);
        opacity: 0.8;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        pointer-events: none;
        transition: var(--transition-base);
    }
    
    .video-thumb:hover::before {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1.1);
    }

    .profile {
        max-width: 720px;
        margin: 20px auto;
        display: flex;
        flex-direction: column;
        gap: 20px;
        padding: 0 16px;
    }
    
    .profile-card {
        background: var(--surface-elevated);
        border: 1px solid var(--border);
        border-radius: var(--border-radius-lg);
        padding: 24px;
        box-shadow: var(--shadow-md);
        transition: var(--transition-base);
        position: relative;
        overflow-X: auto; 
        -webkit-overflow-scrolling: touch;
    }
    
    .profile-card:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-2px);
    }
    
    .profile-top {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 16px;
        padding: 20px 0;
    }
    
    .profile-avatar {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid var(--surface);
        box-shadow: var(--shadow-md), 0 0 0 3px var(--primary);
        transition: var(--transition-base);
    }
    
    .profile-avatar:hover {
        transform: scale(1.05);
        box-shadow: var(--shadow-lg), 0 0 0 3px var(--primary);
    }
    
    .profile-uid {
        font-size: 13px;
        color: var(--muted);
        font-family: var(--font-mono);
        background: var(--primary-light);
        padding: 4px 10px;
        border-radius: 6px;
    }
    
    .profile-form {
        margin-top: 20px;
    }
    
    .profile-form label {
        font-weight: 700;
        margin-bottom: 8px;
        display: block;
        color: var(--fg);
        font-size: 14px;
    }
    
    .profile-form input {
        padding: 14px 16px;
        border-radius: var(--border-radius);
        border: 1px solid var(--border);
        background: var(--surface);
        width: 100%;
        transition: var(--transition-fast);
        font-size: 15px;
    }
    
    .profile-form input:focus {
        box-shadow: 0 0 0 3px var(--primary-light);
        border-color: var(--primary);
    }
    
    .profile-form input[type="file"] {
        padding: 10px;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 16px;
        border-radius: var(--border-radius);
        overflow: hidden;
    }
    
    .table th,
    .table td {
        border-bottom: 1px solid var(--border);
        padding: 12px;
        text-align: left;
        transition: var(--transition-fast);
    }
    
    .table th {
        background: var(--surface);
        font-weight: 700;
        color: var(--fg);
        font-size: 14px;
        position: sticky;
        top: 0;
    }
    
    .table tr {
        transition: var(--transition-fast);
    }
    
    .table tr:hover {
        background: var(--primary-light);
    }
    
    .table td {
        font-size: 14px;
    }

    .btn {
        padding: 12px 18px;
        border-radius: var(--border-radius);
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--fg);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition-fast);
        text-decoration: none;
        display: inline-block;
        font-size: 14px;
        text-align: center;
        box-shadow: var(--shadow-xs);
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-sm);
        border-color: var(--primary);
        color: var(--primary);
    }
    
    .btn:active {
        transform: translateY(0);
    }
    
    .btn-primary {
        background: var(--primary-gradient);
        color: #fff;
        border: none;
        box-shadow: var(--shadow-sm);
    }
    
    .btn-primary:hover {
        color: #fff;
        background: linear-gradient(135deg, var(--primary-hover) 0%, #1e3a8a 100%);
        box-shadow: var(--shadow-md);
    }
    
    .btn-danger {
        background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
        color: #fff;
        border: none;
    }
    
    .btn-danger:hover {
        background: linear-gradient(135deg, var(--danger-hover) 0%, #b91c1c 100%);
    }
    
    .link {
        color: var(--primary);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition-fast);
        position: relative;
    }
    
    .link:hover {
        text-decoration: underline;
    }
    
    .link::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 0;
        height: 2px;
        background: var(--primary-gradient);
        transition: var(--transition-base);
    }
    
    .link:hover::after {
        width: 100%;
    }

    .modal {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 200;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        animation: fadeIn 0.3s ease forwards;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    .modal.active {
        display: flex;
    }
    
    .modal-content {
        max-width: 92vw;
        max-height: 88vh;
        border-radius: var(--border-radius-lg);
        overflow: hidden;
        animation: zoomIn 0.3s var(--transition-bounce) forwards;
        transform: scale(0.9);
    }
    
    @keyframes zoomIn {
        to {
            transform: scale(1);
        }
    }
    
    .modal img,
    .modal video {
        max-width: 92vw;
        max-height: 88vh;
        border-radius: var(--border-radius-lg);
        box-shadow: var(--shadow-xl);
    }

    .system-note {
        font-size: 13px;
        color: var(--muted);
        font-weight: 500;
        background: var(--primary-light);
        padding: 2px 8px;
        border-radius: 4px;
        display: inline-block;
    }

    input[type="text"],
    input[type="password"],
    input[type="file"],
    select,
    textarea {
        padding: 14px 16px;
        border-radius: var(--border-radius);
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--fg);
        outline: none;
        width: 100%;
        transition: var(--transition-fast);
        font-family: var(--font-family);
        font-size: 15px;
    }
    
    input[type="text"]:focus,
    input[type="password"]:focus,
    select:focus,
    textarea:focus {
        box-shadow: 0 0 0 3px var(--primary-light);
        border-color: var(--primary);
    }
    
    select {
        cursor: pointer;
    }
    
    input[type="file"] {
        padding: 10px;
    }

    @media (max-width: 768px) {
        .profile {
            padding: 0 12px;
        }
        
        .card {
            padding: 20px;
        }
        
        .chat-inputbar {
            left: 12px;
            right: 12px;
        }
        
        .mobile-nav {
            max-width: 100%;
            bottom: 0px;
        }
    }
    
    @media (min-width: 840px) {
        .chat-list {
            padding: 24px 20px calc(var(--nav-height) + 28px) 20px;
        }
        
        .bubble-body {
            max-width: 600px;
        }
    }

    .skeleton {
        background: linear-gradient(90deg, var(--border) 25%, var(--surface) 50%, var(--border) 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
        border-radius: var(--border-radius);
    }
    
    @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }

    .status-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--ok);
        display: inline-block;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.5; }
        100% { opacity: 1; }
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: var(--muted);
    }
    
    .empty-state i {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.5;
    }
    
    .empty-state p {
        font-size: 16px;
        margin: 0;
    }

    html.transition,
    html.transition *,
    html.transition *:before,
    html.transition *:after {
        transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1) !important;
        transition-delay: 0 !important;
    }

    <?php
    echo '</style>';
    echo '<script>';
    ?>
    (function() {
        if (window.innerWidth > 768 && !/iPad|Android|Mobile/.test(navigator.userAgent)) {
            window.location.href = 'http://outpost.mcbes.top/';  //由于桌面端的样式没有做好，所以我在这里加了个桌面端自动跳转，你可以把这个地址改成你的地址，或者直接把这里删掉，自己优化一下桌面端的显示效果
        }
    })();

    (function(){
        const pref = localStorage.getItem('theme') || '<?php echo e($prefTheme); ?>';
        if (pref === 'dark') document.documentElement.setAttribute('data-theme','dark');
        else if (pref === 'light') document.documentElement.setAttribute('data-theme','light');
        window.toggleTheme = function(){
            const cur = document.documentElement.getAttribute('data-theme');
            const next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        };
    })();

    document.addEventListener('DOMContentLoaded', function(){
        const t = document.getElementById('app-title'); if (t) {
            let timer = null;
            const go = ()=>{ location.href='?action=admin'; };
            t.addEventListener('mousedown', ()=>{ timer = setTimeout(go, 1500); });
            t.addEventListener('touchstart', ()=>{ timer = setTimeout(go, 1500); });
            ['mouseup','mouseleave','touchend','touchcancel'].forEach(ev=> t.addEventListener(ev, ()=>{ if (timer) { clearTimeout(timer); timer=null; }}));
        }

        const loginBox = document.getElementById('login-box');
        const registerBox = document.getElementById('register-box');
        const showReg = document.getElementById('show-register');
        const showLogin = document.getElementById('show-login');
        if (loginBox && registerBox && showReg && showLogin) {
            registerBox.style.display = 'none';
            showReg.addEventListener('click', function(){ loginBox.style.display = 'none'; registerBox.style.display = 'block'; });
            showLogin.addEventListener('click', function(){ registerBox.style.display = 'none'; loginBox.style.display = 'block'; });
        }

        let latestTs = 0;
        async function fetchChats(initial=false){
            try {
                const res = await fetch('?action=chats&since=' + (initial ? 0 : latestTs));
                const data = await res.json();
                if (!data || !Array.isArray(data.messages)) return;
                const list = document.getElementById('chat-list');
                const me = list?.getAttribute('data-me');
                const wasNearBottom = list ? (list.scrollHeight - list.scrollTop - list.clientHeight) < 560 : true;
                data.messages.forEach(m => {
                    const el = document.createElement('div');
                    el.className = 'bubble' + ((m.uid === me) ? ' self' : '');
                    const avatarSrc = (m.uid === '<?php echo BOT_UID; ?>') ? '<?php echo BOT_AVATAR; ?>' : (m.avatar ? ('?action=media&file=' + encodeURIComponent(m.avatar)) : 'data:image/svg+xml;base64,<?php echo base64_encode("<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'44\' height=\'44\'><rect width=\'44\' height=\'44\' fill=\'#e6eef8\'/></svg>"); ?>');
                    el.innerHTML = `
                        <img class="avatar" src="${avatarSrc}" alt="avatar">
                        <div class="bubble-body">
                            <div class="meta">
                                <span class="nick">${escapeHtml(m.nickname||'未知')}</span>
                                <span class="uid">UID: ${escapeHtml(m.uid||'')}</span>
                                ${m.title ? `<span class="title-badge">${escapeHtml(m.title)}</span>` : ''}
                                <span class="system-note">${formatTs(m.ts||Date.now()/1000)}</span>
                            </div>
                            ${m.text ? `<div class="text">${escapeHtml(m.text)}</div>` : ''}
                            ${m.media ? renderMedia(m.media) : ''}
                        </div>
                    `;
                    list.appendChild(el);
                });
                if (data.latest_ts) latestTs = data.latest_ts;
                if (wasNearBottom) {
                    list.scrollTop = list.scrollHeight;
                    setTimeout(()=>{ list.scrollTop = list.scrollHeight; }, 60);
                }
            } catch(e) { /* ignore */ }
        }
        window.fetchChats = fetchChats;
        const list = document.getElementById('chat-list');
        if (list) { fetchChats(true); setInterval(fetchChats, 1200); }
        
        window.escapeHtml = function(s){ const d=document.createElement('div'); d.innerText = s ?? ''; return d.innerHTML; };
        window.formatTs = function(ts){ const d=new Date(ts*1000); const y=d.getFullYear(); const m=(d.getMonth()+1).toString().padStart(2,'0'); const dd=d.getDate().toString().padStart(2,'0'); const hh=d.getHours().toString().padStart(2,'0'); const mm=d.getMinutes().toString().padStart(2,'0'); return `${y}-${m}-${dd} ${hh}:${mm}`; };
        window.renderMedia = function(m){ 
            const src = `?action=media&file=${encodeURIComponent(m.file)}`; 
            if (m.type === 'image') {
                return `<div class="media"><img class="img-thumb" src="${src}" alt="image" onclick="openModalMedia('${src}', false)"></div>`; 
            }
            if (m.type === 'video') {
                return `<div class="media">
                    <video class="video-thumb" src="${src}" preload="metadata" muted 
                           onloadedmetadata="this.currentTime=0.1" 
                           controls onclick="openModalMedia('${src}', true)"></video>
                </div>`; 
            }
            return ''; 
        };

        window.openModalMedia = function(src,isVideo){ const modal=document.getElementById('modal'); const box=document.getElementById('modal-box'); box.innerHTML=''; if(isVideo){ const v=document.createElement('video'); v.src=src; v.controls=true; v.autoplay=true; box.appendChild(v);} else { const img=document.createElement('img'); img.src=src; box.appendChild(img);} modal.classList.add('active'); };
        window.closeModal = function(){ document.getElementById('modal').classList.remove('active'); };
    });
    <?php
    echo '</script>';
    echo '</head><body>';
}

function render_header(): void {
    global $titleText;
    echo '<header><div class="brand">';
    echo '<div class="logo"></div>';
    echo '<div id="app-title" class="app-title">' . e($titleText) . '</div>';
    echo '<div class="actions">';
    echo '<button class="icon-btn" title="切换主题" onclick="toggleTheme()"><i class="fa fa-sun"></i></button>';
    echo '</div></div></header>';
}

function render_footer_nav(string $active) {
    echo '<nav class="mobile-nav">';
    echo '<a class="nav-item '.($active==='chat'?'active':'').'" href="?action=chat"><i class="fa fa-comments"></i><div>聊天</div></a>';
    echo '<a class="nav-item '.($active==='me'?'active':'').'" href="?action=me"><i class="fa fa-user"></i><div>我的</div></a>';
    echo '</nav>';
    echo '<div id="modal" class="modal" onclick="closeModal()"><div class="modal-content" id="modal-box"></div></div>';
}

if ($action === 'home') {
    render_head('登录或注册');
    render_header();
    echo '<main class="center">';
    if ($user && ($user['status'] ?? '') === 'pending') {
        echo '<div class="card"><div class="title-xl">已登录，等待审核</div><p class="system-note">您的账户需要管理员审批，审批完成前无法访问其他内容。</p><div style="display:flex;gap:8px;margin-top:12px"><a class="btn" href="?action=logout">退出登录</a></div></div>';
    } elseif ($user) {
        echo '<div class="card"><div class="title-xl">欢迎</div><p class="system-note">正在加载中…</p><script>location.href="?action=chat";</script></div>';
    } else {
        echo '<div class="card">';
        echo '<div id="login-box">';
        echo '<div class="title-xl">登录</div>';
        if (!empty($msg) && route('action')==='login') echo '<p style="color:var(--danger)">'.e($msg).'</p>';
        echo '<form method="post" action="?action=login" style="display:flex;flex-direction:column;gap:10px">';
        echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
        echo '<input class="input-text" name="username" placeholder="用户名" required>';
        echo '<input class="input-text" type="password" name="password" placeholder="密码" required>';
        echo '<button class="btn btn-primary" type="submit">登录</button>';
        echo '</form>';
        echo '<div style="margin-top:10px;font-size:13px;color:var(--muted)">没有账号？<span id="show-register" class="link">立即注册</span></div>';
        echo '</div>';
        echo '<div id="register-box">';
        echo '<div class="title-xl">注册</div>';
        if (!empty($msg) && route('action')==='register') echo '<p style="color:var(--danger)">'.e($msg).'</p>';
        echo '<form method="post" action="?action=register" style="display:flex;flex-direction:column;gap:10px">';
        echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
        echo '<input class="input-text" name="username" placeholder="用户名" required>';
        echo '<input class="input-text" type="password" name="password" placeholder="密码" required>';
        echo '<input class="input-text" name="nickname" placeholder="昵称" required>';
        echo '<button class="btn" type="submit">注册</button>';
        echo '</form>';
        echo '<div style="margin-top:10px;font-size:13px;color:var(--muted)">已有账号？<span id="show-login" class="link">返回登录</span></div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</main>';
    render_footer_nav($user ? 'chat' : '');
    echo '</body></html>'; exit;
}

if ($action === 'chat') {
    if (!$user) { header('Location: ?action=home'); exit; }
    if (($user['status'] ?? '') !== 'approved') { header('Location: ?action=home'); exit; }

    render_head('聊天');
    render_header();
    echo '<main class="container">';
    echo '<section class="chat-page">';
    echo '<div id="chat-list" class="chat-list" data-me="'.e($user['uid']).'"></div>';

    echo '<div style="height:calc(var(--nav-height) + 4px)"></div>';

    echo '<div class="chat-inputbar">';
    echo '<form method="post" action="?action=send" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div class="row" style="align-items:center">';
    echo '<div style="flex:0 0 80%;position:relative">';
    echo '<input id="text-input" class="input-text" name="text" placeholder="可用 @哨哨 调用机器人">';
    echo '<label id="media-label" class="file-label" title="选择文件">';
    echo '<i class="fa fa-image"></i>';
    echo '<input id="media-input" type="file" name="media" accept="image/*,video/*" style="display:none">';
    echo '</label>';
    echo '</div>';
    echo '<div class="send-wrap">';
    echo '<button class="send-btn" type="submit"><i class="fa fa-paper-plane" style="margin-right:0px"></i></button>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo '</section></main>';
    render_footer_nav('chat');
    echo '</body></html>'; exit;
}

if ($action === 'me') {
    if (!$user) { header('Location: ?action=home'); exit; }
    if (($user['status'] ?? '') === 'pending') { header('Location: ?action=home'); exit; }

    render_head('我的');
    render_header();
    echo '<main class="container">';
    echo '<section class="profile">';
    echo '<div class="profile-card">';
    echo '<div class="profile-top">';
    $avatarSrc = !empty($user['avatar']) ? ('?action=media&file=' . urlencode($user['avatar'])) : 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="110" height="110"><rect width="110" height="110" fill="#e6eef8"/></svg>');
    echo '<img class="profile-avatar" src="'.$avatarSrc.'" alt="avatar">';
    echo '<div class="profile-uid">UID：'.e($user['uid']).'</div>';
    echo '<div style="font-size:20px;font-weight:800">'.e($user['nickname']).'</div>';
    if (!empty($user['title_active'])) echo '<div class="title-badge" style="margin-top:6px">'.e($user['title_active']).'</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="profile-card">';
    echo '<h3 style="margin-top:0">身份信息与设置</h3>';
    if (!empty($_GET['updated'])) echo '<p style="color:var(--ok)">已更新</p>';
    echo '<form class="profile-form" method="post" action="?action=profile_update" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<label>昵称</label><input class="input-text" name="nickname" value="'.e($user['nickname']).'">';
    echo '<label>头像</label><input class="input-text" type="file" name="avatar" accept="image/*">';
    echo '<div style="display:flex;gap:8px;margin-top:12px"><button class="btn btn-primary" type="submit">保存</button><a class="btn" href="?action=my_titles">称号</a><a class="btn" href="?action=admin">管理员入口</a><a class="btn btn-danger" href="?action=logout">退出登录</a></div>';
    echo '</form></div>';

    echo '<div style="height:var(--nav-height)"></div>';

    echo '</section></main>';
    render_footer_nav('me');
    echo '</body></html>'; exit;
}

if ($action === 'my_titles') {
    if (!$user) { header('Location: ?action=home'); exit; }
    if (($user['status'] ?? '') === 'pending') { header('Location: ?action=home'); exit; }

    render_head('称号');
    render_header();
    echo '<main class="container">';
    echo '<section class="profile">';
    echo '<div class="profile-card"><h3 style="margin-top:0">称号</h3>';
    $owned = $user['titles'] ?? [];
    if (!$owned) {
        echo '<p class="system-note">您还没有被授予任何称号。</p>';
    } else {
        echo '<table class="table"><thead><tr><th>称号</th><th>状态</th><th>操作</th></tr></thead><tbody>';
        foreach ($owned as $t) {
            $active = ($user['title_active'] ?? '') === $t;
            echo '<tr>';
            echo '<td>'.e($t).'</td>';
            echo '<td>'.($active?'<span class="title-badge">已启用</span>':'<span class="system-note">未启用</span>').'</td>';
            echo '<td><form method="post" action="?action=toggle_title" style="display:inline">';
            echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
            echo '<input type="hidden" name="title" value="'.e($t).'">';
            echo '<input type="hidden" name="use" value="'.($active? '': '1').'">';
            echo '<button class="btn" type="submit">'.($active?'停用':'启用').'</button>';
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div style="height:var(--nav-height)"></div>';

    echo '</section></main>';
    render_footer_nav('me');
    echo '</body></html>'; exit;
}

if ($action === 'admin') {
    if (!$user || !is_admin($user)) { header('Location: ?action=admin_login'); exit; }
    render_head('后台管理');
    render_header();
    echo '<main class="container">';
    if (!empty($_GET['ok'])) echo '<div class="card" style="border-color:var(--ok)"><strong>操作成功</strong></div>';

    echo '<section class="profile-card" style="margin-top:16px"><h3 style="margin-top:0">用户审批</h3>';
    $users = read_json(USERS_FILE);
    echo '<table class="table"><thead><tr><th>UID</th><th>用户名</th><th>昵称</th><th>状态</th><th>操作</th></tr></thead><tbody>';
    foreach ($users as $u) {
        echo '<tr>';
        echo '<td>'.e($u['uid']).'</td>';
        echo '<td>'.e($u['username']).'</td>';
        echo '<td>'.e($u['nickname']).'</td>';
        echo '<td>'.e($u['status'] ?? 'unknown').'</td>';
        echo '<td>';
        if (($u['status'] ?? '') !== 'approved') {
            echo '<form method="post" action="?action=admin_approve" style="display:inline">';
            echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
            echo '<input type="hidden" name="uid" value="'.e($u['uid']).'">';
            echo '<button class="btn btn-primary" type="submit">审批通过</button>';
            echo '</form>';
        } else {
            echo '<span class="system-note">已通过</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></section>';

    echo '<section class="profile-card" style="margin-top:16px"><h3 style="margin-top:0">授予称号</h3>';
    echo '<form method="post" action="?action=admin_title" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div><label>用户 UID</label><input class="input-text" name="uid" placeholder="例如：'.e($user['uid']).'"></div>';
    echo '<div><label>称号</label><input class="input-text" name="title" placeholder="例如：Noob"></div>';
    echo '<div style="grid-column:1/-1"><button class="btn btn-primary" type="submit">授予称号</button></div>';
    echo '</form></section>';

    $s = read_json(SETTINGS_FILE);
    echo '<section class="profile-card" style="margin-top:16px"><h3 style="margin-top:0">基础设置</h3>';
    echo '<form method="post" action="?action=admin_settings" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div><label>聊天历史显示条数</label><input class="input-text" type="number" name="chat_history_limit" value="'.e((string)($s['chat_history_limit'] ?? DEFAULT_CHAT_HISTORY)).'" min="10" max="1000"></div>';
    echo '<div><label>允许注册</label><select class="input-text" name="allow_register"><option value="1" '.(($s['allow_register'] ?? true)?'selected':'').'>是</option><option value="0" '.(($s['allow_register'] ?? true)?'':'selected').'>否</option></select></div>';
    echo '<div><label>站点标题</label><input class="input-text" name="site_title" value="'.e($s['site_title'] ?? APP_NAME).'"></div>';
    echo '<div><label>默认主题</label><select class="input-text" name="theme"><option value="auto" '.(($s['theme'] ?? 'auto')==='auto'?'selected':'').'>自动</option><option value="light" '.(($s['theme'] ?? 'auto')==='light'?'selected':'').'>白天</option><option value="dark" '.(($s['theme'] ?? 'auto')==='dark'?'selected':'').'>夜间</option></select></div>';
    echo '<div><label>启用哨哨机器人</label><select class="input-text" name="bot_enabled"><option value="1" '.(($s['bot_enabled'] ?? true)?'selected':'').'>启用</option><option value="0" '.(($s['bot_enabled'] ?? true)?'':'selected').'>停用</option></select></div>';
    echo '<div><label>哨哨上下文条数</label><input class="input-text" type="number" name="bot_context" value="'.e((string)($s['bot_context'] ?? 5)).'" min="1" max="20"></div>';
    echo '<div style="grid-column:1/-1"><button class="btn btn-primary" type="submit">保存设置</button></div>';
    echo '</form></section>';

    echo '<div style="height:var(--nav-height)"></div>';

    echo '</main>';
    render_footer_nav('chat');
    echo '</body></html>'; exit;
}

if ($action === 'admin_login') {
    render_head('后台登录');
    render_header();
    echo '<main class="center">';
    echo '<div class="card">';
    echo '<div class="title-xl">后台管理登录</div>';
    if (!empty($msg)) echo '<p style="color:var(--danger)">'.e($msg).'</p>';
    echo '<form method="post" action="?action=admin_login" style="display:flex;flex-direction:column;gap:10px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<input class="input-text" name="username" placeholder="管理员用户名" required>';
    echo '<input class="input-text" type="password" name="password" placeholder="密码" required>';
    echo '<button class="btn btn-primary" type="submit">登录后台</button>';
    echo '</form><p class="system-note" style="margin-top:10px">提示：长按顶部标题 1.5 秒可快速进入后台入口。</p>';
    echo '</div></main>';
    render_footer_nav('chat');
    echo '</body></html>'; exit;
}

header('Location: ?action=home'); exit;