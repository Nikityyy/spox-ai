/**
 * SpoX+AI — Chat Module
 * Handles chat rendering, SSE streaming, markdown, and message actions.
 */

const Chat = {
    currentUuid: null,
    currentProjectId: null,
    messages: [],
    attachedFiles: [], // Local state for files waiting to be sent
    isStreaming: false,
    abortController: null,

    SUGGESTIONS: [
        {
            text: 'Erkläre Biochemie',
            prompt: 'Erkläre mir die Grundlagen der Biochemie in Bezug auf sportliche Leistungsfähigkeit. Was sind die wichtigsten Prozesse?',
            icon: 'flask-conical'
        },
        {
            text: 'Mahlzeiten-Vorschläge',
            prompt: 'Gib mir 5 gesunde Mahlzeiten-Vorschläge für einen Sportler, die reich an Proteinen und komplexen Kohlenhydraten sind.',
            icon: 'utensils'
        },
        {
            text: 'Ideen für Sport-Events',
            prompt: 'Ich plane ein Sport-Event für die HAK Steyr Sport+. Hast du kreative Ideen für Wettbewerbe oder Aktivitäten?',
            icon: 'trophy'
        },
        {
            text: 'Trainingsplan erstellen',
            prompt: 'Erstelle mir einen beispielhaften 4-Wochen-Trainingsplan für Kraftaufbau (Hypertrophie) für einen Anfänger.',
            icon: 'dumbbell'
        }
    ],

    /**
     * Show empty state (home / new chat).
     */
    showHome(projectUuid = null) {
        this.currentUuid = null;
        // Only override if projectUuid is provided, otherwise keep current state
        if (projectUuid !== null) {
            this.currentProjectId = projectUuid;
        }
        this.messages = [];
        this.attachedFiles = [];
        this.renderUploadPreviews();
        Sidebar.clearActive();

        // Default hero content
        let heroTitle = 'Wie kann ich dich heute bei <span class="text-styled">Sport+</span> unterstützen?';
        let heroSubtitle = 'Dein digitaler Coach für Training, Ernährung und die Matura-Vorbereitung.';
        let headerTitle = 'SpoX+ AI';

        // Project-specific hero content
        if (this.currentProjectId) {
            const p = Sidebar.projects.find(p => p.uuid === this.currentProjectId);
            if (p) {
                heroTitle = `Wie kann ich dich heute bei <span class="text-styled">${escapeHtml(p.name)}</span> unterstützen?`;
                heroSubtitle = 'Alle Dateien und Chats in diesem Projekt helfen mir, dir noch präziser zu antworten.';
                headerTitle = `
                    <i data-lucide="folder" style="width:18px;height:18px;margin-left:8px;color:var(--text-secondary);"></i>
                    ${escapeHtml(p.name)}
                `;
            }
        }

        document.getElementById('header-title').innerHTML = headerTitle;

        const area = document.getElementById('chat-area');
        area.innerHTML = `
      <div class="home-hero">
        <div class="home-logo-container">
          <img src="/assets/favicon.svg" alt="Sport+ AI" class="home-hero-logo">
        </div>
        <h1 class="home-hero-title">${heroTitle}</h1>
        <p class="home-hero-subtitle">${heroSubtitle}</p>

        <div class="suggestions-grid" id="suggestions-grid"></div>
      </div>
    `;
        const grid = document.getElementById('suggestions-grid');
        this.SUGGESTIONS.forEach(s => {
            const card = document.createElement('div');
            card.className = 'suggestion-card';
            card.innerHTML = `
                <div style="display:flex; gap:12px; align-items:center;">
                    <span class="suggestion-icon"><i data-lucide="${s.icon}" style="width:18px;height:18px;"></i></span>
                    <span class="suggestion-text">${escapeHtml(s.text)}</span>
                </div>
                <svg class="suggestion-arrow" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            `;
            card.addEventListener('click', () => {
                const input = document.getElementById('message-input');
                input.value = s.prompt;
                input.style.height = 'auto'; // Reset height
                input.dispatchEvent(new Event('input')); // Trigger input event for auto-resize and send button state
                input.focus();
                this.sendMessage();
            });
            grid.appendChild(card);
        });
        if (window.lucide) lucide.createIcons();
    },

    /**
     * Load and display an existing chat.
     * @param {string} uuid
     */
    async loadChat(uuid) {
        this.currentUuid = uuid;
        this.messages = [];
        this.attachedFiles = [];
        this.renderUploadPreviews();
        Sidebar.setActiveChat(uuid);

        let chatData;
        if (Profile.user) {
            try {
                const res = await fetch(`/api/chats.php?uuid=${encodeURIComponent(uuid)}`);
                if (!res.ok) { this.showHome(); return; }
                const data = await res.json();
                chatData = data.chat;
                this.currentProjectId = chatData.project_uuid || null;
            } catch { this.showHome(); return; }
        } else {
            // Guest: load from localStorage
            const local = Storage.getChat(uuid);
            if (!local) { this.showHome(); return; }
            chatData = { uuid, title: local.title, messages: local.messages || [] };
        }

        this.messages = chatData.messages || [];
        const title = chatData.title || 'Neuer Chat';
        document.getElementById('header-title').innerHTML = this.renderEditableTitle(title, uuid, chatData.project_name);
        this.bindTitleEdit(uuid);

        this.renderMessages();
    },

    renderEditableTitle(title, uuid, projectName = null) {
        const displayTitle = title.length > 20 ? title.substring(0, 20) + '...' : title;
        let html = `
      <span id="chat-title-text" title="${escapeHtml(title)}">${escapeHtml(displayTitle)}</span>
      <button class="header-title-edit" id="edit-title-btn" title="Titel bearbeiten">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
      </button>
    `;

        if (projectName) {
            html += `
                <div class="project-badge">
                    <i data-lucide="folder" style="width:12px; height:12px;"></i>
                    ${escapeHtml(projectName)}
                </div>
            `;
        }
        return html;
    },

    bindTitleEdit(uuid) {
        document.getElementById('edit-title-btn')?.addEventListener('click', async () => {
            const current = document.getElementById('chat-title-text')?.textContent || '';
            const newTitle = await UI.prompt('Chat-Titel bearbeiten', 'Neuer Titel…', current);
            if (newTitle && newTitle.trim()) {
                this.updateTitle(uuid, newTitle.trim());
            }
        });
    },

    async updateTitle(uuid, title) {
        const displayTitle = title.length > 20 ? title.substring(0, 20) + '...' : title;
        document.getElementById('chat-title-text').textContent = displayTitle;
        document.getElementById('chat-title-text').title = title;
        Sidebar.updateChatTitle(uuid, title);

        if (Profile.user) {
            await fetch('/api/chats.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': Profile.getCsrfToken() },
                body: JSON.stringify({ uuid, title }),
            });
        } else {
            const chat = Storage.getChat(uuid);
            if (chat) Storage.upsertChat({ ...chat, title });
        }
    },

    renderMessages() {
        const area = document.getElementById('chat-area');
        area.innerHTML = `<div class="messages-container" id="messages-container"></div>`;
        const container = document.getElementById('messages-container');

        this.messages.forEach(msg => {
            const el = this.createMessageEl(msg.sender, msg.content, msg.files || []);
            container.appendChild(el);
            this.renderMath(el);
        });

        // Detect "orphan" user message (last message has no assistant response)
        const lastMsg = this.messages[this.messages.length - 1];
        if (lastMsg && lastMsg.sender === 'user' && !this.isStreaming) {
            const row = document.createElement('div');
            row.className = 'message-row assistant';
            const bubble = document.createElement('div');
            bubble.className = 'error-bubble-v2';
            bubble.innerHTML = `
                <div class="error-title">
                    <i data-lucide="alert-circle" style="width:16px; height:16px;"></i>
                    Antwort unterbrochen
                </div>
                <div class="error-text">Die Antwort wurde unterbrochen oder konnte nicht gespeichert werden.</div>
                <button class="retry-btn-v2" onclick="Chat.retry('${escapeHtml(lastMsg.content)}')">
                    <i data-lucide="rotate-ccw" style="width:14px; height:14px;"></i>
                    Erneut versuchen
                </button>
            `;
            row.appendChild(bubble);
            container.appendChild(row);
            if (window.lucide) lucide.createIcons();
        }

        this.scrollToBottom();
    },

    createMessageEl(sender, content, files = []) {
        const row = document.createElement('div');
        row.className = `message-row ${sender}`;

        const bubble = document.createElement('div');
        bubble.className = `message-bubble ${sender}`;

        if (sender === 'assistant') {
            bubble.innerHTML = this.renderMarkdown(content);
        } else {
            bubble.textContent = content;
        }

        // Render attachments if any
        if (files && files.length > 0) {
            const attContainer = document.createElement('div');
            attContainer.className = 'message-attachments';
            files.forEach(file => {
                const item = document.createElement('div');
                item.className = 'attachment-item';

                if (file.mime_type.startsWith('image/')) {
                    item.innerHTML = `<img src="/uploads/${file.filename}" alt="${escapeHtml(file.original_name)}">`;
                } else {
                    item.innerHTML = `
                        <div class="attachment-file-icon">
                            <i data-lucide="file-text" style="width:24px;height:24px;"></i>
                            <div class="attachment-file-name">${escapeHtml(file.original_name)}</div>
                        </div>
                    `;
                }

                item.addEventListener('click', () => {
                    this.showPreviewModal(file);
                });
                attContainer.appendChild(item);
            });
            bubble.appendChild(attContainer);
            if (window.lucide) lucide.createIcons();
        }

        row.appendChild(bubble);

        if (sender === 'assistant') {
            const actions = document.createElement('div');
            actions.className = 'message-actions';
            actions.innerHTML = `
        <button class="action-icon" title="Kopieren" onclick="Chat.copyMessage(this)">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
          </svg>
        </button>
        <button class="action-icon" title="Neu generieren" onclick="Chat.regenerate()">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <polyline points="23 4 23 10 17 10"/>
            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
          </svg>
        </button>
      `;
            row.appendChild(actions);
        }

        return row;
    },

    /**
     * Markdown renderer — line-by-line state machine.
     * Handles: headings (h1-h6), bold, italic, inline code, code blocks,
     * unordered/ordered lists, tables, horizontal rules, paragraphs.
     */
    /**
     * Renders markdown and LaTeX.
     */
    renderMarkdown(text) {
        if (!text) return '';

        // Configure marked to handle line breaks properly
        marked.setOptions({
            breaks: true,
            gfm: true
        });

        // First parse markdown
        let html = marked.parse(text);

        // We will call renderMathInElement on the container after it's added to DOM
        return html;
    },

    /**
     * Send a message.
     */
    async sendMessage(isRegenerate = false) {
        const input = document.getElementById('message-input');
        const text = isRegenerate ? input.value : input.value.trim();
        if (!text || this.isStreaming) return;

        // Reset input immediately
        if (!isRegenerate) {
            input.value = '';
            input.style.height = 'auto';
        }
        document.getElementById('send-btn').disabled = true;
        this.updateSendButtonState(true);

        // Reset attached files after sending
        const fileData = [...this.attachedFiles];
        this.attachedFiles = [];
        this.renderUploadPreviews();

        // Ensure chat UUID
        if (!this.currentUuid) {
            this.currentUuid = Storage.generateUUID();
            // Create chat in sidebar
            const newChat = { uuid: this.currentUuid, title: text.slice(0, 60), last_updated: Date.now(), messages: [] };
            if (!Profile.user) {
                Storage.upsertChat(newChat);
            }
            Sidebar.addChat({
                uuid: this.currentUuid,
                title: newChat.title,
                project_uuid: this.currentProjectId
            });
            Sidebar.setActiveChat(this.currentUuid);

            // Update header
            document.getElementById('header-title').innerHTML = this.renderEditableTitle(newChat.title, this.currentUuid);
            this.bindTitleEdit(this.currentUuid);
        }

        // Ensure messages container exists
        let container = document.getElementById('messages-container');
        if (!container) {
            document.getElementById('chat-area').innerHTML = `<div class="messages-container" id="messages-container"></div>`;
            container = document.getElementById('messages-container');
        }

        // Add user message if not regenerating
        if (!isRegenerate) {
            this.messages.push({ sender: 'user', content: text, files: fileData });
            const el = this.createMessageEl('user', text, fileData);
            container.appendChild(el);
            this.renderMath(el);

            // Save to localStorage (guest)
            if (!Profile.user) {
                Storage.addMessage(this.currentUuid, { sender: 'user', content: text, files: fileData });
            }
        }

        // Show typing indicator
        const typingEl = this.createTypingIndicator();
        container.appendChild(typingEl);
        this.scrollToBottom();

        // Stream response
        this.isStreaming = true;
        this.abortController = new AbortController();
        let botContent = '';
        let botBubble = null;

        try {
            const history = this.messages.slice(0, -1).slice(-20); // last 20 before current

            const res = await fetch('/api/chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': Profile.getCsrfToken(),
                },
                body: JSON.stringify({
                    chat_uuid: this.currentUuid,
                    message: text,
                    project_uuid: this.currentProjectId,
                    history,
                    files: fileData.map(f => f.filename), // Send filenames to API
                }),
                signal: this.abortController.signal
            });

            if (!res.ok) {
                const data = await res.json();
                UI.toast(data.message || 'Verbindungsfehler', 'error');
                return;
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let tokenCount = 0;

            while (true) {
                const { done, value } = await reader.read();
                if (done) { break; }

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop();

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;
                    try {
                        const event = JSON.parse(line.slice(6));

                        if (event.type === 'token') {
                            tokenCount++;
                            // Remove typing indicator on first token
                            if (typingEl.parentNode) typingEl.remove();

                            if (!botBubble) {
                                const row = document.createElement('div');
                                row.className = 'message-row assistant';
                                botBubble = document.createElement('div');
                                botBubble.className = 'message-bubble assistant';
                                row.appendChild(botBubble);
                                container.appendChild(row);
                            }

                            botContent += event.text;
                            botBubble.innerHTML = this.renderMarkdown(botContent);
                            // Throttled Math: only render if closing tag detected or message is getting long
                            if (event.text.includes('$') || event.text.includes(']') || event.text.includes(')')) {
                                this.renderMath(botBubble);
                            }
                            this.scrollToBottom();

                        } else if (event.type === 'error') {
                            if (typingEl.parentNode) typingEl.remove();
                            const row = document.createElement('div');
                            row.className = 'message-row assistant';
                            const bubble = document.createElement('div');
                            bubble.className = 'message-bubble assistant';
                            bubble.style.color = '#f87171';
                            bubble.textContent = event.message;
                            row.appendChild(bubble);
                            container.appendChild(row);
                            this.scrollToBottom();

                        } else if (event.type === 'done') {
                            if (event.title) {
                                Sidebar.updateChatTitle(this.currentUuid, event.title);
                            }
                            // Final math render on done
                            if (botBubble) {
                                this.renderMath(botBubble);
                                const actions = document.createElement('div');
                                actions.className = 'message-actions';
                                actions.innerHTML = `
                  <button class="action-icon" title="Kopieren" onclick="Chat.copyMessage(this)">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                      <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                    </svg>
                  </button>
                  <button class="action-icon" title="Neu generieren" onclick="Chat.regenerate()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                      <polyline points="23 4 23 10 17 10"/>
                      <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                    </svg>
                  </button>
                `;
                                botBubble.parentNode.appendChild(actions);
                            }
                        }
                    } catch { }
                }
            }

            // Save bot response to localStorage (guest)
            if (!Profile.user && botContent) {
                Storage.addMessage(this.currentUuid, { sender: 'assistant', content: botContent });
            }

            this.messages.push({ sender: 'assistant', content: botContent });

        } catch (e) {
            if (typingEl.parentNode) typingEl.remove();
            const row = document.createElement('div');
            row.className = 'message-row assistant';
            const bubble = document.createElement('div');
            bubble.className = 'error-bubble-v2';
            bubble.innerHTML = `
                <div class="error-title">
                    <i data-lucide="wifi-off" style="width:16px; height:16px;"></i>
                    Verbindungsfehler
                </div>
                <div class="error-text">Es konnte keine Verbindung aufgebaut werden. Bitte überprüfe deine Internetverbindung.</div>
                <button class="retry-btn-v2" onclick="Chat.retry('${escapeHtml(text)}')">
                    <i data-lucide="rotate-ccw" style="width:14px; height:14px;"></i>
                    Erneut versuchen
                </button>
            `;
            row.appendChild(bubble);
            container.appendChild(row);
            this.scrollToBottom();
            if (window.lucide) lucide.createIcons();
        } finally {
            this.isStreaming = false;
            this.abortController = null;
            this.updateSendButtonState(false);
            const sendBtn = document.getElementById('send-btn');
            sendBtn.disabled = !document.getElementById('message-input').value.trim();
        }
    },

    updateSendButtonState(isStreaming) {
        const btn = document.getElementById('send-btn');
        if (isStreaming) {
            btn.innerHTML = '<i data-lucide="square" style="width:16px; height:16px; fill: currentColor;"></i>';
            btn.title = 'Stoppen';
            btn.disabled = false;
            btn.classList.add('stop-mode');
        } else {
            btn.innerHTML = '<i data-lucide="arrow-up" style="width:16px; height:16px;"></i>';
            btn.title = 'Senden';
            btn.classList.remove('stop-mode');
        }
        if (window.lucide) lucide.createIcons();
    },

    stopGeneration() {
        if (this.abortController) {
            this.abortController.abort();
            this.isStreaming = false;
        }
    },

    createTypingIndicator() {
        const row = document.createElement('div');
        row.className = 'message-row assistant';
        row.innerHTML = `
      <div class="typing-indicator">
        <div class="typing-dots">
          <div class="typing-dot"></div>
          <div class="typing-dot"></div>
          <div class="typing-dot"></div>
        </div>
        <span style="margin-left:6px;font-size:12px;color:var(--text-muted);">SpoX+AI schreibt…</span>
      </div>
    `;
        return row;
    },

    scrollToBottom() {
        const area = document.getElementById('chat-area');
        area.scrollTop = area.scrollHeight;
    },

    copyMessage(btn) {
        const bubble = btn.closest('.message-row').querySelector('.message-bubble');
        navigator.clipboard.writeText(bubble.textContent).then(() => {
            btn.style.color = '#86efac';
            setTimeout(() => btn.style.color = '', 1500);
        });
    },



    regenerate() {
        // Re-send the last user message
        const lastUser = [...this.messages].reverse().find(m => m.sender === 'user');
        if (!lastUser || this.isStreaming) return;

        // Remove last assistant message from display
        const container = document.getElementById('messages-container');
        const rows = container.querySelectorAll('.message-row.assistant');
        if (rows.length) rows[rows.length - 1].remove();

        // Remove from messages array
        const lastIdx = this.messages.map(m => m.sender).lastIndexOf('assistant');
        if (lastIdx >= 0) this.messages.splice(lastIdx, 1);

        document.getElementById('message-input').value = lastUser.content;
        this.sendMessage(true);
        document.getElementById('message-input').value = '';
    },

    retry(text) {
        // Remove the error message bubble
        const container = document.getElementById('messages-container');
        const rows = container.querySelectorAll('.message-row.assistant');
        if (rows.length) rows[rows.length - 1].remove();

        document.getElementById('message-input').value = text;
        this.sendMessage(true);
        document.getElementById('message-input').value = '';
    },

    bindInputEvents() {
        const input = document.getElementById('message-input');
        const sendBtn = document.getElementById('send-btn');

        input.addEventListener('input', () => {
            // Auto-resize
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 120) + 'px';
            sendBtn.disabled = !input.value.trim() || this.isStreaming;
        });

        input.addEventListener('keydown', (e) => {
            // Enter sends only on desktop (width > 768px)
            if (e.key === 'Enter' && !e.shiftKey && window.innerWidth > 768) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        sendBtn.addEventListener('click', () => {
            if (this.isStreaming) {
                this.stopGeneration();
            } else {
                this.sendMessage();
            }
        });

        // File attachment
        document.getElementById('attach-btn').addEventListener('click', () => {
            const fileInput = document.getElementById('file-input');
            fileInput.onchange = async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                await this.uploadFile(file);
                fileInput.value = '';
                fileInput.onchange = null;
            };
            fileInput.click();
        });
    },

    async uploadFile(file, overrideProjectUuid = null, onSuccess = null) {
        if (!Profile.user) {
            Profile.showLoginModal();
            return;
        }

        const container = document.getElementById('upload-progress-container');
        const fill = document.getElementById('upload-progress-fill');
        const percent = document.getElementById('upload-progress-percent');
        const filenameLabel = document.getElementById('upload-progress-filename');

        container.style.display = 'flex';
        filenameLabel.textContent = file.name;
        fill.style.width = '0%';
        percent.textContent = '0%';

        const formData = new FormData();

        let fileToUpload = file;
        if (file.type.startsWith('image/')) {
            try {
                fileToUpload = await this.compressImage(file);
            } catch (e) {
                console.warn('Compression failed, using original', e);
            }
        }
        formData.append('file', fileToUpload);

        // Use override or current state
        const pUuid = overrideProjectUuid || this.currentProjectId;
        if (pUuid) formData.append('project_uuid', pUuid);

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/api/upload_file.php');
            xhr.setRequestHeader('X-CSRF-Token', Profile.getCsrfToken());

            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    const p = Math.round((e.loaded / e.total) * 100);
                    fill.style.width = p + '%';
                    percent.textContent = p + '%';
                }
            };

            xhr.onload = () => {
                container.style.display = 'none';
                if (xhr.status >= 200 && xhr.status < 300) {
                    const data = JSON.parse(xhr.responseText);
                    if (data.file) {
                        if (onSuccess) {
                            onSuccess(data.file);
                        } else {
                            // Default chat behavior: add to attachedFiles
                            this.attachedFiles.push(data.file);
                            this.renderUploadPreviews();
                        }
                        resolve(data.file);
                    } else {
                        UI.alert('Upload fehlgeschlagen', data.message || 'Ein unbekannter Fehler ist aufgetreten.');
                        reject();
                    }
                } else {
                    UI.toast('Upload-Fehler', 'error');
                    reject();
                }
            };

            xhr.onerror = () => {
                container.style.display = 'none';
                UI.toast('Upload-Fehler', 'error');
                reject();
            };

            xhr.send(formData);
        });
    },

    renderUploadPreviews() {
        const bar = document.getElementById('upload-preview-bar');
        if (this.attachedFiles.length === 0) {
            bar.style.display = 'none';
            bar.innerHTML = '';
            return;
        }

        bar.style.display = 'flex';
        bar.innerHTML = '';

        this.attachedFiles.forEach((file, index) => {
            const item = document.createElement('div');
            item.className = 'preview-item';

            // Icon or thumbnail
            let thumbHtml = '';
            const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(file.original_name);
            if (isImage) {
                thumbHtml = `<img src="/uploads/${file.filename}" class="preview-thumb">`;
            } else {
                const icon = file.original_name.toLowerCase().endsWith('.pdf') ? 'file-text' : 'file';
                thumbHtml = `<div class="preview-thumb"><i data-lucide="${icon}" style="width:14px;height:14px;"></i></div>`;
            }

            item.innerHTML = `
                ${thumbHtml}
                <span class="preview-name" title="${escapeHtml(file.original_name)}">${escapeHtml(file.original_name)}</span>
                <button class="preview-remove" title="Entfernen" onclick="event.stopPropagation(); Chat.removeFile(${index})">
                    <i data-lucide="x" style="width:12px; height:12px;"></i>
                </button>
            `;

            item.addEventListener('click', () => this.showPreviewModal(file));
            bar.appendChild(item);
        });

        if (window.lucide) lucide.createIcons();
    },

    async removeFile(index) {
        const file = this.attachedFiles[index];
        if (!file) return;

        // Instantly delete from server if it has an ID
        if (file.id) {
            try {
                fetch(`/api/files.php?id=${file.id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-Token': Profile.getCsrfToken() }
                });
                // We don't necessarily need to await this as it's a "fire and forget" for the UI,
                // but we should at least try to notify the server.
            } catch (e) { console.error('Silent delete failed', e); }
        }

        this.attachedFiles.splice(index, 1);
        this.renderUploadPreviews();
    },

    showPreviewModal(file) {
        const modal = document.getElementById('file-preview-modal');
        const content = document.getElementById('preview-content');
        const filename = document.getElementById('preview-filename');

        filename.textContent = file.original_name;
        content.innerHTML = '';

        const ext = file.original_name.split('.').pop().toLowerCase();
        const url = `/uploads/${file.filename}`;

        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            const img = document.createElement('img');
            img.src = url;
            content.appendChild(img);
        } else if (ext === 'pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = url;
            content.appendChild(iframe);
        } else if (ext === 'txt') {
            fetch(url)
                .then(r => r.text())
                .then(text => {
                    const pre = document.createElement('div');
                    pre.className = 'preview-text-content';
                    pre.textContent = text;
                    content.appendChild(pre);
                });
        } else {
            content.innerHTML = `<div style="color:var(--text-muted);">Vorschau für diesen Dateityp nicht verfügbar.</div>`;
        }

        modal.style.display = 'flex';

        // Bind close button once
        if (!modal.dataset.bound) {
            document.getElementById('close-preview-btn').addEventListener('click', () => {
                modal.style.display = 'none';
            });
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.style.display = 'none';
            });
            modal.dataset.bound = 'true';
        }
    },

    /**
     * Renders math in a specific element.
     */
    renderMath(el) {
        if (typeof renderMathInElement === 'function') {
            renderMathInElement(el, {
                delimiters: [
                    { left: '$$', right: '$$', display: true },
                    { left: '$', right: '$', display: false },
                    { left: '\\(', right: '\\)', display: false },
                    { left: '\\[', right: '\\]', display: true }
                ],
                throwOnError: false
            });
        }
    },

    /**
     * Client-side image compression.
     */
    async compressImage(file, maxWidth = 512, quality = 0.8) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = (e) => {
                const img = new Image();
                img.src = e.target.result;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    let width = img.width;
                    let height = img.height;

                    if (width > maxWidth) {
                        height = Math.round((height * maxWidth) / width);
                        width = maxWidth;
                    }

                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);

                    canvas.toBlob((blob) => {
                        if (blob) {
                            const compressedFile = new File([blob], file.name, {
                                type: 'image/jpeg',
                                lastModified: Date.now()
                            });
                            resolve(compressedFile);
                        } else {
                            reject(new Error('Canvas blob failed'));
                        }
                    }, 'image/jpeg', quality);
                };
                img.onerror = (err) => reject(err);
            };
            reader.onerror = (err) => reject(err);
        });
    },
};

window.Chat = Chat;

/**
 * fmt: No longer needed with marked, but kept as a thin wrapper if used elsewhere.
 */
function fmt(str) {
    return marked.parseInline(str || '');
}

