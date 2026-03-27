<?php
declare(strict_types=1);
$topbarSearchPlaceholder = $topbarSearchPlaceholder ?? 'Search...';
?>
<div class="topbar">
    <button class="icon-btn menu-btn" data-sidebar-toggle aria-label="Toggle menu">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 7h16M4 12h16M4 17h16"></path>
        </svg>
    </button>
    <div class="search">
        <span class="icon-inline">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle>
                <path d="M20 20l-3.2-3.2"></path>
            </svg>
        </span>
        <input type="text" placeholder="<?= h($topbarSearchPlaceholder) ?>" readonly>
    </div>
    <div class="top-actions">
        <button class="icon-btn" aria-label="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M6 9a6 6 0 0 1 12 0c0 5 2 5 2 5H4s2 0 2-5"></path>
                <path d="M9.5 19a2.5 2.5 0 0 0 5 0"></path>
            </svg>
        </button>
        <a class="icon-btn" href="/FinancialSM/auth/logout.php" aria-label="Logout" title="Logout">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M15 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3"></path>
                <path d="M10 17l5-5-5-5"></path>
                <path d="M15 12H4"></path>
            </svg>
        </a>
    </div>
</div>
