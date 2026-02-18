/**
 * SpoX+AI — Sidebar Module
 * Manages projects list, chats list, search, and navigation.
 */

const Sidebar = {
    projects: [],
    chats: [],
    activeProjectId: null,
    activeChatUuid: null,

    async init() {
        this.bindEvents();
        await this.load();
    },

    async load() {
        if (Profile.user) {
            await Promise.all([this.loadProjects(), this.loadChats()]);
        } else {
            // Guest: load from LocalStorage
            this.loadGuestChats();
        }
    },

    async loadProjects() {
        try {
            const res = await fetch('/api/projects.php');
            if (!res.ok) return;
            const data = await res.json();
            this.projects = data.projects || [];
            this.renderProjects();
        } catch { }
    },

    async loadChats() {
        try {
            const res = await fetch('/api/chats.php');
            if (!res.ok) return;
            const data = await res.json();
            this.chats = data.chats || [];
            this.renderChats();
        } catch { }
    },

    loadGuestChats() {
        this.chats = Storage.getChats().map(c => ({
            uuid: c.uuid,
            title: c.title || 'Neuer Chat',
            updated_at: new Date(c.last_updated).toISOString(),
        }));
        this.renderChats();
    },

    renderProjects() {
        const container = document.getElementById('projects-list');
        container.innerHTML = '';

        this.projects.forEach(project => {
            const el = document.createElement('div');
            el.className = 'sidebar-project-item' + (this.activeProjectId === project.uuid ? ' active' : '');
            el.dataset.id = project.uuid;

            const content = document.createElement('a');
            content.href = `/project/${project.uuid}`;
            content.className = 'project-item-content';
            content.innerHTML = `
                <i data-lucide="folder" style="width:14px; height:14px; margin-left:8px; flex-shrink:0;"></i>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(project.name)}</span>
            `;
            content.addEventListener('click', (e) => {
                e.preventDefault();
                App.navigate('project', { uuid: project.uuid });
            });

            const delBtn = document.createElement('button');
            delBtn.className = 'project-item-delete';
            delBtn.title = 'Löschen';
            delBtn.innerHTML = `<i data-lucide="trash-2" style="width:12px; height:12px;"></i>`;
            delBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.deleteProject(project.uuid);
            });

            el.appendChild(content);
            el.appendChild(delBtn);
            container.appendChild(el);
        });
        if (window.lucide) lucide.createIcons();
    },

    renderChats(filter = '') {
        const container = document.getElementById('chats-list');
        container.innerHTML = '';

        const filtered = filter
            ? this.chats.filter(c => (c.title || '').toLowerCase().includes(filter.toLowerCase()))
            : this.chats;

        filtered.slice(0, 30).forEach(chat => {
            const el = document.createElement('div'); // Keep as div container for chat item
            el.className = 'sidebar-chat-item' + (this.activeChatUuid === chat.uuid ? ' active' : '');
            el.dataset.uuid = chat.uuid;

            const title = document.createElement('a');
            title.href = `/chat/${chat.uuid}`;
            title.className = 'chat-item-title';
            title.textContent = chat.title || 'Neuer Chat';
            title.title = chat.title || 'Neuer Chat';
            title.addEventListener('click', (e) => {
                e.preventDefault();
                App.navigate('chat', { uuid: chat.uuid });
            });

            const delBtn = document.createElement('button');
            delBtn.className = 'chat-item-delete';
            delBtn.title = 'Löschen';
            delBtn.innerHTML = `<i data-lucide="trash-2" style="width:12px; height:12px;"></i>`;
            delBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.deleteChat(chat.uuid);
            });

            el.appendChild(title);
            el.appendChild(delBtn);
            container.appendChild(el);
        });
        if (window.lucide) lucide.createIcons();
    },

    async deleteChat(uuid) {
        if (!confirm('Chat wirklich löschen?')) return;

        try {
            if (Profile.user) {
                const res = await fetch(`/api/chats.php?uuid=${encodeURIComponent(uuid)}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-Token': Profile.getCsrfToken() },
                });
                if (!res.ok) throw new Error();
            } else {
                Storage.deleteChat(uuid);
            }

            this.chats = this.chats.filter(c => c.uuid !== uuid);
            this.renderChats();

            if (this.activeChatUuid === uuid) {
                App.navigate('home');
            }
        } catch {
            alert('Fehler beim Löschen des Chats.');
        }
    },

    async deleteProject(uuid) {
        if (!confirm('Projekt wirklich löschen? Dies löscht auch alle enthaltenen Chats und Dateien.')) return;

        try {
            const res = await fetch(`/api/projects.php?uuid=${encodeURIComponent(uuid)}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-Token': Profile.getCsrfToken() },
            });
            if (!res.ok) throw new Error();

            this.projects = this.projects.filter(p => p.uuid !== uuid);
            this.renderProjects();

            if (this.activeProjectId === uuid) {
                App.navigate('home');
            }
        } catch {
            alert('Fehler beim Löschen des Projekts.');
        }
    },

    setActiveChat(uuid) {
        this.activeChatUuid = uuid;
        this.activeProjectId = null;
        document.querySelectorAll('.sidebar-chat-item').forEach(el => {
            el.classList.toggle('active', el.dataset.uuid === uuid);
        });
        document.querySelectorAll('.sidebar-project-item').forEach(el => el.classList.remove('active'));
    },

    setActiveProject(id) {
        this.activeProjectId = id;
        this.activeChatUuid = null;
        document.querySelectorAll('.sidebar-project-item').forEach(el => {
            el.classList.toggle('active', el.dataset.id === id);
        });
        document.querySelectorAll('.sidebar-chat-item').forEach(el => el.classList.remove('active'));
    },

    clearActive() {
        this.activeChatUuid = null;
        this.activeProjectId = null;
        document.querySelectorAll('.sidebar-chat-item, .sidebar-project-item').forEach(el => el.classList.remove('active'));
    },

    addChat(chat) {
        this.chats.unshift(chat);
        this.renderChats();
    },

    updateChatTitle(uuid, title) {
        const chat = this.chats.find(c => c.uuid === uuid);
        if (chat) {
            chat.title = title;
            this.renderChats();
        }
    },

    bindEvents() {
        // New chat
        document.getElementById('btn-new-chat').addEventListener('click', () => {
            App.navigate('home');
        });

        // Search toggle
        document.getElementById('btn-search').addEventListener('click', () => {
            const container = document.getElementById('search-container');
            const input = document.getElementById('search-input');
            const visible = container.style.display !== 'none';
            container.style.display = visible ? 'none' : 'block';
            if (!visible) { input.value = ''; input.focus(); }
        });

        // Search input
        document.getElementById('search-input').addEventListener('input', (e) => {
            this.renderChats(e.target.value);
        });

        // New project
        document.getElementById('btn-new-project').addEventListener('click', () => {
            if (!Profile.user) {
                Profile.showLoginModal();
                return;
            }
            document.getElementById('new-project-modal').style.display = 'flex';
            document.getElementById('new-project-name').focus();
        });

        // Cancel project modal
        document.getElementById('cancel-project-btn').addEventListener('click', () => {
            document.getElementById('new-project-modal').style.display = 'none';
        });

        // Create project
        document.getElementById('create-project-btn').addEventListener('click', async () => {
            const name = document.getElementById('new-project-name').value.trim();
            if (!name) return;

            try {
                const res = await fetch('/api/projects.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Profile.getCsrfToken(),
                    },
                    body: JSON.stringify({ name }),
                });
                const data = await res.json();
                if (data.project) {
                    this.projects.unshift(data.project);
                    this.renderProjects();
                    document.getElementById('new-project-modal').style.display = 'none';
                    document.getElementById('new-project-name').value = '';
                    App.navigate('project', { uuid: data.project.uuid });
                }
            } catch (e) {
                console.error('Project creation failed', e);
            }
        });

        // Enter key in project name
        document.getElementById('new-project-name').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') document.getElementById('create-project-btn').click();
        });
    },
};

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

window.Sidebar = Sidebar;
window.escapeHtml = escapeHtml;
