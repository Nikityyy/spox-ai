/**
 * SpoX+AI — LocalStorage Schema for Guest Users
 * Manages chat persistence for unauthenticated users.
 */

const STORAGE_KEY = 'spoxai_chats';
const STORAGE_VERSION = 1;
const MAX_GUEST_CHATS = 50;
const GUEST_EXPIRY_DAYS = 30;

const Storage = {
  /**
   * Get all guest chats from LocalStorage.
   * @returns {Array}
   */
  getChats() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return [];
      const data = JSON.parse(raw);
      if (data.version !== STORAGE_VERSION) return [];
      // Filter expired chats
      const cutoff = Date.now() - GUEST_EXPIRY_DAYS * 86400 * 1000;
      return (data.chats || []).filter(c => c.last_updated > cutoff);
    } catch {
      return [];
    }
  },

  /**
   * Save all guest chats to LocalStorage.
   * @param {Array} chats
   */
  saveChats(chats) {
    try {
      // Keep only most recent MAX_GUEST_CHATS
      const sorted = [...chats].sort((a, b) => b.last_updated - a.last_updated);
      const trimmed = sorted.slice(0, MAX_GUEST_CHATS);
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        version: STORAGE_VERSION,
        chats: trimmed,
      }));
    } catch (e) {
      console.warn('SpoX+AI: Could not save to localStorage', e);
    }
  },

  /**
   * Get a single chat by UUID.
   * @param {string} uuid
   * @returns {Object|null}
   */
  getChat(uuid) {
    return this.getChats().find(c => c.uuid === uuid) || null;
  },

  /**
   * Create or update a chat.
   * @param {Object} chat - { uuid, title, messages }
   */
  upsertChat(chat) {
    const chats = this.getChats();
    const idx = chats.findIndex(c => c.uuid === chat.uuid);
    const updated = { ...chat, last_updated: Date.now() };
    if (idx >= 0) {
      chats[idx] = updated;
    } else {
      chats.unshift(updated);
    }
    this.saveChats(chats);
    return updated;
  },

  /**
   * Add a message to a chat.
   * @param {string} uuid
   * @param {Object} message - { id, sender, content, ts }
   */
  addMessage(uuid, message) {
    const chats = this.getChats();
    const chat = chats.find(c => c.uuid === uuid);
    if (!chat) return;
    if (!chat.messages) chat.messages = [];
    chat.messages.push({ ...message, ts: Date.now() });
    chat.last_updated = Date.now();
    this.saveChats(chats);
  },

  /**
   * Delete a chat.
   * @param {string} uuid
   */
  deleteChat(uuid) {
    const chats = this.getChats().filter(c => c.uuid !== uuid);
    this.saveChats(chats);
  },

  /**
   * Clear all guest chats (after sync).
   */
  clearAll() {
    localStorage.removeItem(STORAGE_KEY);
  },

  /**
   * Generate a UUID v4.
   * @returns {string}
   */
  generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  },
};

window.Storage = Storage;
