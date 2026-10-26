<?php
date_default_timezone_set("Asia/Manila");
require_once __DIR__ . "/backend/db.php";

$gramsPerSecond = 15.0;

$currentMonth = date("Y-m");

$monthlyEstimatedGrams = 0;

$records = [];
$loadError = "";
try {
    $result = $conn->query("
        SELECT id, schedule_id, feeding_type, dispense_seconds, status, created_at
        FROM feeding_history
        ORDER BY created_at DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) $records[] = $row;
    }
} catch (Throwable $e) {
    error_log("PurrFeed history: " . $e->getMessage());
    $loadError = "Could not load feeding history. Check your database table and column names.";
}

// ADD THE MONTHLY CALCULATION HERE

$monthlyEstimatedGrams = 0;

foreach ($records as $record) {
    if (
        date("Y-m", strtotime($record["created_at"])) === $currentMonth
        && $record["status"] === "Completed"
    ) {
        $monthlyEstimatedGrams +=
            (float)$record["dispense_seconds"] * $gramsPerSecond;
    }
}

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8"); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PurrFeed | Feeding History</title>
    <link rel="stylesheet" href="css/style.css"><script defer src="js/script.js"></script>
</head>
<body data-page="history">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">🐾</span><span><strong>PurrFeed</strong><small>smart feeding companion</small></span></a>
        <nav class="nav-links" aria-label="Main navigation">
            <a class="nav-link" href="index.php"><span class="nav-icon">⌂</span>Dashboard</a>
            <a class="nav-link" href="schedules.php"><span class="nav-icon">▦</span>Schedules</a>
            <a class="nav-link" href="manual.php"><span class="nav-icon">◉</span>Manual Feeding</a>
            <a class="nav-link active" href="history.php"><span class="nav-icon">↻</span>Feeding History</a>
        </nav>
        <div class="sidebar-bottom"><div class="pet-tip"><span class="tip-emoji">📋</span><strong>Keep track</strong><p>Review feeding attempts and spot failures.</p></div><small class="sidebar-foot">PurrFeed · Home feeder</small></div>
    </aside>
    <main class="main-content">
        <header class="topbar"><button class="icon-button mobile-menu" type="button" data-menu-toggle aria-label="Open navigation">☰</button><div><p class="eyebrow">FEEDING RECORDS</p><h1>Feeding History <span class="paw">📋</span></h1><p class="muted">Review your latest feeding attempts and their status.</p></div><button class="button button-outline top-action" type="button" onclick="window.location.reload()">↻ Refresh</button></header>
        <?php if ($loadError): ?><div class="alert alert-warning"><?= h($loadError) ?></div><?php endif; ?>
        <section class="stats-grid history-stats section-block">
            <article class="stat-card"><div class="stat-top"><span>Records shown</span><span class="stat-icon sage">↻</span></div><strong><?= count($records) ?></strong><p>Latest 200 records</p></article>
            <article class="stat-card"><div class="stat-top"><span>Estimated Grams</span><span class="stat-icon peach">⚖️</span></div><strong><?= number_format($monthlyEstimatedGrams, 0) ?> g</strong><p>Completed feedings this month</p></article>
            <article class="stat-card"><div class="stat-top"><span>Failed attempts</span><span class="stat-icon cream">!</span></div><strong><?= count(array_filter($records, fn($r) => $r["status"] === "Failed")) ?></strong><p>In records shown</p></article>
        </section>
        <section class="panel section-block">
            <div class="section-heading"><div><h2>All recent records</h2><p class="muted">Newest feeding attempts appear first.</p></div><label class="search-field"><span>⌕</span><input id="history-search" type="search" placeholder="Search type or status" aria-label="Search feeding history"></label>
               <input type="date" id="history-date">

               <button type="button" id="clear-history-filters">
                 Clear
               </button>
            </div>

            <?php if (!$records): ?>
                <div class="empty-state"><span>📭</span><strong>No history yet</strong><p>Feeding records will appear here after the backend saves a result.</p></div>
            <?php else: ?>
                <div class="table-wrap"><table id="history-table"><thead><tr><th>Date &amp; time</th><th>Type</th><th>Duration</th><th>Status</th><th>Estimated Grams</th></tr></thead><tbody>
                <?php foreach ($records as $row): ?>
                    <tr
                     data-date="<?= h(date("Y-m-d", strtotime($row["created_at"]))) ?>"
                     data-search="<?= h(strtolower($row["feeding_type"] . " " . $row["status"] . " " . $row["created_at"])) ?>">
                        <td><?= h(date("M j, Y · g:i A", strtotime($row["created_at"]))) ?></td>
                        <td>
                          <span class="type-label"><?= $row["feeding_type"] === "Automatic" ? "🤖" : "✋" ?> <?= h($row["feeding_type"]) ?></span>
                        </td>

                        <td>
                          <?= number_format((float)$row["dispense_seconds"], 2) ?> sec
                        </td>

                        <td>
                          <span class="status-pill <?= h(strtolower($row["status"])) ?>"><?= h($row["status"]) ?></span>
                        </td>
                    
                        <td>
                           <?= number_format((float)$row["dispense_seconds"] * $gramsPerSecond, 0) ?> g
                        </td>
                    </tr>
                <?php endforeach; ?>

                </tbody></table></div>
                <p id="history-no-results" class="empty-inline hidden">No records match your search.</p>
            <?php endif; ?>
        </section>
        <footer class="page-footer">Made with care for your furry friend <span>♥</span></footer>
    </main>
</div>
</body>
</html>
