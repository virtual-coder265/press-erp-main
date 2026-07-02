document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const desktopToggle = document.getElementById('sidebar-toggle');
    const mobileToggle = document.getElementById('mobile-menu-toggle');
    const mobileNotifToggle = document.getElementById('mobile-notif-toggle');
    const mobileNotifDropdown = document.getElementById('mobile-notif-dropdown');
    const collapseKey = 'govpress_sidebar_collapsed';

    function isDesktop() {
        return window.innerWidth >= 768;
    }

    function syncSidebarButtons(collapsed) {
        if (desktopToggle) {
            desktopToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            desktopToggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
            desktopToggle.innerHTML = '<i data-lucide="' + (collapsed ? 'panel-left' : 'panel-left-close') + '" aria-hidden="true"></i>';
        }
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
    }

    function setSidebarCollapsed(collapsed) {
        if (!sidebar || !isDesktop()) {
            return;
        }

        sidebar.classList.toggle('is-collapsed', collapsed);
        body.classList.toggle('sidebar-collapsed', collapsed);
        syncSidebarButtons(collapsed);
        window.localStorage.setItem(collapseKey, collapsed ? 'true' : 'false');
    }

    function setMobileSidebar(open) {
        if (!sidebar || !sidebarOverlay || isDesktop()) {
            return;
        }

        sidebar.classList.toggle('sidebar-open', open);
        sidebarOverlay.classList.toggle('hidden', !open);
        body.classList.toggle('overflow-hidden', open);
        if (mobileToggle) {
            mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function togglePanel(button) {
        const panelId = button.getAttribute('data-sidebar-toggle');
        const panel = panelId ? document.getElementById(panelId) : null;

        if (!panel) {
            return;
        }

        const isOpen = !panel.classList.contains('hidden');
        panel.classList.toggle('hidden', isOpen);
        button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        button.classList.toggle('is-active', !isOpen);
    }

    if (sidebar && isDesktop()) {
        setSidebarCollapsed(window.localStorage.getItem(collapseKey) === 'true');
    }

    if (desktopToggle) {
        desktopToggle.addEventListener('click', function () {
            if (!sidebar) {
                return;
            }

            if (!isDesktop()) {
                setMobileSidebar(!sidebar.classList.contains('sidebar-open'));
                return;
            }

            setSidebarCollapsed(!sidebar.classList.contains('is-collapsed'));
        });
    }

    if (mobileToggle) {
        mobileToggle.addEventListener('click', function () {
            if (!sidebar) {
                return;
            }

            setMobileSidebar(!sidebar.classList.contains('sidebar-open'));
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function () {
            setMobileSidebar(false);
        });
    }

    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (sidebar && sidebar.classList.contains('is-collapsed') && isDesktop()) {
                setSidebarCollapsed(false);
            }

            togglePanel(button);
        });
    });

    document.querySelectorAll('#sidebar a[href]').forEach(function (link) {
        link.addEventListener('click', function () {
            if (!isDesktop()) {
                setMobileSidebar(false);
            }
        });
    });

    if (mobileNotifToggle && mobileNotifDropdown) {
        mobileNotifToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            mobileNotifDropdown.classList.toggle('hidden');
        });
    }

    document.addEventListener('click', function (event) {
        if (
            mobileNotifDropdown &&
            !mobileNotifDropdown.classList.contains('hidden') &&
            !mobileNotifDropdown.contains(event.target) &&
            mobileNotifToggle &&
            !mobileNotifToggle.contains(event.target)
        ) {
            mobileNotifDropdown.classList.add('hidden');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setMobileSidebar(false);
            if (mobileNotifDropdown) {
                mobileNotifDropdown.classList.add('hidden');
            }
        }
    });

    window.addEventListener('resize', function () {
        if (isDesktop()) {
            if (sidebarOverlay) {
                sidebarOverlay.classList.add('hidden');
            }
            body.classList.remove('overflow-hidden');
            if (sidebar) {
                sidebar.classList.remove('sidebar-open');
                setSidebarCollapsed(window.localStorage.getItem(collapseKey) === 'true');
            }
        } else if (sidebar) {
            sidebar.classList.remove('is-collapsed');
            syncSidebarButtons(false);
        }
    });

    // In-page anchors: workspace content scrolls inside `main.app-page`, not the viewport.
    // Native fragment scrolling often skips that panel, so #task-documentation-hub etc. looked like "nothing happens".
    function appPageFragmentTopReservePx() {
        return isDesktop() ? 24 : (80 + 24);
    }

    function scrollAppPageToFragment() {
        var hash = window.location.hash || '';
        if (!hash || hash.length < 2) {
            return;
        }

        var mainEl = document.querySelector('main.app-page');
        if (!mainEl) {
            return;
        }

        var id;
        try {
            id = decodeURIComponent(hash.slice(1).replace(/\+/g, ' '));
        } catch (err) {
            return;
        }
        if (!id) {
            return;
        }

        var target = document.getElementById(id);
        if (!target || !mainEl.contains(target)) {
            return;
        }

        var reserve = appPageFragmentTopReservePx();
        var delta = target.getBoundingClientRect().top - mainEl.getBoundingClientRect().top - reserve;
        mainEl.scrollTop = Math.max(0, mainEl.scrollTop + delta);
    }

    function queueScrollAppPageToFragment() {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(scrollAppPageToFragment);
        });
    }

    queueScrollAppPageToFragment();
    window.addEventListener('hashchange', scrollAppPageToFragment);

    document.addEventListener(
        'click',
        function (event) {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var link = event.target && event.target.closest ? event.target.closest('a[href^="#"]') : null;
            if (!link) {
                return;
            }

            var hrefRaw = (link.getAttribute('href') || '').trim();
            if (!hrefRaw || hrefRaw === '#') {
                return;
            }
            if (link.target === '_blank') {
                return;
            }

            var id;
            try {
                id = decodeURIComponent(hrefRaw.slice(1).replace(/\+/g, ' '));
            } catch (e) {
                return;
            }
            if (!id) {
                return;
            }

            var mainEl = document.querySelector('main.app-page');
            var targetEl = document.getElementById(id);
            if (!mainEl || !targetEl || !mainEl.contains(targetEl)) {
                return;
            }

            event.preventDefault();

            try {
                var nextUrl = window.location.pathname + window.location.search + hrefRaw;
                window.history.replaceState(null, '', nextUrl);
            } catch (e) {
                window.location.hash = hrefRaw;
            }

            queueScrollAppPageToFragment();
        },
        true
    );
});
