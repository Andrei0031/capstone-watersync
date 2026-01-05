/**
 * WaterSync Notification System
 * Replaces all alert() calls with proper Bootstrap alert notifications
 */

/**
 * Show a notification message box
 * @param {string} message - The message to display
 * @param {string} type - Type of notification: 'success', 'error', 'warning', 'info' (default: 'info')
 * @param {number} duration - Auto-close duration in milliseconds (default: 5000, 0 = no auto-close)
 * @param {string} position - Position: 'top-right', 'top-left', 'bottom-right', 'bottom-left', 'top-center' (default: 'top-right')
 */
function showNotification(message, type = 'info', duration = 5000, position = 'top-right') {
    // Map type to Bootstrap alert class
    const alertTypes = {
        'success': 'success',
        'error': 'danger',
        'danger': 'danger',
        'warning': 'warning',
        'info': 'info'
    };
    
    const alertClass = alertTypes[type] || 'info';
    
    // Map position to CSS classes
    const positionClasses = {
        'top-right': 'top-0 end-0',
        'top-left': 'top-0 start-0',
        'bottom-right': 'bottom-0 end-0',
        'bottom-left': 'bottom-0 start-0',
        'top-center': 'top-0 start-50 translate-middle-x'
    };
    
    const positionClass = positionClasses[position] || 'top-0 end-0';
    
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
        'success': 'Success!',
        'error': 'Error!',
        'danger': 'Error!',
        'warning': 'Warning!',
        'info': 'Notice'
    };
    
    const title = titles[type] || 'Notice';
    
    // Create notification element
    const notificationId = 'notification-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const alertDiv = document.createElement('div');
    alertDiv.id = notificationId;
    alertDiv.className = `alert alert-${alertClass} alert-dismissible fade show`;
    alertDiv.setAttribute('role', 'alert');
    alertDiv.style.cssText = `position: fixed; ${positionClass.includes('translate') ? '' : positionClass.replace('top-0', 'top: 20px').replace('end-0', 'right: 20px').replace('start-0', 'left: 20px').replace('bottom-0', 'bottom: 20px')}; z-index: 9999; min-width: 300px; max-width: 500px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);`;
    
    if (positionClass.includes('translate')) {
        alertDiv.style.cssText += ' transform: translateX(-50%);';
    }
    
    alertDiv.innerHTML = `
        <i class="fas ${icon} me-2"></i>
        <strong>${title}</strong> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" onclick="document.getElementById('${notificationId}').remove()"></button>
    `;
    
    // Add to body
    document.body.appendChild(alertDiv);
    
    // Auto-remove after duration
    if (duration > 0) {
        setTimeout(function() {
            const notification = document.getElementById(notificationId);
            if (notification) {
                // Add fade out animation
                notification.classList.remove('show');
                setTimeout(function() {
                    notification.remove();
                }, 300); // Match Bootstrap fade duration
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

