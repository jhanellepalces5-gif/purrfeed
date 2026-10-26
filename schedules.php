<?php
date_default_timezone_set("Asia/Manila");
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8"); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PurrFeed | Schedules</title>
    <link rel="stylesheet" href="css/style.css"><script defer src="js/script.js"></script>
</head>
<body data-page="schedules">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">🐾</span><span><strong>PurrFeed</strong><small>smart feeding companion</small></span></a>
        <nav class="nav-links" aria-label="Main navigation">
            <a class="nav-link" href="index.php"><span class="nav-icon">⌂</span>Dashboard</a>
            <a class="nav-link active" href="schedules.php"><span class="nav-icon">▦</span>Schedules</a>
            <a class="nav-link" href="manual.php"><span class="nav-icon">◉</span>Manual Feeding</a>
            <a class="nav-link" href="history.php"><span class="nav-icon">↻</span>Feeding History</a>
        </nav>
        <div class="sidebar-bottom"><div class="pet-tip"><span class="tip-emoji">🗓️</span><strong>Plan ahead</strong><p>Set meal times that work for your furry friend.</p></div><small class="sidebar-foot">PurrFeed · Home feeder</small></div>
    </aside>
    <main class="main-content">
        <header class="topbar"><button class="icon-button mobile-menu" type="button" data-menu-toggle aria-label="Open navigation">☰</button><div><p class="eyebrow">FEEDING PLAN</p><h1>Schedules <span class="paw">🗓️</span></h1><p class="muted">Create and manage your cat's upcoming meals.</p></div></header>
        <section class="panel section-block">
            <div class="section-heading"><div><h2 id="schedule-form-title">Add a schedule</h2><p class="muted">Choose the date and time.</p></div></div>
            <form id="schedule-form" class="form-grid">
                <input type="hidden" id="schedule-id" name="id">
                <label class="field"><span>Feeding date</span><input type="date" id="feeding-date" name="feeding_date" required></label>
                <label class="field"><span>Feeding time</span><input type="time" id="feeding-time" name="feeding_time" required></label>
                <div class="form-actions"><button class="button button-primary" id="schedule-submit" type="submit">＋ Save schedule</button><button class="button button-quiet hidden" id="schedule-cancel-edit" type="button">Cancel edit</button></div>
            </form>
            <div id="schedule-message" class="inline-message" role="status" aria-live="polite"></div>
        </section>
        <section class="panel section-block">
            <div class="section-heading"><div><h2>Your schedules</h2><p class="muted">Refreshes after you add, edit, or cancel a schedule.</p></div><button class="button button-outline button-small" id="refresh-schedules" type="button">↻ Refresh</button></div>
            <div id="schedule-list"><div class="loading-state">Loading schedules…</div></div>
        </section>
        <footer class="page-footer">Made with care for your furry friend <span>♥</span></footer>
    </main>
</div>
</body>
</html>
