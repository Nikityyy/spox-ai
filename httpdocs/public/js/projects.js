/**
 * SpoX+AI — Projects Module
 * Renders project view with file list, chat list, and new chat button.
 */

const Projects = {
  currentProject: null,

  async loadProject(uuid) {
    try {
      // Parallel fetch for chats and files
      const [resChats, resFiles] = await Promise.all([
        fetch(`/api/chats.php?project_uuid=${uuid}`),
        fetch(`/api/files.php?project_uuid=${uuid}`)
      ]);

      if (!resChats.ok) { App.navigate('home'); return; }

      const [dataChats, dataFiles] = await Promise.all([
        resChats.json(),
        resFiles.json()
      ]);

      this.currentProject = dataChats.project;
      this.render(dataChats.project, dataChats.chats || [], dataFiles.files || []);
      Sidebar.setActiveProject(uuid);
    } catch {
      App.navigate('home');
    }
  },

  render(project, chats, files = []) {
    // Update header
    document.getElementById('header-title').innerHTML = `
      <i data-lucide="folder" style="width:18px;height:18px;margin-left:8px;color:var(--text-secondary);"></i>
      ${escapeHtml(project.name)}
    `;

    const area = document.getElementById('chat-area');
    area.style.padding = '0';
    area.innerHTML = `
      <div style="display:flex;flex-direction:column;height:100%;overflow-y:auto;">
        <!-- Project Header -->
        <div class="project-header">
          <i data-lucide="folder" style="width:32px;height:32px;color:var(--text-secondary);"></i>
          <h1>${escapeHtml(project.name)}</h1>
          <button class="add-files-btn" id="add-files-btn">
            <i data-lucide="plus" style="width:14px;height:14px;"></i>
            Dateien hinzufügen
          </button>
        </div>

        <!-- Files Section -->
        <div class="project-section-title">Dokumente & Daten</div>
        <div class="file-list" id="project-files-list">
          ${files.length === 0 ? `
            <div style="padding:12px;color:var(--text-muted);font-size:13px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px dashed var(--border);">
              Keine Dateien hochgeladen. Füge Dokumente hinzu, damit die AI sie als Kontext nutzen kann.
            </div>
          ` : files.map(file => `
            <div class="file-item">
              <div class="file-preview-trigger" style="display:contents; cursor:pointer;">
                <i data-lucide="${file.mime_type === 'application/pdf' ? 'file-text' : 'file'}" style="width:18px;height:18px;color:var(--text-secondary);"></i>
                <div class="file-info">
                  <div class="file-name">${escapeHtml(file.original_name)}</div>
                  <div class="file-meta">${(file.size / 1024 / 1024).toFixed(2)} MB • ${new Date(file.created_at).toLocaleDateString()}</div>
                </div>
              </div>
              <button class="file-delete-btn" data-id="${file.id}" title="Löschen">
                <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
              </button>
            </div>
          `).join('')}
        </div>

        <!-- New Chat Button -->
        <button class="new-chat-project-btn" id="new-project-chat-btn">
          <i data-lucide="plus" style="width:18px;height:18px;"></i>
          Neuer Chat in ${escapeHtml(project.name)}
        </button>

        <!-- Chat List Section -->
        <div class="project-section-title">Chats</div>
        <div id="project-chats-list">
          ${chats.length === 0 ? `
            <div style="padding:24px;color:var(--text-muted);font-size:13px;text-align:center;">
              Noch keine Chats in diesem Projekt.
            </div>
          ` : chats.map(chat => `
            <div class="project-chat-item" data-uuid="${escapeHtml(chat.uuid)}">
              <div>
                <div class="project-chat-title">${escapeHtml(chat.title || 'Neuer Chat')}</div>
                <div class="project-chat-subtitle">${escapeHtml(chat.first_message || '')}</div>
              </div>
              <div class="project-chat-date">${this.formatDate(chat.updated_at)}</div>
            </div>
          `).join('')}
        </div>
      </div>
    `;

    if (window.lucide) lucide.createIcons();

    // Bind events
    document.getElementById('new-project-chat-btn').addEventListener('click', () => {
      Chat.currentProjectId = project.uuid;
      Chat.showHome();
      // Ensure input bar is visible for the new chat
      document.getElementById('input-area').style.display = 'block';
      // Ensure header reflects project context
      document.getElementById('header-title').innerHTML = `
        <i data-lucide="folder" style="width:18px;height:18px;margin-left:8px;color:var(--text-secondary);"></i>
        ${escapeHtml(project.name)}
      `;
      if (window.lucide) lucide.createIcons();
    });

    document.getElementById('add-files-btn').addEventListener('click', () => {
      const fileInput = document.getElementById('file-input');
      // Set a clean one-time handler
      fileInput.onchange = async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        await Chat.uploadFile(file, project.uuid, () => {
          this.loadProject(project.uuid);
        });
        fileInput.value = '';
        fileInput.onchange = null; // Prevent leak / lingering handler
      };
      fileInput.click();
    });

    // File interaction (Preview & Delete)
    document.querySelectorAll('.file-item').forEach((el, index) => {
      const file = files[index];

      // Clinical binding for preview
      el.querySelectorAll('.file-preview-trigger').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
          e.preventDefault();
          Chat.showPreviewModal(file);
        });
      });

      // Delete button
      el.querySelector('.file-delete-btn').addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!confirm('Datei wirklich löschen?')) return;
        const id = el.querySelector('.file-delete-btn').dataset.id;
        try {
          const res = await fetch(`/api/files.php?id=${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': Profile.getCsrfToken() }
          });
          if (res.ok) this.loadProject(project.uuid);
        } catch { alert('Fehler beim Löschen.'); }
      });
    });

    // Chat item clicks
    document.querySelectorAll('.project-chat-item').forEach(el => {
      el.addEventListener('click', () => {
        App.navigate('chat', { uuid: el.dataset.uuid });
      });
    });
  },

  formatDate(isoString) {
    if (!isoString) return '';
    const date = new Date(isoString);
    const now = new Date();
    const diff = now - date;
    const days = Math.floor(diff / 86400000);

    if (days === 0) return 'Heute';
    if (days === 1) return 'Gestern';
    if (days < 7) return `${days} Tage`;

    return date.toLocaleDateString('de-AT', { day: 'numeric', month: 'short' });
  },
};

window.Projects = Projects;
