<?php
// EZTV Search with status check, extra torrent data (no thumbnail)
$search_title = $_GET['title'] ?? '';
$show_all = isset($_GET['show_all']);
$results = [];
$full_list = [];
$error = '';
$show_name = '';
$imdb_display = '';
$status_message = '';

// Check EZTV status
$status_url = 'https://eztvstatus.org/';
$status_content = @file_get_contents($status_url);
if ($status_content !== false) {
    if (stripos($status_content, 'All systems are up and running') !== false) {
        $status_message = '<span style="color:green;">✅ All systems operational</span>';
    } elseif (stripos($status_content, 'degraded') !== false) {
        $status_message = '<span style="color:orange;">⚠️ Degraded performance</span>';
    } else {
        $status_message = '<span style="color:gray;">ℹ️ Status unknown</span>';
    }
} else {
    $status_message = '<span style="color:red;">❌ Cannot reach status page</span>';
}

// If no search, fetch latest 50 torrents
if (!$search_title) {
    $eztv_url = 'https://eztvx.to/api/get-torrents?limit=50&page=1';
    $eztv_json = @file_get_contents($eztv_url);
    if ($eztv_json) {
        $eztv_data = json_decode($eztv_json, true);
        $full_list = $eztv_data['torrents'] ?? [];
        $results = $full_list;
    }
}

if ($search_title) {
    // 1. Get show info from TVmaze
    $tvmaze_url = 'https://api.tvmaze.com/singlesearch/shows?q=' . urlencode($search_title);
    $tvmaze_json = @file_get_contents($tvmaze_url);
    if ($tvmaze_json) {
        $show = json_decode($tvmaze_json, true);
        if ($show && isset($show['externals']['imdb'])) {
            $imdb_full = $show['externals']['imdb'];
            $imdb_id = ltrim($imdb_full, 'tt');
            $imdb_display = $imdb_full;
            $show_name = $show['name'];

            // 2. Query EZTV API with stripped IMDB ID
            $eztv_url = 'https://eztvx.to/api/get-torrents?imdb_id=' . $imdb_id;
            $eztv_json = @file_get_contents($eztv_url);
            if ($eztv_json) {
                $eztv_data = json_decode($eztv_json, true);
                $full_list = $eztv_data['torrents'] ?? [];

                // 3. Filter if not showing all
                if (!$show_all) {
                    foreach ($full_list as $torrent) {
                        if (stripos($torrent['title'] ?? '', $show_name) !== false) {
                            $results[] = $torrent;
                        }
                    }
                    if (empty($results) && !empty($full_list)) {
                        $error = "No exact matches for '{$show_name}'. 
                                  <a href='?title=" . urlencode($search_title) . "&show_all=1'>Show all results from EZTV</a>";
                    } elseif (empty($results)) {
                        $error = "No torrents found for '{$show_name}'.";
                    }
                } else {
                    $results = $full_list;
                }
            } else {
                $error = 'EZTV API unavailable.';
            }
        } else {
            $error = 'Show not found on TVmaze.';
        }
    } else {
        $error = 'TVmaze API failed.';
    }
}

// Helper: format size
function format_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1024, 0) . ' KB';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>EZTV Search</title>
    <style>
        body { font-family: Arial; max-width: 1000px; margin: 20px auto; padding: 10px; }
        input[type="text"] { width: 300px; padding: 8px; }
        input[type="submit"] { padding: 8px 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; font-size: 13px; }
        th { background: #f2f2f2; }
        .error { color: red; }
        .info { color: green; }
        .note { font-style: italic; }
        .status { margin-bottom: 15px; padding: 8px; background: #fafafa; border-left: 4px solid #4CAF50; }
        .title-link { text-decoration: none; color: inherit; }
        .title-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1><a href="?" class="title-link">EZTV Torrent Search</a></h1>
    <div class="status">Status: <?php echo $status_message; ?></div>
    <form method="get">
        <input type="text" name="title" placeholder="Enter TV show title" value="<?php echo htmlspecialchars($search_title); ?>">
        <input type="submit" value="Search">
    </form>

    <?php if ($show_name): ?>
        <p class="info">Found show: <strong><?php echo htmlspecialchars($show_name); ?></strong> (IMDB: <?php echo htmlspecialchars($imdb_display); ?>)</p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="error"><?php echo $error; ?></p>
    <?php endif; ?>

    <?php if ($show_all && $results): ?>
        <p class="note">Showing all torrents from EZTV (unfiltered). 
           <a href="?title=<?php echo urlencode($search_title); ?>">Back to filtered results</a></p>
    <?php endif; ?>

    <?php if ($results): ?>
        <h2><?php echo $show_all ? 'All Torrents' : ($search_title ? 'Results for "' . htmlspecialchars($show_name) . '"' : 'Latest 50 Torrents'); ?></h2>
        <table>
            <tr>
                <th>Title</th>
                <th>Season/Ep</th>
                <th>Size</th>
                <th>Seeds</th>
                <th>Peers</th>
                <th>Date</th>
                <th>Magnet</th>
            </tr>
            <?php foreach ($results as $torrent): ?>
                <tr>
                    <td><?php echo htmlspecialchars($torrent['title'] ?? ''); ?></td>
                    <td>
                        <?php 
                        $s = $torrent['season'] ?? ''; 
                        $e = $torrent['episode'] ?? '';
                        if ($s && $e) echo "S{$s}E{$e}";
                        elseif ($s) echo "Season {$s}";
                        else echo '-';
                        ?>
                    </td>
                    <td><?php echo format_size($torrent['size_bytes'] ?? 0); ?></td>
                    <td><?php echo $torrent['seeds'] ?? 0; ?></td>
                    <td><?php echo $torrent['peers'] ?? 0; ?></td>
                    <td>
                        <?php 
                        $ts = $torrent['date_released_unix'] ?? 0;
                        echo $ts ? date('Y-m-d', $ts) : 'N/A';
                        ?>
                    </td>
                    <td><a href="<?php echo htmlspecialchars($torrent['magnet_url'] ?? '#'); ?>">Magnet</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>
