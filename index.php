<?php
date_default_timezone_set("Asia/Manila");
require_once __DIR__ . "/backend/db.php";


$gramsPerSecond = defined("ESTIMATED_GRAMS_PER_SECOND") ? (float) ESTIMATED_GRAMS_PER_SECOND : 15.0;

$stats = [
    "today" => 0,
    "manual" => 0,
    "month" => 0,
    "grams" => 0.0,
    "next" => null,
    "recent" => [],
    "errors" => []
];

$today = date("Y-m-d");
$monthStart = date("Y-m-01");


try {
    // Total feedings today
    $stmt = $conn->prepare(" SELECT COUNT(*) AS total,COALESCE(SUM(
                   CASE WHEN status = 'Completed'
                        THEN dispense_seconds ELSE 0 END ), 0) AS seconds FROM feeding_history WHERE DATE(created_at) = ? ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $stats["today"] = (int) $row["total"];
    $stats["grams"] = (float) $row["seconds"] * $gramsPerSecond; 
    $stmt->close();

    // Total feedings this month
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM feeding_history
        WHERE created_at >= ?
          AND created_at < DATE_ADD(?, INTERVAL 1 MONTH)
    ");
    $stmt->bind_param("ss", $monthStart, $monthStart);
    $stmt->execute();
    $stats["month"] = (int) $stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    // Next scheduled feeding
    $result = $conn->query("
        SELECT id, feeding_date, feeding_time
        FROM schedules
        WHERE status = 'Pending'
          AND TIMESTAMP(feeding_date, feeding_time) >= NOW()
        ORDER BY feeding_date ASC, feeding_time ASC
        LIMIT 1
    ");

    if ($result) {
        $stats["next"] = $result->fetch_assoc();
    }
   


    $result = $conn->query("
        SELECT feeding_type, dispense_seconds, status, created_at
        FROM feeding_history
        ORDER BY created_at DESC
        LIMIT 6
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) $stats["recent"][] = $row;
    }
} catch (Throwable $e) {
    error_log("PurrFeed dashboard: " . $e->getMessage());
    $stats["errors"][] = "Some dashboard information could not be loaded. Check your database tables and backend/db.php.";
}
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8"); }
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PurrFeed | Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
    <script defer src="js/script.js"></script>
</head>
<body data-page="dashboard">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">🐾</span><span><strong>PurrFeed</strong><small>smart feeding companion</small></span></a>
        <nav class="nav-links" aria-label="Main navigation">
            <a class="nav-link active" href="index.php"><span class="nav-icon">⌂</span>Dashboard</a>
            <a class="nav-link" href="schedules.php"><span class="nav-icon">▦</span>Schedules</a>
            <a class="nav-link" href="manual.php"><span class="nav-icon">◉</span>Manual Feeding</a>
            <a class="nav-link" href="history.php"><span class="nav-icon">↻</span>Feeding History</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="pet-tip"><span class="tip-emoji">🐈</span><strong>Little reminder</strong><p>Fresh food, happy paws. Check the feeder regularly.</p></div>
            <small class="sidebar-foot">PurrFeed · Home feeder</small>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
          <button class="icon-button mobile-menu" type="button" data-menu-toggle aria-label="Open navigation">☰</button>

            <div>
              <h1>Good <?= (int)date("G") < 12 ? "morning" : ((int)date("G") < 18 ? "afternoon" : "evening") ?>! <span class="paw">🐾</span></h1>
             <p class="muted">Here's your PurrFeed overview.</p>
           </div>

            <div class="datetime">
                <span id="current-date"></span>
              <strong id="current-time"></strong>
           </div>

</header>

        <?php foreach ($stats["errors"] as $error): ?>
            <div class="alert alert-warning"><?= h($error) ?></div>
        <?php endforeach; ?>

        <section class="section-block">
            <div class="section-heading"><div><h2>Next feeding</h2><p class="muted">Your next scheduled meal</p></div><a class="text-link" href="schedules.php">Manage schedules →</a></div>
            <div class="next-feeding-card">
                <div class="next-icon">🕒</div>
                <div class="next-info">
                    <?php if ($stats["next"]): ?>
                        <span class="status-pill status-scheduled">Scheduled</span>
                        <h3 class="countdown" data-countdown="<?= h($stats["next"]["feeding_date"] . "T" . $stats["next"]["feeding_time"]) ?>"> Calculating countdown… </h3>
                        <p><?= h(date("l, F j, Y", strtotime($stats["next"]["feeding_date"]))) ?></p>
                        <h4><?= h(date("g:i A", strtotime($stats["next"]["feeding_time"]))) ?> </h4>
                    <?php else: ?>
                        <span class="status-pill status-neutral">No upcoming schedule</span>
                        <h3>All clear for now</h3>
                        <p>Add a schedule to plan your cat's next meal.</p>
                        <a class="text-link" href="schedules.php">＋ Create a schedule</a>
                    <?php endif; ?>
                </div>
               
            </div>
        </section>

        <section class="stats-grid section-block" aria-label="Feeding statistics">
            <article class="stat-card"><div class="stat-top"><span>Estimated food dispensed</span><span class="stat-icon sage">⚖</span></div><strong><?= number_format($stats["grams"], 0) ?> g</strong><p>EVERYDAY · estimate only</p></article>
            <article class="stat-card"><div class="stat-top"><span>Manual feedings today</span><span class="stat-icon peach">◉</span></div><strong><?= number_format($stats["manual"]) ?></strong><p>Manual feeding records</p></article>
            <article class="stat-card"><div class="stat-top"><span>Feedings this month</span><span class="stat-icon cream">▦</span></div><strong><?= number_format($stats["month"]) ?></strong><p>All recorded feeding attempts</p></article>
        </section>

        <section class="section-block panel">
            <div class="section-heading"><div><h2>Recent feeding activity</h2><p class="muted">Your latest recorded feeding attempts</p></div><a class="text-link" href="history.php">View history →</a></div>
            <?php if (!$stats["recent"]): ?>
                <div class="empty-state"><span>🍽️</span><strong>No feeding records yet</strong><p>Completed and failed feedings will appear here.</p></div>
            <?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>Type</th><th>Date &amp; time</th><th>Duration</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($stats["recent"] as $item): ?>
                    <tr><td><span class="type-label"><?= h($item["feeding_type"]) === "Automatic" ? "🤖" : "✋" ?> <?= h($item["feeding_type"]) ?></span></td><td><?= h(date("M j, Y · g:i A", strtotime($item["created_at"]))) ?></td><td><?= number_format((float)$item["dispense_seconds"], 2) ?> sec</td><td><span class="status-pill <?= h(strtolower($item["status"])) ?>"><?= h($item["status"]) ?></span></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
        <footer class="page-footer">Made with care for your furry friend <span>♥</span></footer>
    </main>
</div>
</body>
</html>
