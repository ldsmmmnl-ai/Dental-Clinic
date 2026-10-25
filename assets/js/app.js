/* app.js — Global JS for every admin page.
   v2: script re-execution after PJAX, Chart.js cleanup, AbortController,
       no event-listener leaks, delegated confirm dialogs, debounced scroll. */

'use strict';

// ─── GLOBAL STATE ─────────────────────────────────────────────────────────
var _scrollHandler = null;
var _bttEl = null;
var _chartRegistry = {};

// ─── UTILITIES ────────────────────────────────────────────────────────────
function debounce(fn, ms) {
    var t;
    return function () { clearTimeout(t); t = setTimeout(fn, ms); };
}

function runScripts(container) {
    container.querySelectorAll('script').forEach(function (old) {
        var fresh = document.createElement('script');
        Array.from(old.attributes).forEach(function (a) { fresh.setAttribute(a.name, a.value); });
        fresh.textContent = old.textContent;
        old.parentNode.replaceChild(fresh, old);
    });
}

function destroyChartsIn(container) {
    container.querySelectorAll('canvas').forEach(function (canvas) {
        var id = canvas.id;
        if (id && _chartRegistry[id]) {
            try { _chartRegistry[id].destroy(); } catch (e) { }
            delete _chartRegistry[id];
        }
    });
    if (window.Chart && Chart.instances) {
        Object.values(Chart.instances).forEach(function (chart) {
            if (container.contains(chart.canvas)) {
                try { chart.destroy(); } catch (e) { }
            }
        });
    }
}

// ─── SKELETON LOADER ──────────────────────────────────────────────────────
// _buildSkeleton(url): returns shimmer HTML that mirrors the destination page
// layout so the user sees a meaningful placeholder while PJAX fetches content.
function _buildSkeleton(url) {
    var isPatients = url.indexOf('patients/list') !== -1;
    var isAppts = url.indexOf('appointments/list') !== -1;
    var isCalendar = url.indexOf('appointments/calendar') !== -1;
    var isDashboard = url.indexOf('dashboard.php') !== -1;
    var sk = 'skeleton skeleton-text';

    // ── Calendar grid skeleton ────────────────────────────────────────────────
    // Mirrors the .cal-grid layout: header row + 35 shimmer cells with ~1/3
    // showing an appointment-chip placeholder so it looks like a real month.
    if (isCalendar) {
        var calDows = '';
        var dows = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        for (var di = 0; di < 7; di++) { calDows += '<div class="cal-dow">' + dows[di] + '</div>'; }
        var calCells = '';
        for (var ci = 0; ci < 35; ci++) {
            if (ci < 2 || ci > 31) {
                calCells += '<div class="cal-cell empty"></div>';
            } else {
                calCells += '<div class="cal-cell">'
                    + '<div class="day-num"><span class="skeleton" style="width:22px;height:22px;border-radius:50%;display:inline-block;"></span></div>'
                    + (ci % 3 === 0 ? '<span class="' + sk + '" style="display:block;height:22px;border-radius:6px;margin:4px 0;"></span>' : '')
                    + '</div>';
            }
        }
        return '<div class="page-content">'
            + '<div class="page-header" style="margin-bottom:16px;">'
            + '<span class="' + sk + '" style="display:block;width:200px;height:1.4rem;"></span>'
            + '<span class="skeleton" style="display:block;width:130px;height:2rem;border-radius:8px;"></span>'
            + '</div>'
            + '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">'
            + '<span class="skeleton" style="width:36px;height:36px;border-radius:8px;display:block;"></span>'
            + '<span class="' + sk + '" style="width:160px;height:1.2rem;display:block;"></span>'
            + '<span class="skeleton" style="width:36px;height:36px;border-radius:8px;display:block;"></span>'
            + '</div>'
            + '<div class="calendar-wrap"><div class="cal-header-row">' + calDows + '</div>'
            + '<div class="cal-grid">' + calCells + '</div></div></div>';
    }

    // ── Dashboard KPI skeleton ────────────────────────────────────────────────
    // 4 shimmer cards matching the .dash-kpi layout: icon, label, value, trend badge.
    if (isDashboard) {
        var kpis = '';
        for (var ki = 0; ki < 4; ki++) {
            kpis += '<div class="dash-kpi" style="pointer-events:none;">'
                + '<div class="kpi-top">'
                + '<span class="' + sk + '" style="display:block;width:100px;height:10px;"></span>'
                + '<div class="skeleton" style="width:36px;height:36px;border-radius:8px;"></div>'
                + '</div>'
                + '<div class="' + sk + '" style="display:block;width:80px;height:2rem;margin:10px 0 6px;"></div>'
                + '<span class="skeleton" style="display:block;width:100px;height:16px;border-radius:20px;"></span>'
                + '</div>';
        }
        return '<div class="page-content">'
            + '<div class="page-header" style="margin-bottom:16px;">'
            + '<span class="' + sk + '" style="display:block;width:200px;height:1.4rem;"></span>'
            + '</div>'
            + '<div class="dash-kpi-grid">' + kpis + '</div></div>';
    }

    // ── Table skeleton (patients list, appointments list, generic fallback) ────
    // Column widths mirror each page's actual <thead>
    var cols = isPatients
        ? ['70px', '28%', '8%', '10%', '16%', '7%', '12%', '68px']   // Code Name Age Gender Phone Visits Registered Actions
        : isAppts
            ? ['70px', '20%', '16%', '13%', '16%', '10%', '96px']        // Code Patient Service Doctor Date Status Actions
            : ['70px', '28%', '20%', '15%', '14%', '68px'];             // generic fallback

    // Shimmer thead
    var thead = '';
    for (var c = 0; c < cols.length; c++) {
        thead += '<th style="padding:10px 16px;">'
            + '<span class="' + sk + '" style="display:block;height:10px;width:' + cols[c] + ';"></span>'
            + '</th>';
    }

    // Shimmer tbody rows (8 rows)
    var rows = '';
    for (var r = 0; r < 8; r++) {
        rows += '<tr style="border-bottom:1px solid var(--gray-100);">';
        for (var c2 = 0; c2 < cols.length; c2++) {
            rows += '<td style="padding:13px 16px;">'
                + '<span class="' + sk + '" style="display:block;height:14px;width:' + cols[c2] + ';"></span>'
                + '</td>';
        }
        rows += '</tr>';
    }

    // Stats bar — patients page only (4 cards matching .stats-bar layout)
    var statsBar = '';
    if (isPatients) {
        var cards = '';
        for (var s = 0; s < 4; s++) {
            cards += '<div class="stat-card">'
                + '<div class="skeleton" style="width:42px;height:42px;border-radius:10px;flex-shrink:0;"></div>'
                + '<div style="flex:1;min-width:0;">'
                + '<span class="' + sk + '" style="display:block;width:65%;height:10px;margin-bottom:8px;"></span>'
                + '<span class="' + sk + '" style="display:block;width:42%;height:1.25rem;"></span>'
                + '</div></div>';
        }
        statsBar = '<div class="stats-bar" style="margin-bottom:18px;">' + cards + '</div>';
    }

    // Filter / search bar shimmer
    var filterRow = '<div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">'
        + '<span class="skeleton" style="width:240px;height:34px;border-radius:8px;display:block;"></span>'
        + '<span class="skeleton" style="width:88px;height:32px;border-radius:20px;display:block;"></span>'
        + '<span class="skeleton" style="width:88px;height:32px;border-radius:20px;display:block;"></span>'
        + '<span class="skeleton" style="width:88px;height:32px;border-radius:20px;display:block;"></span>'
        + '</div>';

    // Table wrapper matches each page's outer container
    var tableWrap = '<div style="background:var(--white);border-radius:14px;border:var(--border);'
        + 'overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06);">'
        + '<table style="width:100%;border-collapse:collapse;">'
        + '<thead><tr style="border-bottom:2px solid var(--gray-100);">' + thead + '</tr></thead>'
        + '<tbody>' + rows + '</tbody>'
        + '</table></div>';

    return '<div class="page-content">'
        + '<div class="page-header" style="margin-bottom:16px;">'
        + '<span class="' + sk + '" style="display:block;width:210px;height:1.4rem;"></span>'
        + '<span class="skeleton" style="display:block;width:130px;height:2rem;border-radius:8px;"></span>'
        + '</div>'
        + statsBar + filterRow + tableWrap
        + '</div>';
}

// showPageSkeleton(url): disposes all Bootstrap components on the outgoing page,
// then replaces .main-content with the shimmer placeholder for the destination.
// Called at PJAX start so the user never stares at stale content during the fetch.
function showPageSkeleton(url) {
    var main = document.querySelector('.main-content');
    if (!main) return;

    // Tear down charts and Bootstrap components before wiping the DOM
    destroyChartsIn(main);
    if (window.bootstrap) {
        if (bootstrap.Modal) {
            main.querySelectorAll('.modal').forEach(function (el) {
                var i = bootstrap.Modal.getInstance(el);
                if (i) try { i.dispose(); } catch (e) { }
            });
        }
        if (bootstrap.Tooltip) {
            main.querySelectorAll('[data-bs-original-title]').forEach(function (el) {
                var i = bootstrap.Tooltip.getInstance(el);
                if (i) try { i.dispose(); } catch (e) { }
            });
        }
    }
    document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');

    // Keep #topbar so the header doesn't vanish and pop back in
    // (also keep the header's <style> so the notification panel stays hidden)
    var keep = Array.prototype.filter.call(main.children, function (el) {
        return el.id === 'topbar' || el.tagName === 'STYLE';
    });
    main.innerHTML = '';
    keep.forEach(function (el) { main.appendChild(el); });
    main.insertAdjacentHTML('beforeend', _buildSkeleton(url));
}

// ─── PAGE INIT ────────────────────────────────────────────────────────────
// Flag set by PJAX loader to suppress card entry animations on navigation
var _isPjaxNav = false;

function initPage() {

    // 0a. SKELETON REVEAL — remove shimmer rows, show real PHP-rendered rows.
    // patients/list.php and appointments/list.php add .page-skeleton-body (shimmer)
    // and .page-data-body (real rows, initially invisible) so initPage() controls
    // the reveal instead of a setTimeout or a perceived-loading hack.
    document.querySelectorAll('.page-skeleton-body').forEach(function (el) { el.remove(); });
    document.querySelectorAll('.page-data-body').forEach(function (el) { el.style.visibility = ''; });

    // 0. PJAX CARD ANIMATION SUPPRESSION
    // On PJAX navigations, add no-anim to all cards so they don't animate in
    if (_isPjaxNav) {
        document.querySelectorAll('.card').forEach(function (c) { c.classList.add('no-anim'); });
        _isPjaxNav = false;
    }

    // 0b. TABLE SCROLL FADE — only show gradient when table actually overflows
    document.querySelectorAll('.table-responsive').forEach(function (wrap) {
        wrap.classList.toggle('is-scrollable', wrap.scrollWidth > wrap.clientWidth);
    });

    // 1. AUTO-DISMISS ALERTS
    document.querySelectorAll('.alert:not(.alert-permanent):not([data-timed])').forEach(function (el) {
        el.setAttribute('data-timed', '1');
        setTimeout(function () {
            el.style.transition = 'opacity 0.4s ease';
            el.style.opacity = '0';
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 420);
        }, 5000);
    });

    // 2. CONFIRM DIALOGS — delegated, set once only
    if (!document._confirmDelegated) {
        document._confirmDelegated = true;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-confirm]');
            if (btn && !confirm(btn.dataset.confirm)) e.preventDefault();
        });
    }

    // 3. ROW COUNT BADGES
    document.querySelectorAll('.card').forEach(function (card) {
        if (card.querySelector('.row-count-badge')) return;
        var tbody = card.querySelector('tbody');
        if (!tbody) return;
        var count = Array.from(tbody.querySelectorAll('tr'))
            .filter(function (tr) { return !tr.querySelector('td[colspan]'); }).length;
        var header = card.querySelector('.card-header');
        if (header) {
            var badge = document.createElement('span');
            badge.className = 'row-count-badge';
            badge.textContent = count + ' result' + (count !== 1 ? 's' : '');
            header.appendChild(badge);
        }
    });

    // 4. AUTO-SUBMIT FILTER SELECTS
    document.querySelectorAll('form[method="GET"] select:not([data-no-auto])').forEach(function (sel) {
        if (sel._autoSubmit) return;
        sel._autoSubmit = true;
        sel.addEventListener('change', function () { this.closest('form').submit(); });
    });

    // 5. BACK-TO-TOP
    if (!_bttEl) {
        _bttEl = document.createElement('button');
        _bttEl.id = 'back-to-top';
        _bttEl.innerHTML = '<i class="bi bi-arrow-up"></i>';
        _bttEl.title = 'Back to top';
        _bttEl.setAttribute('aria-label', 'Back to top');
        document.body.appendChild(_bttEl);
        _bttEl.addEventListener('click', function () {
            var el = document.querySelector('.main-content');
            (el || window).scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
    var scrollEl = document.querySelector('.main-content') || window;
    if (_scrollHandler) {
        scrollEl.removeEventListener('scroll', _scrollHandler);
        window.removeEventListener('scroll', _scrollHandler);
    }
    _scrollHandler = debounce(function () {
        var top = (scrollEl === window) ? window.scrollY : scrollEl.scrollTop;
        _bttEl.classList.toggle('btt-visible', top > 300);
    }, 50);
    scrollEl.addEventListener('scroll', _scrollHandler, { passive: true });

    // 6. SEARCH HINT
    var searchEl = document.querySelector('input[name="search"], input[type="search"]');
    if (searchEl && !searchEl.dataset.hintAdded) {
        searchEl.dataset.hintAdded = '1';
        var hint = document.createElement('span');
        hint.className = 'kbd-hint';
        hint.innerHTML = 'Press <kbd>/</kbd> to search';
        var par = searchEl.closest('.input-group') || searchEl.parentNode;
        if (par && par.parentNode) par.parentNode.insertBefore(hint, par.nextSibling);
    }

    // 7. IMPROVED EMPTY STATES
    var emptyMap = {
        'no patients': { icon: 'bi-people', msg: 'No patients yet', hint: 'Add your first patient to get started.' },
        'no appointments': { icon: 'bi-calendar-x', msg: 'No appointments found', hint: 'Try adjusting your filters.' },
        'no bills': { icon: 'bi-receipt', msg: 'No bills found', hint: 'Bills will appear here once created.' },
        'no dental records': { icon: 'bi-journal-medical', msg: 'No dental records', hint: 'No treatment records have been added yet.' },
        'no logs': { icon: 'bi-shield-check', msg: 'No activity yet', hint: 'Audit logs will appear here as actions are taken.' },
        'no users': { icon: 'bi-person-x', msg: 'No users found', hint: 'No users match your search.' },
        'no services': { icon: 'bi-list-ul', msg: 'No services found', hint: 'No services have been added yet.' },
        'no doctors': { icon: 'bi-person-badge', msg: 'No doctors found', hint: 'No doctors have been added yet.' },
        'no results': { icon: 'bi-search', msg: 'No results found', hint: 'Try a different search term or clear the filter.' }
    };
    document.querySelectorAll('td[colspan]:not([data-empty-done])').forEach(function (td) {
        td.setAttribute('data-empty-done', '1');
        var raw = td.textContent.trim().toLowerCase();
        var cfg = null;
        Object.keys(emptyMap).forEach(function (k) { if (raw.indexOf(k) !== -1) cfg = emptyMap[k]; });
        if (!cfg) return;
        td.style.cssText = 'text-align:center;padding:48px 24px;';
        td.innerHTML =
            '<div style="display:inline-flex;flex-direction:column;align-items:center;gap:10px;max-width:320px;">' +
            '<div style="width:52px;height:52px;border-radius:14px;background:var(--gray-100);' +
            'display:flex;align-items:center;justify-content:center;">' +
            '<i class="bi ' + cfg.icon + '" style="font-size:1.5rem;color:var(--gray-400);"></i>' +
            '</div>' +
            '<div>' +
            '<div style="font-weight:600;font-size:0.9rem;color:var(--gray-700);margin-bottom:4px;">' + cfg.msg + '</div>' +
            '<div style="font-size:0.8rem;color:var(--gray-400);line-height:1.5;">' + cfg.hint + '</div>' +
            '</div>' +
            '</div>';
    });

    // 8. BREADCRUMB
    var titleBlock = document.querySelector('.topbar-title-block');
    if (titleBlock && !titleBlock.querySelector('#breadcrumb')) {
        var path = window.location.pathname;
        var crumbs = ['Home'];
        if (path.indexOf('/modules/') !== -1) {
            var parts = path.split('/');
            var modIdx = parts.indexOf('modules');
            if (modIdx !== -1 && parts[modIdx + 1]) {
                var sec = parts[modIdx + 1];
                crumbs.push(sec.charAt(0).toUpperCase() + sec.slice(1));
            }
        }
        var ptEl = document.querySelector('.page-title');
        var pt = ptEl ? ptEl.textContent.trim() : '';
        if (pt && crumbs[crumbs.length - 1].toLowerCase() !== pt.toLowerCase()) crumbs.push(pt);
        if (crumbs.length > 1) {
            var bc = document.createElement('div');
            bc.id = 'breadcrumb';
            bc.innerHTML = crumbs.map(function (c, i) {
                return i === crumbs.length - 1
                    ? '<span class="bc-current">' + c + '</span>'
                    : '<span class="bc-item">' + c + '</span>';
            }).join('<span class="bc-sep"><i class="bi bi-chevron-right"></i></span>');
            titleBlock.appendChild(bc);
        }
    }

    // 9. KPI / STAT COUNT-UP
    document.querySelectorAll('.kpi-value, .an-kpi-value, .bill-kpi-value, .stat-value').forEach(animateCountUp);
}

// ─── PJAX NAVIGATION ──────────────────────────────────────────────────────
(function () {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    var _ctrl = null; // AbortController

    // ── Top progress bar (thin stripe at top) ──
    var bar = document.createElement('div');
    bar.id = 'pjax-bar';
    bar.style.cssText =
        'position:fixed;top:0;left:0;height:3px;width:0;' +
        'background:linear-gradient(90deg,#0d6e6e,#2dd4bf);' +
        'z-index:99999;transition:width 0.25s ease,opacity 0.3s ease;pointer-events:none;opacity:0;';
    document.body.appendChild(bar);

    // ── Page loader overlay (dots) ──
    var _loaderEl = (function () {
        var overlay = document.createElement('div');
        overlay.id = 'pjax-loader';
        overlay.innerHTML =
            '<div class="pjax-dots">' +
            '<span></span><span></span><span></span>' +
            '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }());

    function barStart() {
        bar.style.opacity = '1';
        bar.style.width = '60%';
        // Show dots immediately on every navigation
        _loaderEl.classList.add('pjax-loader-visible');
    }

    function barDone() {
        _loaderEl.classList.remove('pjax-loader-visible');
        bar.style.width = '100%';
        setTimeout(function () {
            bar.style.opacity = '0';
            setTimeout(function () { bar.style.width = '0'; }, 300);
        }, 180);
    }

    function updateActive(url) {
        var uPath = url.split('?')[0];
        sidebar.querySelectorAll('a.nav-link').forEach(function (a) {
            a.classList.toggle('active', a.href.split('?')[0] === uPath);
        });
    }

    function pjaxLoad(url, push) {
        if (_ctrl) { try { _ctrl.abort(); } catch (e) { } }
        _ctrl = new AbortController();
        barStart();
        showPageSkeleton(url); // immediately replace outgoing content with shimmer

        fetch(url, { signal: _ctrl.signal, credentials: 'same-origin', headers: { 'X-Requested-With': 'pjax' } })
            .then(function (res) {
                if (res.redirected && res.url.indexOf('index.php') !== -1) {
                    window.location.href = res.url; return null;
                }
                return res.text();
            })
            .then(function (html) {
                if (!html) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newMain = doc.querySelector('.main-content');
                var curMain = document.querySelector('.main-content');
                if (!newMain || !curMain) { window.location.href = url; return; }

                // Destroy charts before removing their canvases
                destroyChartsIn(curMain);

                // Dispose all Bootstrap modal instances before wiping the DOM.
                // Without this, Bootstrap's internal WeakMap keeps stale references
                // to the old elements, causing getOrCreateInstance() to behave
                // unpredictably on the new page and leaving orphaned backdrops.
                if (window.bootstrap && window.bootstrap.Modal) {
                    curMain.querySelectorAll('.modal').forEach(function (el) {
                        var inst = bootstrap.Modal.getInstance(el);
                        if (inst) { try { inst.dispose(); } catch (e) { } }
                    });
                }
                // Clean up any leftover backdrop / body state from open modals
                document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');

                // Swap in the destination page's own <head> <style> blocks.
                // (They live in <head>, outside .main-content, so PJAX used to drop them.)
                document.querySelectorAll('style[data-pjax-page]').forEach(function (el) { el.remove(); });
                doc.head.querySelectorAll('style').forEach(function (st) {
                    var ns = document.createElement('style');
                    ns.setAttribute('data-pjax-page', '1');
                    ns.textContent = st.textContent;
                    document.head.appendChild(ns);
                });

                curMain.innerHTML = newMain.innerHTML;
                if (doc.title) document.title = doc.title;
                if (push !== false) history.pushState({ pjax: true, url: url }, doc.title || '', url);

                curMain.scrollTo({ top: 0 });
                window.scrollTo({ top: 0 });

                var oldBc = document.getElementById('breadcrumb');
                if (oldBc) oldBc.remove();

                // THIS is the key fix — re-run inline <script> tags so Chart.js works
                try {
                    runScripts(curMain);
                    _isPjaxNav = true;  // suppress card animations on PJAX navigations
                    initPage();
                    updateActive(url);
                } catch (e) {
                    console.error('[pjax] page-init error:', e);
                } finally {
                    _isPjaxNav = false;
                    barDone(); // always fires — prevents the loader overlay from locking the UI
                }
            })
            .catch(function (err) {
                if (err.name === 'AbortError') return;
                bar.style.opacity = '0'; bar.style.width = '0';
                window.location.href = url;
            });
    }

    sidebar.addEventListener('click', function (e) {
        var link = e.target.closest('a.nav-link');
        if (!link) return;
        var href = link.getAttribute('href');
        if (!href || href === '#' || href.startsWith('javascript')) return;
        if (href.indexOf('index.php') !== -1 || link.target === '_blank') return;
        if (e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();
        var url = link.href;
        if (url.split('?')[0] === window.location.href.split('?')[0]) return;
        pjaxLoad(url, true);
    });

    window.addEventListener('popstate', function (e) {
        if (e.state && e.state.pjax) pjaxLoad(e.state.url, false);
        else window.location.reload();
    });

    history.replaceState({ pjax: true, url: window.location.href }, document.title, window.location.href);
})();

// ─── ONE-TIME SETUP ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    // ── CLOSE NOTIFICATION PANEL WHEN ANY MODAL OPENS ────────────────────
    document.addEventListener('show.bs.modal', function () {
        var panel = document.getElementById('notifPanel');
        if (panel) panel.classList.remove('open');
    });

    // ── SMOOTH ALERT DISMISS ──────────────────────────────────────────────
    // Bootstrap fires 'close.bs.alert' before the element is removed.
    // We add the .dismissing class which triggers a CSS collapse animation,
    // then let Bootstrap finish removing it after the transition.
    document.addEventListener('close.bs.alert', function (e) {
        var alert = e.target;
        if (!alert) return;
        e.preventDefault(); // Stop Bootstrap from removing immediately
        alert.classList.add('dismissing');
        // After transition completes, let Bootstrap do the actual removal
        setTimeout(function () {
            alert.classList.remove('dismissing'); // restore so Bootstrap can hide
            var bsAlert = window.bootstrap && bootstrap.Alert ? bootstrap.Alert.getInstance(alert) : null;
            if (bsAlert) {
                bsAlert.dispose();
            }
            alert.style.display = 'none';
            alert.remove();
        }, 300); // matches longest transition (max-height 0.28s)
    });

    document.addEventListener('keydown', function (e) {
        var tag = document.activeElement ? document.activeElement.tagName : '';
        var inInput = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
            || !!(document.activeElement && document.activeElement.isContentEditable);

        if (e.key === '/' && !inInput && !e.ctrlKey && !e.metaKey) {
            var s = document.querySelector('input[name="search"],input[type="search"],input[placeholder*="earch"]');
            if (s) { e.preventDefault(); s.focus(); s.select(); }
        }
        if ((e.key === 'n' || e.key === 'N') && !inInput && !e.ctrlKey && !e.metaKey) {
            var btn = document.querySelector('a.btn-primary[href*="add"],a.btn-primary[href*="create"],a.btn-primary[href*="book"]');
            if (btn) { e.preventDefault(); btn.click(); }
        }
        if (e.key === 'Escape') {
            var modal = document.querySelector('.modal.show');
            if (modal && window.bootstrap && bootstrap.Modal.getInstance(modal)) {
                bootstrap.Modal.getInstance(modal).hide();
            }
        }
    });

    initPage();
});

// ─── KPI / STAT VALUE COUNT-UP ─────────────────────────────────────────────
// Animates .kpi-value / .an-kpi-value / .bill-kpi-value / .stat-value from 0
// up to their server-rendered number, on both full page loads and PJAX swaps.
// Currency symbols, thousands separators, and any other formatting already
// present in the markup (₱, commas, "sm" suffix classes, etc.) are preserved.
function animateCountUp(el) {
    var raw = el.textContent.trim();
    var match = raw.match(/^(\D*)([\d,]*\.?\d+)(\D*)$/);
    if (!match) return; // no parseable number in this element — leave as-is

    var prefix = match[1], numStr = match[2], suffix = match[3];
    var target = parseFloat(numStr.replace(/,/g, ''));
    if (isNaN(target)) return;

    var decimals = (numStr.split('.')[1] || '').length;
    var finalText = prefix + numStr + suffix;

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion || target === 0) { el.textContent = finalText; return; }

    if (el._countRAF) cancelAnimationFrame(el._countRAF);

    var duration = 900;
    var start = null;

    function frame(ts) {
        if (!start) start = ts;
        var progress = Math.min((ts - start) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
        var current = target * eased;
        el.textContent = prefix + current.toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }) + suffix;
        if (progress < 1) {
            el._countRAF = requestAnimationFrame(frame);
        } else {
            el.textContent = finalText; // snap to the exact original text
        }
    }
    el._countRAF = requestAnimationFrame(frame);
}
