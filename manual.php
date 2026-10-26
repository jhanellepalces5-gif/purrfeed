<?php
date_default_timezone_set("Asia/Manila");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PurrFeed | Manual Feeding</title>
    <link rel="stylesheet" href="css/style.css"><script defer src="js/script.js"></script>
</head>
<body data-page="manual">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">🐾</span><span><strong>PurrFeed</strong><small>smart feeding companion</small></span></a>
        <nav class="nav-links" aria-label="Main navigation">
            <a class="nav-link" href="index.php"><span class="nav-icon">⌂</span>Dashboard</a>
            <a class="nav-link" href="schedules.php"><span class="nav-icon">▦</span>Schedules</a>
            <a class="nav-link active" href="manual.php"><span class="nav-icon">◉</span>Manual Feeding</a>
            <a class="nav-link" href="history.php"><span class="nav-icon">↻</span>Feeding History</a>
        </nav>
        <div class="sidebar-bottom"><div class="pet-tip"><span class="tip-emoji">🐾</span><strong>One tap at a time</strong><p>Wait for the current request before sending another.</p></div><small class="sidebar-foot">PurrFeed · Home feeder</small></div>
    </aside>
    <main class="main-content">
        <header class="topbar"><button class="icon-button mobile-menu" type="button" data-menu-toggle aria-label="Open navigation">☰</button><div><p class="eyebrow">ON-DEMAND FEEDING</p><h1>Manual Feeding <span class="paw">🍽️</span></div></header>
        <section class="manual-layout section-block">
            <article class="manual-card panel">
                <div class="manual-illustration">🐈</div>
                <span class="status-pill status-neutral">Manual control</span>
                <h2>Ready for a snack?</h2>
                <p class="muted">Push the button. Wait for the current request before sending another.</p>
                <button class="button button-primary button-large" id="manual-feed-button" type="button"><span>🍽️</span> FEED</button>
                <div id="manual-message" class="inline-message" role="status" aria-live="polite"></div>
                <p class="form-hint"></p>
            </article>

            <aside class="panel manual-notes">
               
              <div class="section-heading">
                  <div>
                      <h2>Manual Feeding Records</h2>
                       
                   </div>

                  <button
                       type="button"
                      class="button button-outline"
                      id="refresh-manual-records"
                   >
                      ↻ Refresh
                  </button>
              </div>

              <div class="table-wrap">
                  <table id="manual-records-table">
                      <thead>
                         <tr>
                             <th>Date &amp; Time</th>
                             <th>Grams</th>
                              <th>Status</th>
                         </tr>
                     </thead>

                      <tbody id="manual-records-body">
                         <tr>
                             <td colspan="3">Loading manual feeding records...</td>
                         </tr>
                      </tbody>
                  </table>
              </div>

           </aside>
      </section>
        <footer class="page-footer">Made with care for your furry friend <span>♥</span></footer>
    </main>
</div>
</body>
</html>
