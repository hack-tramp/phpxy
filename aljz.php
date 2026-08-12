<?php
// Al Jazeera News Proxy - Full Article Reader
// ============================================

define('BASE_URL', 'https://www.aljazeera.com');
define('SCRIPT_NAME', 'aljz.php');

// Get the current section from URL parameter
$section = isset($_GET['section']) ? $_GET['section'] : 'news';
$sectionUrl = BASE_URL . '/' . $section . '/';

// Handle article view request
if (isset($_GET['article'])) {
    displayArticle($_GET['article']);
    exit;
}

// Display the main page for the current section
displaySectionPage($section, $sectionUrl);

// ============================================
// FUNCTIONS
// ============================================

function displaySectionPage($section, $sectionUrl) {
    $html = fetchUrl($sectionUrl);
    $articles = parseArticles($html);
    
    // Define all available sections
    $sections = [
        'news' => 'News',
        'africa' => 'Africa',
        'asia' => 'Asia',
        'asia-pacific' => 'Asia Pacific',
        'europe' => 'Europe',
        'latin-america' => 'Latin America',
        'us-canada' => 'US & Canada',
        'middle-east' => 'Middle East',
        'sports' => 'Sport',
        'opinion' => 'Opinion',
        'features' => 'Features',
        'economy' => 'Economy',
        'travel' => 'Travel'
    ];
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= ucfirst($section) ?> - Al Jazeera Proxy</title>
        <style>
            /* Reset & Base - Al Jazeera style */
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
                max-width: 800px; 
                margin: 0 auto; 
                padding: 20px; 
                background: #f4f4f4; 
                color: #222; 
                font-size: 16px;
                line-height: 1.6;
            }
            /* Al Jazeera Orange */
            .aj-orange { color: #fa9000; }
            .aj-border { border-bottom: 3px solid #fa9000; }
            
            h1 { 
                color: #222; 
                border-bottom: 3px solid #fa9000; 
                padding-bottom: 10px; 
                font-size: 28px;
                font-weight: 700;
                letter-spacing: -0.5px;
            }
            h1 small { font-size: 16px; font-weight: 400; color: #666; }
            
            .nav { 
                display: flex; 
                flex-wrap: wrap; 
                gap: 4px; 
                margin: 16px 0; 
                padding: 10px 12px; 
                background: white; 
                border-radius: 4px; 
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            }
            .nav a { 
                color: #555; 
                text-decoration: none; 
                padding: 4px 12px; 
                border-radius: 3px; 
                font-size: 13px; 
                font-weight: 500;
                transition: all 0.15s;
            }
            .nav a:hover { background: #fa9000; color: white; }
            .nav a.active { background: #fa9000; color: white; }
            
            .article { 
                background: white; 
                padding: 20px 24px; 
                margin-bottom: 16px; 
                border-radius: 4px; 
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                transition: box-shadow 0.2s;
            }
            .article:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
            .article h2 { 
                margin: 0 0 6px 0; 
                font-size: 20px; 
                font-weight: 700;
                line-height: 1.3;
            }
            .article h2 a { 
                color: #222; 
                text-decoration: none; 
                transition: color 0.15s;
            }
            .article h2 a:hover { color: #fa9000; }
            .article .excerpt { 
                color: #555; 
                font-size: 15px; 
                margin: 4px 0 8px 0; 
                line-height: 1.5; 
            }
            .article .meta { 
                font-size: 13px; 
                color: #999; 
                font-weight: 400;
            }
            
            /* Article view - no images */
            .article-content { 
                background: white; 
                padding: 30px 35px; 
                border-radius: 4px; 
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                line-height: 1.8; 
                font-size: 17px; 
            }
            .article-content h1 { 
                color: #222; 
                border-bottom: 3px solid #fa9000; 
                padding-bottom: 10px; 
                font-size: 32px;
                font-weight: 700;
                letter-spacing: -0.5px;
            }
            .article-content .back-link { 
                display: inline-block; 
                margin-bottom: 20px; 
                color: #fa9000; 
                text-decoration: none; 
                font-weight: 500; 
            }
            .article-content .back-link:hover { text-decoration: underline; }
            .article-content .subhead { 
                font-size: 19px; 
                color: #555; 
                font-style: italic; 
                margin: 8px 0 12px 0;
                font-weight: 400;
            }
            .article-content .byline { 
                color: #666; 
                font-size: 14px; 
                margin: 4px 0;
                font-weight: 500;
            }
            .article-content .date { 
                color: #999; 
                font-size: 14px; 
                margin: 2px 0 16px 0;
            }
            .article-content p { margin: 18px 0; }
            .article-content a { color: #fa9000; text-decoration: none; }
            .article-content a:hover { text-decoration: underline; }
            
            /* Hide images in articles */
            .article-content img,
            .article-content figure,
            .article-content .featured-caption,
            .article-content .article-featured-image {
                display: none !important;
            }
            
            @media (max-width: 600px) {
                body { padding: 12px; font-size: 15px; }
                h1 { font-size: 22px; }
                .article { padding: 16px 18px; }
                .article h2 { font-size: 17px; }
                .article-content { padding: 20px; font-size: 16px; }
                .article-content h1 { font-size: 24px; }
                .nav a { font-size: 12px; padding: 3px 8px; }
            }
        </style>
        <!-- Roboto font from Google -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    </head>
    <body>
        <h1>Al Jazeera <small><?= ucfirst($section) ?></small></h1>
        
        <div class="nav">
            <?php foreach ($sections as $key => $label): ?>
                <a href="?section=<?= $key ?>" class="<?= $section === $key ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
        
        <div id="articles">
            <?php if (empty($articles)): ?>
                <p style="color:#888;padding:20px;">No articles found. Please try again later.</p>
            <?php else: ?>
                <?php foreach ($articles as $article): ?>
                    <div class="article">
                        <h2><a href="?section=<?= urlencode($section) ?>&article=<?= urlencode($article['link']) ?>"><?= htmlspecialchars($article['title']) ?></a></h2>
                        <?php if (!empty($article['excerpt'])): ?>
                            <p class="excerpt"><?= htmlspecialchars($article['excerpt']) ?></p>
                        <?php endif; ?>
                        <div class="meta"><?= htmlspecialchars($article['date']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

function displayArticle($articleUrl) {
    $section = isset($_GET['section']) ? $_GET['section'] : 'news';
    $fullUrl = strpos($articleUrl, 'http') === 0 ? $articleUrl : BASE_URL . $articleUrl;
    $html = fetchUrl($fullUrl);
    $content = extractArticleContent($html);
    $content['body'] = rewriteInternalLinks($content['body']);
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($content['title'] ?? 'Article') ?> - Al Jazeera Proxy</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
                max-width: 800px; 
                margin: 0 auto; 
                padding: 20px; 
                background: #f4f4f4; 
                color: #222; 
                font-size: 16px;
                line-height: 1.6;
            }
            .article-content { 
                background: white; 
                padding: 30px 35px; 
                border-radius: 4px; 
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                line-height: 1.8; 
                font-size: 17px; 
            }
            .article-content h1 { 
                color: #222; 
                border-bottom: 3px solid #fa9000; 
                padding-bottom: 10px; 
                font-size: 32px;
                font-weight: 700;
                letter-spacing: -0.5px;
            }
            .article-content .back-link { 
                display: inline-block; 
                margin-bottom: 20px; 
                color: #fa9000; 
                text-decoration: none; 
                font-weight: 500; 
            }
            .article-content .back-link:hover { text-decoration: underline; }
            .article-content .subhead { 
                font-size: 19px; 
                color: #555; 
                font-style: italic; 
                margin: 8px 0 12px 0;
                font-weight: 400;
            }
            .article-content .byline { 
                color: #666; 
                font-size: 14px; 
                margin: 4px 0;
                font-weight: 500;
            }
            .article-content .date { 
                color: #999; 
                font-size: 14px; 
                margin: 2px 0 16px 0;
            }
            .article-content p { margin: 18px 0; }
            .article-content a { color: #fa9000; text-decoration: none; }
            .article-content a:hover { text-decoration: underline; }
            
            /* Hide all images in articles */
            .article-content img,
            .article-content figure,
            .article-content .featured-caption,
            .article-content .article-featured-image,
            .article-content .article-featured__media,
            .article-content .vertical-video-thumbnail,
            .article-content .responsive-image {
                display: none !important;
            }
            
            @media (max-width: 600px) {
                body { padding: 12px; font-size: 15px; }
                .article-content { padding: 20px; font-size: 16px; }
                .article-content h1 { font-size: 24px; }
            }
        </style>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    </head>
    <body>
        <div class="article-content">
            <a class="back-link" href="?section=<?= urlencode($section) ?>">&larr; Back to <?= ucfirst($section) ?></a>
            <h1><?= htmlspecialchars($content['title'] ?? 'Article') ?></h1>
            <?php if (!empty($content['subhead'])): ?>
                <p class="subhead"><?= htmlspecialchars($content['subhead']) ?></p>
            <?php endif; ?>
            <?php if (!empty($content['byline'])): ?>
                <p class="byline">By <?= htmlspecialchars($content['byline']) ?></p>
            <?php endif; ?>
            <?php if (!empty($content['date'])): ?>
                <p class="date"><?= htmlspecialchars($content['date']) ?></p>
            <?php endif; ?>
            <div class="article-body">
                <?= $content['body'] ?>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// ============================================
// URL REWRITING FUNCTION
// ============================================

function rewriteInternalLinks($html) {
    $sections = ['news', 'sports', 'features', 'economy', 'opinion', 
                 'investigations', 'interactives', 'gallery', 'travel', 
                 'climate-crisis', 'tag', 'video', 'africa', 'asia', 
                 'asia-pacific', 'europe', 'latin-america', 'us-canada', 
                 'middle-east'];
    
    $pattern = '/href="\/((' . implode('|', $sections) . ')\/[^"]*)"/i';
    $html = preg_replace_callback($pattern, function($matches) {
        $url = $matches[1];
        if (strpos($url, 'video/') === 0) return 'href="/' . $url . '"';
        $fullUrl = BASE_URL . '/' . $url;
        $section = explode('/', $url)[0];
        return 'href="' . SCRIPT_NAME . '?section=' . $section . '&article=' . urlencode($fullUrl) . '"';
    }, $html);
    
    $pattern2 = '/href="https?:\/\/(www\.)?aljazeera\.com\/((' . implode('|', $sections) . ')\/[^"]*)"/i';
    $html = preg_replace_callback($pattern2, function($matches) {
        $url = $matches[2];
        if (strpos($url, 'video/') === 0) return 'href="https://www.aljazeera.com/' . $url . '"';
        $fullUrl = BASE_URL . '/' . $url;
        $section = explode('/', $url)[0];
        return 'href="' . SCRIPT_NAME . '?section=' . $section . '&article=' . urlencode($fullUrl) . '"';
    }, $html);
    
    return $html;
}

// ============================================
// DATA FETCHING FUNCTIONS
// ============================================

function fetchUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function parseArticles($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    
    $articles = [];
    $seenLinks = [];
    
    $cards = $xpath->query("//article[contains(@class, 'article-card')] | //li[contains(@class, 'themed-featured-posts-list__item')]");
    
    foreach ($cards as $card) {
        $linkNode = $xpath->query(".//a[contains(@class, 'article-card__link')]", $card)->item(0);
        if (!$linkNode) {
            $linkNode = $xpath->query(".//a[contains(@href, '/news/') or contains(@href, '/sports/') or contains(@href, '/features/')]", $card)->item(0);
            if (!$linkNode) continue;
        }
        
        $href = $linkNode->getAttribute('href');
        if (empty($href) || strpos($href, '#') === 0 || strpos($href, '/video/') !== false) continue;
        
        $fullLink = strpos($href, 'http') === 0 ? $href : BASE_URL . $href;
        if (in_array($fullLink, $seenLinks)) continue;
        $seenLinks[] = $fullLink;
        
        $titleNode = $xpath->query(".//h2[contains(@class, 'article-card__title')]", $card)->item(0);
        if (!$titleNode) $titleNode = $xpath->query(".//h2", $card)->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : 'Untitled';
        if (strlen($title) < 5) continue;
        
        $excerptNode = $xpath->query(".//p[contains(@class, 'article-card__excerpt')]", $card)->item(0);
        $excerpt = $excerptNode ? trim($excerptNode->textContent) : '';
        
        $dateNode = $xpath->query(".//div[contains(@class, 'date-simple')]/span[@aria-hidden='true']", $card)->item(0);
        $date = $dateNode ? trim($dateNode->textContent) : date('M j, Y');
        
        $videoIndicator = $xpath->query(".//*[contains(@class, 'post-icon--play-arrow')] | .//*[contains(@class, 'post-icon--video')]", $card)->item(0);
        if ($videoIndicator) continue;
        
        $articles[] = [
            'link' => $fullLink,
            'title' => $title,
            'excerpt' => $excerpt,
            'date' => $date
        ];
        
        if (count($articles) >= 20) break;
    }
    
    return $articles;
}

function extractArticleContent($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    
    $result = [
        'title' => '',
        'subhead' => '',
        'byline' => '',
        'date' => '',
        'image' => '',
        'caption' => '',
        'body' => ''
    ];
    
    $titleNode = $xpath->query("//h1")->item(0);
    if ($titleNode) $result['title'] = trim($titleNode->textContent);
    
    $subheadNode = $xpath->query("//p[contains(@class, 'article__subhead')]")->item(0);
    if ($subheadNode) $result['subhead'] = trim(strip_tags($subheadNode->textContent));
    
    $bylineNode = $xpath->query("//div[contains(@class, 'byline-content')]//a")->item(0);
    if ($bylineNode) $result['byline'] = trim($bylineNode->textContent);
    
    $dateNode = $xpath->query("//div[contains(@class, 'date-simple')]/span[@aria-hidden='true']")->item(0);
    if ($dateNode) $result['date'] = trim($dateNode->textContent);
    
    // Don't extract image anymore - we're hiding them with CSS
    // Keep the variables empty
    
    $bodyNode = $xpath->query("//div[contains(@class, 'wysiwyg') and contains(@class, 'wysiwyg--all-content')]")->item(0);
    if ($bodyNode) {
        $result['body'] = extractCleanBody($bodyNode);
    } else {
        $result['body'] = '<p>Full article content could not be extracted.</p>';
    }
    
    return $result;
}

function extractCleanBody($node) {
    $doc = new DOMDocument();
    $importedNode = $doc->importNode($node, true);
    $doc->appendChild($importedNode);
    
    $xpath = new DOMXPath($doc);
    
    // Remove video containers
    $removeNodes = $xpath->query("//div[contains(@class, 'video-player-facade-container')]");
    foreach ($removeNodes as $removeNode) $removeNode->parentNode->removeChild($removeNode);
    
    $removeNodes = $xpath->query("//div[contains(@class, 'pre_video-wrapper')]");
    foreach ($removeNodes as $removeNode) $removeNode->parentNode->removeChild($removeNode);
    
    // Remove "Recommended Stories" section
    $removeNodes = $xpath->query("//section[contains(@class, 'more-on')]");
    foreach ($removeNodes as $removeNode) $removeNode->parentNode->removeChild($removeNode);
    
    // Remove ad containers
    $removeNodes = $xpath->query("//div[contains(@class, 'container--ads')] | //div[contains(@class, 'in-article-ads')]");
    foreach ($removeNodes as $removeNode) $removeNode->parentNode->removeChild($removeNode);
    
    // Remove image containers
    $removeNodes = $xpath->query("//img | //figure | //div[contains(@class, 'article-featured-image')] | //div[contains(@class, 'responsive-image')] | //div[contains(@class, 'article-featured__media')]");
    foreach ($removeNodes as $removeNode) $removeNode->parentNode->removeChild($removeNode);
    
    $html = '';
    foreach ($doc->documentElement->childNodes as $child) {
        $html .= $doc->saveHTML($child);
    }
    
    return $html;
}
?>
