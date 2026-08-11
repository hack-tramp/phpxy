<?php
// libgen.php - Library Genesis Proxy with working download

class LibgenProxy {
    private $baseUrl = 'https://libgen.li';
    
    public function search($query) {
        $searchUrl = $this->baseUrl . '/index.php?req=' . urlencode($query) . 
            '&columns[]=t&objects[]=f&objects[]=e&objects[]=s&objects[]=a&objects[]=p&objects[]=w' .
            '&topics[]=l&topics[]=s&res=25&filesuns=all';
        
        $html = $this->fetchPage($searchUrl);
        
        if (!$html) {
            return ['error' => 'Failed to fetch search page'];
        }
        
        $results = $this->parseResults($html);
        
        if (empty($results)) {
            return ['error' => 'No results found'];
        }
        
        return [
            'success' => true,
            'count' => count($results),
            'results' => $results
        ];
    }
    
    /**
     * Get the actual download URL from the ads page
     */
    public function getDownloadUrl($md5) {
        // Step 1: Go to the ads page
        $adsUrl = $this->baseUrl . '/ads.php?md5=' . $md5;
        $adsHtml = $this->fetchPage($adsUrl);
        
        if (!$adsHtml) {
            return false;
        }
        
        // Look for the GET link in the table
        if (preg_match('/<a[^>]*href="([^"]*get\.php\?md5=' . $md5 . '[^"]*)"[^>]*>/i', $adsHtml, $matches)) {
            $getUrl = $matches[1];
            if (strpos($getUrl, 'http') !== 0) {
                $getUrl = $this->baseUrl . '/' . $getUrl;
            }
            
            // Step 2: Follow the GET link (which may redirect to CDN)
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $getUrl,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$finalUrl, &$headers) {
                    $len = strlen($header);
                    $headers[] = $header;
                    if (preg_match('/^Location: (.+)$/i', $header, $matches)) {
                        $finalUrl = trim($matches[1]);
                    }
                    return $len;
                },
                CURLOPT_WRITEFUNCTION => function($curl, $data) {
                    // Discard output during header fetch
                    return strlen($data);
                }
            ]);
            
            curl_exec($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            // If we got a final URL from the Location header, use it
            if (isset($finalUrl) && !empty($finalUrl)) {
                return $finalUrl;
            }
            
            // If we followed redirects, the effective URL might be the download URL
            if (isset($info['url']) && $info['url'] != $getUrl) {
                return $info['url'];
            }
            
            // Otherwise return the GET URL
            return $getUrl;
        }
        
        return false;
    }
    
    /**
     * Stream a file directly to the browser
     */
    public function streamFile($md5, $extension = '', $title = '') {
        // Get the actual download URL
        $downloadUrl = $this->getDownloadUrl($md5);
        
        if (!$downloadUrl) {
            http_response_code(404);
            echo "Could not find download link for this file";
            return false;
        }
        
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Now stream the file
        $ch = curl_init();
        
        $content_type = '';
        $content_disposition = '';
        $content_length = '';
        $http_code = 0;
        $filename_from_header = '';
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $downloadUrl,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_TIMEOUT => 300,
            CURLOPT_REFERER => $this->baseUrl,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$content_type, &$content_disposition, &$content_length, &$http_code, &$filename_from_header) {
                $len = strlen($header);
                if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $http_code = intval($matches[1]);
                }
                $header = explode(':', $header, 2);
                if (count($header) < 2) return $len;
                
                $name = strtolower(trim($header[0]));
                $value = trim($header[1]);
                
                if ($name === 'content-type') {
                    $content_type = $value;
                } elseif ($name === 'content-disposition') {
                    $content_disposition = $value;
                    if (preg_match('/filename="?([^"]+)"?/', $content_disposition, $filenameMatch)) {
                        $filename_from_header = $filenameMatch[1];
                    }
                } elseif ($name === 'content-length') {
                    $content_length = $value;
                }
                
                return $len;
            },
            CURLOPT_WRITEFUNCTION => function($curl, $data) {
                echo $data;
                return strlen($data);
            }
        ]);
        
        // Build filename: use title if available, otherwise use md5
        if (!empty($title)) {
            // Sanitize: remove invalid filename characters
            $filename = preg_replace('/[\\/\\:*?"<>|]/', '', $title);
            // Replace spaces with underscores
            $filename = str_replace(' ', '_', $filename);
            // Truncate to 50 characters
            if (strlen($filename) > 50) {
                $filename = substr($filename, 0, 50);
            }
            // Remove trailing underscores
            $filename = rtrim($filename, '_');
            // Add extension
            if (!empty($extension)) {
                $filename .= '.' . $extension;
            }
        } else {
            // Fallback to md5 if no title
            $filename = 'book_' . $md5;
            if (!empty($extension)) {
                $filename .= '.' . $extension;
            }
        }
        
        // Set headers before starting output
        if ($content_disposition) {
            $content_disposition = preg_replace('/filename="?[^"]+"?/', 'filename="' . $filename . '"', $content_disposition);
            header('Content-Disposition: ' . $content_disposition);
        } else {
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }
        
        if ($content_type) {
            header('Content-Type: ' . $content_type);
        } else {
            header('Content-Type: application/octet-stream');
        }
        
        if ($content_length) {
            header('Content-Length: ' . $content_length);
        }
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            echo "Download failed: " . $error;
            return false;
        }
        
        return true;
    }
    
    private function fetchPage($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Connection: keep-alive',
            ]
        ]);
        
        $html = curl_exec($ch);
        curl_close($ch);
        
        return $html;
    }
    
    private function parseResults($html) {
        $results = [];
        
        // Extract table
        $tableStart = strpos($html, '<table id="tablelibgen"');
        if ($tableStart === false) {
            $tableStart = strpos($html, '<table class="table');
        }
        if ($tableStart === false) {
            return $results;
        }
        
        $tableEnd = strpos($html, '</table>', $tableStart);
        if ($tableEnd === false) {
            return $results;
        }
        
        $tableHtml = substr($html, $tableStart, $tableEnd - $tableStart + 8);
        
        // Find all rows
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $rowMatches);
        
        foreach ($rowMatches[1] as $rowHtml) {
            // Skip header rows
            if (strpos($rowHtml, '<th') !== false) {
                continue;
            }
            
            // Extract cells
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cellMatches);
            $cells = $cellMatches[1];
            
            if (count($cells) < 8) {
                continue;
            }
            
            // Clean cells
            $cleanCells = array_map(function($cell) {
                return trim(strip_tags($cell));
            }, $cells);
            
            // Extract MD5 from the mirrors cell
            $md5 = '';
            if (preg_match('/ads\.php\?md5=([a-f0-9]{32})/', $rowHtml, $md5Match)) {
                $md5 = $md5Match[1];
            }
            
            // Get extension from the extension cell (cell 7)
            $extension = '';
            if (isset($cleanCells[7])) {
                $extension = trim($cleanCells[7]);
            }
            
            // Clean up title
            $title = $cleanCells[0] ?? 'Unknown Title';
            $title = preg_replace('/\s*\d{10,13}(?:\s*;\s*\d{10,13})*/', '', $title);
            $title = preg_replace('/\s+\b[a-z]\b$/', '', $title);
            $title = trim($title);
            
            // Try to extract ISBN
            $isbn = '';
            if (preg_match('/\b(?:ISBN[:\s]*)?(\d{10,13})\b/', $rowHtml, $isbnMatch)) {
                $isbn = $isbnMatch[1];
            }
            
            $result = [
                'title' => $title,
                'author' => $cleanCells[1] ?? '',
                'publisher' => $cleanCells[2] ?? '',
                'year' => $cleanCells[3] ?? '',
                'language' => $cleanCells[4] ?? '',
                'pages' => $cleanCells[5] ?? '',
                'size' => $cleanCells[6] ?? '',
                'extension' => $extension,
                'isbn' => $isbn,
                'md5' => $md5
            ];
            
            if (!empty($md5)) {
                $results[] = $result;
            }
        }
        
        return $results;
    }
}

// Handle requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $proxy = new LibgenProxy();
    
    // Handle file download
    if (isset($_GET['download']) && isset($_GET['md5']) && strlen($_GET['md5']) === 32) {
        $md5 = $_GET['md5'];
        $extension = isset($_GET['ext']) ? $_GET['ext'] : '';
        $title = isset($_GET['title']) ? $_GET['title'] : '';
        $proxy->streamFile($md5, $extension, $title);
        exit;
    }
    
    // Handle search
    if (isset($_GET['search'])) {
        $query = $_GET['search'];
        $results = $proxy->search($query);
        
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            header('Content-Type: application/json');
            echo json_encode($results, JSON_PRETTY_PRINT);
            exit;
        }
        
        displayResults($results, $query);
        exit;
    }
    
    displaySearchForm();
}

function displaySearchForm() {
    ?>
<!DOCTYPE html>
<html>
<head>
		<meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Library Genesis Proxy</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); width: 100%; max-width: 700px; }
        h1 { color: #1a1a2e; margin: 0 0 10px 0; font-size: 32px; }
        .subtitle { color: #666; margin-bottom: 30px; font-size: 16px; }
        .search-box { display: flex; gap: 10px; margin: 20px 0; }
        .search-box input[type="text"] { flex: 1; padding: 14px 18px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 16px; transition: border-color 0.3s; }
        .search-box input[type="text"]:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 3px rgba(0,123,255,0.1); }
        .search-box button { padding: 14px 35px; background: #007bff; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.3s; }
        .search-box button:hover { background: #0056b3; }
        .features { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-top: 30px; padding-top: 30px; border-top: 1px solid #eee; }
        .feature { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .feature .icon { font-size: 28px; display: block; margin-bottom: 8px; }
        .feature strong { display: block; color: #333; font-size: 14px; }
        .feature span { font-size: 13px; color: #666; }
@media (max-width: 600px) { 
    body { font-size: 16px; }
    .container { padding: 15px; }
    .search-box { flex-direction: column; } 
    .search-box input[type="text"] { font-size: 16px; padding: 12px; }
    .search-box button { font-size: 16px; padding: 12px; }
    .header { flex-direction: column; align-items: flex-start; } 
    .header-actions { width: 100%; } 
    .header-actions .btn { flex: 1; text-align: center; font-size: 14px; } 
    .book-title { font-size: 16px; } 
    .book-author { font-size: 14px; } 
    .book-meta { flex-direction: column; gap: 3px; font-size: 13px; } 
    .book-meta span { display: block; } 
    .book-actions { flex-direction: column; } 
    .book-actions .btn { width: 100%; text-align: center; font-size: 16px; padding: 12px; } 
    .features { grid-template-columns: 1fr; }
    h1 { font-size: 24px; }
    .subtitle { font-size: 14px; }
}
    </style>
</head>
<body>
    <div class="container">
        <h1>📚 Library Genesis Proxy</h1>
        <p class="subtitle">Search and download books anonymously</p>
        <form method="GET" class="search-box">
            <input type="text" name="search" placeholder="Enter book title, author, or ISBN..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" autofocus>
            <button type="submit">Search</button>
        </form>
        <div class="features">
            <div class="feature"><span class="icon">🔍</span><strong>Search</strong><span>By title, author, or ISBN</span></div>
            <div class="feature"><span class="icon">📥</span><strong>Download</strong><span>Proxy downloads via server</span></div>
            <div class="feature"><span class="icon">🔒</span><strong>Private</strong><span>Your IP is hidden</span></div>
        </div>
    </div>
</body>
</html>
    <?php
}

function displayResults($results, $query) {
    ?>
<!DOCTYPE html>
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results - Library Genesis Proxy</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background: #f0f2f5; }
        .container { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .header-left h1 { margin: 0; font-size: 24px; color: #1a1a2e; }
        .header-left .subtitle { margin: 5px 0 0 0; color: #666; font-size: 14px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 14px; transition: all 0.2s; display: inline-block; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; color: white; }
        .error { color: #721c24; padding: 15px; border: 1px solid #f5c6cb; border-radius: 8px; background: #f8d7da; margin: 20px 0; }
        .no-results { text-align: center; padding: 60px 20px; color: #666; }
        .no-results .icon { font-size: 48px; display: block; margin-bottom: 15px; }
        .no-results h3 { margin: 0 0 10px 0; color: #333; }
        .book { border: 1px solid #e8e8e8; padding: 18px; margin-bottom: 15px; border-radius: 8px; background: #fafafa; transition: all 0.2s; }
        .book:hover { background: #f5f5f5; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .book-title { font-size: 18px; font-weight: 600; color: #0066cc; margin-bottom: 4px; }
        .book-author { color: #555; font-size: 15px; margin-bottom: 6px; }
        .book-meta { margin: 6px 0; font-size: 14px; color: #555; display: flex; flex-wrap: wrap; gap: 5px 15px; }
        .book-meta .label { font-weight: 600; color: #333; }
        .book-actions { margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .badge-format { display: inline-block; padding: 2px 10px; background: #e3f2fd; border-radius: 4px; font-size: 12px; color: #0d47a1; }
        @media (max-width: 600px) { 
            .header { flex-direction: column; align-items: flex-start; } 
            .header-actions { width: 100%; } 
            .header-actions .btn { flex: 1; text-align: center; } 
            .book-meta { flex-direction: column; gap: 3px; } 
            .book-meta span { display: block; } 
            .book-actions { flex-direction: column; } 
            .book-actions .btn { width: 100%; text-align: center; } 
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <h1>📚 Search Results</h1>
                <p class="subtitle">Found <?php echo isset($results['count']) ? $results['count'] : 0; ?> results for "<strong><?php echo htmlspecialchars($query); ?></strong>"</p>
            </div>
            <div class="header-actions">
                <a href="?" class="btn btn-secondary">← New Search</a>
                <?php if (isset($results['success']) && $results['success']): ?>
                    <a href="?search=<?php echo urlencode($query); ?>&format=json" class="btn btn-secondary">📊 JSON</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (isset($results['error'])): ?>
            <div class="error">⚠️ Error: <?php echo htmlspecialchars($results['error']); ?></div>
        <?php elseif (empty($results['results'])): ?>
            <div class="no-results"><span class="icon">😕</span><h3>No results found</h3><p>Try different search terms or check your spelling.</p></div>
        <?php else: ?>
            <?php foreach ($results['results'] as $book): ?>
                <div class="book">
                    <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
                    <?php if (!empty($book['author'])): ?>
                        <div class="book-author">✍️ <?php echo htmlspecialchars($book['author']); ?></div>
                    <?php endif; ?>
                    <div class="book-meta">
                        <?php if (!empty($book['publisher'])): ?><span><span class="label">Publisher:</span> <?php echo htmlspecialchars($book['publisher']); ?></span><?php endif; ?>
                        <?php if (!empty($book['year'])): ?><span><span class="label">Year:</span> <?php echo htmlspecialchars($book['year']); ?></span><?php endif; ?>
                        <?php if (!empty($book['language'])): ?><span><span class="label">Language:</span> <?php echo htmlspecialchars($book['language']); ?></span><?php endif; ?>
                        <?php if (!empty($book['pages']) && $book['pages'] !== '0'): ?><span><span class="label">Pages:</span> <?php echo htmlspecialchars($book['pages']); ?></span><?php endif; ?>
                        <?php if (!empty($book['size'])): ?><span><span class="label">Size:</span> <?php echo htmlspecialchars($book['size']); ?></span><?php endif; ?>
                        <?php if (!empty($book['extension'])): ?><span class="badge-format">📄 <?php echo strtoupper(htmlspecialchars($book['extension'])); ?></span><?php endif; ?>
                        <?php if (!empty($book['isbn'])): ?><span><span class="label">ISBN:</span> <?php echo htmlspecialchars($book['isbn']); ?></span><?php endif; ?>
                    </div>
                    <div class="book-actions">
                        <?php if (!empty($book['md5'])): ?>
                            <a href="?download&md5=<?php echo htmlspecialchars($book['md5']); ?>&ext=<?php echo htmlspecialchars($book['extension']); ?>&title=<?php echo urlencode($book['title']); ?>" class="btn btn-success">📥 Download</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
    <?php
}
?>
