/**
 * Modal Dialog Component - Replaces native confirm/alert/prompt
 * 
 * Usage:
 *   modalConfirm('ยืนยันการลบ?').then(ok => { if (ok) doDelete(); });
 *   modalAlert('บันทึกสำเร็จ!');
 *   modalConfirm('ยืนยัน?', { confirmText: 'ลบ', confirmClass: 'danger' });
 */

(function() {
    'use strict';

    // Create modal container on DOM ready
    let modalContainer = null;

    function ensureContainer() {
        if (modalContainer) return modalContainer;
        
        modalContainer = document.createElement('div');
        modalContainer.id = 'app-modal-container';
        modalContainer.innerHTML = `
            <div id="app-modal-backdrop" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[9998] hidden opacity-0 transition-opacity duration-200"></div>
            <div id="app-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4">
                <div id="app-modal-dialog" class="bg-white rounded-2xl shadow-2xl max-w-md w-full transform scale-95 opacity-0 transition-all duration-200">
                    <div class="p-6">
                        <div class="flex items-start">
                            <div id="app-modal-icon" class="flex-shrink-0 mr-4">
                                <!-- Icon inserted here -->
                            </div>
                            <div class="flex-1">
                                <h3 id="app-modal-title" class="text-lg font-semibold text-gray-900 mb-2"></h3>
                                <p id="app-modal-message" class="text-gray-600 whitespace-pre-line"></p>
                            </div>
                        </div>
                    </div>
                    <div id="app-modal-footer" class="flex justify-end gap-3 px-6 py-4 bg-gray-50 rounded-b-2xl">
                        <!-- Buttons inserted here -->
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modalContainer);
        
        // Close on backdrop click
        document.getElementById('app-modal-backdrop').addEventListener('click', function() {
            closeModal(false);
        });
        
        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isModalOpen()) {
                closeModal(false);
            }
        });
        
        return modalContainer;
    }

    function isModalOpen() {
        const modal = document.getElementById('app-modal');
        return modal && !modal.classList.contains('hidden');
    }

    let currentResolve = null;

    function showModal(options) {
        ensureContainer();
        
        const backdrop = document.getElementById('app-modal-backdrop');
        const modal = document.getElementById('app-modal');
        const dialog = document.getElementById('app-modal-dialog');
        const iconContainer = document.getElementById('app-modal-icon');
        const title = document.getElementById('app-modal-title');
        const message = document.getElementById('app-modal-message');
        const footer = document.getElementById('app-modal-footer');

        // Set icon based on type
        const icons = {
            confirm: `<div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center">
                        <i class="bi bi-question-lg text-2xl text-amber-600"></i>
                      </div>`,
            alert: `<div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                      <i class="bi bi-info-lg text-2xl text-blue-600"></i>
                    </div>`,
            success: `<div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                        <i class="bi bi-check-lg text-2xl text-green-600"></i>
                      </div>`,
            warning: `<div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center">
                        <i class="bi bi-exclamation-triangle text-2xl text-amber-600"></i>
                      </div>`,
            danger: `<div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                       <i class="bi bi-exclamation-triangle text-2xl text-red-600"></i>
                     </div>`,
            error: `<div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                      <i class="bi bi-x-lg text-2xl text-red-600"></i>
                    </div>`
        };
        
        iconContainer.innerHTML = icons[options.type] || icons.confirm;
        title.textContent = options.title || (options.type === 'alert' ? 'แจ้งเตือน' : 'ยืนยัน');
        message.textContent = options.message || '';

        // Build buttons
        const btnClasses = {
            primary: 'px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2',
            success: 'px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2',
            danger: 'px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2',
            secondary: 'px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2'
        };

        let buttonsHtml = '';
        
        if (options.type === 'alert' || options.alertOnly) {
            // Alert: only OK button
            const btnClass = btnClasses[options.confirmClass] || btnClasses.primary;
            buttonsHtml = `<button id="app-modal-ok" class="${btnClass}">${options.confirmText || 'ตกลง'}</button>`;
        } else {
            // Confirm: Cancel + OK buttons
            const confirmClass = btnClasses[options.confirmClass] || btnClasses.primary;
            buttonsHtml = `
                <button id="app-modal-cancel" class="${btnClasses.secondary}">${options.cancelText || 'ยกเลิก'}</button>
                <button id="app-modal-ok" class="${confirmClass}">${options.confirmText || 'ยืนยัน'}</button>
            `;
        }
        
        footer.innerHTML = buttonsHtml;

        // Show modal with animation
        backdrop.classList.remove('hidden');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        
        // Trigger animation
        requestAnimationFrame(() => {
            backdrop.classList.remove('opacity-0');
            dialog.classList.remove('scale-95', 'opacity-0');
            dialog.classList.add('scale-100', 'opacity-100');
        });

        // Focus OK button
        const okBtn = document.getElementById('app-modal-ok');
        if (okBtn) okBtn.focus();

        // Return promise
        return new Promise((resolve) => {
            currentResolve = resolve;
            
            document.getElementById('app-modal-ok').addEventListener('click', function() {
                closeModal(true);
            });
            
            const cancelBtn = document.getElementById('app-modal-cancel');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function() {
                    closeModal(false);
                });
            }
        });
    }

    function closeModal(result) {
        const backdrop = document.getElementById('app-modal-backdrop');
        const modal = document.getElementById('app-modal');
        const dialog = document.getElementById('app-modal-dialog');

        if (!modal || modal.classList.contains('hidden')) return;

        // Animate out
        backdrop.classList.add('opacity-0');
        dialog.classList.remove('scale-100', 'opacity-100');
        dialog.classList.add('scale-95', 'opacity-0');

        setTimeout(() => {
            backdrop.classList.add('hidden');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            
            if (currentResolve) {
                currentResolve(result);
                currentResolve = null;
            }
        }, 200);
    }

    // Public API
    window.modalConfirm = function(message, options = {}) {
        return showModal({
            type: options.type || 'confirm',
            title: options.title || 'ยืนยันการดำเนินการ',
            message: message,
            confirmText: options.confirmText,
            cancelText: options.cancelText,
            confirmClass: options.confirmClass
        });
    };

    window.modalAlert = function(message, options = {}) {
        return showModal({
            type: options.type || 'alert',
            title: options.title || 'แจ้งเตือน',
            message: message,
            confirmText: options.confirmText || 'ตกลง',
            alertOnly: true,
            confirmClass: options.confirmClass
        });
    };

    window.modalSuccess = function(message, options = {}) {
        return showModal({
            type: 'success',
            title: options.title || 'สำเร็จ',
            message: message,
            confirmText: options.confirmText || 'ตกลง',
            alertOnly: true,
            confirmClass: 'success'
        });
    };

    window.modalError = function(message, options = {}) {
        return showModal({
            type: 'error',
            title: options.title || 'เกิดข้อผิดพลาด',
            message: message,
            confirmText: options.confirmText || 'ตกลง',
            alertOnly: true,
            confirmClass: 'danger'
        });
    };

    // Helper for form submission with confirm
    window.confirmSubmit = function(form, message, options = {}) {
        modalConfirm(message, options).then(function(confirmed) {
            if (confirmed) {
                form.submit();
            }
        });
        return false; // Prevent default form submission
    };

})();
