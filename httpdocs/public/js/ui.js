/**
 * SpoX+ AI — Custom UI System
 * Replaces native alert/confirm with premium modals and adds toast notifications.
 */

const UI = {
    modal: {
        el: null,
        title: null,
        text: null,
        cancelBtn: null,
        submitBtn: null,
        resolve: null,

        init() {
            this.el = document.getElementById('custom-modal-v3');
            this.title = document.getElementById('custom-modal-title');
            this.text = document.getElementById('custom-modal-text');
            this.cancelBtn = document.getElementById('custom-modal-cancel');
            this.submitBtn = document.getElementById('custom-modal-submit');

            this.cancelBtn.onclick = () => this.close(false);
            this.submitBtn.onclick = () => this.close(true);

            // Keyboard support
            window.addEventListener('keydown', (e) => {
                if (this.el.style.display === 'flex') {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.close(true);
                    } else if (e.key === 'Escape') {
                        this.close(false);
                    }
                }
            });
        },

        show(title, text, showCancel = true, submitText = 'Bestätigen') {
            if (!this.el) this.init();
            this.title.textContent = title;
            this.text.textContent = text;
            this.cancelBtn.style.display = showCancel ? 'block' : 'none';
            this.submitBtn.textContent = submitText;
            this.el.style.display = 'flex';

            return new Promise((resolve) => {
                this.resolve = resolve;
            });
        },

        close(result) {
            this.el.style.display = 'none';
            if (this.resolve) this.resolve(result);
        }
    },

    toastContainer: null,

    /**
     * Show a temporary toast notification.
     */
    toast(message, type = 'info', duration = 3000) {
        if (!this.toastContainer) {
            this.toastContainer = document.getElementById('toast-container');
        }

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;

        let icon = 'info';
        if (type === 'error') icon = 'alert-circle';
        if (type === 'success') icon = 'check-circle';

        toast.innerHTML = `
      <i data-lucide="${icon}" style="width:16px; height:16px;"></i>
      <span>${message}</span>
    `;

        this.toastContainer.appendChild(toast);
        if (window.lucide) lucide.createIcons();

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(20px)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },

    /**
     * Replacement for native alert().
     */
    async alert(title, text) {
        return this.modal.show(title, text, false, 'OK');
    },

    /**
     * Replacement for native confirm().
     */
    async confirm(title, text) {
        return this.modal.show(title, text, true, 'Bestätigen');
    },

    promptModal: {
        el: null,
        title: null,
        input: null,
        cancelBtn: null,
        submitBtn: null,
        resolve: null,

        init() {
            this.el = document.getElementById('custom-prompt-modal');
            this.title = document.getElementById('prompt-modal-title');
            this.input = document.getElementById('prompt-modal-input');
            this.cancelBtn = document.getElementById('prompt-modal-cancel');
            this.submitBtn = document.getElementById('prompt-modal-submit');

            this.cancelBtn.onclick = () => this.close(null);
            this.submitBtn.onclick = () => this.close(this.input.value);

            this.input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.close(this.input.value);
                } else if (e.key === 'Escape') {
                    this.close(null);
                }
            });
        },

        show(title, placeholder = '', defaultValue = '') {
            if (!this.el) this.init();
            this.title.textContent = title;
            this.input.placeholder = placeholder;
            this.input.value = defaultValue;
            this.el.style.display = 'flex';
            setTimeout(() => this.input.focus(), 10);

            return new Promise((resolve) => {
                this.resolve = resolve;
            });
        },

        close(result) {
            this.el.style.display = 'none';
            if (this.resolve) this.resolve(result);
        }
    },

    async prompt(title, placeholder = '', defaultValue = '') {
        return this.promptModal.show(title, placeholder, defaultValue);
    }
};

window.UI = UI;
