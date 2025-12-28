/* ... existing code ... */

// --- Mod Selection Queue ---

function toggleModSelection(modId) {
    // Find mod object in current lists
    // This is inefficient but functional for small lists. 
    // Ideally we would index them.
    let mod = modState.curseforge.find(m => String(m.id) === String(modId)) ||
              modState.modrinth.find(m => String(m.id) === String(modId)) ||
              modState.installed.find(m => String(m.id) === String(modId) || m.name === modId); // Installed mods might use name as ID or have hash

    if (!mod) {
        // Try to fetch from internal map if we decide to store it
        console.warn("Mod not found for ID:", modId);
        return;
    }

    const key = String(mod.id);
    if (modState.selected.has(key)) {
        modState.selected.delete(key);
    } else {
        modState.selected.set(key, mod);
    }
    
    // Update UI
    renderMods();
    renderQueue();
}

function renderQueue() {
    const panel = document.getElementById('modQueuePanel');
    const list = document.getElementById('queueList');
    const count = document.getElementById('queueCount');
    const btn = document.getElementById('btnInstallSelected');
    const grid = document.getElementById('modList'); // The grid container
    
    if (!panel || !list) return;

    if (modState.selected.size > 0) {
        panel.classList.add('open');
        // Shrink grid slightly? No, let panel overlay or push content. 
        // With flex layout, if we change width of panel, it pushes content.
        // Current CSS has panel as absolute overlay on right.
        // To make it push content, we need relative positioning or flex grow.
        // Let's stick to absolute overlay but maybe add padding-right to grid container?
        // Actually, CSS says .mcmm-queue-panel is absolute. 
        // Let's modify grid container width if we want push behavior, or just let it overlay.
        // Overlay is fine given the large width.
    } else {
        panel.classList.remove('open');
    }

    count.textContent = `${modState.selected.size} item${modState.selected.size !== 1 ? 's' : ''}`;
    btn.textContent = `Install ${modState.selected.size} Mod${modState.selected.size !== 1 ? 's' : ''}`;
    
    list.innerHTML = Array.from(modState.selected.values()).map(mod => `
        <div class="mcmm-queue-item">
            <div class="mcmm-queue-thumb" style="background-image: url('${mod.icon || ''}');"></div>
            <div class="mcmm-queue-info">
                <div class="mcmm-queue-name" title="${mod.name}">${mod.name}</div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">
                    ${mod.downloads ? '⬇ ' + mod.downloads : ''}
                </div>
            </div>
            <button class="mcmm-queue-remove" onclick="removeModFromQueue('${mod.id}')" title="Remove">×</button>
        </div>
    `).join('');
}

function removeModFromQueue(modId) {
    const key = String(modId);
    if (modState.selected.has(key)) {
        modState.selected.delete(key);
        renderMods(); // To uncheck items in grid
        renderQueue();
    }
}

async function installSelectedMods() {
    if (modState.selected.size === 0) return;
    if (!currentServerId) return;

    const btn = document.getElementById('btnInstallSelected');
    const originalText = btn.textContent;
    btn.disabled = true;
    
    const modsToInstall = Array.from(modState.selected.values());
    let successCount = 0;
    let failCount = 0;

    // Process sequentially to avoid overwhelming server/API
    for (let i = 0; i < modsToInstall.length; i++) {
        const mod = modsToInstall[i];
        btn.textContent = `Installing ${i + 1}/${modsToInstall.length}...`;
        
        try {
            // Find specific file ID logic? installMod API currently takes mod_id and handles file resolution backend-side.
            const res = await fetch(`/plugins/mcmm/api.php?action=mod_install&id=${currentServerId}&mod_id=${mod.id}`);
            const data = await res.json();
            
            if (data.success) {
                successCount++;
                // Mark as installed in UI immediately?
                // Ideally we update local state.
                const installedMod = { ...mod, file: mod.name + '.jar' }; // Rough approx until reload
                modState.installed.push(installedMod); 
            } else {
                console.error(`Failed to install ${mod.name}: ${data.error}`);
                failCount++;
            }
        } catch (e) {
            console.error(`Error installing ${mod.name}:`, e);
            failCount++;
        }
    }

    // Done
    btn.textContent = `Done (${successCount} installed)`;
    btn.style.background = successCount > 0 ? 'var(--success)' : 'var(--danger)';
    
    // Clear queue after delay
    setTimeout(() => {
        modState.selected.clear();
        renderQueue();
        renderMods(); // Refresh grid state
        loadInstalledMods(); // Refresh actual installed list
        
        btn.disabled = false;
        btn.textContent = originalText;
        btn.style.background = ''; // Reset to default class style
    }, 2000);
}


