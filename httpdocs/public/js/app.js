/**
 * SpoX+AI — Main App Router & Entry Point
 * Initializes all modules and handles client-side routing.
 */

const App = {
  async init() {

    // Initialize modules
    await Profile.init();
    await Sidebar.init();

    // Logo navigation
    document.getElementById('logo-link')?.addEventListener('click', (e) => {
      e.preventDefault();
      this.navigate('home');
    });

    // Bind input events
    Chat.bindInputEvents();

    // Route to initial view
    this.routeFromPath();

    // Auto-focus input
    document.getElementById('message-input')?.focus();

    // Listen for history changes
    window.addEventListener('popstate', () => this.routeFromPath());

    // Handle auth error from URL
    const params = new URLSearchParams(window.location.search);
    if (params.get('auth_error')) {
      history.replaceState({}, '', '/');
    }
  },

  routeFromPath() {
    const path = window.location.pathname;
    const parts = path.split('/').filter(p => p);
    const route = parts[0] || 'home';
    const param = parts[1];

    switch (route) {
      case 'home':
        this.navigate('home', {}, false);
        break;
      case 'chat':
        if (param) this.navigate('chat', { uuid: param }, false);
        else this.navigate('home', {}, false);
        break;
      case 'project':
        if (param) this.navigate('project', { uuid: param }, false);
        else this.navigate('home', {}, false);
        break;
      case 'settings':
        this.navigate('settings', {}, false);
        break;
      default:
        this.navigate('home', {}, false);
    }
  },

  /**
   * Navigate to a view.
   * @param {string} view - 'home' | 'chat' | 'project' | 'settings'
   * @param {Object} params
   */
  navigate(view, params = {}, updateHistory = true) {
    // Reset chat area padding
    document.getElementById('chat-area').style.padding = '20px';

    let url = '/';

    switch (view) {
      case 'home':
        url = '/';
        Chat.currentProjectId = null;
        Chat.showHome();
        break;

      case 'chat':
        url = `/chat/${params.uuid}`;
        Chat.loadChat(params.uuid);
        break;

      case 'project':
        url = `/project/${params.uuid}`;
        if (!Profile.user) {
          Profile.showLoginModal();
          return;
        }
        Projects.loadProject(params.uuid);

        if (updateHistory) {
          history.pushState({}, '', url);
        }
        return;

      case 'settings':
        url = '/settings';
        this.showSettings();
        break;
    }

    if (updateHistory) {
      history.pushState({}, '', url);
    }

    // Show input bar and focus
    const inputArea = document.getElementById('input-area');
    inputArea.style.display = 'block';

    // Always show attach button as requested ("add a add file button")
    document.getElementById('attach-btn').style.display = 'flex';

    setTimeout(() => {
      document.getElementById('message-input')?.focus();
    }, 50);
  },

  showSettings() {
    Sidebar.clearActive();
    document.getElementById('header-title').textContent = 'Einstellungen';
    document.getElementById('input-area').style.display = 'none';

    const user = Profile.user;
    const area = document.getElementById('chat-area');
    area.style.padding = '0';
    area.innerHTML = `
      <div class="settings-section">
        <h2>Konto</h2>

        ${user ? `
          <div class="settings-item">
            <div>
              <div>${escapeHtml(user.display_name)}</div>
              <div class="settings-item-label">${escapeHtml(user.email)}</div>
            </div>
          </div>
          <div class="settings-item">
            <div>
              <div>Daten exportieren</div>
              <div class="settings-item-label">Alle deine Chats und Daten als JSON herunterladen</div>
            </div>
            <a href="/api/export.php" style="text-decoration:none;">
              <button class="add-files-btn">Exportieren</button>
            </a>
          </div>
          <div class="settings-item">
            <div>
              <div>Konto löschen</div>
              <div class="settings-item-label">Alle deine Daten werden unwiderruflich gelöscht</div>
            </div>
            <button class="danger-btn" id="delete-account-btn">Konto löschen</button>
          </div>
        ` : `
          <div class="settings-item">
            <div>Du bist nicht eingeloggt.</div>
            <button class="add-files-btn" onclick="Profile.showLoginModal()">Anmelden</button>
          </div>
        `}

        <h2 style="margin-top:32px;">Datenschutz</h2>
        <div class="settings-item">
          <div>
            <div>Datenschutzerklärung</div>
            <div class="settings-item-label">Informationen zur Datenverarbeitung</div>
          </div>
          <a href="/docs/privacy.html" target="_blank" style="text-decoration:none;">
            <button class="add-files-btn">Öffnen</button>
          </a>
        </div>
        <div class="settings-item">
          <div>
            <div>Cookie-Richtlinie</div>
            <div class="settings-item-label">Informationen zu Cookies & Speicher</div>
          </div>
          <a href="/docs/cookies.html" target="_blank" style="text-decoration:none;">
            <button class="add-files-btn">Öffnen</button>
          </a>
        </div>
        <div class="settings-item">
          <div>
            <div>Impressum</div>
            <div class="settings-item-label">Rechtliche Informationen</div>
          </div>
          <a href="/docs/imprint.html" target="_blank" style="text-decoration:none;">
            <button class="add-files-btn">Öffnen</button>
          </a>
        </div>

        <h2 style="margin-top:32px;">Über SpoX+AI</h2>
        <div class="settings-item">
          <div>
            <div>Version</div>
            <div class="settings-item-label">HAK Sport+ — BHAK &amp; BHAS Steyr</div>
          </div>
        </div>
        <div class="settings-item">
          <div>
            <div>Entwickler</div>
            <div class="settings-item-label">Entwickelt von <a href="https://nikityyy.github.io/" target="_blank" style="color:var(--accent);text-decoration:underline;">Nikita Berger</a> mit Hilfe von Lara Sophia Harant (UI und UX Design), Laura Gere (Promotion), Sandro Samuel Bramberger (Feedbackanalyse) und Felix Kühhas (Rechtliche Grundlagen)</div>
          </div>
        </div>
      </div>
    `;

    // Delete account
    document.getElementById('delete-account-btn')?.addEventListener('click', async () => {
      if (!await UI.confirm('Konto löschen', 'Bist du sicher? Alle deine Daten werden unwiderruflich gelöscht.')) return;
      if (!await UI.confirm('Letzte Bestätigung', 'Soll dein Konto wirklich gelöscht werden?')) return;

      try {
        const res = await fetch('/api/delete_account.php', {
          method: 'POST',
          headers: { 'X-CSRF-Token': Profile.getCsrfToken() },
        });
        const data = await res.json();
        if (data.success) {
          await UI.alert('Erfolg', 'Dein Konto wurde gelöscht.');
          window.location.reload();
        } else {
          UI.alert('Fehler', data.message || 'Unbekannter Fehler');
        }
      } catch {
        UI.toast('Verbindungsfehler', 'error');
      }
    });
  },

};

// Boot
document.addEventListener('DOMContentLoaded', () => App.init());

window.App = App;
