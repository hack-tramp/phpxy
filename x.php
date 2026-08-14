<?php
// twitter_proxy.php - Single file Twitter/X profile viewer

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'fetch':
            $username = isset($_GET['username']) ? trim($_GET['username']) : '';
            if (empty($username)) {
                echo json_encode(['error' => 'Username required']);
                exit;
            }
            echo json_encode(fetchProfile($username));
            exit;
            
        case 'delete_cache':
            echo json_encode(['success' => true]);
            exit;
    }
}

// Function to fetch profile data
function fetchProfile($username) {
    // Clean username
    $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
    if (empty($username)) return ['error' => 'Invalid username'];
    
    // Fetch the page
    $url = "https://x.com/{$username}";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate, br',
        ],
        CURLOPT_ENCODING => 'gzip, deflate, br',
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => "CURL error: $error"];
    if (empty($html)) return ['error' => 'No content received'];
    
    // Parse the HTML
    $data = parseTwitterHTML($html, $username);
    
    return $data;
}

// Parse Twitter/X HTML
function parseTwitterHTML($html, $username) {
    $data = [
        'username' => $username,
        'name' => $username,
        'bio' => '',
        'followers' => 0,
        'following' => 0,
        'posts' => 0,
        'avatar' => '',
        'banner' => '',
        'location' => '',
        'url' => '',
        'joined' => '',
        'tweets' => [],
        'error' => null
    ];
    
    // Extract profile info
    if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
        $data['name'] = trim(str_replace(['(@' . $username . ')', ' on X'], '', $matches[1]));
    }
    
    if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
        $data['bio'] = html_entity_decode(trim($matches[1]));
    }
    
    if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+_200x200[^"\']*)["\']/i', $html, $matches)) {
        $data['avatar'] = str_replace('_200x200', '_400x400', $matches[1]);
    }
    
    if (preg_match('/<meta\s+name=["\']twitter:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
        $data['banner'] = $matches[1];
    }
    
    if (preg_match('/<svg[^>]*icon-location[^>]*>.*?<\/svg>\s*<[^>]+>([^<]+)<\/[^>]+>/s', $html, $matches)) {
        $data['location'] = trim($matches[1]);
    }
    
    if (preg_match('/<svg[^>]*icon-link[^>]*>.*?<\/svg>\s*<a[^>]+href=["\']([^"\']+)["\'][^>]*>/s', $html, $matches)) {
        $data['url'] = $matches[1];
    }
    
    if (preg_match('/<svg[^>]*icon-calendar[^>]*>.*?<\/svg>\s*<[^>]+>Joined\s+([^<]+)<\/[^>]+>/s', $html, $matches)) {
        $data['joined'] = trim($matches[1]);
    }
    
    // Extract followers/following/posts from JSON
    if (preg_match('/"followers_count":(\d+)/', $html, $matches)) {
        $data['followers'] = (int)$matches[1];
    }
    if (preg_match('/"following_count":(\d+)/', $html, $matches)) {
        $data['following'] = (int)$matches[1];
    }
    if (preg_match('/"tweet_count":(\d+)/', $html, $matches)) {
        $data['posts'] = (int)$matches[1];
    }
    
    // Also try meta tags for posts count
    if ($data['posts'] == 0 && preg_match('/<meta\s+name=["\']twitter:data1["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
        $data['posts'] = parseCount($matches[1]);
    }
    
    // Extract tweets
    $tweets = [];
    $seen_texts = [];
    
    if (preg_match_all('/<article[^>]*data-tweet-id=["\']([^"\']+)["\'][^>]*>(.*?)<\/article>/s', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tweet_id = $match[1];
            $tweet_html = $match[0];
            
            // Extract FULL text - try multiple methods// Extract FULL text
$text = '';

$details_key = 'client:' . base64_encode('Tweet:' . $tweet_id) . ':details';

$details_pos = strpos($html, $details_key);

if ($details_pos !== false) {

    // The full_text field is immediately after the details object.
    // Only inspect a limited section to avoid huge regex searches.
    $details_chunk = substr($html, $details_pos, 10000);

    if (preg_match(
        '/full_text:"((?:\\\\.|[^"\\\\])*)"/s',
        $details_chunk,
        $text_match
    )) {
        $decoded = json_decode('"' . $text_match[1] . '"');

        if ($decoded !== null || json_last_error() === JSON_ERROR_NONE) {
            $text = $decoded;
        } else {
            $text = $text_match[1];
        }
    }
}

// Fallback to articleBody
if (empty($text)) {
    if (preg_match(
        '/<meta\s+content="([^"]*)"\s+itemProp="articleBody"/i',
        $tweet_html,
        $text_match
    )) {
        $text = html_entity_decode(
            $text_match[1],
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }
}

// Fallback to visible text
if (empty($text)) {
    if (preg_match(
        '/<div[^>]*dir=["\']auto["\'][^>]*>(.*?)<\/div>/s',
        $tweet_html,
        $text_match
    )) {
        $text = trim(strip_tags(
            html_entity_decode(
                $text_match[1],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        ));
    }
}
            
            // Check if pinned
            $is_pinned = strpos($tweet_html, 'Pinned') !== false || 
                        strpos($tweet_html, '"context_type":"Pin"') !== false ||
                        strpos($tweet_html, 'pin-fill') !== false;
            
            // Extract date
            $date = '';
            $timestamp = 0;
            
            // Try time tag first
            if (preg_match('/<time[^>]*datetime=["\']([^"\']+)["\']/i', $tweet_html, $date_match)) {
                $timestamp = strtotime($date_match[1]);
                if ($timestamp !== false && $timestamp > 0) {
                    $date = date('M j, Y g:i A', $timestamp);
                }
            }
            // Try datePublished meta
            if (empty($date) && preg_match('/<meta\s+content="([^"]*)"\s+itemProp="datePublished"/i', $tweet_html, $date_match)) {
                $timestamp = strtotime($date_match[1]);
                if ($timestamp !== false && $timestamp > 0) {
                    $date = date('M j, Y g:i A', $timestamp);
                }
            }
            
            // Extract stats - from JSON
            $likes = 0; $replies = 0; $retweets = 0; $views = 0;
            
            if (preg_match('/"favorite_count":(\d+)/', $tweet_html, $stats_match)) {
                $likes = (int)$stats_match[1];
            }
            if (preg_match('/"reply_count":(\d+)/', $tweet_html, $stats_match)) {
                $replies = (int)$stats_match[1];
            }
            if (preg_match('/"retweet_count":(\d+)/', $tweet_html, $stats_match)) {
                $retweets = (int)$stats_match[1];
            }
            if (preg_match('/"view_count":"?(\d+)"?/', $tweet_html, $stats_match)) {
                $views = (int)$stats_match[1];
            }
            
            // Also try from schema.org meta
            if ($likes == 0 && preg_match('/<meta\s+content="(\d+)"\s+itemProp="userInteractionCount"[^>]*>.*?LikeAction/s', $tweet_html, $stats_match)) {
                $likes = (int)$stats_match[1];
            }
            if ($replies == 0 && preg_match('/<meta\s+content="(\d+)"\s+itemProp="userInteractionCount"[^>]*>.*?ReplyAction/s', $tweet_html, $stats_match)) {
                $replies = (int)$stats_match[1];
            }
            if ($retweets == 0 && preg_match('/<meta\s+content="(\d+)"\s+itemProp="userInteractionCount"[^>]*>.*?ShareAction/s', $tweet_html, $stats_match)) {
                $retweets = (int)$stats_match[1];
            }
            if ($views == 0 && preg_match('/<meta\s+content="(\d+)"\s+itemProp="userInteractionCount"[^>]*>.*?ViewAction/s', $tweet_html, $stats_match)) {
                $views = (int)$stats_match[1];
            }
            
            // Deduplicate
            $text_key = substr($text, 0, 50);
            if (in_array($text_key, $seen_texts)) continue;
            $seen_texts[] = $text_key;
            
            $tweets[] = [
                'id' => $tweet_id,
                'text' => $text,
                'url' => "https://x.com/{$username}/status/{$tweet_id}",
                'date' => $date ?: 'Recent',
                'timestamp' => $timestamp,
                'likes' => $likes,
                'replies' => $replies,
                'retweets' => $retweets,
                'views' => $views,
                'is_pinned' => $is_pinned,
                'has_image' => false,
                'has_video' => false
            ];
        }
    }
    
    $data['tweets'] = $tweets;
    
    return $data;
}

// Helper to parse count strings
function parseCount($str) {
    $str = trim($str);
    if (empty($str)) return 0;
    $multiplier = 1;
    if (stripos($str, 'K') !== false) $multiplier = 1000;
    elseif (stripos($str, 'M') !== false) $multiplier = 1000000;
    $str = preg_replace('/[^0-9.]/', '', $str);
    return round(floatval($str) * $multiplier);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Twitter/X Profile Proxy Viewer</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        background: #f7f9fc;
        color: #0f1419;
        padding: 20px;
        min-height: 100vh;
    }
    
    .container { max-width: 1400px; margin: 0 auto; }
    
    .header {
        background: #fff;
        padding: 20px 30px;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        margin-bottom: 24px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
    }
    .header h1 {
        font-size: 20px;
        font-weight: 700;
        color: #1d9bf0;
        margin-right: auto;
    }
    .header h1 span { color: #0f1419; }
    
    .add-form {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .add-form input {
        padding: 8px 14px;
        border: 2px solid #e1e8ed;
        border-radius: 24px;
        font-size: 14px;
        width: 160px;
        transition: border-color 0.2s;
        outline: none;
    }
    .add-form input:focus { border-color: #1d9bf0; }
    .add-form button {
        padding: 8px 20px;
        background: #1d9bf0;
        color: #fff;
        border: none;
        border-radius: 24px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .add-form button:hover { background: #1a8cd8; }
    .add-form button:disabled { opacity: 0.6; cursor: not-allowed; }
    
    .clear-btn {
        padding: 8px 16px;
        background: #e0245e;
        color: #fff;
        border: none;
        border-radius: 24px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .clear-btn:hover { background: #c01e4f; }
    
    .refresh-btn {
        padding: 8px 16px;
        background: #536471;
        color: #fff;
        border: none;
        border-radius: 24px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .refresh-btn:hover { background: #3d4a55; }
    
    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
        gap: 20px;
    }
    
    .box {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: all 0.3s ease;
        height: 500px;
        position: relative;
    }
    .box.maximized {
        position: fixed;
        top: 10px;
        left: 10px;
        right: 10px;
        bottom: 10px;
        width: auto;
        height: auto;
        z-index: 1000;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    .box.maximized .box-body { flex: 1; }
    
    .box-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e1e8ed;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
        background: #fff;
        position: sticky;
        top: 0;
        z-index: 2;
    }
    .box-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #e1e8ed;
        flex-shrink: 0;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 600;
        color: #1d9bf0;
    }
    .box-avatar img { width: 100%; height: 100%; object-fit: cover; }
    
    .box-info {
        flex: 1;
        min-width: 0;
    }
    .box-name {
        font-weight: 700;
        font-size: 15px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .box-handle {
        font-size: 13px;
        color: #536471;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .box-handle a { color: #536471; text-decoration: none; }
    .box-handle a:hover { text-decoration: underline; color: #1d9bf0; }
    
    .box-actions {
        display: flex;
        gap: 4px;
        flex-shrink: 0;
    }
    .box-actions button {
        width: 32px;
        height: 32px;
        border: none;
        border-radius: 50%;
        background: transparent;
        cursor: pointer;
        font-size: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
        color: #536471;
    }
    .box-actions button:hover { background: #e1e8ed; }
    .box-actions .maximize-btn { font-size: 18px; }
    .box-actions .close-btn { color: #e0245e; }
    .box-actions .close-btn:hover { background: #fde7ed; }
    
    .box-stats {
        display: flex;
        gap: 16px;
        padding: 8px 20px;
        font-size: 13px;
        color: #536471;
        border-bottom: 1px solid #e1e8ed;
        flex-shrink: 0;
        background: #fafbfc;
        flex-wrap: wrap;
    }
    .box-stats span strong { color: #0f1419; font-weight: 700; }
    
    .box-body {
        flex: 1;
        overflow-y: auto;
        padding: 0;
        overscroll-behavior: contain;
    }
    .box-body::-webkit-scrollbar { width: 6px; }
    .box-body::-webkit-scrollbar-thumb { background: #c4c9cd; border-radius: 4px; }
    .box-body::-webkit-scrollbar-track { background: transparent; }
    
    .tweet {
        padding: 14px 20px;
        border-bottom: 2px solid #e8ecf0;
        transition: background 0.15s;
        cursor: default;
    }
    .tweet:hover { background: #f7f9fc; }
    .tweet:last-child { border-bottom: none; }
    
    .tweet-header {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 14px;
        margin-bottom: 4px;
        flex-wrap: wrap;
    }
    .tweet-author {
        font-weight: 700;
        color: #0f1419;
    }
    .tweet-author:hover { text-decoration: underline; cursor: pointer; }
    .tweet-handle {
        color: #536471;
        font-weight: 400;
    }
    .tweet-dot {
        color: #536471;
        font-weight: 300;
    }
    .tweet-date {
        color: #536471;
        font-weight: 400;
        font-size: 13px;
    }
    .tweet-date a { color: #536471; text-decoration: none; }
    .tweet-date a:hover { text-decoration: underline; color: #1d9bf0; }
    .tweet-pinned {
        color: #1d9bf0;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 3px;
        background: #e8f5fe;
        padding: 1px 8px;
        border-radius: 12px;
    }
    .tweet-pinned::before { content: "📌"; font-size: 11px; }
    
    .tweet-text {
        font-size: 15px;
        line-height: 1.5;
        white-space: pre-wrap;
        word-wrap: break-word;
        color: #0f1419;
        margin: 4px 0 10px 0;
    }
    .tweet-text a { color: #1d9bf0; text-decoration: none; }
    .tweet-text a:hover { text-decoration: underline; }
    
    .tweet-stats {
        display: flex;
        gap: 20px;
        font-size: 13px;
        color: #536471;
        margin-top: 8px;
        padding-top: 10px;
        border-top: 1px solid #eff3f4;
    }
    .tweet-stats span {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .tweet-stats .stat-icon {
        font-size: 15px;
        opacity: 0.8;
    }
    .tweet-stats .stat-number {
        font-weight: 400;
        color: #536471;
    }
    
    .no-tweets {
        padding: 30px 20px;
        text-align: center;
        color: #536471;
        font-size: 14px;
    }
    
    .box.loading .box-body {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        color: #536471;
        padding: 40px;
    }
    
    .box.error .box-body {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        color: #e0245e;
        padding: 20px;
        text-align: center;
    }
    
    .spinner {
        width: 30px;
        height: 30px;
        border: 3px solid #e1e8ed;
        border-top-color: #1d9bf0;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        margin: 10px auto;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    .empty-state {
        grid-column: 1 / -1;
        text-align: center;
        padding: 60px 20px;
        color: #536471;
    }
    .empty-state h2 { font-size: 22px; color: #0f1419; margin-bottom: 8px; }
    .empty-state p { font-size: 15px; }
    
    .box-bio {
        padding: 8px 20px 4px 20px;
        font-size: 14px;
        color: #0f1419;
        line-height: 1.4;
        border-bottom: 1px solid #eff3f4;
        flex-shrink: 0;
        background: #fafbfc;
    }
    .box-bio .bio-text {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    @media (max-width: 600px) {
        .grid { grid-template-columns: 1fr; }
        .header { flex-direction: column; align-items: stretch; }
        .header h1 { margin-right: 0; text-align: center; }
        .add-form { flex-direction: column; }
        .add-form input { width: 100%; }
        .box { height: 450px; }
        .box.maximized { top: 5px; left: 5px; right: 5px; bottom: 5px; border-radius: 12px; }
        .tweet-stats { gap: 12px; font-size: 12px; flex-wrap: wrap; }
    }
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🐦 X <span>Profile Viewer</span></h1>
        <div class="add-form">
            <input type="text" id="usernameInput" placeholder="Enter @username" value="">
            <button id="addBtn">Add Profile</button>
        </div>
        <button class="refresh-btn" id="refreshAllBtn">⟳ Refresh All</button>
        <button class="clear-btn" id="clearAllBtn">✕ Clear All</button>
    </div>
    
    <div class="grid" id="grid"></div>
</div>

<script>
(function() {
    const STORAGE_KEY = 'twitter_proxy_accounts';
    const grid = document.getElementById('grid');
    const usernameInput = document.getElementById('usernameInput');
    const addBtn = document.getElementById('addBtn');
    const clearAllBtn = document.getElementById('clearAllBtn');
    const refreshAllBtn = document.getElementById('refreshAllBtn');
    
    let accounts = [];
    let loading = new Set();
    
    function loadAccounts() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) accounts = JSON.parse(stored);
        } catch (e) { accounts = []; }
        if (!Array.isArray(accounts)) accounts = [];
        accounts = accounts.filter((v, i, a) => a.findIndex(t => t === v) === i);
        saveAccounts();
    }
    
    function saveAccounts() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(accounts));
        } catch (e) {}
    }
    
async function fetchProfile(username) {
    const resp = await fetch(`?action=fetch&username=${encodeURIComponent(username)}`);
    const raw = await resp.text();

    console.log("SERVER RESPONSE:", raw);

    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);

    try {
        return JSON.parse(raw);
    } catch (e) {
        throw new Error("PHP returned non-JSON: " + raw.substring(0, 500));
    }
}
    
    function render() {
        if (accounts.length === 0) {
            grid.innerHTML = `
                <div class="empty-state">
                    <h2>No profiles added yet</h2>
                    <p>Enter a Twitter/X username above and click "Add Profile"</p>
                    <p style="margin-top:8px;font-size:13px;color:#536471;">Example: Thenationth, nytimes, BBCNews</p>
                </div>
            `;
            return;
        }
        
        grid.innerHTML = '';
        accounts.forEach(username => {
            const box = createBox(username);
            grid.appendChild(box);
            loadProfileData(username, box);
        });
    }
    
    function createBox(username) {
        const box = document.createElement('div');
        box.className = 'box';
        box.dataset.username = username;
        box.dataset.maximized = 'false';
        
        const header = document.createElement('div');
        header.className = 'box-header';
        header.innerHTML = `
            <div class="box-avatar" id="avatar-${username}">?</div>
            <div class="box-info">
                <div class="box-name" id="name-${username}">${username}</div>
                <div class="box-handle"><a href="https://x.com/${username}" target="_blank">@${username}</a></div>
            </div>
            <div class="box-actions">
                <button class="maximize-btn" title="Maximize" data-username="${username}">⛶</button>
                <button class="close-btn" title="Remove" data-username="${username}">✕</button>
            </div>
        `;
        
        const stats = document.createElement('div');
        stats.className = 'box-stats';
        stats.id = `stats-${username}`;
        stats.innerHTML = `<span>Loading...</span>`;
        
        const bio = document.createElement('div');
        bio.className = 'box-bio';
        bio.id = `bio-${username}`;
        bio.innerHTML = ``;
        
        const body = document.createElement('div');
        body.className = 'box-body';
        body.id = `body-${username}`;
        body.innerHTML = `<div class="spinner"></div>`;
        
        box.appendChild(header);
        box.appendChild(stats);
        box.appendChild(bio);
        box.appendChild(body);
        
        header.querySelector('.close-btn').addEventListener('click', function(e) {
            e.stopPropagation();
            removeAccount(username);
        });
        
        header.querySelector('.maximize-btn').addEventListener('click', function(e) {
            e.stopPropagation();
            toggleMaximize(box);
        });
        
        box.addEventListener('dblclick', function(e) {
            if (e.target.closest('a') || e.target.closest('button')) return;
            toggleMaximize(box);
        });
        
        document.addEventListener('keydown', function escHandler(e) {
            if (e.key === 'Escape' && box.dataset.maximized === 'true') {
                toggleMaximize(box);
            }
        });
        
        return box;
    }
    
    function toggleMaximize(box) {
        const isMaximized = box.dataset.maximized === 'true';
        if (!isMaximized) {
            document.querySelectorAll('.box.maximized').forEach(b => {
                b.dataset.maximized = 'false';
                b.classList.remove('maximized');
                const body = b.querySelector('.box-body');
                if (body) body.style.overflowY = 'auto';
            });
        }
        box.dataset.maximized = isMaximized ? 'false' : 'true';
        box.classList.toggle('maximized');
        if (box.dataset.maximized === 'true') {
            const body = box.querySelector('.box-body');
            if (body) setTimeout(() => body.focus(), 100);
        }
    }
    
    async function loadProfileData(username, box) {
        const body = document.getElementById(`body-${username}`);
        const stats = document.getElementById(`stats-${username}`);
        const bio = document.getElementById(`bio-${username}`);
        const avatar = document.getElementById(`avatar-${username}`);
        const nameEl = document.getElementById(`name-${username}`);
        
        if (!body) return;
        if (loading.has(username)) return;
        loading.add(username);
        
        try {
            box.classList.remove('error');
            box.classList.add('loading');
            
            const data = await fetchProfile(username);
            
            box.classList.remove('loading');
            
            if (data.error) {
                box.classList.add('error');
                body.innerHTML = `<div class="no-tweets">⚠️ ${data.error}</div>`;
                stats.innerHTML = `<span>Error loading profile</span>`;
                return;
            }
            
            if (data.avatar) {
                avatar.innerHTML = `<img src="${data.avatar}" alt="${username}" loading="lazy">`;
            } else {
                avatar.textContent = data.name ? data.name.charAt(0).toUpperCase() : '?';
            }
            
            nameEl.textContent = data.name || username;
            
            if (data.bio) {
                bio.innerHTML = `<div class="bio-text">${escapeHtml(data.bio)}</div>`;
            } else {
                bio.innerHTML = '';
            }
            
            const followers = data.followers || 0;
            const following = data.following || 0;
            const posts = data.posts || 0;
            
            let statsHtml = '';
            if (posts > 0) statsHtml += `<span><strong>${formatCount(posts)}</strong> Posts</span>`;
            if (followers > 0) statsHtml += `<span><strong>${formatCount(followers)}</strong> Followers</span>`;
            if (following > 0) statsHtml += `<span><strong>${formatCount(following)}</strong> Following</span>`;
            if (data.joined) statsHtml += `<span>📅 ${data.joined}</span>`;
            if (data.location) statsHtml += `<span>📍 ${data.location}</span>`;
            if (!statsHtml) statsHtml = `<span>Profile loaded</span>`;
            stats.innerHTML = statsHtml;
            
            if (data.tweets && data.tweets.length > 0) {
                body.innerHTML = data.tweets.map(tweet => renderTweet(tweet, username)).join('');
            } else {
                body.innerHTML = `<div class="no-tweets">No posts found for @${username}</div>`;
            }
            
        } catch (err) {
            box.classList.remove('loading');
            box.classList.add('error');
            body.innerHTML = `<div class="no-tweets">⚠️ Error: ${err.message || 'Failed to load'}</div>`;
            stats.innerHTML = `<span>❌ Error loading</span>`;
        } finally {
            loading.delete(username);
        }
    }
    
    function renderTweet(tweet, username) {
        const text = tweet.text || '';
        const date = tweet.date || 'Recent';
        const likes = tweet.likes || 0;
        const replies = tweet.replies || 0;
        const retweets = tweet.retweets || 0;
        const views = tweet.views || 0;
        const isPinned = tweet.is_pinned || false;
        
        let htmlText = escapeHtml(text)
            .replace(/\n/g, '<br>')
            .replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank">$1</a>')
            .replace(/#(\w+)/g, '<a href="https://x.com/hashtag/$1" target="_blank">#$1</a>')
            .replace(/@(\w+)/g, '<a href="https://x.com/$1" target="_blank">@$1</a>');
        
        let statsHtml = '';
        if (replies > 0) statsHtml += `<span><span class="stat-icon">💬</span> <span class="stat-number">${formatCount(replies)}</span></span>`;
        if (retweets > 0) statsHtml += `<span><span class="stat-icon">🔄</span> <span class="stat-number">${formatCount(retweets)}</span></span>`;
        if (likes > 0) statsHtml += `<span><span class="stat-icon">❤️</span> <span class="stat-number">${formatCount(likes)}</span></span>`;
        if (views > 0) statsHtml += `<span><span class="stat-icon">👁️</span> <span class="stat-number">${formatCount(views)}</span></span>`;
        
        let pinnedHtml = '';
        if (isPinned) pinnedHtml = `<span class="tweet-pinned">Pinned</span>`;
        
        let headerHtml = `
            <div class="tweet-header">
                <span class="tweet-author">${escapeHtml(tweet.name || username)}</span>
                <span class="tweet-handle">@${username}</span>
                <span class="tweet-dot">·</span>
                <span class="tweet-date"><a href="${tweet.url}" target="_blank">${escapeHtml(date)}</a></span>
                ${pinnedHtml}
            </div>
        `;
        
        return `
            <div class="tweet">
                ${headerHtml}
                <div class="tweet-text">${htmlText}</div>
                ${statsHtml ? `<div class="tweet-stats">${statsHtml}</div>` : ''}
            </div>
        `;
    }
    
    function formatCount(num) {
        if (!num) return '0';
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
        return num.toString();
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    function addAccount(username) {
        username = username.trim().replace(/^@/, '');
        if (!username) return;
        if (accounts.includes(username)) {
            const box = document.querySelector(`.box[data-username="${username}"]`);
            if (box) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        accounts.push(username);
        saveAccounts();
        
        // Remove empty state if it exists
        const emptyState = grid.querySelector('.empty-state');
        if (emptyState) emptyState.remove();
        
        const box = createBox(username);
        grid.appendChild(box);
        loadProfileData(username, box);
        usernameInput.value = '';
        usernameInput.focus();
    }
    
    function removeAccount(username) {
        accounts = accounts.filter(u => u !== username);
        saveAccounts();
        const box = document.querySelector(`.box[data-username="${username}"]`);
        if (box) box.remove();
        if (accounts.length === 0) render();
    }
    
    function clearAllAccounts() {
        if (accounts.length === 0) return;
        if (confirm('Remove all profiles?')) {
            accounts = [];
            saveAccounts();
            render();
        }
    }
    
    async function refreshAllAccounts() {
        if (accounts.length === 0) return;
        const boxes = document.querySelectorAll('.box');
        boxes.forEach(box => {
            const body = box.querySelector('.box-body');
            if (body) body.innerHTML = `<div class="spinner"></div>`;
        });
        for (const username of accounts) {
            const box = document.querySelector(`.box[data-username="${username}"]`);
            if (box) {
                await loadProfileData(username, box);
            }
            await new Promise(r => setTimeout(r, 500));
        }
    }
    
    addBtn.addEventListener('click', function() {
        addAccount(usernameInput.value);
    });
    
    usernameInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addAccount(this.value);
        }
    });
    
    clearAllBtn.addEventListener('click', clearAllAccounts);
    refreshAllBtn.addEventListener('click', refreshAllAccounts);
    
    loadAccounts();
    render();
    
})();
</script>
</body>
</html>
