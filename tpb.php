<?php
function searchTPB($query, $category = null) {
    $url = "https://apibay.org/q.php?q=" . urlencode($query);
    if ($category) $url .= "&cat=" . urlencode($category);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function getTop50($category) {
    if ($category === 'recent') {
        $url = "https://apibay.org/precompiled/data_top100_recent.json";
    } else {
        $url = "https://apibay.org/precompiled/data_top100_" . $category . ".json";
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    return $data ? array_slice($data, 0, 50) : [];
}

// Handle search
$results = [];
$showTop = true;
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $query = $_GET['q'];
    $category = $_GET['cat'] ?? null;
    $results = searchTPB($query, $category);
    $showTop = false;
}

// Get top 50 for each category
$categories = [
    'Recent' => 'recent',
    'Audio' => '100',
    'Video' => '200',
    'Apps' => '300',
    'Games' => '400',
    'Porn' => '500',
    'Other' => '600'
];
$topTorrents = [];
if ($showTop) {
    foreach ($categories as $name => $cat) {
        $topTorrents[$name] = getTop50($cat);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TPB Search</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f0f0f0; }
        .search-box { 
            background: white; 
            padding: 20px; 
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .title-link { 
            text-decoration: none; 
            color: #0066cc; 
            font-size: 24px;
        }
        .title-link:hover { text-decoration: underline; }
        .category-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        .category-box {
            background: white;
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .category-box h3 {
            margin: 0 0 10px 0;
            padding: 5px;
            background: #0066cc;
            color: white;
            border-radius: 4px;
            text-align: center;
        }
        .scroll-list {
            max-height: 400px;
            overflow-y: auto;
        }
        table { 
            width: 100%; 
            font-size: 12px;
            border-collapse: collapse;
        }
        th, td { 
            padding: 4px 6px; 
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th { 
            background: #f5f5f5;
            position: sticky;
            top: 0;
        }
        .magnet-link { 
            color: #0066cc; 
            text-decoration: none;
            font-weight: bold;
        }
        .magnet-link:hover { text-decoration: underline; }
        .search-results table { font-size: 14px; }
        .search-results th, .search-results td { padding: 8px; }
        .torrent-name {
            position: relative;
            cursor: help;
        }
        .torrent-name:hover::after {
            content: attr(data-fullname);
            position: absolute;
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 13px;
            white-space: normal;
            max-width: 400px;
            word-wrap: break-word;
            z-index: 1000;
            left: 0;
            top: 100%;
            margin-top: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .torrent-name:hover::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 100%;
            border: 6px solid transparent;
            border-bottom-color: #333;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="search-box">
        <h1><a href="?q=" class="title-link">The Pirate Bay Search</a></h1>
        <form method="GET">
            <input type="text" name="q" placeholder="Search..." value="<?= $_GET['q'] ?? '' ?>" size="30">
            <select name="cat">
                <option value="">All</option>
                <option value="200" <?= ($_GET['cat'] ?? '') == '200' ? 'selected' : '' ?>>Video</option>
                <option value="201" <?= ($_GET['cat'] ?? '') == '201' ? 'selected' : '' ?>>Movies</option>
                <option value="205" <?= ($_GET['cat'] ?? '') == '205' ? 'selected' : '' ?>>TV Shows</option>
                <option value="207" <?= ($_GET['cat'] ?? '') == '207' ? 'selected' : '' ?>>HD Movies</option>
                <option value="301" <?= ($_GET['cat'] ?? '') == '301' ? 'selected' : '' ?>>Windows Apps</option>
                <option value="601" <?= ($_GET['cat'] ?? '') == '601' ? 'selected' : '' ?>>E-books</option>
            </select>
            <button type="submit">Search</button>
        </form>
    </div>

    <?php if ($showTop): ?>
        <div class="category-grid">
            <?php 
            $count = 0;
            foreach ($topTorrents as $name => $torrents): 
                if ($count == 3) echo '</div><div class="category-grid">';
                $count++;
            ?>
                <div class="category-box">
                    <h3><?= $name ?></h3>
                    <div class="scroll-list">
                        <table>
                            <tr>
                                <th>Name</th>
                                <th>SE</th>
                                <th>M</th>
                            </tr>
                            <?php foreach ($torrents as $torrent): ?>
                                <tr>
                                    <td>
                                        <span class="torrent-name" data-fullname="<?= htmlspecialchars($torrent['name']) ?>">
                                            <?= htmlspecialchars(substr($torrent['name'], 0, 30)) ?>...
                                        </span>
                                    </td>
                                    <td><?= $torrent['seeders'] ?></td>
                                    <td>
                                        <a href="magnet:?xt=urn:btih:<?= $torrent['info_hash'] ?>&dn=<?= urlencode($torrent['name']) ?>&tr=udp://tracker.opentrackr.org:1337" class="magnet-link">
                                            ⬇
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="search-results">
            <h2>Results for: <?= htmlspecialchars($_GET['q']) ?></h2>
            <table>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Size</th>
                    <th>SE</th>
                    <th>LE</th>
                    <th>Uploader</th>
                    <th>Magnet</th>
                </tr>
                <?php foreach ($results as $torrent): ?>
                    <tr>
                        <td><?= htmlspecialchars($torrent['name']) ?></td>
                        <td><?= $torrent['category'] ?></td>
                        <td><?= round($torrent['size'] / 1048576, 2) ?> MB</td>
                        <td><?= $torrent['seeders'] ?></td>
                        <td><?= $torrent['leechers'] ?></td>
                        <td><?= htmlspecialchars($torrent['username']) ?></td>
                        <td>
                            <a href="magnet:?xt=urn:btih:<?= $torrent['info_hash'] ?>&dn=<?= urlencode($torrent['name']) ?>&tr=udp://tracker.opentrackr.org:1337" class="magnet-link">
                                Magnet
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p>Found <?= count($results) ?> results</p>
        </div>
    <?php endif; ?>
</body>
</html>
