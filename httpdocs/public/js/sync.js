/**
 * SpoX+AI — Guest→User Chat Sync
 * Syncs LocalStorage chats to server after login.
 */

const Sync = {
    /**
     * Sync local guest chats to server.
     * Called once after successful login.
     * @param {string} csrfToken
     */
    async syncLocalChats(csrfToken) {
        const chats = Storage.getChats();
        if (!chats.length) return;

        try {
            const res = await fetch('/api/sync_local.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ chats }),
            });

            if (!res.ok) {
                console.warn('SpoX+AI: Sync failed', res.status);
                return;
            }

            const data = await res.json();
            console.log(`SpoX+AI: Synced ${data.synced} chats`, data.merged?.length ? `(${data.merged.length} merged)` : '');

            // Clear local storage after successful sync
            Storage.clearAll();

            return data;
        } catch (e) {
            console.warn('SpoX+AI: Sync error', e);
        }
    },
};

window.Sync = Sync;
