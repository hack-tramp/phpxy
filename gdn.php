<?php
// guardian_proxy.php
// Full-featured proxy for The Guardian - text only, no images, no ads

// Configuration
$baseUrl = "https://www.theguardian.com";
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// ---- Helper Functions ----

function fetchPage($url) {
    global $userAgent;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => "CURL Error: $error"];
    }
    
    return [
        'html' => $response,
        'final_url' => $info['url'],
        'http_code' => $info['http_code']
    ];
}

function cleanHtml($html) {
    // Extract just the HTML part (remove headers)
    $htmlStart = strpos($html, '<!DOCTYPE html');
    if ($htmlStart === false) {
        $htmlStart = strpos($html, '<!doctype html');
    }
    if ($htmlStart === false) {
        $htmlStart = strpos($html, '<html');
    }
    if ($htmlStart !== false) {
        $html = substr($html, $htmlStart);
    }
    return $html;
}

function getXPath($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    return new DOMXPath($dom);
}

// ---- Category Menu Data ----
function getCategories() {
    return [
        ['name' => 'UK', 'url' => '/uk'],
        ['name' => 'World', 'url' => '/world'],
        ['name' => 'Opinion', 'url' => '/commentisfree'],
        ['name' => 'Sport', 'url' => '/sport'],
        ['name' => 'Culture', 'url' => '/culture'],
        ['name' => 'Lifestyle', 'url' => '/lifeandstyle'],
        ['name' => 'Business', 'url' => '/business'],
        ['name' => 'Tech', 'url' => '/technology'],
        ['name' => 'Environment', 'url' => '/environment'],
        ['name' => 'Politics', 'url' => '/politics'],
        ['name' => 'Science', 'url' => '/science'],
        ['name' => 'Global development', 'url' => '/global-development']
    ];
}

function getCategoryName($url) {
    $categories = getCategories();
    foreach ($categories as $cat) {
        if (strpos($url, $cat['url']) === 0 || $cat['url'] === $url) {
            return $cat['name'];
        }
    }
    return 'Home';
}

function isCategoryActive($catUrl, $currentUrl) {
    // Remove leading slash for comparison
    $cat = ltrim($catUrl, '/');
    $current = ltrim($currentUrl, '/');
    
    if ($cat === '' && $current === '') return false;
    if ($cat === $current) return true;
    if ($cat !== '' && strpos($current, $cat) === 0) return true;
    return false;
}

// ---- Content Parsers ----

function parseFrontPage($html, $baseUrl) {
    $xpath = getXPath($html);
    $result = ['type' => 'front', 'articles' => []];
    
    // Find article cards
    $cardLinks = $xpath->query("//a[contains(@class, 'dcr-idxb0f')]");
    
    foreach ($cardLinks as $link) {
        $url = $link->getAttribute('href');
        if (strpos($url, 'http') !== 0) {
            $url = $baseUrl . $url;
        }
        
        // Skip video/gallery
        if (strpos($url, '/video/') !== false || strpos($url, '/gallery/') !== false) {
            continue;
        }
        
        // Get headline
        $headline = '';
        $headlineNode = $xpath->query(".//h3/span[contains(@class, 'headline-text')]", $link->parentNode);
        if ($headlineNode->length > 0) {
            $headline = trim($headlineNode->item(0)->textContent);
        } else {
            $headlineNode = $xpath->query(".//h3", $link->parentNode);
            if ($headlineNode->length > 0) {
                $headline = trim($headlineNode->item(0)->textContent);
            }
        }
        
        if (empty($headline) || strlen($headline) < 8) continue;
        
        // Get description
        $desc = '';
        $descNodes = $xpath->query(".//div[contains(@class, 'dcr-1iga2bk')]/div", $link->parentNode);
        if ($descNodes->length > 0) {
            $desc = trim($descNodes->item(0)->textContent);
        }
        
        // Get kicker (section label)
        $kicker = '';
        $kickerNodes = $xpath->query(".//div[contains(@class, 'dcr-1kw5ykz')]", $link->parentNode);
        if ($kickerNodes->length > 0) {
            $kicker = trim($kickerNodes->item(0)->textContent);
        }
        
        $result['articles'][] = [
            'title' => $headline,
            'url' => $url,
            'description' => $desc,
            'kicker' => $kicker
        ];
    }
    
    // Deduplicate
    $seen = [];
    $unique = [];
    foreach ($result['articles'] as $article) {
        $key = $article['title'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $article;
        }
    }
    $result['articles'] = $unique;
    
    return $result;
}

function parseArticle($html) {
    $xpath = getXPath($html);
    $result = [
        'type' => 'article',
        'title' => '',
        'author' => '',
        'date' => '',
        'standfirst' => '',
        'content' => []
    ];
    
    // Title
    $titleNodes = $xpath->query("//h1[contains(@class, 'dcr-9lxoak')]");
    if ($titleNodes->length === 0) {
        $titleNodes = $xpath->query("//h1[contains(@class, 'dcr-uwz9qz')]");
    }
    if ($titleNodes->length > 0) {
        $result['title'] = trim($titleNodes->item(0)->textContent);
    }
    
    // Author - try multiple selectors
    $author = '';
    $authorNodes = $xpath->query("//div[contains(@class, 'dcr-15wyqgv')]/a");
    if ($authorNodes->length > 0) {
        $author = trim($authorNodes->item(0)->textContent);
    } else {
        $authorNodes = $xpath->query("//a[contains(@rel, 'author')]");
        if ($authorNodes->length > 0) {
            $author = trim($authorNodes->item(0)->textContent);
        }
    }
    $result['author'] = $author;
    
    // Date
    $dateNodes = $xpath->query("//time");
    if ($dateNodes->length > 0) {
        $result['date'] = trim($dateNodes->item(0)->textContent);
    }
    
    // Standfirst
    $standfirstNodes = $xpath->query("//div[contains(@class, 'dcr-l0rrwy')]/p");
    if ($standfirstNodes->length > 0) {
        $result['standfirst'] = trim($standfirstNodes->item(0)->textContent);
    }
    
    // Article body - clean paragraphs
    $bodyNodes = $xpath->query("//div[contains(@class, 'dcr-1w2vkyc')]//p");
    if ($bodyNodes->length === 0) {
        $bodyNodes = $xpath->query("//div[contains(@class, 'article-body')]//p");
    }
    if ($bodyNodes->length === 0) {
        $bodyNodes = $xpath->query("//div[contains(@data-testid, 'article-body')]//p");
    }
    
    foreach ($bodyNodes as $node) {
        $text = trim($node->textContent);
        if (empty($text) || strlen($text) < 10) continue;
        
        // Skip newsletter/email signup blocks
        $skipPatterns = [
            'Sign up to',
            'newsletter',
            'after newsletter promotion',
            'Free newsletter',
            'Enter your email',
            'Sign up for our email'
        ];
        $skip = false;
        foreach ($skipPatterns as $pattern) {
            if (stripos($text, $pattern) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;
        
        $result['content'][] = $text;
    }
    
    return $result;
}

// ---- Main Execution ----

$requestUri = isset($_GET['url']) ? $_GET['url'] : '/uk';
$requestUri = ltrim($requestUri, '/');
if (strpos($requestUri, '/') !== 0) {
    $requestUri = '/' . $requestUri;
}

$targetUrl = $baseUrl . $requestUri;
$response = fetchPage($targetUrl);

if (isset($response['error'])) {
    $error = $response['error'];
    $data = ['type' => 'error', 'error' => $error];
} else {
    $cleanHtml = cleanHtml($response['html']);
    
    // Detect if it's an article or front page
    if (strpos($cleanHtml, '<article') !== false || 
        strpos($cleanHtml, 'data-article-theme') !== false ||
        strpos($requestUri, '/2026/') !== false) {
        $data = parseArticle($cleanHtml);
    } else {
        $data = parseFrontPage($cleanHtml, $baseUrl);
    }
    
    if ($response['final_url'] != $targetUrl) {
        $data['redirected_from'] = $targetUrl;
        $data['redirected_to'] = $response['final_url'];
    }
}

// ---- Render Output ----

$categories = getCategories();
$currentPath = $requestUri;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guardian Text Proxy</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Georgia, serif;
            max-width: 820px;
            margin: 0 auto;
            padding: 20px;
            background: #f6f6f6;
            color: #121212;
            line-height: 1.6;
        }
        .header {
            background: #052962;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 300;
        }
        .header p {
            margin: 5px 0 0 0;
            opacity: 0.8;
            font-size: 0.9rem;
        }
        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 20px 0;
            padding: 12px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .nav a {
            color: #052962;
            text-decoration: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            border: 1px solid transparent;
            transition: all 0.2s;
        }
        .nav a:hover {
            background: #f0f0f0;
            border-color: #ddd;
        }
        .nav a.current {
            background: #052962;
            color: white;
            border-color: #052962;
        }
        .article-card {
            background: white;
            padding: 20px;
            margin-bottom: 16px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.1s;
        }
        .article-card:hover {
            transform: translateX(4px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .article-card .kicker {
            color: #C74600;
            font-weight: bold;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .article-card h2 {
            margin: 8px 0 5px 0;
            font-size: 1.35rem;
        }
        .article-card h2 a {
            color: #121212;
            text-decoration: none;
        }
        .article-card h2 a:hover {
            text-decoration: underline;
            color: #052962;
        }
        .article-card .description {
            color: #545454;
            font-size: 0.95rem;
            margin: 5px 0 0 0;
        }
        .article-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .article-content .article-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 5px 0;
            line-height: 1.2;
        }
        .article-content .article-author {
            color: #C74600;
            font-size: 1.1rem;
            margin: 0 0 10px 0;
        }
        .article-content .article-standfirst {
            font-size: 1.2rem;
            color: #333;
            border-left: 4px solid #C74600;
            padding-left: 15px;
            margin: 15px 0;
            font-weight: 300;
        }
        .article-content .article-date {
            color: #707070;
            font-size: 0.85rem;
            margin: 10px 0;
        }
        .article-content p {
            margin: 15px 0;
            font-size: 1.05rem;
        }
        .article-content .back-link {
            display: inline-block;
            margin-top: 30px;
            padding: 10px 20px;
            background: #052962;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .article-content .back-link:hover {
            background: #03183A;
        }
        .redirect-notice {
            background: #FFF3CD;
            border: 1px solid #FFE69C;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            color: #856404;
        }
        .footer {
            margin-top: 40px;
            padding: 20px 0;
            border-top: 1px solid #DCDCDC;
            text-align: center;
            color: #707070;
            font-size: 0.85rem;
        }
        .error {
            background: #F8D7DA;
            border: 1px solid #F5C6CB;
            padding: 20px;
            border-radius: 8px;
            color: #721C24;
        }
        .back-home {
            display: inline-block;
            margin-top: 15px;
            color: #052962;
            text-decoration: none;
            font-weight: bold;
        }
        .back-home:hover {
            text-decoration: underline;
        }
        .no-articles {
            text-align: center;
            padding: 40px;
            color: #707070;
        }
        @media (max-width: 600px) {
            body { padding: 10px; }
            .article-content { padding: 15px; }
            .article-content .article-title { font-size: 1.5rem; }
            .nav { flex-direction: column; gap: 4px; }
            .nav a { display: block; text-align: center; }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>📰 Guardian Text Proxy</h1>
    <p>No images, no ads, just the news</p>
</div>

<!-- Category Navigation -->
<div class="nav">
    <?php foreach ($categories as $cat): 
        $isActive = isCategoryActive($cat['url'], $currentPath);
    ?>
        <a href="?url=<?php echo urlencode($cat['url']); ?>" class="<?php echo $isActive ? 'current' : ''; ?>">
            <?php echo htmlspecialchars($cat['name']); ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (isset($data['error'])): ?>
    <div class="error">
        <strong>Error:</strong> <?php echo htmlspecialchars($data['error']); ?>
        <br><a href="?url=/uk" class="back-home">← Back to homepage</a>
    </div>

<?php elseif (isset($data['type']) && $data['type'] === 'front'): ?>
    
    <?php if (isset($data['redirected_from'])): ?>
        <div class="redirect-notice">
            ⚡ Followed redirect: <?php echo htmlspecialchars($data['redirected_from']); ?> 
            → <?php echo htmlspecialchars($data['redirected_to']); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($data['articles'])): ?>
        <div class="no-articles">
            <p>No articles found on this page. The structure may have changed.</p>
            <p><small>Try another category from the menu above.</small></p>
        </div>
    <?php else: ?>
        <?php foreach ($data['articles'] as $article): ?>
            <div class="article-card">
                <?php if (!empty($article['kicker'])): ?>
                    <div class="kicker"><?php echo htmlspecialchars($article['kicker']); ?></div>
                <?php endif; ?>
                <h2>
                    <a href="?url=<?php echo urlencode(str_replace('https://www.theguardian.com', '', $article['url'])); ?>">
                        <?php echo htmlspecialchars($article['title']); ?>
                    </a>
                </h2>
                <?php if (!empty($article['description'])): ?>
                    <p class="description"><?php echo htmlspecialchars($article['description']); ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php elseif (isset($data['type']) && $data['type'] === 'article'): ?>
    
    <div class="article-content">
        <a href="?url=/uk" class="back-home" style="display:inline-block;margin-bottom:20px;">← Back to homepage</a>
        
        <?php if (isset($data['redirected_from'])): ?>
            <div class="redirect-notice">
                ⚡ Followed redirect: <?php echo htmlspecialchars($data['redirected_from']); ?> 
                → <?php echo htmlspecialchars($data['redirected_to']); ?>
            </div>
        <?php endif; ?>
        
        <h1 class="article-title"><?php echo htmlspecialchars($data['title'] ?? 'Article'); ?></h1>
        
        <?php if (!empty($data['author'])): ?>
            <div class="article-author">By <?php echo htmlspecialchars($data['author']); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($data['date'])): ?>
            <div class="article-date"><?php echo htmlspecialchars($data['date']); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($data['standfirst'])): ?>
            <div class="article-standfirst"><?php echo nl2br(htmlspecialchars($data['standfirst'])); ?></div>
        <?php endif; ?>
        
        <hr style="border: 1px solid #eee; margin: 20px 0;">
        
        <?php if (empty($data['content'])): ?>
            <p><em>No article content could be extracted. The page structure may have changed.</em></p>
        <?php else: ?>
            <?php foreach ($data['content'] as $paragraph): ?>
                <p><?php echo nl2br(htmlspecialchars($paragraph)); ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <a href="?url=/uk" class="back-link">← Back to homepage</a>
    </div>

<?php else: ?>
    <div class="error">
        <strong>Error:</strong> Could not parse the page.
        <br><a href="?url=/uk" class="back-home">← Back to homepage</a>
    </div>
<?php endif; ?>

<div class="footer">
    <p>Guardian Text Proxy — No images, no ads, just text.</p>
    <p style="font-size:0.75rem;color:#999;">All content © Guardian News &amp; Media. For personal, non-commercial use only.</p>
</div>

</body>
</html>
