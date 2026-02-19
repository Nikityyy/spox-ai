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
            const projectChats = this.chats.filter(c => c.project_uuid === project.uuid);
            const isCollapsed = localStorage.getItem(`project_collapsed_${project.uuid}`) === 'true';

            const el = document.createElement('div');
            el.className = 'sidebar-project-item' +
                (this.activeProjectId === project.uuid ? ' active' : '') +
                (isCollapsed ? ' collapsed' : '');
            el.dataset.id = project.uuid;

            const content = document.createElement('div');
            content.className = 'project-item-content';
            content.style.cursor = 'pointer';

            content.innerHTML = `
                <i data-lucide="chevron-down" class="project-chevron" style="width:14px; height:14px; margin-left:12px; flex-shrink:0;"></i>
                <i data-lucide="folder" style="width:14px; height:14px; flex-shrink:0; color:var(--text-secondary);"></i>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;">${escapeHtml(project.name)}</span>
            `;

            // Toggle collapse when clicking the project header (chevron or name)
            content.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleProject(project.uuid);
                // Also navigate to project if not already there
                if (this.activeProjectId !== project.uuid) {
                    App.navigate('project', { uuid: project.uuid });
                }
                this.closeMobileSidebar();
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

            // Nested Chats Container
            const chatContainer = document.createElement('div');
            chatContainer.className = 'project-chats-container';
            chatContainer.id = `project-chats-${project.uuid}`;
            chatContainer.style.maxHeight = isCollapsed ? '0' : '500px';

            projectChats.forEach(chat => {
                const chatEl = this.createChatElement(chat);
                chatContainer.appendChild(chatEl);
            });

            container.appendChild(chatContainer);
        });
        if (window.lucide) lucide.createIcons();
    },

    toggleProject(uuid) {
        const container = document.getElementById(`project-chats-${uuid}`);
        const item = document.querySelector(`.sidebar-project-item[data-id="${uuid}"]`);
        if (!container || !item) return;

        const isCollapsed = !item.classList.contains('collapsed');
        item.classList.toggle('collapsed', isCollapsed);
        container.style.maxHeight = isCollapsed ? '0' : '500px';
        localStorage.setItem(`project_collapsed_${uuid}`, isCollapsed);
    },

    createChatElement(chat) {
        const el = document.createElement('div');
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
            this.closeMobileSidebar();
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
        return el;
    },

    renderChats(filter = '') {
        const container = document.getElementById('chats-list');
        container.innerHTML = '';

        // Filter chats that are NOT assigned to a project for the global list
        const globalChats = this.chats.filter(c => !c.project_uuid);

        const filtered = filter
            ? globalChats.filter(c => (c.title || '').toLowerCase().includes(filter.toLowerCase()))
            : globalChats;

        filtered.slice(0, 30).forEach(chat => {
            const el = this.createChatElement(chat);
            container.appendChild(el);
        });
        if (window.lucide) lucide.createIcons();
    },

    async deleteChat(uuid) {
        if (!await UI.confirm('Chat löschen', 'Möchtest du diesen Chat wirklich unwiderruflich löschen?')) return;

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
            UI.toast('Chat gelöscht', 'success');
        } catch {
            UI.toast('Fehler beim Löschen des Chats', 'error');
        }
    },

    async deleteProject(uuid) {
        if (!await UI.confirm('Projekt löschen', 'Möchtest du dieses Projekt wirklich löschen? Dies entfernt auch alle enthaltenen Chats und Dateien.')) return;

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
            UI.toast('Projekt gelöscht', 'success');
        } catch {
            UI.toast('Fehler beim Löschen des Projekts', 'error');
        }
    },

    setActiveChat(uuid) {
        this.activeChatUuid = uuid;
        this.activeProjectId = null;

        // Find the chat element and its project container
        const chatEl = document.querySelector(`.sidebar-chat-item[data-uuid="${uuid}"]`);
        if (chatEl) {
            const container = chatEl.closest('.project-chats-container');
            if (container) {
                const projectUuid = container.id.replace('project-chats-', '');
                const projectItem = document.querySelector(`.sidebar-project-item[data-id="${projectUuid}"]`);
                if (projectItem && projectItem.classList.contains('collapsed')) {
                    this.toggleProject(projectUuid);
                }
            }
        }

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
        if (chat.project_uuid) {
            this.renderProjects();
        }
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
            this.closeMobileSidebar();
        });

        // Mobile toggle
        document.getElementById('mobile-sidebar-toggle')?.addEventListener('click', () => {
            this.toggleMobileSidebar();
        });

        document.getElementById('sidebar-overlay')?.addEventListener('click', () => {
            this.closeMobileSidebar();
        });

        // Logo link (in Sidebar)
        document.getElementById('logo-link')?.addEventListener('click', () => {
            this.closeMobileSidebar();
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
            this.closeMobileSidebar();
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

    toggleMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const isOpen = sidebar.classList.toggle('open');
        overlay.classList.toggle('open', isOpen);
    },

    closeMobileSidebar() {
        if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebar-overlay').classList.remove('open');
        }
    },
};

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

window.Sidebar = Sidebar;
window.escapeHtml = escapeHtml;
