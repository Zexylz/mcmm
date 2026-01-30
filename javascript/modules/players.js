/**
 * Opens the players modal for a specific server and fetches the current player list.
 * Identifies online players, their head avatars, and operator status.
 *
 * @param {string} serverId - The ID of the server.
 * @param {string} serverName - The display name of the server.
 * @param {string} [port='25565'] - The server query port.
 * @returns {Promise<void>}
 */
export async function openPlayersModal(serverId, serverName, port) {
    const modal = document.getElementById('playersModal');
    const body = document.getElementById('playersBody');
    const subtitle = document.getElementById('playersModalSubtitle');
    if (!modal || !body || !subtitle) return;
    subtitle.textContent = serverName || '';
    modal.classList.add('open');
    body.innerHTML = `<div style="text-align:center; padding: 2rem; color: var(--text-secondary);">
        <div class="mcmm-spinner"></div>
        <div style="margin-top:0.5rem;">Loading players...</div>
    </div>`;
    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=server_players&id=${encodeURIComponent(serverId)}&port=${encodeURIComponent(port || '25565')}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load players');
        const players = data.data.players || [];
        const online = data.data.online ?? 0;
        const max = data.data.max ?? 0;
        if (!players.length) {
            body.innerHTML = `<div style="text-align:center; padding: 1.5rem; color: var(--text-secondary);">
                <div style="font-weight:700; margin-bottom:0.25rem;">${online} / ${max || '?'} online</div>
                <div style="color: var(--text-muted);">No player list available.</div>
            </div>`;
            return;
        }
        const rows = players.map(p => {
            const name = p.name || p;
            const headUrl = `https://cravatar.eu/helmavatar/${encodeURIComponent(name)}/64.png`;
            const isOp = !!p.isOp;
            const opAction = isOp ? 'deop' : 'op';
            const opLabel = isOp ? 'Deop' : 'Op';
            return `
                <div class="mcmm-player-row">
                    <div class="mcmm-player-head" style="background-image: url('${headUrl}');"></div>
                    <div class="mcmm-player-name">${name}</div>
                    <div class="mcmm-player-actions">
                        <button class="mcmm-btn warning" style="padding: 0.35rem 0.65rem;" onclick="playerAction('${serverId}', '${name}', 'kick')">Kick</button>
                        <button class="mcmm-btn danger" style="padding: 0.35rem 0.65rem;" onclick="playerAction('${serverId}', '${name}', 'ban')">Ban</button>
                        <button class="mcmm-btn ${isOp ? 'danger' : 'success'}" style="padding: 0.35rem 0.65rem;" onclick="playerAction('${serverId}', '${name}', '${opAction}')">${opLabel}</button>
                    </div>
                </div>
            `;
        }).join('');
        body.innerHTML = `
            <div style="padding: 0.5rem 0.75rem; color: var(--text-secondary); font-weight:700; margin-bottom: 0.25rem;">
                ${online} / ${max || '?'} online
            </div>
            <div class="mcmm-player-list">${rows}</div>
        `;
    } catch (e) {
        body.innerHTML = `<div style="padding: 1.5rem; color: var(--danger); text-align:center;">Error: ${e.message}</div>`;
    }
}

/**
 * Closes the players modal.
 */
export function closePlayersModal() {
    const modal = document.getElementById('playersModal');
    if (modal) modal.classList.remove('open');
}

/**
 * Executes a player management action (kick, ban, op, deop) for a specific player.
 *
 * @param {string} serverId - The ID of the server.
 * @param {string} playerName - The name of the player.
 * @param {string} action - The action to perform.
 * @returns {Promise<void>}
 */
export async function playerAction(serverId, playerName, action) {
    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=server_player_action&id=${encodeURIComponent(serverId)}&player=${encodeURIComponent(playerName)}&action=${encodeURIComponent(action)}`);
        const data = await res.json();
        if (!data.success) {
            alert('Error: ' + (data.error || 'command failed'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}
