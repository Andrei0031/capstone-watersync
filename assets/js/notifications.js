/**
 * WaterSync Notification System
 * Replaces all alert() calls with modern, animated Bootstrap-based toast notifications
 */

/**
 * Inject minimal CSS for stacked, animated notifications (once per page)
 */
function ensureNotificationStyles() {
    if (document.getElementById('ws-notification-styles')) return;

    const style = document.createElement('style');
    style.id = 'ws-notification-styles';
    style.innerHTML = `
        .ws-notification-container {
            position: fixed;
            z-index: 9999;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            pointer-events: none;
        }
        .ws-top-right { top: 0; right: 0; }
        .ws-top-left { top: 0; left: 0; }
        .ws-bottom-right { bottom: 0; right: 0; }
        .ws-bottom-left { bottom: 0; left: 0; }
        .ws-top-center {
            top: 0;
            left: 50%;
            transform: translateX(-50%);
        }

        .ws-notification {
            pointer-events: auto;
            border-radius: 0.75rem;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            align-items: flex-start;
            padding: 0.75rem 1rem;
            min-width: 280px;
            max-width: 420px;
            animation: ws-slide-in 0.25s ease-out;
        }

        .ws-notification .ws-icon {
            font-size: 1.4rem;
            margin-right: 0.75rem;
            margin-top: 0.15rem;
        }

        .ws-notification .ws-content {
            flex: 1;
        }

        .ws-notification .ws-title {
            font-weight: 600;
            margin-bottom: 0.15rem;
            font-size: 0.95rem;
        }

        .ws-notification .ws-message {
            margin: 0;
            font-size: 0.85rem;
        }

        .ws-notification .btn-close {
            margin-left: 0.75rem;
            margin-top: 0.1rem;
        }

        @keyframes ws-slide-in {
            from {
                opacity: 0;
                transform: translateX(8px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes ws-slide-out {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(8px);
            }
        }
    `;

    document.head.appendChild(style);
}

/**
 * Get or create a container element for the given position
 */
function getNotificationContainer(positionKey) {
    const existing = document.querySelector(`.ws-notification-container[data-position="${positionKey}"]`);
    if (existing) return existing;

    const container = document.createElement('div');
    container.className = `ws-notification-container ws-${positionKey}`;
    container.dataset.position = positionKey;
    document.body.appendChild(container);
    return container;
}

/**
 * Show a notification message box
 * @param {string} message - The message to display
 * @param {string} type - Type of notification: 'success', 'error', 'warning', 'info' (default: 'info')
 * @param {number} duration - Auto-close duration in milliseconds (default: 5000, 0 = no auto-close)
 * @param {string} position - Position: 'top-right', 'top-left', 'bottom-right', 'bottom-left', 'top-center' (default: 'top-right')
 */
function showNotification(message, type = 'info', duration = 5000, position = 'top-right') {
    ensureNotificationStyles();

    // Map type to Bootstrap alert class
    const alertTypes = {
        'success': 'success',
        'error': 'danger',
        'danger': 'danger',
        'warning': 'warning',
        'info': 'info'
    };
    
    const alertClass = alertTypes[type] || 'info';
    
    // Map position key to container class suffix
    const positionKeys = {
        'top-right': 'top-right',
        'top-left': 'top-left',
        'bottom-right': 'bottom-right',
        'bottom-left': 'bottom-left',
        'top-center': 'top-center'
    };
    
    const positionKey = positionKeys[position] || 'top-right';
    
    // Determine icon based on type
    const icons = {
        'success': 'fa-check-circle',
        'error': 'fa-exclamation-circle',
        'danger': 'fa-exclamation-circle',
        'warning': 'fa-exclamation-triangle',
        'info': 'fa-info-circle'
    };
    
    const icon = icons[type] || 'fa-info-circle';
    
    // Determine title based on type
    const titles = {
        'success': 'Success',
        'error': 'Error',
        'danger': 'Error',
        'warning': 'Warning',
        'info': 'Notice'
    };
    
    const title = titles[type] || 'Notice';
    
    // Create notification element
    const notificationId = 'notification-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const alertDiv = document.createElement('div');
    alertDiv.id = notificationId;
    alertDiv.className = `alert alert-${alertClass} alert-dismissible fade show ws-notification`;
    alertDiv.setAttribute('role', 'alert');

    alertDiv.innerHTML = `
        <i class="fas ${icon} ws-icon"></i>
        <div class="ws-content">
            <div class="ws-title">${title}</div>
            <p class="ws-message">${message}</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;

    // Close handler (with slide-out animation)
    const closeNotification = () => {
        if (!alertDiv) return;
        alertDiv.style.animation = 'ws-slide-out 0.2s ease-in forwards';
        setTimeout(() => {
            alertDiv.remove();
        }, 200);
    };

    const closeButton = alertDiv.querySelector('.btn-close');
    if (closeButton) {
        closeButton.addEventListener('click', (e) => {
            e.preventDefault();
            closeNotification();
        });
    }
    
    // Add to appropriate container (enables stacking)
    const container = getNotificationContainer(positionKey);
    // Newest on top
    container.insertBefore(alertDiv, container.firstChild);
    
    // Auto-remove after duration
    if (duration > 0) {
        setTimeout(() => {
            if (document.getElementById(notificationId)) {
                closeNotification();
            }
        }, duration);
    }
    
    return notificationId;
}

/**
 * Show success notification
 */
function showSuccess(message, duration = 5000) {
    return showNotification(message, 'success', duration);
}

/**
 * Show error notification
 */
function showError(message, duration = 5000) {
    return showNotification(message, 'error', duration);
}

/**
 * Show warning notification
 */
function showWarning(message, duration = 5000) {
    return showNotification(message, 'warning', duration);
}

/**
 * Show info notification
 */
function showInfo(message, duration = 5000) {
    return showNotification(message, 'info', duration);
}

/**
 * Show confirmation dialog (replaces confirm())
 * @param {string} message - The confirmation message
 * @param {function} onConfirm - Callback function when confirmed
 * @param {function} onCancel - Optional callback function when cancelled
 */
function showConfirm(message, onConfirm, onCancel = null) {
    // Create modal for confirmation
    const modalId = 'confirmModal-' + Date.now();
    const modalHtml = `
        <div class="modal fade" id="${modalId}" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">
                            <i class="fas fa-exclamation-triangle me-2"></i>Confirm Action
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">${message}</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="${modalId}-cancel">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-warning" id="${modalId}-confirm">
                            <i class="fas fa-check me-2"></i>Confirm
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove any existing confirm modals
    const existingModals = document.querySelectorAll('[id^="confirmModal-"]');
    existingModals.forEach(modal => modal.remove());
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Show modal
    const modalElement = document.getElementById(modalId);
    const modal = new bootstrap.Modal(modalElement, {
        backdrop: 'static',
        keyboard: false
    });
    modal.show();
    
    // Handle confirm button
    document.getElementById(`${modalId}-confirm`).addEventListener('click', function() {
        modal.hide();
        setTimeout(function() {
            modalElement.remove();
            if (onConfirm) onConfirm();
        }, 300);
    });
    
    // Handle cancel button
    document.getElementById(`${modalId}-cancel`).addEventListener('click', function() {
        modal.hide();
        setTimeout(function() {
            modalElement.remove();
            if (onCancel) onCancel();
        }, 300);
    });
    
    // Handle close button
    modalElement.addEventListener('hidden.bs.modal', function() {
        setTimeout(function() {
            modalElement.remove();
            if (onCancel) onCancel();
        }, 100);
    }, { once: true });
}

// Make functions globally available
window.showNotification = showNotification;
window.showSuccess = showSuccess;
window.showError = showError;
window.showWarning = showWarning;
window.showInfo = showInfo;
window.showConfirm = showConfirm;

/**
 * Convert existing inline bootstrap alerts to toast-style notifications.
 * This helps standardize system messages across pages.
 */
function convertInlineAlertsToToasts() {
    const alerts = document.querySelectorAll('.alert:not(.ws-notification)');
    alerts.forEach((alertEl) => {
        const msg = (alertEl.textContent || '').replace(/\s+/g, ' ').trim();
        if (!msg) return;

        let type = 'info';
        if (alertEl.classList.contains('alert-success')) type = 'success';
        else if (alertEl.classList.contains('alert-danger')) type = 'error';
        else if (alertEl.classList.contains('alert-warning')) type = 'warning';
        else if (alertEl.classList.contains('alert-info')) type = 'info';

        showNotification(msg, type, 6000, 'top-right');
        alertEl.remove();
    });
}

/**
 * Admin realtime notification center (floating bell + unread badge + list).
 * Replaces popup spam with a persistent lower-right notification inbox.
 */
function initAdminNotificationCenter() {
    const currentPath = (window.location.pathname || '').toLowerCase();
    const adminPages = [
        'adminlandingpage.php',
        'billing_list.php',
        'view_clients.php',
        'pending_readings.php',
        'payments.php',
        'client_reports.php',
        'reports.php',
        'settings_rate.php',
        'customer_accounts.php'
    ];
    const isAdminPage = adminPages.some((p) => currentPath.includes('/' + p) || currentPath.endsWith(p));
    if (!isAdminPage) return;

    // Inject styles for notification center (once)
    if (!document.getElementById('ws-admin-notification-center-styles')) {
        const style = document.createElement('style');
        style.id = 'ws-admin-notification-center-styles';
        style.textContent = `
            .ws-admin-notif-fab {
                position: fixed;
                right: 20px;
                bottom: 20px;
                width: 56px;
                height: 56px;
                border: none;
                border-radius: 50%;
                background: linear-gradient(135deg, #2563eb, #1d4ed8);
                color: #fff;
                box-shadow: 0 10px 25px rgba(37, 99, 235, 0.35);
                z-index: 10000;
                cursor: pointer;
            }
            .ws-admin-notif-fab i {
                font-size: 1.15rem;
            }
            .ws-admin-notif-badge {
                position: absolute;
                top: -5px;
                right: -5px;
                min-width: 22px;
                height: 22px;
                border-radius: 999px;
                background: #ef4444;
                color: #fff;
                font-size: 0.72rem;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0 6px;
                border: 2px solid #fff;
            }
            .ws-admin-notif-panel {
                position: fixed;
                right: 20px;
                bottom: 86px;
                width: 360px;
                max-width: calc(100vw - 30px);
                max-height: 460px;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                box-shadow: 0 18px 40px rgba(15, 23, 42, 0.25);
                z-index: 10000;
                overflow: hidden;
                display: none;
            }
            .ws-admin-notif-panel.open {
                display: block;
            }
            .ws-admin-notif-header {
                padding: 12px 14px;
                border-bottom: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
                justify-content: space-between;
                font-weight: 600;
            }
            .ws-admin-notif-clear {
                border: none;
                background: transparent;
                color: #2563eb;
                font-size: 0.82rem;
                cursor: pointer;
            }
            .ws-admin-notif-list {
                max-height: 400px;
                overflow-y: auto;
            }
            .ws-admin-notif-item {
                border-bottom: 1px solid #f1f5f9;
                padding: 12px 14px;
                cursor: pointer;
                transition: background-color 0.2s ease;
            }
            .ws-admin-notif-item:hover {
                background: #f8fafc;
            }
            .ws-admin-notif-title {
                font-size: 0.88rem;
                font-weight: 600;
                color: #111827;
                margin-bottom: 3px;
            }
            .ws-admin-notif-desc {
                font-size: 0.8rem;
                color: #4b5563;
                margin-bottom: 4px;
            }
            .ws-admin-notif-time {
                font-size: 0.74rem;
                color: #6b7280;
            }
            .ws-admin-notif-empty {
                padding: 20px 14px;
                font-size: 0.85rem;
                color: #6b7280;
                text-align: center;
            }
        `;
        document.head.appendChild(style);
    }

    const knownIdsKey = 'ws_admin_reports_seen_ids';
    const unreadKey = 'ws_admin_notification_center_unread';

    let knownIds = [];
    try {
        knownIds = JSON.parse(localStorage.getItem(knownIdsKey) || '[]');
    } catch (e) {
        knownIds = [];
    }
    const knownSet = new Set(knownIds);

    let unread = [];
    try {
        unread = JSON.parse(localStorage.getItem(unreadKey) || '[]');
    } catch (e) {
        unread = [];
    }

    const fab = document.createElement('button');
    fab.className = 'ws-admin-notif-fab';
    fab.type = 'button';
    fab.setAttribute('aria-label', 'Open notifications');
    fab.innerHTML = '<i class="fas fa-bell"></i><span class="ws-admin-notif-badge" style="display:none">0</span>';

    const panel = document.createElement('div');
    panel.className = 'ws-admin-notif-panel';
    panel.innerHTML = `
        <div class="ws-admin-notif-header">
            <span>Notifications</span>
            <button type="button" class="ws-admin-notif-clear">Mark all as read</button>
        </div>
        <div class="ws-admin-notif-list"></div>
    `;
    document.body.appendChild(fab);
    document.body.appendChild(panel);

    const badge = fab.querySelector('.ws-admin-notif-badge');
    const listEl = panel.querySelector('.ws-admin-notif-list');
    const clearBtn = panel.querySelector('.ws-admin-notif-clear');

    const saveSeenState = () => {
        const compact = Array.from(knownSet).slice(-200);
        localStorage.setItem(knownIdsKey, JSON.stringify(compact));
    };

    const saveUnreadState = () => {
        unread = unread.slice(0, 80);
        localStorage.setItem(unreadKey, JSON.stringify(unread));
    };

    const renderUnread = () => {
        const count = unread.length;
        if (badge) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.style.display = count > 0 ? 'inline-flex' : 'none';
        }

        if (!listEl) return;
        listEl.innerHTML = '';
        if (count === 0) {
            const empty = document.createElement('div');
            empty.className = 'ws-admin-notif-empty';
            empty.textContent = 'No new notifications';
            listEl.appendChild(empty);
            return;
        }

        unread.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'ws-admin-notif-item';
            row.dataset.uid = item.uid;
            row.innerHTML = `
                <div class="ws-admin-notif-title">${item.title}</div>
                <div class="ws-admin-notif-desc">${item.description}</div>
                <div class="ws-admin-notif-time">${item.timeText || ''}</div>
            `;
            row.addEventListener('click', () => {
                unread = unread.filter((x) => x.uid !== item.uid);
                saveUnreadState();
                renderUnread();
                window.location.href = item.link || 'client_reports.php';
            });
            listEl.appendChild(row);
        });
    };

    fab.addEventListener('click', () => {
        panel.classList.toggle('open');
    });

    document.addEventListener('click', (e) => {
        if (!panel.classList.contains('open')) return;
        if (panel.contains(e.target) || fab.contains(e.target)) return;
        panel.classList.remove('open');
    });

    clearBtn.addEventListener('click', () => {
        unread = [];
        saveUnreadState();
        renderUnread();
    });

    renderUnread();

    const poll = async (initial = false) => {
        try {
            // Always fetch the latest pending reports and dedupe by uid/id to avoid time precision misses.
            const resp = await fetch('check_new_reports.php?limit=20', {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            if (!resp.ok) return;
            const data = await resp.json();
            if (!data || !data.success || !Array.isArray(data.reports)) return;

            if (initial) {
                data.reports.forEach((r) => knownSet.add(String(r.uid || r.id)));
                saveSeenState();
                return;
            }

            const newReports = data.reports.filter((r) => !knownSet.has(String(r.uid || r.id)));
            if (newReports.length > 0) {
                newReports.forEach((r) => {
                    const customer = r.customer_name || 'Unknown customer';
                    const label = r.source === 'client_reports'
                        ? (r.report_type || 'Client report')
                        : (r.location || 'No location');

                    const notif = {
                        uid: String(r.uid || r.id),
                        title: 'New Client Report',
                        description: `${customer} - ${label}`,
                        timeText: r.report_date || '',
                        link: 'client_reports.php'
                    };
                    // Newest first, avoid duplicates
                    unread = unread.filter((x) => x.uid !== notif.uid);
                    unread.unshift(notif);
                    knownSet.add(String(r.uid || r.id));
                });
                saveUnreadState();
                renderUnread();
                saveSeenState();
            }
        } catch (e) {
            // Silent failure: polling should never break page interactions.
        }
    };

    // Prime cache without popups, then poll periodically.
    poll(true);
    setInterval(() => poll(false), 15000);
}

document.addEventListener('DOMContentLoaded', () => {
    convertInlineAlertsToToasts();
    initAdminNotificationCenter();
});

