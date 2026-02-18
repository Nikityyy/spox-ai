/**
 * SpoX+AI — Profile & Auth Module
 * Handles login state, profile dropdown, and auth modal.
 */

const Profile = {
    user: null,
    csrfToken: null,
    isGuest: false,

    async init() {
        // Check auth state
        try {
            const res = await fetch('/api/auth.php?action=me');
            const data = await res.json();

            if (data.authenticated) {
                this.user = data.user;
                this.csrfToken = data.csrf_token;
                this.isGuest = false;
                this.renderLoggedIn();

                // Sync local chats after login
                await Sync.syncLocalChats(this.csrfToken);
            } else {
                this.renderLoggedOut();
                // Check if user chose guest mode before
                if (localStorage.getItem('spoxai_guest_mode') === '1') {
                    this.isGuest = true;
                } else {
                    // Show login modal on first visit
                    setTimeout(() => this.showLoginModal(), 300);
                }
            }
        } catch {
            this.renderLoggedOut();
        }

        this.bindEvents();
    },

    renderLoggedIn() {
        const initial = (this.user.display_name || 'U')[0].toUpperCase();
        const profileBtn = document.getElementById('profile-btn');
        const loginBtn = document.getElementById('login-btn');

        profileBtn.textContent = initial;
        profileBtn.style.display = 'flex';
        loginBtn.style.display = 'none';

        // Show attach button (file upload for logged-in users)
        document.getElementById('attach-btn').style.display = 'flex';

        // Fill dropdown
        document.getElementById('dd-avatar').textContent = initial;
        document.getElementById('dd-name').textContent = this.user.display_name;
        document.getElementById('dd-email').textContent = this.user.email;
        document.getElementById('dd-email').style.display = 'block';
        document.getElementById('dd-logout').style.display = 'flex'; // Ensure logout is visible
    },

    renderLoggedOut() {
        document.getElementById('login-btn').style.display = 'block';

        // Also show profile button for guests so they can access settings
        const profileBtn = document.getElementById('profile-btn');
        profileBtn.innerHTML = '<i data-lucide="user" style="width:16px;height:16px;"></i>';
        profileBtn.style.display = 'flex';
        profileBtn.title = 'Profil & Einstellungen';
        if (window.lucide) lucide.createIcons();

        // Fill dropdown for guest
        document.getElementById('dd-avatar').innerHTML = '<i data-lucide="user" style="width:20px;height:20px;"></i>';
        document.getElementById('dd-name').textContent = 'Gast';
        document.getElementById('dd-email').style.display = 'none';
        document.getElementById('dd-logout').style.display = 'none'; // Hide logout for guests
        if (window.lucide) lucide.createIcons();
    },

    bindEvents() {
        // Profile button toggle dropdown
        document.getElementById('profile-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            const dd = document.getElementById('profile-dropdown');
            dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
        });

        // Close dropdown on outside click
        document.addEventListener('click', () => {
            document.getElementById('profile-dropdown').style.display = 'none';
        });

        // Login button
        document.getElementById('login-btn').addEventListener('click', () => {
            window.location.href = '/api/auth.php?action=login';
        });

        // Modal login
        document.getElementById('modal-login-btn').addEventListener('click', () => {
            window.location.href = '/api/auth.php?action=login';
        });

        // Modal guest
        document.getElementById('modal-guest-btn').addEventListener('click', () => {
            this.isGuest = true;
            localStorage.setItem('spoxai_guest_mode', '1');
            this.hideLoginModal();
        });

        // Logout
        document.getElementById('dd-logout').addEventListener('click', async () => {
            await fetch('/api/auth.php?action=logout', {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfToken || '' },
            });
            localStorage.removeItem('spoxai_guest_mode');
            window.location.reload();
        });

        // Settings
        document.getElementById('dd-settings').addEventListener('click', () => {
            document.getElementById('profile-dropdown').style.display = 'none';
            App.navigate('settings');
        });

        // Export
        document.getElementById('dd-export').addEventListener('click', () => {
            window.location.href = '/api/export.php';
        });

        // Check for auth error in URL
        const params = new URLSearchParams(window.location.search);
        if (params.get('auth_error')) {
            this.showLoginModal();
            history.replaceState({}, '', '/');
        }
    },

    showLoginModal() {
        const modal = document.getElementById('login-modal');
        modal.style.display = 'flex';

        // Backdrop click handler (one-time if needed, or check target)
        if (!modal.dataset.bound) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.isGuest = true;
                    localStorage.setItem('spoxai_guest_mode', '1');
                    this.hideLoginModal();
                }
            });
            modal.dataset.bound = 'true';
        }
    },

    hideLoginModal() {
        document.getElementById('login-modal').style.display = 'none';
    },

    getCsrfToken() {
        return this.csrfToken || '';
    },
};

window.Profile = Profile;
