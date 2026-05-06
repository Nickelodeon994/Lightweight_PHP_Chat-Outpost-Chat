<?php

/**
 * 超轻量级PHP聊天系统
 * 
 * @version 0.0.10
 * @build 2026-04-28
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
*
* - 0.0.3 (2025-12-31) 
*   * 移出强制刷新
*   * 增加消息发送频率限制
*   * 重置聊天记录保存逻辑
* 
* - 0.0.4 (2026-03-13) 
*   * 再次重置聊天记录保存逻辑
*   * 保留与旧版聊天记录的兼容性
* 
* - 0.0.5 (2026-03-13) 
*   * 优化桌面端显示效果
* 
* - 0.0.6 (2026-04-09)
*   * 引入浏览器端 IndexedDB 媒体缓存机制
*   * 图片/视频自动缓存，减少重复加载
*   * 增加缓存管理按钮（查看/清空缓存）
*
* - 0.0.7 (2026-04-10)
*   * 移除大量硬编码，迁移到后台配置
*
* - 0.0.8 (2026-04-14)
*   * 增加聊天记录撤回/删除功能
*   * 支持编辑自己的近期消息（默认5分钟）
*
* - 0.0.9 (2026-04-15)
*   * 增加消息回复/引用功能
*   * 点击引用可定位历史范围内的信息
*
* - 0.0.10 (2026-04-28)
*   * 增加「工具」板块
*
* - 0.0.11 (2026-05-06)
*   * 紧急修复严重漏洞
*   * 删除消息时同步删除关联的媒体文件
 */


/**
 * Copyright 2026 Nickelodeon994
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

const CHATS_DIR = DATA_DIR . '/Chats';
const MESSAGES_DIR = DATA_DIR . '/messages';   

const APP_NAME = 'Open·Chat';  
const DEFAULT_CHAT_HISTORY = 100;
const MAX_UPLOAD_SIZE = 10 * 1024 * 1024; 
const ALLOWED_IMAGE_EXT = ['jpg','jpeg','png','gif','webp'];
const ALLOWED_VIDEO_EXT = ['mp4','webm','ogg'];
const EDIT_TIME_LIMIT = 300; // 5分钟（单位为秒）

function ensure_setup(): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0775, true);
    if (!is_dir(CHATS_DIR)) mkdir(CHATS_DIR, 0775, true);
    if (!is_dir(MESSAGES_DIR)) mkdir(MESSAGES_DIR, 0775, true); 
    $files = [
        USERS_FILE => [],
        CHATS_FILE => [],
        SETTINGS_FILE => [
            'chat_history_limit' => DEFAULT_CHAT_HISTORY,
            'allow_register' => true,
            'site_title' => APP_NAME,
            'theme' => 'auto',
            'bot_enabled' => true,
            'bot_context' => 5,
            'bot_name' => '哨哨',
            'bot_uid' => 'BOT_SHAO',
            'bot_avatar' => '/Outpost.png',
            'ai_provider' => 'deepseek',
            'deepseek_api_key' => 'sk-1145141919810',
            'deepseek_api_url' => 'https://api.deepseek.com/v1/chat/completions',
            'deepseek_model' => 'deepseek-chat',
            'deepseek_max_tokens' => 512,
            'deepseek_temperature' => 0.8,
            'rate_limit_window' => 60,
            'rate_limit_count' => 10
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

function get_message_files_sorted(): array {
    $files = glob(MESSAGES_DIR . '/*.json');
    usort($files, function($a, $b) {
        $ta = (int) explode('_', basename($a))[0];
        $tb = (int) explode('_', basename($b))[0];
        return $ta <=> $tb;
    });
    return $files;
}

function get_reply_preview(string $reply_to_id): ?array {
    $file = find_message_file_by_id($reply_to_id);
    if (!$file) return null;
    $msg = json_decode(file_get_contents($file), true);
    if (!$msg) return null;
    $text = $msg['text'] ?? '';
    $preview = mb_strlen($text) > 50 ? mb_substr($text, 0, 50) . '…' : $text;
    return [
        'nickname' => $msg['nickname'] ?? '未知用户',
        'text' => $preview,
        'ts' => $msg['ts'] ?? 0
    ];
}

function read_chats_since(int $since_ts, int $limit): array {
    $files = get_message_files_sorted();
    $result = [];
    foreach ($files as $file) {
        $ts = (int) explode('_', basename($file))[0];
        if ($ts > $since_ts) {
            $content = file_get_contents($file);
            if ($content) {
                $msg = json_decode($content, true);
                if (is_array($msg) && isset($msg['id'])) {
                    if (!empty($msg['reply_to'])) {
                        $preview = get_reply_preview($msg['reply_to']);
                        $msg['reply_preview'] = $preview; 
                    }
                    $result[] = $msg;
                    if (count($result) >= $limit) break;
                }
            }
        }
    }
    return $result;
}

function read_chats_before(int $before_ts, int $limit): array {
    $files = get_message_files_sorted();
    $result = [];
    for ($i = count($files) - 1; $i >= 0; $i--) {
        $ts = (int) explode('_', basename($files[$i]))[0];
        if ($ts < $before_ts) {
            $content = file_get_contents($files[$i]);
            if ($content) {
                $msg = json_decode($content, true);
                if (is_array($msg) && isset($msg['id'])) {
                    if (!empty($msg['reply_to'])) {
                        $preview = get_reply_preview($msg['reply_to']);
                        $msg['reply_preview'] = $preview;
                    }
                    array_unshift($result, $msg); 
                    if (count($result) >= $limit) break;
                }
            }
        }
    }
    return $result;
}

function read_chats_recent(int $limit): array {
    $files = get_message_files_sorted();
    $result = [];
    $start = max(0, count($files) - $limit);
    for ($i = $start; $i < count($files); $i++) {
        $content = file_get_contents($files[$i]);
        if ($content) {
            $msg = json_decode($content, true);
            if (is_array($msg) && isset($msg['id'])) {
                if (!empty($msg['reply_to'])) {
                    $preview = get_reply_preview($msg['reply_to']);
                    $msg['reply_preview'] = $preview;
                }
                $result[] = $msg;
            }
        }
    }
    return $result;
}

function find_message_file_by_id(string $id): ?string {
    $files = glob(MESSAGES_DIR . '/*.json');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content) {
            $msg = json_decode($content, true);
            if (is_array($msg) && ($msg['id'] ?? '') === $id) {
                return $file;
            }
        }
    }
    return null;
}

function update_message_text(string $id, string $newText): bool {
    $file = find_message_file_by_id($id);
    if (!$file) return false;
    $msg = json_decode(file_get_contents($file), true);
    if (!$msg) return false;
    $msg['text'] = $newText;
    $msg['edited'] = true;
    $msg['edited_ts'] = time();
    return file_put_contents($file, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

function delete_message(string $id): bool {
    $file = find_message_file_by_id($id);
    if ($file) {
        $msg = json_decode(file_get_contents($file), true);
        if (is_array($msg) && !empty($msg['media']['file'])) {
            $mediaPath = MEDIA_DIR . '/' . $msg['media']['file'];
            if (is_file($mediaPath)) @unlink($mediaPath);
        }
        return unlink($file);
    }
    return false;
}

function clear_all_messages(): int {
    $files = glob(MESSAGES_DIR . '/*.json');
    $count = 0;
    foreach ($files as $file) {
        $msg = json_decode(file_get_contents($file), true);
        if (is_array($msg) && !empty($msg['media']['file'])) {
            $mediaPath = MEDIA_DIR . '/' . $msg['media']['file'];
            if (is_file($mediaPath)) @unlink($mediaPath);
        }
        if (unlink($file)) $count++;
    }
    $oldChats = glob(CHATS_DIR . '/*.json');
    foreach ($oldChats as $f) {
        @unlink($f);
    }
    if (file_exists(CHATS_FILE)) @unlink(CHATS_FILE);
    return $count;
}

function current_hour_chats_file(): string {
    return CHATS_DIR . '/' . date('Ymd_H') . '.json';
}

function read_chats_from_file(string $path): array {
    if (!is_file($path)) return [];
    $fp = fopen($path, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (!$content) return [];
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function read_all_chats(): array {
    return read_chats_recent(10000); 
}

function append_chat_message(array $msg): bool {
    $ts = $msg['ts'] ?? time();
    $id = $msg['id'] ?? bin2hex(random_bytes(8));
    if (!isset($msg['id'])) {
        $msg['id'] = $id;
    }
    $filename = $ts . '_' . $id . '.json';
    $path = MESSAGES_DIR . '/' . $filename;
    $fp = fopen($path, 'x'); 
    if (!$fp) {
        $fp = fopen($path, 'c+');
        if (!$fp) return false;
    }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0);
    $ok = fwrite($fp, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function uid(): string { return strtoupper(bin2hex(random_bytes(4))); }
function now_ts(): int { return time(); }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function media_safe_name(string $original): string {
    $ext = pathinfo($original, PATHINFO_EXTENSION);
    $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $rand = bin2hex(random_bytes(16));
    return date('Ymd_His') . '_' . $rand . ($ext ? '.' . $ext : '');
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
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (is_image_file($file['tmp_name']) && in_array($ext, ALLOWED_IMAGE_EXT, true) && strpos($realMime, 'image/') === 0) {
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? ($info['mime'] ?? $realMime) : $realMime;
        return ['type'=>'image','ext'=>$ext,'mime'=>$mime];
    }
    if (in_array($ext, ALLOWED_VIDEO_EXT, true) && strpos($realMime, 'video/') === 0) {
        $mimeMap = ['mp4'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg'];
        $mime = $mimeMap[$ext] ?? $realMime;
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

function is_ajax(): bool {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false) return true;
    return false;
}

if (route('action') === 'media') {
    $mediaUser = current_user();
    if (!$mediaUser || ($mediaUser['status'] ?? '') !== 'approved') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    
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
    header('Cache-Control: public, max-age=31536000, immutable'); 
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    header('ETag: ' . $etag);
    
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    
    if ($ifNoneMatch === $etag || 
        ($ifModifiedSince && strtotime($ifModifiedSince) >= $lastModified)) {
        http_response_code(304);
        exit;
    }
    
    readfile($path);
    exit;
}

function call_deepseek(array $messages, int $max_tokens = null, float $temperature = null): ?string {
    $settings = read_json(SETTINGS_FILE);
    $apiKey = $settings['deepseek_api_key'] ?? '';
    $apiUrl = $settings['deepseek_api_url'] ?? '';
    $model = $settings['deepseek_model'] ?? 'deepseek-chat';
    if ($max_tokens === null) $max_tokens = (int)($settings['deepseek_max_tokens'] ?? 512);
    if ($temperature === null) $temperature = (float)($settings['deepseek_temperature'] ?? 0.8);
    if (!$apiKey || !$apiUrl) return null;
    
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
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

function contains_bot_trigger(string $text, string $bot_name): bool {
    return mb_strpos($text, '@' . $bot_name) !== false || mb_strpos($text, '＠' . $bot_name) !== false;
}
function get_bot_context(int $n, string $bot_name, string $bot_uid): array {
    $recent = read_chats_recent($n * 2); 
    $collected = [];
    foreach (array_reverse($recent) as $c) { 
        if (count($collected) >= $n) break;
        $role = ($c['uid'] ?? '') === $bot_uid ? 'assistant' : 'user';
        $text = trim(($c['text'] ?? '') . (isset($c['media']) ? ' [媒体]' : ''));
        if ($text === '') continue;
        array_unshift($collected, [
            'role' => $role,
            'content' => ($c['nickname'] ?? ($role === 'assistant' ? $bot_name : '用户')) . '：' . $text
        ]);
    }
    $messages = [];
    $messages[] = [
        'role' => 'system',
        'content' => '你是一个名为“' . $bot_name . '”的聊天机器人，语气友好、简洁、有帮助，能根据上下文给出相关回复。'
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
            session_regenerate_id(true);
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
        session_regenerate_id(true);
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

if ($action === 'delete_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$user || ($user['status'] ?? '') !== 'approved') {
        echo json_encode(['ok'=>false,'error'=>'未登录或账号未审核']);
        exit;
    }
    if (!check_csrf()) {
        echo json_encode(['ok'=>false,'error'=>'CSRF token 无效']);
        exit;
    }
    $msgId = trim($_POST['id'] ?? '');
    if (!$msgId) {
        echo json_encode(['ok'=>false,'error'=>'缺少消息ID']);
        exit;
    }
    $file = find_message_file_by_id($msgId);
    if (!$file) {
        echo json_encode(['ok'=>false,'error'=>'消息不存在']);
        exit;
    }
    $msg = json_decode(file_get_contents($file), true);
    if (!$msg) {
        echo json_encode(['ok'=>false,'error'=>'消息解析失败']);
        exit;
    }
    $isOwner = ($msg['uid'] ?? '') === $user['uid'];
    $isAdminUser = is_admin($user);
    if (!$isOwner && !$isAdminUser) {
        echo json_encode(['ok'=>false,'error'=>'无权删除此消息']);
        exit;
    }
    if (delete_message($msgId)) {
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'删除失败，请重试']);
    }
    exit;
}

if ($action === 'edit_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$user || ($user['status'] ?? '') !== 'approved') {
        echo json_encode(['ok'=>false,'error'=>'未登录或账号未审核']);
        exit;
    }
    if (!check_csrf()) {
        echo json_encode(['ok'=>false,'error'=>'CSRF token 无效']);
        exit;
    }
    $msgId = trim($_POST['id'] ?? '');
    $newText = trim($_POST['text'] ?? '');
    if (!$msgId || $newText === '') {
        echo json_encode(['ok'=>false,'error'=>'参数错误']);
        exit;
    }
    $file = find_message_file_by_id($msgId);
    if (!$file) {
        echo json_encode(['ok'=>false,'error'=>'消息不存在']);
        exit;
    }
    $msg = json_decode(file_get_contents($file), true);
    if (!$msg) {
        echo json_encode(['ok'=>false,'error'=>'消息解析失败']);
        exit;
    }
    $isOwner = ($msg['uid'] ?? '') === $user['uid'];
    $isAdminUser = is_admin($user);
    $now = time();
    $canEdit = false;
    if ($isAdminUser) {
        $canEdit = true;
    } elseif ($isOwner && ($now - (int)($msg['ts'] ?? 0)) <= EDIT_TIME_LIMIT) {
        $canEdit = true;
    }
    if (!$canEdit) {
        echo json_encode(['ok'=>false,'error'=>'无权编辑此消息或超过编辑时限（5分钟）']);
        exit;
    }
    if (update_message_text($msgId, $newText)) {
        $updatedMsg = json_decode(file_get_contents($file), true);
        echo json_encode(['ok'=>true, 'message'=> $updatedMsg]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'编辑失败']);
    }
    exit;
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) { 
        if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'未登录']); exit; }
        header('Location: ?action=home'); exit; 
    }
    if (($user['status'] ?? '') !== 'approved') { 
        if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'账号未通过审核']); exit; }
        header('Location: ?action=home'); exit; 
    }
    if (!check_csrf()) { 
        if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'CSRF invalid']); exit; }
        http_response_code(400); die('CSRF invalid'); 
    }

    $settings = read_json(SETTINGS_FILE);
    if (!is_admin($user)) {
        $window = max(1, (int)($settings['rate_limit_window'] ?? 60));
        $limitCount = max(1, (int)($settings['rate_limit_count'] ?? 10));
        $cutoff = time() - $window;
        $recentMsgs = read_chats_since($cutoff, 1000);
        $cnt = 0;
        foreach ($recentMsgs as $c) {
            if (($c['uid'] ?? '') === $user['uid']) $cnt++;
        }
        if ($cnt >= $limitCount) {
            if (is_ajax()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>false,'error'=>'发送过于频繁，请稍后再试']);
                exit;
            } else {
                $msg = '发送过于频繁，请稍后再试';
                header('Location: ?action=chat'); exit;
            }
        }
    }

    $text = trim($_POST['text'] ?? '');
    $media = null;
    $reply_to = trim($_POST['reply_to'] ?? '');
    if (!empty($_FILES['media']['name'])) {
        $file = $_FILES['media'];
        if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= MAX_UPLOAD_SIZE) {
            $det = detect_media_type_by_upload($file);
            if ($det) {
                $fname = media_safe_name($file['name']);
                move_uploaded_file($file['tmp_name'], MEDIA_DIR . '/' . $fname);
                $media = ['type' => $det['type'], 'file' => $fname, 'mime' => $det['mime']];
            } else {
                if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'不支持的媒体类型']); exit; }
                $msg = '不支持的媒体类型：仅支持部分图片或视频';
            }
        } else {
            if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'媒体上传失败或文件过大']); exit; }
            $msg = '媒体上传失败或文件过大';
        }
    }
    if ($text === '' && !$media) {
        if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'发送内容不能为空']); exit; }
        $msg = '发送内容不能为空';
    } else {
        $bot_name = $settings['bot_name'] ?? '哨哨';
        $bot_uid = $settings['bot_uid'] ?? 'BOT_SHAO';
        $bot_avatar = $settings['bot_avatar'] ?? '/Outpost.png';
        
        $newMsg = [
            'id' => bin2hex(random_bytes(8)),
            'uid' => $user['uid'],
            'nickname' => $user['nickname'],
            'avatar' => $user['avatar'] ?? '',
            'title' => $user['title_active'] ?? '',
            'text' => $text,
            'media' => $media,
            'ts' => now_ts(),
            'reply_to' => $reply_to !== '' ? $reply_to : null,
        ];
        append_chat_message($newMsg);

        if (contains_bot_trigger($text, $bot_name)) {
            $bot_enabled = (bool)($settings['bot_enabled'] ?? true);
            $bot_context = max(1, (int)($settings['bot_context'] ?? 5));
            if (!$bot_enabled) {
                $reply = $bot_name . '正在维护，敬请期待～';
                $botMsg = [
                    'id' => bin2hex(random_bytes(8)),
                    'uid' => $bot_uid,
                    'nickname' => $bot_name,
                    'avatar' => $bot_avatar,
                    'title' => '',
                    'text' => $reply,
                    'media' => null,
                    'ts' => now_ts(),
                ];
                append_chat_message($botMsg);
            } else {
                $context_messages = get_bot_context($bot_context, $bot_name, $bot_uid);
                $user_prompt = str_replace(['@' . $bot_name, '＠' . $bot_name], '', $text);
                $context_messages[] = ['role' => 'user', 'content' => $user['nickname'] . '：' . $user_prompt];
                $ai_reply = call_deepseek($context_messages);
                if ($ai_reply === null) {
                    $ai_reply = '服务器繁忙，请稍候再试';
                }
                $botMsg = [
                    'id' => bin2hex(random_bytes(8)),
                    'uid' => $bot_uid,
                    'nickname' => $bot_name,
                    'avatar' => $bot_avatar,
                    'title' => '',
                    'text' => $ai_reply,
                    'media' => null,
                    'ts' => now_ts(),
                ];
                append_chat_message($botMsg);
            }
        }

        if (is_ajax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true, 'message'=> $newMsg]);
            exit;
        } else {
            header('Location: ?action=chat'); exit;
        }
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
        session_regenerate_id(true);
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
if ($action === 'admin_disable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $uidTarget = trim($_POST['uid'] ?? '');
    $users = read_json(USERS_FILE);
    foreach ($users as &$u) {
        if (($u['uid'] ?? '') === $uidTarget) { $u['status'] = 'pending'; break; }
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
if ($action === 'admin_clear_chats' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    if (!check_csrf()) { http_response_code(400); die('CSRF invalid'); }
    $count = clear_all_messages();
    header('Location: ?action=admin&clear=1&count=' . $count); exit;
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
    $bot_name = trim($_POST['bot_name'] ?? '哨哨');
    $bot_uid = trim($_POST['bot_uid'] ?? 'BOT_SHAO');
    $bot_avatar = trim($_POST['bot_avatar'] ?? '/Outpost.png');
    
    $deepseek_api_key = trim($_POST['deepseek_api_key'] ?? '');
    $deepseek_api_url = trim($_POST['deepseek_api_url'] ?? '');
    $deepseek_model = trim($_POST['deepseek_model'] ?? 'deepseek-chat');
    $deepseek_max_tokens = max(1, (int)($_POST['deepseek_max_tokens'] ?? 512));
    $deepseek_temperature = max(0.0, min(2.0, (float)($_POST['deepseek_temperature'] ?? 0.8)));
    
    $rate_window = max(1, (int)($_POST['rate_limit_window'] ?? 60));
    $rate_count = max(1, (int)($_POST['rate_limit_count'] ?? 10));
    
    $settings = read_json(SETTINGS_FILE);
    $settings['chat_history_limit'] = max(10, min(1000, $limit));
    $settings['allow_register'] = $allow;
    $settings['site_title'] = $title !== '' ? $title : APP_NAME;
    $settings['theme'] = in_array($theme, ['auto','light','dark'], true) ? $theme : 'auto';
    $settings['bot_enabled'] = $bot_enabled;
    $settings['bot_context'] = $bot_context;
    $settings['bot_name'] = $bot_name;
    $settings['bot_uid'] = $bot_uid;
    $settings['bot_avatar'] = $bot_avatar;
    $settings['deepseek_api_key'] = $deepseek_api_key;
    $settings['deepseek_api_url'] = $deepseek_api_url;
    $settings['deepseek_model'] = $deepseek_model;
    $settings['deepseek_max_tokens'] = $deepseek_max_tokens;
    $settings['deepseek_temperature'] = $deepseek_temperature;
    $settings['rate_limit_window'] = $rate_window;
    $settings['rate_limit_count'] = $rate_count;
    
    write_json(SETTINGS_FILE, $settings);
    header('Location: ?action=admin&ok=1'); exit;
}

if ($action === 'chats') {
    if (!$user || ($user['status'] ?? '') !== 'approved') { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
    $since = (int)($_GET['since'] ?? 0);
    $before = isset($_GET['before']) ? (int)$_GET['before'] : null;
    $limit = min(50, (int)($_GET['limit'] ?? 50));
    $settings = read_json(SETTINGS_FILE);
    $defaultLimit = (int)($settings['chat_history_limit'] ?? DEFAULT_CHAT_HISTORY);
    
    if ($before !== null && $before > 0) {
        $messages = read_chats_before($before, $limit);
        $latest_ts = !empty($messages) ? max(array_column($messages, 'ts')) : 0;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['messages'=>$messages, 'latest_ts'=> $latest_ts, 'has_more'=> count($messages) === $limit], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($since > 0) {
        $messages = read_chats_since($since, 100); 
        $latest_ts = !empty($messages) ? max(array_column($messages, 'ts')) : $since;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['messages'=>$messages, 'latest_ts'=> $latest_ts], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $messages = read_chats_recent($defaultLimit);
    $latest_ts = !empty($messages) ? max(array_column($messages, 'ts')) : 0;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['messages'=>$messages, 'latest_ts'=> $latest_ts], JSON_UNESCAPED_UNICODE);
    exit;
}

$settings = read_json(SETTINGS_FILE);
$limit = (int)($settings['chat_history_limit'] ?? DEFAULT_CHAT_HISTORY);
$titleText = $settings['site_title'] ?? APP_NAME;
$prefTheme = $settings['theme'] ?? 'auto';

function render_head(string $pageTitle) {
    global $prefTheme, $titleText, $settings, $user;
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($pageTitle) . ' - ' . e($titleText) . '</title>';
    echo '<meta name="description" content="前方的哨所 · Chat">';
    echo '<link rel="icon" href="/Outpost.png">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />';
    echo '<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>';
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
    flex-wrap: nowrap; 
}

.actions .nav-link {
    display: none;
    align-items: center;
    height: 42px;
    padding: 0 16px;
    border-radius: 21px;
    background: transparent;
    color: var(--muted);
    text-decoration: none;
    font-weight: 500;
    transition: var(--transition-fast);
    gap: 8px;
}

.actions .nav-link i {
    font-size: 18px;
}

.actions .nav-link:hover {
    background: var(--primary-light);
    color: var(--primary);
}

.actions .nav-link.active {
    color: var(--primary);
    background: var(--primary-light);
}

.actions .icon-btn {
    margin-left: 12px;
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
    flex-direction: column-reverse; 
    gap: 16px;
    scroll-behavior: smooth; 
}

.chat-list::-webkit-scrollbar {
    width: 6px;
}

.chat-list::-webkit-scrollbar-track {
    background: transparent;
}

.chat-list::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 3px;
}

.chat-list::-webkit-scrollbar-thumb:hover {
    background: var(--primary-hover);
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

.reply-preview {
    background: var(--surface);
    border-left: 3px solid var(--primary);
    padding: 6px 12px;
    margin-bottom: 8px;
    font-size: 13px;
    color: var(--muted);
    border-radius: 8px;
    cursor: pointer;
    transition: var(--transition-fast);
}
.reply-preview:hover {
    background: var(--primary-light);
    color: var(--fg);
}
.reply-preview strong {
    color: var(--primary);
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

.tools-grid {
    display: flex;
    flex-direction: column;
    gap: 24px;
    max-width: 900px;
    margin: 0 auto;
}
.tool-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--border-radius-lg);
    padding: 20px;
    transition: var(--transition-base);
}
.tool-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.tool-title {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.ocr-preview {
    margin: 16px 0;
    max-width: 100%;
}
.ocr-preview img {
    max-width: 100%;
    max-height: 300px;
    border-radius: var(--border-radius);
    border: 1px solid var(--border);
}
.result-area {
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--border-radius);
    padding: 12px;
    margin: 12px 0;
    white-space: pre-wrap;
    word-break: break-all;
    font-family: var(--font-mono);
    font-size: 14px;
}
.base64-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.base64-row textarea {
    flex: 1;
    min-height: 120px;
}
.copy-btn {
    background: var(--primary-light);
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
    padding: 8px 16px;
    cursor: pointer;
    font-size: 13px;
    transition: var(--transition-fast);
}
.copy-btn:hover {
    background: var(--primary);
    color: white;
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

@media (min-width: 1024px) {
    .actions .nav-link {
        display: inline-flex;
    }
    .mobile-nav {
        display: none;
    }

    body {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    header {
        order: 0;
    }

    main {
        flex: 1;
        display: flex;
        flex-direction: column;
        order: 2;
    }

    .chat-page {
        height: 100%;
        flex: 1;
        max-width: 1000px;
        margin: 0 auto;
        width: 100%;
    }

    .chat-list {
        padding-bottom: calc(var(--inputbar-height) + 30px);
        padding-left: 24px;
        padding-right: 24px;
    }

    .chat-inputbar {
        left: 50%;
        right: auto;
        transform: translateX(-50%);
        width: 90%;
        max-width: 960px;
        bottom: 30px;
    }

    .img-thumb, .video-thumb {
        max-width: 480px;
    }

    .bubble-body {
        max-width: 700px;
    }

    .profile {
        max-width: 960px;
        padding: 0 24px;
    }

    .profile-card {
        padding: 32px;
    }

    .table th,
    .table td {
        padding: 14px 16px;
    }

    .profile-card form {
        grid-template-columns: 1fr 1fr 1fr !important;
    }
}

    <?php
    echo '</style>';
    echo '<script>';
    ?>
    const currentUserIsAdmin = <?php echo json_encode(is_admin($user)); ?>;
    const EDIT_TIME_LIMIT_SEC = <?php echo EDIT_TIME_LIMIT; ?>;

    class MediaCacheManager {
        constructor() {
            this.dbName = 'ChatAppMediaCache';
            this.storeName = 'mediaFiles';
            this.db = null;
            this.cacheEnabled = false;
            this.maxCacheSize = 200 * 1024 * 1024; 
            this.currentSize = 0;
        }

        async init() {
            if (!window.indexedDB) {
                console.warn('浏览器不支持IndexedDB，缓存功能已禁用');
                this.cacheEnabled = false;
                return;
            }

            return new Promise((resolve) => {
                try {
                    const request = indexedDB.open(this.dbName, 1);
                    
                    request.onerror = () => {
                        console.warn('IndexedDB初始化失败，缓存功能已禁用');
                        this.cacheEnabled = false;
                        resolve();
                    };
                    
                    request.onsuccess = (event) => {
                        this.db = event.target.result;
                        this.cacheEnabled = true;
                        this.calculateCacheSize().then(resolve);
                    };
                    
                    request.onupgradeneeded = (event) => {
                        const db = event.target.result;
                        if (!db.objectStoreNames.contains(this.storeName)) {
                            const store = db.createObjectStore(this.storeName, { keyPath: 'url' });
                            store.createIndex('timestamp', 'timestamp', { unique: false });
                            store.createIndex('type', 'type', { unique: false });
                        }
                    };
                } catch (e) {
                    console.warn('IndexedDB初始化异常:', e);
                    this.cacheEnabled = false;
                    resolve();
                }
            });
        }

        async calculateCacheSize() {
            if (!this.db) return;
            return new Promise((resolve) => {
                const transaction = this.db.transaction([this.storeName], 'readonly');
                const store = transaction.objectStore(this.storeName);
                const request = store.getAll();
                
                request.onsuccess = () => {
                    const items = request.result;
                    let totalSize = 0;
                    items.forEach(item => {
                        if (item.data && item.data.blob) {
                            totalSize += item.data.blob.size || 0;
                        }
                    });
                    this.currentSize = totalSize;
                    resolve();
                };
                
                request.onerror = () => resolve();
            });
        }

        async get(url) {
            if (!this.cacheEnabled || !this.db) return null;
            
            try {
                const transaction = this.db.transaction([this.storeName], 'readonly');
                const store = transaction.objectStore(this.storeName);
                const request = store.get(url);
                
                return new Promise((resolve) => {
                    request.onsuccess = () => {
                        const result = request.result;
                        if (result && result.data && result.data.blob) {
                            const now = Date.now();
                            if (now - result.timestamp > 7 * 24 * 60 * 60 * 1000) {
                                this.remove(url);
                                resolve(null);
                                return;
                            }
                            
                            const blobUrl = URL.createObjectURL(result.data.blob);
                            resolve({
                                url: blobUrl,
                                type: result.type,
                                originalUrl: url,
                                timestamp: result.timestamp
                            });
                        } else {
                            resolve(null);
                        }
                    };
                    request.onerror = () => resolve(null);
                });
            } catch (e) {
                console.warn('缓存读取失败:', e);
                return null;
            }
        }

        async set(url, blob, type) {
            if (!this.cacheEnabled || !this.db) return;
            
            try {
                await this.cleanupIfNeeded(blob.size);
                
                const item = {
                    url: url,
                    type: type,
                    data: { blob: blob },
                    timestamp: Date.now(),
                    size: blob.size
                };
                
                const transaction = this.db.transaction([this.storeName], 'readwrite');
                const store = transaction.objectStore(this.storeName);
                store.put(item);
                
                this.currentSize += blob.size;
            } catch (e) {
                console.warn('缓存存储失败:', e);
            }
        }

        async remove(url) {
            if (!this.cacheEnabled || !this.db) return;
            
            try {
                const transaction = this.db.transaction([this.storeName], 'readwrite');
                const store = transaction.objectStore(this.storeName);
                const request = store.get(url);
                
                request.onsuccess = () => {
                    const result = request.result;
                    if (result && result.size) {
                        this.currentSize -= result.size;
                    }
                    store.delete(url);
                };
            } catch (e) {
                console.warn('缓存删除失败:', e);
            }
        }

        async cleanupIfNeeded(newItemSize) {
            if (this.currentSize + newItemSize <= this.maxCacheSize) return;
            
            try {
                const transaction = this.db.transaction([this.storeName], 'readonly');
                const store = transaction.objectStore(this.storeName);
                const index = store.index('timestamp');
                const request = index.getAll();
                
                return new Promise((resolve) => {
                    request.onsuccess = () => {
                        const items = request.result.sort((a, b) => a.timestamp - b.timestamp);
                        let sizeToRemove = this.currentSize + newItemSize - this.maxCacheSize;
                        let removedSize = 0;
                        
                        const cleanupTransaction = this.db.transaction([this.storeName], 'readwrite');
                        const cleanupStore = cleanupTransaction.objectStore(this.storeName);
                        
                        for (let item of items) {
                            if (removedSize >= sizeToRemove) break;
                            cleanupStore.delete(item.url);
                            removedSize += item.size || 0;
                            this.currentSize -= (item.size || 0);
                        }
                        
                        resolve();
                    };
                    request.onerror = () => resolve();
                });
            } catch (e) {
                console.warn('缓存清理失败:', e);
            }
        }

        async clear() {
            if (!this.cacheEnabled || !this.db) return;
            
            try {
                const transaction = this.db.transaction([this.storeName], 'readwrite');
                const store = transaction.objectStore(this.storeName);
                store.clear();
                this.currentSize = 0;
            } catch (e) {
                console.warn('缓存清空失败:', e);
            }
        }

        getCacheInfo() {
            return {
                enabled: this.cacheEnabled,
                size: this.currentSize,
                maxSize: this.maxCacheSize,
                usage: (this.currentSize / this.maxCacheSize * 100).toFixed(2) + '%'
            };
        }
    }

    const mediaCache = new MediaCacheManager();

    async function renderMediaWithCache(media, messageId) {
        if (!media) return '';
        
        const src = media.file ? `?action=media&file=${encodeURIComponent(media.file)}` : '';
        if (!src) return '';
        
        if (!mediaCache.cacheEnabled) {
            return renderMediaOriginal(media, src);
        }
        
        const cacheKey = src;
        
        if (media.type === 'image') {
            try {
                const cached = await mediaCache.get(cacheKey);
                if (cached) {
                    return `<div class="media"><img class="img-thumb" src="${cached.url}" alt="image" onclick="openModalMedia('${cached.url}', false)" data-original="${src}" data-cached="true" title="已缓存"></div>`;
                }
            } catch (e) {
                console.warn('缓存读取失败:', e);
            }
            
            setTimeout(async () => {
                try {
                    const response = await fetch(src);
                    if (response.ok) {
                        const blob = await response.blob();
                        if (blob.type.startsWith('image/')) {
                            await mediaCache.set(cacheKey, blob, 'image');
                            const img = document.querySelector(`img[data-original="${src}"]`);
                            if (img) {
                                const blobUrl = URL.createObjectURL(blob);
                                img.src = blobUrl;
                                img.dataset.cached = "true";
                                img.title = "已缓存";
                            }
                        }
                    }
                } catch (e) {
                    console.warn('图片缓存失败:', e);
                }
            }, 50);
            
            return `<div class="media"><img class="img-thumb" src="${src}" alt="image" onclick="openModalMedia('${src}', false)" data-original="${src}" data-cached="false" title="加载中..."></div>`;
        }
        
        if (media.type === 'video') {
            try {
                const cached = await mediaCache.get(cacheKey);
                if (cached) {
                    return `<div class="media">
                        <video class="video-thumb" src="${cached.url}" preload="metadata" muted
                               onloadedmetadata="this.currentTime=0.1"
                               controls onclick="openModalMedia('${cached.url}', true)" data-original="${src}" data-cached="true" title="已缓存"></video>
                    </div>`;
                }
            } catch (e) {
                console.warn('缓存读取失败:', e);
            }
            
            setTimeout(async () => {
                try {
                    const response = await fetch(src, { method: 'HEAD' });
                    const contentLength = response.headers.get('content-length');
                    if (contentLength && parseInt(contentLength) < 20 * 1024 * 1024) {
                        const fullResponse = await fetch(src);
                        if (fullResponse.ok) {
                            const blob = await fullResponse.blob();
                            if (blob.type.startsWith('video/')) {
                                await mediaCache.set(cacheKey, blob, 'video');
                            }
                        }
                    }
                } catch (e) {
                    console.warn('视频缓存失败:', e);
                }
            }, 100);
            
            return `<div class="media">
                <video class="video-thumb" src="${src}" preload="metadata" muted
                       onloadedmetadata="this.currentTime=0.1"
                       controls onclick="openModalMedia('${src}', true)" data-original="${src}" data-cached="false" title="在线播放"></video>
            </div>`;
        }
        
        return '';
    }

    function renderMediaOriginal(media, src) {
        if (media.type === 'image') {
            return `<div class="media"><img class="img-thumb" src="${src}" alt="image" onclick="openModalMedia('${src}', false)"></div>`;
        }
        if (media.type === 'video') {
            return `<div class="media">
                <video class="video-thumb" src="${src}" preload="metadata" muted
                       onloadedmetadata="this.currentTime=0.1"
                       controls onclick="openModalMedia('${src}', true)"></video>
            </div>`;
        }
        return '';
    }

    function getCurrentCsrf() {
        const input = document.querySelector('input[name="csrf"]');
        return input ? input.value : '';
    }

    (function(){
        window.getCsrf = getCurrentCsrf;
        window.csrf = getCurrentCsrf(); 
        
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

    document.addEventListener('DOMContentLoaded', async function(){
        await mediaCache.init();
        
        if (!localStorage.getItem('cacheWelcomeShown')) {
            const info = mediaCache.getCacheInfo();
            if (info.enabled) {
                console.log(`🎯 媒体缓存已启用 - 当前使用: ${info.usage}`);
            }
        }
        
        const headerActions = document.querySelector('.actions');
        if (headerActions) {
            const cacheBtn = document.createElement('button');
            cacheBtn.className = 'icon-btn';
            cacheBtn.title = '缓存管理';
            cacheBtn.innerHTML = '<i class="fa fa-database"></i>';
            cacheBtn.onclick = function() {
                const info = mediaCache.getCacheInfo();
                const message = `媒体缓存状态:\n\n已使用: ${formatBytes(info.size)}\n最大限制: ${formatBytes(info.maxSize)}\n使用率: ${info.usage}\n\n功能选项:\n1. 查看统计详情\n2. 清空所有缓存\n3. 取消`;
                const choice = prompt(message + "\n\n请输入选项 (1/2/3):");
                if (choice === '1') {
                    showCacheStats();
                } else if (choice === '2') {
                    if (confirm('确定要清空所有媒体缓存吗？')) {
                        mediaCache.clear().then(() => {
                            alert('缓存已清空');
                        });
                    }
                }
            };
            headerActions.appendChild(cacheBtn);
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        async function showCacheStats() {
            if (!mediaCache.db) {
                alert('缓存未初始化或不支持');
                return;
            }

            try {
                const transaction = mediaCache.db.transaction([mediaCache.storeName], 'readonly');
                const store = transaction.objectStore(mediaCache.storeName);
                const request = store.getAll();

                request.onsuccess = () => {
                    const items = request.result;
                    if (items.length === 0) {
                        alert('缓存中没有文件');
                        return;
                    }

                    let stats = {
                        total: items.length,
                        images: 0,
                        videos: 0,
                        totalSize: 0,
                        oldest: null,
                        newest: null
                    };

                    items.forEach(item => {
                        if (item.type === 'image') stats.images++;
                        if (item.type === 'video') stats.videos++;
                        stats.totalSize += item.size || 0;
                        
                        if (!stats.oldest || item.timestamp < stats.oldest.timestamp) {
                            stats.oldest = { url: item.url, timestamp: item.timestamp };
                        }
                        if (!stats.newest || item.timestamp > stats.newest.timestamp) {
                            stats.newest = { url: item.url, timestamp: item.timestamp };
                        }
                    });

                    const oldestDate = new Date(stats.oldest.timestamp).toLocaleString();
                    const newestDate = new Date(stats.newest.timestamp).toLocaleString();

                    alert(`缓存详细统计:\n\n总数: ${stats.total} 个文件\n图片: ${stats.images} 个\n视频: ${stats.videos} 个\n总大小: ${formatBytes(stats.totalSize)}\n\n最早缓存: ${oldestDate}\n最新缓存: ${newestDate}\n\n平均文件大小: ${formatBytes(stats.totalSize / stats.total)}`);
                };
            } catch (e) {
                alert('无法获取统计详情: ' + e.message);
            }
        }
        
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
        let oldestTs = null;      
        let isLoadingMore = false;
        let hasMoreHistory = true;
        const seenIds = new Set();

        async function fetchChats(initial = false) {
            try {
                let url = '?action=chats';
                if (initial) {
                    url += '&since=0';
                } else if (latestTs > 0) {
                    url += '&since=' + latestTs;
                }
                const res = await fetch(url);
                const data = await res.json();
                if (!data || !Array.isArray(data.messages)) return;

                const list = document.getElementById('chat-list');
                if (!list) return;

                const me = list.getAttribute('data-me');
                const createBubble = async m => {
                    const el = document.createElement('div');
                    el.className = 'bubble' + ((m.uid === me) ? ' self' : '');
                    el.dataset.msgId = m.id || '';
                    const avatarSrc = (m.uid === '<?php echo e($settings['bot_uid'] ?? 'BOT_SHAO'); ?>') ? '<?php echo e($settings['bot_avatar'] ?? '/Outpost.png'); ?>' : (m.avatar ? ('?action=media&file=' + encodeURIComponent(m.avatar)) : 'data:image/svg+xml;base64,<?php echo base64_encode("<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'44\' height=\'44\'><rect width=\'44\' height=\'44\' fill=\'#e6eef8\'/></svg>"); ?>');
                    const mediaHtml = m.media ? await renderMediaWithCache(m.media, m.id) : '';
                    const editedHtml = m.edited ? ' <span class="system-note edited-badge" title="编辑于 ' + (m.edited_ts ? new Date(m.edited_ts*1000).toLocaleString() : '') + '">已编辑</span>' : '';
                    const actionButtons = await getActionButtons(m, me);
                    
                    let replyHtml = '';
                    if (m.reply_preview) {
                        const previewText = m.reply_preview.text;
                        replyHtml = `<div class="reply-preview" data-reply-id="${m.reply_to}">
                                        <i class="fa fa-reply" style="font-size:12px"></i> 回复 <strong>${escapeHtml(m.reply_preview.nickname)}</strong>：
                                        ${escapeHtml(previewText)}
                                    </div>`;
                    } else if (m.reply_to) {
                        replyHtml = `<div class="reply-preview" data-reply-id="${m.reply_to}" style="opacity:0.6">
                                        <i class="fa fa-reply"></i> 回复的消息已不存在
                                    </div>`;
                    }
                    
                    el.innerHTML = `
                        <img class="avatar" src="${avatarSrc}" alt="avatar">
                        <div class="bubble-body">
                            ${replyHtml}
                            <div class="meta">
                                <span class="nick">${escapeHtml(m.nickname||'未知')}</span>
                                ${m.title ? `<span class="title-badge">${escapeHtml(m.title)}</span>` : ''}
                                <span class="uid">UID: ${escapeHtml(m.uid||'')}</span>
                                <span class="system-note">${formatTs(m.ts||Date.now()/1000)}</span>
                                ${editedHtml}
                                ${actionButtons}
                            </div>
                            ${m.text ? `<div class="text" data-text-original="${escapeHtml(m.text)}">${escapeHtml(m.text)}</div>` : ''}
                            ${mediaHtml}
                        </div>
                    `;
                    return el;
                };

                if (initial) {
                    list.innerHTML = '';
                    seenIds.clear();
                    const reversedMessages = [...data.messages].reverse();
                    for (const m of reversedMessages) {
                        if (!m.id) continue;
                        if (seenIds.has(m.id)) continue;
                        seenIds.add(m.id);
                        const bubble = await createBubble(m);
                        list.appendChild(bubble);
                        latestTs = Math.max(latestTs, Number(m.ts || 0));
                        if (oldestTs === null || Number(m.ts) < oldestTs) oldestTs = Number(m.ts);
                    }
                } else {
                    for (let i = 0; i < data.messages.length; i++) {
                        const m = data.messages[i];
                        if (!m.id) continue;
                        if (seenIds.has(m.id)) continue;
                        const bubble = await createBubble(m);
                        list.insertBefore(bubble, list.firstChild);
                        seenIds.add(m.id);
                        latestTs = Math.max(latestTs, Number(m.ts || 0));
                        if (oldestTs === null || Number(m.ts) < oldestTs) oldestTs = Number(m.ts);
                    }
                }

                if ((data.latest_ts || 0) > latestTs) latestTs = data.latest_ts;
                if (data.has_more === false) hasMoreHistory = false;
            } catch(e) {
                console.error('Fetch chats error:', e);
            }
        }

        async function loadMoreHistory() {
            if (isLoadingMore || !hasMoreHistory || oldestTs === null) return;
            isLoadingMore = true;
            try {
                const res = await fetch(`?action=chats&before=${oldestTs}&limit=30`);
                const data = await res.json();
                if (data && Array.isArray(data.messages) && data.messages.length > 0) {
                    const list = document.getElementById('chat-list');
                    const me = list.getAttribute('data-me');
                    const createBubble = async m => {
                        const el = document.createElement('div');
                        el.className = 'bubble' + ((m.uid === me) ? ' self' : '');
                        el.dataset.msgId = m.id || '';
                        const avatarSrc = (m.uid === '<?php echo e($settings['bot_uid'] ?? 'BOT_SHAO'); ?>') ? '<?php echo e($settings['bot_avatar'] ?? '/Outpost.png'); ?>' : (m.avatar ? ('?action=media&file=' + encodeURIComponent(m.avatar)) : 'data:image/svg+xml;base64,<?php echo base64_encode("<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'44\' height=\'44\'><rect width=\'44\' height=\'44\' fill=\'#e6eef8\'/></svg>"); ?>');
                        const mediaHtml = m.media ? await renderMediaWithCache(m.media, m.id) : '';
                        const editedHtml = m.edited ? ' <span class="system-note edited-badge" title="编辑于 ' + (m.edited_ts ? new Date(m.edited_ts*1000).toLocaleString() : '') + '">已编辑</span>' : '';
                        const actionButtons = await getActionButtons(m, me);
                        let replyHtml = '';
                        if (m.reply_preview) {
                            replyHtml = `<div class="reply-preview" data-reply-id="${m.reply_to}">
                                            <i class="fa fa-reply"></i> 回复 <strong>${escapeHtml(m.reply_preview.nickname)}</strong>：
                                            ${escapeHtml(m.reply_preview.text)}
                                        </div>`;
                        } else if (m.reply_to) {
                            replyHtml = `<div class="reply-preview" data-reply-id="${m.reply_to}" style="opacity:0.6">
                                            <i class="fa fa-reply"></i> 回复的消息已不存在
                                        </div>`;
                        }
                        el.innerHTML = `
                            <img class="avatar" src="${avatarSrc}" alt="avatar">
                            <div class="bubble-body">
                                ${replyHtml}
                                <div class="meta">
                                    <span class="nick">${escapeHtml(m.nickname||'未知')}</span>
                                    ${m.title ? `<span class="title-badge">${escapeHtml(m.title)}</span>` : ''}
                                    <span class="uid">UID: ${escapeHtml(m.uid||'')}</span>
                                    <span class="system-note">${formatTs(m.ts||Date.now()/1000)}</span>
                                    ${editedHtml}
                                    ${actionButtons}
                                </div>
                                ${m.text ? `<div class="text">${escapeHtml(m.text)}</div>` : ''}
                                ${mediaHtml}
                            </div>
                        `;
                        return el;
                    };
                    for (let i = data.messages.length - 1; i >= 0; i--) {
                        const m = data.messages[i];
                        if (seenIds.has(m.id)) continue;
                        seenIds.add(m.id);
                        const bubble = await createBubble(m);
                        list.appendChild(bubble);
                        if (oldestTs === null || Number(m.ts) < oldestTs) oldestTs = Number(m.ts);
                    }
                    if (data.has_more === false) hasMoreHistory = false;
                } else {
                    hasMoreHistory = false;
                }
            } catch(e) {
                console.warn('加载历史消息失败', e);
            } finally {
                isLoadingMore = false;
            }
        }

        const chatList = document.getElementById('chat-list');
        if (chatList) {
            chatList.addEventListener('scroll', () => {
                if (chatList.scrollTop === 0 && !isLoadingMore && hasMoreHistory) {
                    loadMoreHistory();
                }
            });
        }

        async function getActionButtons(msg, currentUid) {
            const isOwner = msg.uid === currentUid;
            const isAdmin = currentUserIsAdmin;
            if (!isOwner && !isAdmin) return '';
            const nowSec = Math.floor(Date.now() / 1000);
            const canEdit = isAdmin || (isOwner && (nowSec - (msg.ts || 0)) <= EDIT_TIME_LIMIT_SEC);
            let btns = '<span style="margin-left:auto;display:flex;gap:6px;">';
            btns += `<i class="fa fa-reply" style="cursor:pointer;opacity:0.6" onclick="setReplyTo('${msg.id}', '${escapeHtml(msg.nickname)}')" title="回复"></i>`;
            if (canEdit) btns += `<i class="fa fa-pencil-alt" style="cursor:pointer;opacity:0.6" onclick="editMessage('${msg.id}')" title="编辑"></i>`;
            btns += `<i class="fa fa-trash-alt" style="cursor:pointer;opacity:0.6" onclick="deleteMessage('${msg.id}')" title="删除"></i>`;
            btns += '</span>';
            return btns;
        }

        window.setReplyTo = function(msgId, nickname) {
            window.currentReplyTo = msgId;
            const input = document.getElementById('text-input');
            if (input) {
                input.focus();
                input.placeholder = `正在回复 @${nickname} ...`;
            }
            setTimeout(() => {
                if (window.currentReplyTo === msgId) {
                    window.currentReplyTo = null;
                    if (input) input.placeholder = `可用 @${botName} 调用机器人`;
                }
            }, 30000);
        };

        window.deleteMessage = async function(msgId) {
            if (!confirm('确定要删除这条消息吗？')) return;
            const csrfToken = getCurrentCsrf();
            if (!csrfToken) {
                alert('安全验证失败，请刷新页面后重试');
                return;
            }
            try {
                const formData = new FormData();
                formData.append('csrf', csrfToken);
                formData.append('id', msgId);
                const res = await fetch('?action=delete_message', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.ok) {
                    const el = document.querySelector(`.bubble[data-msg-id="${msgId}"]`);
                    if (el) el.remove();
                    if (seenIds) seenIds.delete(msgId);
                } else {
                    alert(data.error || '删除失败');
                }
            } catch(e) {
                console.error(e);
                alert('请求失败：' + (e.message || '网络错误'));
            }
        };

        window.editMessage = async function(msgId) {
            const bubble = document.querySelector(`.bubble[data-msg-id="${msgId}"]`);
            if (!bubble) return;
            const textDiv = bubble.querySelector('.text');
            if (!textDiv) return;
            const oldText = textDiv.innerText;
            const newText = prompt('编辑消息:', oldText);
            if (newText === null || newText === oldText) return;
            const csrfToken = getCurrentCsrf();
            if (!csrfToken) {
                alert('安全验证失败，请刷新页面后重试');
                return;
            }
            try {
                const formData = new FormData();
                formData.append('csrf', csrfToken);
                formData.append('id', msgId);
                formData.append('text', newText);
                const res = await fetch('?action=edit_message', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.ok) {
                    textDiv.innerText = newText;
                    textDiv.setAttribute('data-text-original', escapeHtml(newText));
                    const meta = bubble.querySelector('.meta');
                    if (meta && !meta.querySelector('.edited-badge')) {
                        const badge = document.createElement('span');
                        badge.className = 'system-note edited-badge';
                        badge.innerText = '已编辑';
                        if (data.message && data.message.edited_ts) {
                            badge.title = '编辑于 ' + new Date(data.message.edited_ts * 1000).toLocaleString();
                        }
                        meta.insertBefore(badge, meta.querySelector('.fa') || null);
                    }
                } else {
                    alert(data.error || '编辑失败');
                }
            } catch(e) {
                console.error(e);
                alert('请求失败：' + (e.message || '网络错误'));
            }
        };

        document.addEventListener('click', function(e) {
            const target = e.target.closest('.reply-preview');
            if (!target) return;
            const replyId = target.getAttribute('data-reply-id');
            if (!replyId) return;
            const targetBubble = document.querySelector(`.bubble[data-msg-id="${replyId}"]`);
            if (targetBubble) {
                targetBubble.scrollIntoView({ behavior: 'smooth', block: 'center' });
                targetBubble.style.transition = 'background 0.3s';
                targetBubble.style.background = 'var(--primary-light)';
                setTimeout(() => { targetBubble.style.background = ''; }, 1500);
            } else {
                alert('引用的消息不在当前聊天记录显示范围内，无法定位。');
            }
        });

        window.fetchChats = fetchChats;
        const list = document.getElementById('chat-list');
        if (list) { fetchChats(true); setInterval(fetchChats, 1000); }

        const sendForm = document.getElementById('send-form');
        if (sendForm) {
            sendForm.addEventListener('submit', async function(ev){
                ev.preventDefault();
                const textInput = document.getElementById('text-input');
                const mediaInput = document.getElementById('media-input');
                const sendBtn = document.getElementById('send-btn');
                const csrfInput = sendForm.querySelector('input[name="csrf"]');
                const originalText = textInput ? textInput.value : '';
                const file = mediaInput && mediaInput.files && mediaInput.files[0] ? mediaInput.files[0] : null;
                if (textInput) textInput.value = '';
                if (mediaInput) mediaInput.value = '';
                const fd = new FormData();
                if (csrfInput) fd.append('csrf', csrfInput.value);
                fd.append('text', originalText);
                if (file) fd.append('media', file);
                if (window.currentReplyTo) {
                    fd.append('reply_to', window.currentReplyTo);
                    window.currentReplyTo = null;
                    if (textInput) textInput.placeholder = `可用 @${botName} 调用机器人`;
                }
                try {
                    sendBtn && (sendBtn.disabled = true);
                    const res = await fetch('?action=send', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} });
                    const data = await res.json();
                    if (!data || data.ok !== true) {
                        alert(data && data.error ? data.error : '发送失败');
                    }
                } catch (e) {
                    console.error('send error', e);
                    alert('发送失败');
                } finally {
                    sendBtn && (sendBtn.disabled = false);
                }
            });
        }

        window.escapeHtml = function(s){ const d=document.createElement('div'); d.innerText = s ?? ''; return d.innerHTML; };
        window.formatTs = function(ts){ const d=new Date(ts*1000); const y=d.getFullYear(); const m=(d.getMonth()+1).toString().padStart(2,'0'); const dd=d.getDate().toString().padStart(2,'0'); const hh=d.getHours().toString().padStart(2,'0'); const mm=d.getMinutes().toString().padStart(2,'0'); return `${y}-${m}-${dd} ${hh}:${mm}`; };
        window.renderMedia = async function(m, messageId){
            return await renderMediaWithCache(m, messageId || 'msg-' + Math.random().toString(36).substr(2, 9));
        };
        window.openModalMedia = function(src,isVideo){ const modal=document.getElementById('modal'); const box=document.getElementById('modal-box'); box.innerHTML=''; if(isVideo){ const v=document.createElement('video'); v.src=src; v.controls=true; v.autoplay=true; box.appendChild(v);} else { const img=document.createElement('img'); img.src=src; box.appendChild(img);} modal.classList.add('active'); };
        window.closeModal = function(){ document.getElementById('modal').classList.remove('active'); };
        
        const botName = '<?php echo e($settings['bot_name'] ?? '哨哨'); ?>';
    });
    <?php
    echo '</script>';
    echo '</head><body>';
}

function render_header(): void {
    global $titleText;
    $user = current_user();
    $show_nav = $user && ($user['status'] ?? '') === 'approved';
    $current_action = $GLOBALS['action'] ?? '';
    echo '<header><div class="brand">';
    echo '<div class="logo"></div>';
    echo '<div id="app-title" class="app-title">' . e($titleText) . '</div>';
    echo '<div class="actions">';
    if ($show_nav) {
        $chat_active = $current_action === 'chat' ? 'active' : '';
        $me_active = $current_action === 'me' ? 'active' : '';
        $tools_active = $current_action === 'tools' ? 'active' : '';
        echo '<a class="nav-link ' . $chat_active . '" href="?action=chat"><i class="fa fa-comments"></i><span>聊天</span></a>';
        echo '<a class="nav-link ' . $tools_active . '" href="?action=tools"><i class="fa fa-tools"></i><span>工具</span></a>';
        echo '<a class="nav-link ' . $me_active . '" href="?action=me"><i class="fa fa-user"></i><span>我的</span></a>';
    }
    echo '<button class="icon-btn" title="切换主题" onclick="toggleTheme()"><i class="fa fa-sun"></i></button>';
    echo '</div></div></header>';
}

function render_footer_nav(string $active) {
    echo '<nav class="mobile-nav">';
    echo '<a class="nav-item '.($active==='chat'?'active':'').'" href="?action=chat"><i class="fa fa-comments"></i><div>聊天</div></a>';
    echo '<a class="nav-item '.($active==='tools'?'active':'').'" href="?action=tools"><i class="fa fa-tools"></i><div>工具</div></a>';
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
        if (!empty($msg) && route('action')==='login') echo '<p style="color:var(--danger)'.e($msg).'</p>';
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
        if (!empty($msg) && route('action')==='register') echo '<p style="color:var(--danger)'.e($msg).'</p>';
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
    echo '<form id="send-form" method="post" action="?action=send" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div class="row" style="align-items:center">';
    echo '<div style="flex:0 0 80%;position:relative">';
    echo '<input id="text-input" class="input-text" name="text" placeholder="可用 @' . e($settings['bot_name'] ?? '哨哨') . ' 调用机器人">';
    echo '<label id="media-label" class="file-label" title="选择文件">';
    echo '<i class="fa fa-image"></i>';
    echo '<input id="media-input" type="file" name="media" accept="image/*,video/*" style="display:none">';
    echo '</label>';
    echo '</div>';
    echo '<div class="send-wrap">';
    echo '<button id="send-btn" class="send-btn" type="submit"><i class="fa fa-paper-plane" style="margin-right:0px"></i></button>';
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
    echo '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">';
    echo '<img class="profile-avatar" src="'.$avatarSrc.'" alt="avatar">';
    echo '<div>';
    echo '<div class="profile-uid">UID：'.e($user['uid']).'</div>';
    echo '<div style="font-size:20px;font-weight:800;margin-top:6px">'.e($user['nickname']).'</div>';
    if (!empty($user['title_active'])) echo '<div class="title-badge" style="margin-top:6px">'.e($user['title_active']).'</div>';
    echo '</div></div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="profile-card">';
    echo '<h3 style="margin-top:0">身份信息与设置</h3>';
    if (!empty($_GET['updated'])) echo '<p style="color:var(--ok)">已更新</p>';
    echo '<form class="profile-form" method="post" action="?action=profile_update" enctype="multipart/form-data" style="display:grid;grid-template-columns:1fr;gap:10px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<label>昵称</label><input class="input-text" name="nickname" value="'.e($user['nickname']).'">';
    echo '<label>头像</label><input class="input-text" type="file" name="avatar" accept="image/*">';
    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">';
    echo '<button class="btn btn-primary" type="submit">保存</button>';
    echo '<a class="btn" href="?action=my_titles">称号</a>';
    if (is_admin($user)) {
        echo '<a class="btn" href="?action=admin">管理后台</a>';
    }
    echo '<a class="btn btn-danger" href="?action=logout">退出登录</a>';
    echo '</div>';
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
    if (!empty($_GET['clear'])) echo '<div class="card" style="border-color:var(--ok)"><strong>已清空 ' . e((string)($_GET['count'] ?? 0)) . ' 条聊天记录</strong></div>';
    
    $s = read_json(SETTINGS_FILE);
    
    echo '<section class="profile-card" style="margin-bottom:20px"><h3 style="margin-top:0">基础设置</h3>';
    echo '<form method="post" action="?action=admin_settings" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div><label>聊天历史显示条数</label><input class="input-text" type="number" name="chat_history_limit" value="'.e((string)($s['chat_history_limit'] ?? DEFAULT_CHAT_HISTORY)).'" min="10" max="1000"></div>';
    echo '<div><label>允许注册</label><select class="input-text" name="allow_register"><option value="1" '.(($s['allow_register'] ?? true)?'selected':'').'>是</option><option value="0" '.(($s['allow_register'] ?? true)?'':'selected').'>否</option></select></div>';
    echo '<div><label>站点标题</label><input class="input-text" name="site_title" value="'.e($s['site_title'] ?? APP_NAME).'"></div>';
    echo '<div><label>默认主题</label><select class="input-text" name="theme"><option value="auto" '.(($s['theme'] ?? 'auto')==='auto'?'selected':'').'>自动</option><option value="light" '.(($s['theme'] ?? 'auto')==='light'?'selected':'').'>白天</option><option value="dark" '.(($s['theme'] ?? 'auto')==='dark'?'selected':'').'>夜间</option></select></div>';
    echo '</div></section>';
    
    echo '<section class="profile-card" style="margin-bottom:20px"><h3 style="margin-top:0">机器人设置</h3>';
    echo '<form method="post" action="?action=admin_settings" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div><label>启用聊天机器人</label><select class="input-text" name="bot_enabled"><option value="1" '.(($s['bot_enabled'] ?? true)?'selected':'').'>启用</option><option value="0" '.(($s['bot_enabled'] ?? true)?'':'selected').'>停用</option></select></div>';
    echo '<div><label>机器人上下文条数</label><input class="input-text" type="number" name="bot_context" value="'.e((string)($s['bot_context'] ?? 5)).'" min="1" max="20"></div>';
    echo '<div><label>机器人名称</label><input class="input-text" name="bot_name" value="'.e($s['bot_name'] ?? '哨哨').'" placeholder="哨哨"></div>';
    echo '<div><label>机器人UID</label><input class="input-text" name="bot_uid" value="'.e($s['bot_uid'] ?? 'BOT_SHAO').'" placeholder="BOT_SHAO"></div>';
    echo '<div><label>机器人头像路径</label><input class="input-text" name="bot_avatar" value="'.e($s['bot_avatar'] ?? '/Outpost.png').'" placeholder="/Outpost.png"></div>';
    echo '</div></section>';
    
    echo '<section class="profile-card" style="margin-bottom:20px"><h3 style="margin-top:0">大模型设置</h3>';
    echo '<form method="post" action="?action=admin_settings" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div><label>API Key</label><input class="input-text" type="password" name="deepseek_api_key" value="'.e($s['deepseek_api_key'] ?? '').'" placeholder="sk-..."></div>';
    echo '<div><label>API URL</label><input class="input-text" name="deepseek_api_url" value="'.e($s['deepseek_api_url'] ?? 'https://api.deepseek.com/v1/chat/completions').'" placeholder="https://..."></div>';
    echo '<div><label>模型名称</label><input class="input-text" name="deepseek_model" value="'.e($s['deepseek_model'] ?? 'deepseek-chat').'" placeholder="deepseek-chat"></div>';
    echo '<div><label>max_tokens</label><input class="input-text" type="number" name="deepseek_max_tokens" value="'.e((string)($s['deepseek_max_tokens'] ?? 512)).'" min="1" max="4096"></div>';
    echo '<div><label>temperature</label><input class="input-text" type="number" step="0.01" name="deepseek_temperature" value="'.e((string)($s['deepseek_temperature'] ?? 0.8)).'" min="0" max="2"></div>';
    echo '</div></section>';
    
    echo '<section class="profile-card" style="margin-bottom:20px"><h3 style="margin-top:0">安全设置</h3>';
    echo '<form method="post" action="?action=admin_settings" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div><label>限速时间单位（秒）</label><input class="input-text" type="number" name="rate_limit_window" value="'.e((string)($s['rate_limit_window'] ?? 60)).'" min="1" max="3600"></div>';
    echo '<div><label>每单位时间允许发送消息条数</label><input class="input-text" type="number" name="rate_limit_count" value="'.e((string)($s['rate_limit_count'] ?? 10)).'" min="1" max="1000"></div>';
    echo '<div style="grid-column:1/-1"><button class="btn btn-primary" type="submit">保存所有设置</button></div>';
    echo '</form></section>';
    
    echo '<section class="profile-card" style="margin-top:16px"><h3 style="margin-top:0">危险操作</h3>';
    echo '<form method="post" action="?action=admin_clear_chats" onsubmit="return confirm(\'⚠️ 确定要清空所有聊天记录吗？此操作不可恢复！\');">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<button class="btn btn-danger" type="submit">清空所有聊天记录</button>';
    echo '</form></section>';
    
    echo '<section class="profile-card" style="margin-top:16px"><h3 style="margin-top:0">用户审批</h3>';
    $users = read_json(USERS_FILE);
    echo '<div style="overflow:auto">'; 
    echo '<table class="table"><thead><tr><th>UID</th><th>用户名</th><th>昵称</th><th>状态</th><th>操作</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $status = $u['status'] ?? 'unknown';
        echo '<tr>';
        echo '<td>'.e($u['uid']).'</td>';
        echo '<td>'.e($u['username']).'</td>';
        echo '<td>'.e($u['nickname']).'</td>';
        if ($status === 'approved') {
            echo '<td><span class="status-approved">已通过</span></td>';
        } else {
            echo '<td><span class="status-pending">待审核</span></td>';
        }
        echo '<td>';
        if ($status !== 'approved') {
            echo '<form method="post" action="?action=admin_approve" style="display:inline">';
            echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
            echo '<input type="hidden" name="uid" value="'.e($u['uid']).'">';
            echo '<button class="btn btn-primary" type="submit">审批通过</button>';
            echo '</form>';
        } else {
            echo '<form method="post" action="?action=admin_disable" style="display:inline" onsubmit="return confirm(\'确定要停用此用户并回到待审核状态吗？\');">';
            echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
            echo '<input type="hidden" name="uid" value="'.e($u['uid']).'">';
            echo '<button class="btn btn-danger" type="submit">停用</button>';
            echo '</form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div></section>';
    
    echo '<section class="profile-card" style="margin-top:16px"><h3 style="margin-top:0">授予称号</h3>';
    echo '<form method="post" action="?action=admin_title" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">';
    echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
    echo '<div><label>用户 UID</label><input class="input-text" name="uid" placeholder="例如：'.e($user['uid']).'"></div>';
    echo '<div><label>称号</label><input class="input-text" name="title" placeholder="例如：Noob"></div>';
    echo '<div style="grid-column:1/-1"><button class="btn btn-primary" type="submit">授予称号</button></div>';
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
    if (!empty($msg)) echo '<p style="color:var(--danger)'.e($msg).'</p>';
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

if ($action === 'tools') {
    if (!$user) { header('Location: ?action=home'); exit; }
    if (($user['status'] ?? '') !== 'approved') { header('Location: ?action=home'); exit; }

    render_head('工具');
    render_header();
    echo '<main class="container" style="padding: 20px 16px;">';
    echo '<div class="tools-grid">';
    
    echo '<div class="tool-card">';
    echo '<div class="tool-title"><i class="fa fa-code"></i> OCR 文字识别</div>';
    echo '<p class="system-note">上传图片，自动识别图片中的文本</p>';
    echo '<input type="file" id="ocr-upload" accept="image/*" style="margin-bottom: 12px;">';
    echo '<div id="ocr-preview" class="ocr-preview"></div>';
    echo '<div style="display: flex; gap: 8px; flex-wrap: wrap;">';
    echo '<button id="ocr-recognize-btn" class="btn btn-primary" disabled>开始识别</button>';
    echo '<button id="ocr-copy-btn" class="btn" disabled>复制结果</button>';
    echo '</div>';
    echo '<div id="ocr-result" class="result-area" style="margin-top: 16px; min-height: 100px;">等待识别</div>';
    echo '</div>';
    
    echo '<div class="tool-card">';
    echo '<div class="tool-title"><i class="fa fa-code"></i> Base64 编解码</div>';
    echo '<div class="base64-row">';
    echo '<textarea id="base64-input" placeholder="输入文本或 Base64 字符串..."></textarea>';
    echo '</div>';
    echo '<div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">';
    echo '<button id="base64-encode" class="btn btn-primary">文本 → Base64</button>';
    echo '<button id="base64-decode" class="btn">Base64 → 文本</button>';
    echo '<button id="base64-copy" class="btn" disabled>复制结果</button>';
    echo '</div>';
    echo '<div id="base64-result" class="result-area" style="min-height: 80px;">结果将显示在这里</div>';
    echo '</div>';
    
    echo '</div>';
    echo '<div style="height: calc(var(--nav-height) + 20px);"></div>';
    echo '</main>';
    
    echo '<script>';
    echo '
    (function() {
        function copyToClipboard(text, successMsg) {
            if (!text) {
                alert("没有可复制的内容");
                return false;
            }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    alert(successMsg);
                }).catch(function(err) {
                    console.error("Clipboard API 失败:", err);
                    fallbackCopy(text, successMsg);
                });
            } else {
                fallbackCopy(text, successMsg);
            }
            return true;
        }

        function fallbackCopy(text, successMsg) {
            const textarea = document.createElement("textarea");
            textarea.value = text;
            textarea.style.position = "fixed";
            textarea.style.top = "-9999px";
            textarea.style.left = "-9999px";
            document.body.appendChild(textarea);
            textarea.select();
            textarea.setSelectionRange(0, text.length);
            let success = false;
            try {
                success = document.execCommand("copy");
            } catch (err) {
                console.error("execCommand 复制失败:", err);
            }
            document.body.removeChild(textarea);
            if (success) {
                alert(successMsg);
            } else {
                alert("复制失败，请手动复制");
            }
        }

        const ocrUpload = document.getElementById("ocr-upload");
        const ocrPreview = document.getElementById("ocr-preview");
        const ocrRecognizeBtn = document.getElementById("ocr-recognize-btn");
        const ocrCopyBtn = document.getElementById("ocr-copy-btn");
        const ocrResultDiv = document.getElementById("ocr-result");
        let currentOcrText = "";

        const base64Input = document.getElementById("base64-input");
        const base64Result = document.getElementById("base64-result");
        const base64Encode = document.getElementById("base64-encode");
        const base64Decode = document.getElementById("base64-decode");
        const base64Copy = document.getElementById("base64-copy");
        let currentBase64Result = "";

        if (ocrUpload) {
            ocrUpload.addEventListener("change", function(e) {
                const file = e.target.files[0];
                if (!file) return;
                const url = URL.createObjectURL(file);
                ocrPreview.innerHTML = `<img src="${url}" alt="预览图">`;
                ocrRecognizeBtn.disabled = false;
                ocrCopyBtn.disabled = true;
                ocrResultDiv.innerText = "点击「开始识别」...";
                currentOcrText = "";
            });
        }

        if (ocrRecognizeBtn) {
            ocrRecognizeBtn.addEventListener("click", async function() {
                const file = ocrUpload.files[0];
                if (!file) {
                    alert("请先选择图片");
                    return;
                }
                ocrRecognizeBtn.disabled = true;
                ocrRecognizeBtn.innerText = "识别中...";
                ocrResultDiv.innerText = "⏳ 正在识别，请稍等...";
                try {
                    const { data: { text } } = await Tesseract.recognize(file, "chi_sim+eng", {
                        logger: m => console.log(m)
                    });
                    currentOcrText = text.trim() || "未识别到文字";
                    ocrResultDiv.innerText = currentOcrText;
                    ocrCopyBtn.disabled = false;
                } catch (err) {
                    console.error(err);
                    ocrResultDiv.innerText = "识别失败：" + err.message;
                    currentOcrText = "";
                    ocrCopyBtn.disabled = true;
                } finally {
                    ocrRecognizeBtn.disabled = false;
                    ocrRecognizeBtn.innerText = "开始识别";
                }
            });
        }

        if (ocrCopyBtn) {
            ocrCopyBtn.addEventListener("click", function() {
                copyToClipboard(currentOcrText, "已复制识别结果");
            });
        }

        if (base64Encode) {
            base64Encode.addEventListener("click", function() {
                const text = base64Input.value;
                if (!text) {
                    base64Result.innerText = "请输入要编码的文本";
                    currentBase64Result = "";
                    base64Copy.disabled = true;
                    return;
                }
                try {
                    const encoded = btoa(unescape(encodeURIComponent(text)));
                    base64Result.innerText = encoded;
                    currentBase64Result = encoded;
                    base64Copy.disabled = false;
                } catch (e) {
                    base64Result.innerText = "编码失败：" + e.message;
                    currentBase64Result = "";
                    base64Copy.disabled = true;
                }
            });
        }

        if (base64Decode) {
            base64Decode.addEventListener("click", function() {
                const str = base64Input.value;
                if (!str) {
                    base64Result.innerText = "请输入 Base64 字符串";
                    currentBase64Result = "";
                    base64Copy.disabled = true;
                    return;
                }
                try {
                    const decoded = decodeURIComponent(escape(atob(str)));
                    base64Result.innerText = decoded;
                    currentBase64Result = decoded;
                    base64Copy.disabled = false;
                } catch (e) {
                    base64Result.innerText = "解码失败：无效的 Base64 字符串";
                    currentBase64Result = "";
                    base64Copy.disabled = true;
                }
            });
        }

        if (base64Copy) {
            base64Copy.addEventListener("click", function() {
                copyToClipboard(currentBase64Result, "已复制结果");
            });
        }
    })();
    ';
    echo '</script>';
    render_footer_nav('tools');
    echo '</body></html>'; exit;
}

header('Location: ?action=home'); exit;