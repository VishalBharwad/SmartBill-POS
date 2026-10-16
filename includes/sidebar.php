
<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');

if (!function_exists('smartbillNavLink')) {
    function smartbillNavLink(string $file, string $label, string $icon, string $currentPage): void
    {
        $active = ($currentPage === $file) ? ' active' : '';
        $safeFile = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeIcon = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');

        echo '<a class="bb-nav-link' . $active . '" href="' . $safeFile . '"' .
            ($active !== '' ? ' aria-current="page"' : '') . '>';
        echo '<span class="bb-nav-icon"><i class="bi ' . $safeIcon . '" aria-hidden="true"></i></span>';
        echo '<span class="bb-nav-label">' . $safeLabel . '</span>';

        if ($active !== '') {
            echo '<span class="bb-active-indicator" aria-hidden="true"></span>';
        }

        echo '</a>';
    }
}
?>

<!-- SmartBill POS Professional Sidebar -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    :root {
        --bb-sidebar-width: 258px;
        --bb-sidebar-bg: #111827;
        --bb-sidebar-muted: #9ca8bd;
        --bb-sidebar-text: #f8fafc;
        --bb-sidebar-accent: #14b8a6;
    }

    .bb-sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 1040;
        display: flex;
        flex-direction: column;
        width: var(--bb-sidebar-width);
        height: 100vh;
        padding: 23px 15px 16px;
        overflow-y: auto;
        color: var(--bb-sidebar-text);
        background: linear-gradient(180deg, #151d2e 0%, #101625 100%);
        border-right: 1px solid rgba(255,255,255,.06);
        box-shadow: 8px 0 28px rgba(15,23,42,.06);
        font-family: Inter, "Segoe UI", Arial, sans-serif;
        transition: transform .25s ease;
    }

    .bb-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0 9px 25px;
        border-bottom: 1px solid rgba(255,255,255,.09);
        margin-bottom: 25px;
    }

    .bb-brand-mark {
        display: grid;
        place-items: center;
        flex: 0 0 44px;
        width: 44px;
        height: 44px;
        border-radius: 14px;
        color: #fff;
        font-size: 22px;
        font-weight: 800;
        background: linear-gradient(135deg, #2dd4bf, #0f766e);
        box-shadow: 0 7px 18px rgba(20,184,166,.25);
    }

    .bb-brand-name {
        margin: 0;
        color: #fff;
        font-size: 17px;
        font-weight: 800;
        letter-spacing: -.35px;
        line-height: 1.2;
    }

    .bb-brand-subtitle {
        margin-top: 5px;
        color: #a6b0c3;
        font-size: 11px;
        letter-spacing: .7px;
        text-transform: uppercase;
    }

    .bb-nav-heading {
        padding: 0 12px 11px;
        color: #7f8ba2;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 1.3px;
    }

    .bb-nav {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .bb-nav-link {
        position: relative;
        display: flex;
        align-items: center;
        gap: 13px;
        min-height: 47px;
        padding: 0 12px;
        border: 1px solid transparent;
        border-radius: 12px;
        color: #b6c0d1;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        transition: background .18s ease, color .18s ease, border-color .18s ease;
    }

    .bb-nav-link:hover {
        color: #fff;
        background: rgba(255,255,255,.065);
    }

    .bb-nav-link:focus-visible {
        outline: 2px solid #5eead4;
        outline-offset: 2px;
    }

    .bb-nav-link.active {
        color: #fff;
        background: linear-gradient(100deg, rgba(20,184,166,.22), rgba(20,184,166,.08));
        border-color: rgba(45,212,191,.22);
    }

    .bb-nav-icon {
        display: grid;
        place-items: center;
        width: 23px;
        color: #9ca9c0;
        font-size: 17px;
    }

    .bb-nav-link.active .bb-nav-icon {
        color: #5eead4;
    }

    .bb-active-indicator {
        position: absolute;
        right: -1px;
        top: 12px;
        bottom: 12px;
        width: 3px;
        border-radius: 5px 0 0 5px;
        background: #2dd4bf;
    }

    .bb-sidebar-spacer {
        flex: 1;
        min-height: 25px;
    }

    .bb-sidebar-footer {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 15px 3px 0;
        padding: 14px 12px;
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 13px;
        background: rgba(255,255,255,.035);
    }

    .bb-status-dot {
        width: 8px;
        height: 8px;
        flex: 0 0 8px;
        border-radius: 50%;
        background: #35d399;
        box-shadow: 0 0 0 4px rgba(53,211,153,.12);
    }

    .bb-status-title {
        color: #e3e9f3;
        font-size: 12px;
        font-weight: 700;
    }

    .bb-status-subtitle {
        margin-top: 2px;
        color: #8491a8;
        font-size: 10px;
    }

    .bb-sidebar-toggle {
        display: none;
        position: fixed;
        z-index: 1055;
        top: 14px;
        left: 14px;
        width: 42px;
        height: 42px;
        align-items: center;
        justify-content: center;
        border: 1px solid #e4e8f0;
        border-radius: 12px;
        background: #fff;
        color: #172033;
        box-shadow: 0 4px 14px rgba(15,23,42,.10);
        font-size: 20px;
        cursor: pointer;
    }

    .bb-sidebar-overlay {
        display: none;
    }

    /* Desktop content spacing */
    body {
        padding-left: var(--bb-sidebar-width);
    }

    .main-content {
        min-width: 0;
    }

    @media (max-width: 900px) {
        body {
            padding-left: 0;
        }

        .bb-sidebar {
            transform: translateX(-105%);
        }

        .bb-sidebar.bb-open {
            transform: translateX(0);
        }

        .bb-sidebar-toggle {
            display: inline-flex;
        }

        .bb-sidebar-overlay.bb-visible {
            display: block;
            position: fixed;
            inset: 0;
            z-index: 1035;
            background: rgba(8,15,30,.48);
        }

        .main-content {
            padding-top: 72px !important;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .bb-sidebar,
        .bb-nav-link {
            transition: none;
        }
    }
</style>

<button class="bb-sidebar-toggle" id="bbSidebarToggle" type="button"
        aria-label="Open navigation" aria-controls="bbSidebar" aria-expanded="false">
    <i class="bi bi-list" aria-hidden="true"></i>
</button>

<div class="bb-sidebar-overlay" id="bbSidebarOverlay"></div>

<aside class="bb-sidebar" id="bbSidebar" aria-label="Main navigation">
    <div class="bb-brand">
        <div class="bb-brand-mark">S</div>
        <div>
            <div class="bb-brand-name">SmartBill POS</div>
            <div class="bb-brand-subtitle">Sales &amp; Inventory</div>
        </div>
    </div>

    <div class="bb-nav-heading">WORKSPACE</div>

    <nav class="bb-nav" aria-label="Workspace">
        <?php smartbillNavLink('index.php', 'Dashboard', 'bi-grid-1x2-fill', $currentPage); ?>
        <?php smartbillNavLink('products.php', 'Products', 'bi-box-seam-fill', $currentPage); ?>
        <?php smartbillNavLink('customers.php', 'Customers', 'bi-people-fill', $currentPage); ?>
        <?php smartbillNavLink('pos.php', 'POS / New Sale', 'bi-cart3', $currentPage); ?>
        <?php smartbillNavLink('invoices.php', 'Invoices', 'bi-receipt-cutoff', $currentPage); ?>
        <?php smartbillNavLink('sales_report.php', 'Sales Report', 'bi-bar-chart-line-fill', $currentPage); ?>

        <a class="bb-nav-link" href="/brainbean_task/logout.php">
            <span class="bb-nav-icon">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            </span>
            <span class="bb-nav-label">Logout</span>
        </a>
    </nav>

    <div class="bb-sidebar-spacer"></div>

    <div class="bb-sidebar-footer">
        <span class="bb-status-dot" aria-hidden="true"></span>
        <div>
            <div class="bb-status-title">System Online</div>
            <div class="bb-status-subtitle">SmartBill workspace active</div>
        </div>
        <i class="bi bi-shield-check ms-auto"
           style="color:#8290a8;font-size:16px" aria-hidden="true"></i>
    </div>
</aside>

<script>
(function () {
    const sidebar = document.getElementById('bbSidebar');
    const toggle = document.getElementById('bbSidebarToggle');
    const overlay = document.getElementById('bbSidebarOverlay');

    if (!sidebar || !toggle || !overlay) return;

    function setOpen(open) {
        sidebar.classList.toggle('bb-open', open);
        overlay.classList.toggle('bb-visible', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');

        toggle.innerHTML = open
            ? '<i class="bi bi-x-lg" aria-hidden="true"></i>'
            : '<i class="bi bi-list" aria-hidden="true"></i>';
    }

    toggle.addEventListener('click', function () {
        setOpen(!sidebar.classList.contains('bb-open'));
    });

    overlay.addEventListener('click', function () {
        setOpen(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setOpen(false);
    });

    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 900) setOpen(false);
        });
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) setOpen(false);
    });
})();
</script>
```