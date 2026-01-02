// MCMM Plugin Logic
/* global csrfToken, $, mcmmConfig */
/* exported switchTab, filterModpacks, openModManager, closeModManager, switchModTab, switchSource, clearModFilters, setModSort, filterMods, checkForUpdates, toggleModSelection, removeModFromQueue, clearQueue, installSelectedMods, toggleImageFit, setRam, toggleSelect, selectOption, controlServer, deleteServer, saveSettings, openServerSettings, closeServerSettings, submitServerSettings, closeDeployProgress, finishDeployAndView, openPlayersModal, closePlayersModal, playerAction, openConsole, closeConsole, changeModsPage */
console.log('MCMM Script Loaded');

// Expose functions globally for inline HTML onclick handlers
window.switchTab = switchTab;
window.filterModpacks = filterModpacks;
window.saveSettings = saveSettings;
window.controlServer = controlServer;
window.deleteServer = deleteServer;
window.openConsole = openConsole;
window.closeConsole = closeConsole;
window.openModManager = openModManager;
window.closeModManager = closeModManager;
window.openServerSettings = openServerSettings;
window.closeServerSettings = closeServerSettings;
window.submitServerSettings = submitServerSettings;
window.openPlayersModal = openPlayersModal;
window.closePlayersModal = closePlayersModal;
window.toggleSelect = toggleSelect;
window.selectOption = selectOption;
window.setRam = setRam;
window.openDeployModal = openDeployModal;
window.closeDeploy = closeDeploy;
window.submitDeploy = submitDeploy;
window.switchModTab = switchModTab;
window.switchSource = switchSource;
window.filterMods = filterMods;
window.installMod = installMod;
window.deleteMod = deleteMod;
window.toggleModSelection = toggleModSelection;
window.installSelectedMods = installSelectedMods;
window.removeModFromQueue = removeModFromQueue;
window.clearQueue = clearQueue;
window.openVersionSelect = openVersionSelect;
window.closeVersionSelect = closeVersionSelect;
window.confirmVersionInstall = confirmVersionInstall;
window.selectVersionFile = selectVersionFile;
window.startAgents = startAgents;
window.checkForUpdates = checkForUpdates;

window.clearModFilters = clearModFilters;
window.setModSort = setModSort;
window.setDeployVersion = setDeployVersion;
window.renderDeployVersions = renderDeployVersions;
window.setDeployConsole = setDeployConsole;
window.openDeployProgress = openDeployProgress;
window.closeDeployProgress = closeDeployProgress;
window.finishDeployAndView = finishDeployAndView;
window.loadBackups = loadBackups;
window.createBackup = createBackup;
window.reinstallFromBackup = reinstallFromBackup;
window.deleteBackup = deleteBackup;

// Hide debug banner
document.addEventListener('DOMContentLoaded', () => {
    const banner = document.getElementById('mcmm-debug-banner');
    if (banner) {
        banner.textContent = 'MCMM UI loaded';
        setTimeout(() => banner.remove(), 1200);
    }

    // Initial load
    console.log("%c MCMM %c Initializing dashboard...", "background:#7c3aed;color:#fff;font-weight:700;padding:2px 6px;border-radius:4px;", "");
    if (document.getElementById('tab-servers')?.classList.contains('active')) {
        loadServers();
    }
});

// Global error handler for debugging
window.addEventListener('error', function (event) {
    console.error("%c MCMM Runtime Error: ", "background:#ef4444;color:#fff;font-weight:700;padding:2px 6px;border-radius:4px;", event.message, "at", event.filename, ":", event.lineno);
});

let modpackState = { items: [], loading: false, error: '' };
let modpackSearchTimer;
let selectedModpack = null;
let modpacksLoaded = false;
let settingsCache = null;

// Mod Manager State
let currentServerId = null;
let modState = {
    curseforge: [],
    modrinth: [],
    installed: [],
    loading: false,
    view: 'all', // 'all', 'installed', 'updates'
    source: 'curseforge', // 'curseforge', 'modrinth'
    search: '',
    sort: 'popular',
    page: 1,
    pageSize: 20,
    hasMore: false,
    total: 0,
    selected: new Map(), // Map<modId, modObject>
    mcVersion: '',       // e.g. "1.20.1"
    loader: '',          // forge | neoforge | fabric | quilt
    serverEnv: {},
    serverInfo: null
};
let modSearchTimer;

// Deploy progress state
let deployLogInterval = null;
// let deployLogContainerId = null;

// Tab Switching
function switchTab(tabId, element) {
    document.querySelectorAll('.mcmm-tab').forEach(t => t.classList.remove('active'));
    if (element) element.classList.add('active');

    document.querySelectorAll('.mcmm-tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('tab-' + tabId).classList.add('active');

    if (tabId === 'catalog' && !modpackState.loading && !modpacksLoaded) {
        modpacksLoaded = true;
        loadModpacks(document.getElementById('modpackSearch')?.value || '');
    }

    if (tabId === 'servers') {
        loadServers();
    }

    if (tabId === 'backups') {
        loadBackups();
    }
}

// --- Modpack Logic ---

function renderModpacks(data) {
    const grid = document.getElementById('modpackGrid');
    if (!grid) return;

    if (typeof window.mcmmConfig !== 'undefined' && !window.mcmmConfig.has_api_key) {
        grid.innerHTML = `
            <div class="mcmm-empty">
                <h3>CurseForge API key required</h3>
                <p>Set your API key in Settings to browse the catalog.</p>
            </div>`;
        return;
    }

    if (modpackState.loading) {
        grid.innerHTML = `
            <div class="mcmm-empty">
                <div class="mcmm-spinner"></div>
                <p style="margin-top: 0.75rem;">Loading modpacks...</p>
            </div>`;
        return;
    }

    if (modpackState.error) {
        grid.innerHTML = `
            <div class="mcmm-empty">
                <p>${modpackState.error}</p>
            </div>`;
        return;
    }

    if (!data || !data.length) {
        grid.innerHTML = `
            <div class="mcmm-empty">
                <h3>No modpacks found</h3>
                <p>Try a different search term.</p>
            </div>`;
        return;
    }

    grid.innerHTML = '';
    data.forEach(pack => {
        const tagsHtml = (pack.tags || []).map(t => `<span class="mcmm-tag">${t}</span>`).join('');
        const card = document.createElement('div');
        card.className = 'mcmm-modpack-card';
        card.onclick = () => openDeployModal(pack);
        card.innerHTML = `
            <div class="mcmm-modpack-thumb" style="background-image: url('${pack.img || ''}')"></div>
            <div class="mcmm-modpack-info">
                <div class="mcmm-modpack-name">${pack.name}</div>
                <div class="mcmm-modpack-meta">
                    <span>by ${pack.author || 'Unknown'}</span>
                    <span style="display: flex; align-items: center; gap: 0.2rem;"><span class="material-symbols-outlined" style="font-size: 1rem;">download</span> ${pack.downloads || '0'}</span>
                </div>
                <div class="mcmm-modpack-tags">${tagsHtml}</div>
            </div>
        `;
        grid.appendChild(card);
    });
}

async function loadModpacks(query = '') {
    if (typeof mcmmConfig !== 'undefined' && !mcmmConfig.has_api_key) {
        renderModpacks([]);
        return;
    }

    modpackState.loading = true;
    modpackState.error = '';
    renderModpacks(modpackState.items);

    try {
        const res = await fetch('/plugins/mcmm/api.php?action=modpacks&search=' + encodeURIComponent(query));
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'Failed to load modpacks');
        }
        modpackState.items = data.data || [];
    } catch (err) {
        modpackState.error = err.message;
    } finally {
        modpackState.loading = false;
        renderModpacks(modpackState.items);
    }
}

function filterModpacks() {
    const query = document.getElementById('modpackSearch').value;
    clearTimeout(modpackSearchTimer);
    modpackSearchTimer = setTimeout(() => loadModpacks(query), 300);
}

// --- Mod Manager Logic ---

async function openModManager(serverId, serverName) {
    currentServerId = serverId;
    document.getElementById('modManagerSubtitle').textContent = 'Managing: ' + serverName;
    document.getElementById('modManagerModal').classList.add('open');

    // Reset state
    modState.curseforge = [];
    modState.modrinth = [];
    modState.installed = [];
    modState.view = 'all';
    modState.source = 'curseforge';
    modState.page = 1;
    modState.pageSize = modState.pageSize || 20;
    modState.hasMore = false;
    modState.total = 0;
    modState.selected.clear();
    modState.mcVersion = '';
    modState.loader = '';
    modState.serverEnv = {};
    modState.serverInfo = null;
    renderQueue(); // Clear queue panel
    renderModsPagination();
    document.getElementById('modSearchInput').value = '';

    // UI Reset
    const tabs = document.querySelectorAll('#modManagerModal .mcmm-tab');
    tabs.forEach(b => b.classList.remove('active'));
    tabs[0].classList.add('active'); // 'All Mods'

    const sourceBtns = document.querySelectorAll('.mcmm-source-btn');
    sourceBtns.forEach(b => b.classList.remove('active'));
    sourceBtns[0].classList.add('active'); // 'CurseForge'

    // Fetch server details to infer MC version & loader for filtering
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=server_details&id=' + serverId);
        const data = await res.json();
        console.log("MCMM Server Details:", data.data);
        if (data.success && data.data) {
            modState.serverInfo = data.data;
            modState.serverEnv = data.data.env || {};
            if (data.data.loader) {
                modState.loader = data.data.loader;
            }
            if (data.data.mcVersion) {
                modState.mcVersion = data.data.mcVersion;
            }

            // UI Update
            const filterContainer = document.getElementById('activeFilterContainer');
            const filterText = document.getElementById('activeFilterText');
            if (modState.mcVersion) {
                filterText.textContent = `Filtering: ${modState.mcVersion}`;
                filterContainer.style.display = 'flex';
            } else {
                filterText.textContent = 'Filtering: Detect...';
                filterContainer.style.display = 'flex'; // Show so they can click to set
            }
        }
    } catch (e) {
        console.warn('Failed to load server details for version filter', e);
        const filterContainer = document.getElementById('activeFilterContainer');
        if (filterContainer) filterContainer.style.display = 'flex'; // Show manual set even on failure
    }

    // Reset sort selector to default on open
    const sortSelect = document.getElementById('modSortSelect');
    if (sortSelect) {
        const trigger = sortSelect.querySelector('.mcmm-select-trigger');
        if (trigger) {
            const labelMap = {
                popular: 'Sort: Popular',
                downloads: 'Sort: Downloads',
                name: 'Sort: Name (A–Z)',
                name_desc: 'Sort: Name (Z–A)',
                author: 'Sort: Author (A–Z)'
            };
            trigger.textContent = labelMap[modState.sort] || 'Sort: Popular';
        }
        const hidden = document.getElementById('mod_sort_value');
        if (hidden) hidden.value = modState.sort || 'popular';
        sortSelect.querySelectorAll('.mcmm-option').forEach(o => o.classList.remove('selected'));
        const match = Array.from(sortSelect.querySelectorAll('.mcmm-option')).find(o => {
            return (o.textContent.includes('Popular') && modState.sort === 'popular') ||
                (o.textContent.includes('Downloads') && modState.sort === 'downloads') ||
                (o.textContent.includes('Name (A–Z)') && modState.sort === 'name') ||
                (o.textContent.includes('Name (Z–A)') && modState.sort === 'name_desc') ||
                (o.textContent.includes('Author') && modState.sort === 'author');
        });
        if (match) match.classList.add('selected');
    }

    loadInstalledMods(); // Load installed first to know status
    loadMods(''); // Initial search
}

function closeModManager() {
    document.getElementById('modManagerModal').classList.remove('open');
    currentServerId = null;
}

function switchModTab(view, btn) {
    modState.view = view;
    document.querySelectorAll('#modManagerModal .mcmm-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    renderMods();
    if (view === 'updates') {
        checkForUpdates();
    }
}

function switchSource(source, btn) {
    modState.source = source;
    document.querySelectorAll('.mcmm-source-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    modState.page = 1;

    // If view is 'all', reload search results from new source
    if (modState.view === 'all') {
        const query = document.getElementById('modSearchInput').value;
        loadMods(query);
    }
}

function clearModFilters() {
    modState.mcVersion = '';
    loadMods(modState.search);
}

function setModSort(value) {
    modState.sort = value || 'popular';
    modState.page = 1;
    const select = document.getElementById('modSortSelect');
    if (select) {
        const trigger = select.querySelector('.mcmm-select-trigger');
        if (trigger) {
            const labelMap = {
                popular: 'Sort: Popular',
                downloads: 'Sort: Downloads',
                name: 'Sort: Name (A–Z)',
                name_desc: 'Sort: Name (Z–A)',
                author: 'Sort: Author (A–Z)'
            };
            trigger.textContent = labelMap[modState.sort] || 'Sort: Popular';
        }
        select.querySelectorAll('.mcmm-option').forEach(o => {
            const val = o.textContent.includes('Downloads') ? 'downloads' :
                o.textContent.includes('Z–A') ? 'name_desc' :
                    o.textContent.includes('Name (A–Z)') ? 'name' :
                        o.textContent.includes('Author') ? 'author' : 'popular';
            o.classList.toggle('selected', val === modState.sort);
        });
        const hidden = document.getElementById('mod_sort_value');
        if (hidden) hidden.value = modState.sort;
    }
    renderMods();
}

function filterMods() {
    const query = document.getElementById('modSearchInput').value;
    modState.search = query;
    modState.page = 1;

    if (modState.view === 'all') {
        clearTimeout(modSearchTimer);
        modSearchTimer = setTimeout(() => loadMods(query), 400);
    } else {
        renderMods(); // Local filter
    }
}

async function loadMods(query) {
    if (modState.view !== 'all') return;

    modState.loading = true;
    modState.search = query;
    renderModsPagination(); // disable/enable buttons while loading
    // Update filter UI
    const filterContainer = document.getElementById('activeFilterContainer');
    const filterText = document.getElementById('activeFilterText');
    if (filterContainer && filterText) {
        if (modState.mcVersion) {
            filterContainer.style.display = 'flex';
            filterText.textContent = `Filtering: ${modState.mcVersion}`;
        } else {
            filterContainer.style.display = 'none';
        }
    }

    renderMods();

    try {
        const params = new URLSearchParams();
        params.set('action', 'mod_search');
        params.set('search', query);
        if (modState.mcVersion) params.set('version', modState.mcVersion);
        if (modState.loader) params.set('loader', modState.loader);
        if (modState.source) params.set('source', modState.source);
        params.set('page', modState.page || 1);
        params.set('page_size', modState.pageSize || 20);
        params.set('_', Date.now()); // cache-bust
        const url = '/plugins/mcmm/api.php?' + params.toString();
        console.debug('[MCMM] loadMods', {
            url,
            page: modState.page,
            pageSize: modState.pageSize,
            source: modState.source
        });

        const res = await fetch(url);
        const data = await res.json();
        if (data.success) {
            if (modState.source === 'modrinth') {
                modState.modrinth = data.data || [];
            } else {
                modState.curseforge = data.data || [];
            }

            // Sync auto-detected versions back to state if they were missing
            if (data.version && !modState.mcVersion) {
                modState.mcVersion = data.version;
                const filterContainer = document.getElementById('activeFilterContainer');
                const filterText = document.getElementById('activeFilterText');
                if (filterContainer && filterText) {
                    filterContainer.style.display = 'flex';
                    filterText.textContent = `Filtering: ${modState.mcVersion}`;
                }
            }
            if (data.loader && !modState.loader) {
                modState.loader = data.loader;
            }

            // Pagination flags
            const page = data.page ? parseInt(data.page, 10) : modState.page;
            const pageSize = data.pageSize ? parseInt(data.pageSize, 10) : modState.pageSize;
            modState.page = Number.isFinite(page) ? page : 1;
            modState.pageSize = Number.isFinite(pageSize) ? pageSize : 20;
            const parsedTotal = parseInt(data.total, 10);
            const dataCount = (data.data || []).length;
            if (typeof data.hasMore !== 'undefined') {
                modState.hasMore = !!data.hasMore;
            } else if (Number.isFinite(parsedTotal)) {
                modState.hasMore = (modState.page * modState.pageSize) < parsedTotal;
            } else {
                modState.hasMore = (dataCount === modState.pageSize);
            }
            if (Number.isFinite(parsedTotal)) {
                modState.total = parsedTotal;
            } else {
                // Estimate total when API does not include it
                modState.total = modState.hasMore
                    ? (modState.page * modState.pageSize + 1)
                    : ((modState.page - 1) * modState.pageSize + dataCount);
            }
            console.debug('[MCMM] page data', {
                page: modState.page,
                pageSize: modState.pageSize,
                total: modState.total,
                hasMore: modState.hasMore,
                count: dataCount
            });
            renderModsPagination();
            // Reset scroll to top on new page
            const listEl = document.getElementById('modList');
            if (listEl) listEl.scrollTop = 0;
        } else {
            throw new Error(data.error || 'Failed to fetch mods');
        }
    } catch (e) {
        console.error(e);
        // Show error in UI
        const container = document.getElementById('modList');
        if (container) {
            container.innerHTML = `<div style="grid-column: 1/-1; text-align: center; color: var(--danger); padding: 2rem;">Error: ${e.message}</div>`;
        }
    } finally {
        modState.loading = false;
        if (!document.getElementById('modList').innerHTML.includes('Error')) {
            renderMods();
        }
        renderModsPagination();
    }
}

async function checkForUpdates() {
    if (!currentServerId) return;
    modState.loading = true;
    renderMods();

    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=check_updates&id=${currentServerId}`);
        const data = await res.json();
        if (data.success && data.updates) {
            // Update installed mods with latest info
            modState.installed = modState.installed.map(m => {
                const update = data.updates[m.modId || m.id];
                if (update) {
                    return {
                        ...m,
                        latestFileId: update.latestFileId,
                        latestFileName: update.latestFileName
                    };
                }
                return m;
            });
            // Also update the general list if we have it
            const lists = [modState.curseforge, modState.modrinth];
            lists.forEach(list => {
                list.forEach(m => {
                    const update = data.updates[m.id];
                    if (update) {
                        m.latestFileId = update.latestFileId;
                        m.latestFileName = update.latestFileName;
                    }
                });
            });
        }
    } catch (e) {
        console.error("[MCMM] Update check failed:", e);
    } finally {
        modState.loading = false;
        renderMods();
    }
}

async function loadInstalledMods() {
    if (!currentServerId) return;
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=mod_list&id=' + currentServerId);
        const data = await res.json();
        if (data.success) {
            modState.installed = data.data || [];
            if (modState.view === 'installed') renderMods();

            // Trigger identification for unknown mods
            const unknownMods = modState.installed.filter(m => m.needsIdentification);
            if (unknownMods.length > 0) {
                console.log(`[MCMM] Found ${unknownMods.length} mods needing identification.`);
                // Identify them sequentially to avoid rate limiting
                for (const mod of unknownMods) {
                    await identifyModBackground(mod.file);
                }
            }
        }
    } catch (e) {
        console.error(e);
    }
}

async function identifyModBackground(filename) {
    if (!currentServerId) return;
    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=identify_mod&id=${currentServerId}&filename=${encodeURIComponent(filename)}`);
        const data = await res.json();
        if (data.success && data.data) {
            console.log(`[MCMM] Successfully identified ${filename} as ${data.data.name}`);
            // Update the mod in state
            modState.installed = modState.installed.map(m => {
                if (m.file === filename) {
                    return {
                        ...m,
                        ...data.data,
                        needsIdentification: false
                    };
                }
                return m;
            });
            if (modState.view === 'installed') renderMods();
        }
    } catch (e) {
        console.error(`[MCMM] Failed to identify ${filename}:`, e);
    }
}

function renderMods() {
    const container = document.getElementById('modList');
    if (!container) return;

    let items = [];

    if (modState.view === 'all') {
        if (modState.loading) {
            container.innerHTML = '<div class="mcmm-spinner" style="grid-column: 1/-1; margin: 2rem auto;"></div>';
            return;
        }
        items = modState.source === 'modrinth' ? modState.modrinth : modState.curseforge;
    } else if (modState.view === 'installed') {
        items = modState.installed;
        if (modState.search) {
            const q = modState.search.toLowerCase();
            items = items.filter(m => (m.name || '').toLowerCase().includes(q) || (m.fileName || '').toLowerCase().includes(q));
        }
    } else if (modState.view === 'updates') {
        // Filter installed mods that have update information from the checkpoint/load results
        items = modState.installed.filter(installedMod => {
            const latestFileId = installedMod.latestFileId || '';
            const installedFileId = installedMod.fileId || '';
            const latestFileName = installedMod.latestFileName || '';
            const installedFileName = installedMod.fileName || installedMod.file || '';

            if (latestFileId && installedFileId) {
                return String(latestFileId) !== String(installedFileId);
            }
            if (latestFileName && installedFileName) {
                return latestFileName !== installedFileName;
            }
            return false;
        });

        if (items.length === 0) {
            container.innerHTML = `
                <div style="grid-column: 1/-1; text-align: center; color: var(--text-secondary); padding: 4rem 2rem;">
                    <div style="font-weight: 600; font-size: 1.25rem; color: var(--text-main); margin-bottom: 0.5rem;">All mods are up to date</div>
                    <div style="font-size: 0.95rem; margin-bottom: 2rem; opacity: 0.7;">
                        No new versions were found for your installed mods.
                    </div>
                    <button class="mcmm-btn mcmm-btn-primary" style="margin: 0 auto; padding: 0.75rem 2rem;" onclick="checkForUpdates()">
                        Check for Updates
                    </button>
                </div>`;
            return;
        }
    }

    // Sort according to selection
    items = sortMods(items);

    if (items.length === 0) {
        const message = 'No mods found.';
        container.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 2rem;">${message}</div>`;
        return;
    }

    container.innerHTML = items.map(mod => {
        // Improved installed check using modId
        const isInstalled = modState.installed.some(m => String(m.modId || m.id) === String(mod.id));
        const installedMod = modState.installed.find(m => String(m.modId || m.id) === String(mod.id));

        let needsUpdate = false;
        if (isInstalled) {
            // Find the most accurate latest info. 
            // If the current mod object (from search) doesn't have it, maybe modState.installed (from updates check) does.
            const remoteLatestFileId = mod.latestFileId || (installedMod ? installedMod.latestFileId : null);
            const remoteLatestFileName = mod.latestFileName || (installedMod ? installedMod.latestFileName : null);

            if (installedMod) {
                const installedFileId = installedMod.fileId || '';
                const installedFileName = installedMod.fileName || installedMod.file || '';

                if (remoteLatestFileId && installedFileId) {
                    needsUpdate = String(remoteLatestFileId) !== String(installedFileId);
                } else if (remoteLatestFileName && installedFileName) {
                    needsUpdate = remoteLatestFileName !== installedFileName;
                } else {
                    needsUpdate = false;
                }
            } else {
                needsUpdate = false;
            }
        }

        const safeModName = (mod.name || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');

        if (modState.view === 'installed') {
            const iconUrl = mod.logo || mod.icon || '';
            const author = mod.author || 'Unknown';
            const size = mod.size || '';
            const mcVer = mod.mcVersion || '';

            return `
                <div class="mcmm-modpack-card" style="display: flex; align-items: center; gap: 1.25rem; padding: 1.25rem; min-height: 90px;">
                    <div style="width: 56px; height: 56px; background-image: url('${iconUrl}'); background-size: cover; background-position: center; background-color: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--text-secondary); flex-shrink: 0;">
                        ${!iconUrl ? '☕' : ''}
                    </div>
                    
                    <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center;">
                        <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.1rem;">
                            <div style="font-weight: 700; font-size: 1rem; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${mod.name}">
                                ${mod.name}
                            </div>
                            ${mcVer ? `<span class="mcmm-ver-chip" style="font-size: 0.75rem; padding: 0.1rem 0.4rem;">MC ${mcVer}</span>` : ''}
                        </div>
                        <div style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.6rem;">
                            <span>by ${author}</span>
                            ${size ? `<span style="width: 4px; height: 4px; background: var(--text-muted); border-radius: 50%;"></span><span>${size}</span>` : ''}
                            <span style="width: 4px; height: 4px; background: var(--text-muted); border-radius: 50%;"></span>
                            <span style="color: var(--success); font-weight: 600;">Installed</span>
                        </div>
                    </div>
                    
                    <button class="mcmm-btn-icon danger" style="width: 44px; height: 44px; font-size: 1.1rem; border-radius: 10px; flex-shrink: 0;" title="Delete Mod" onclick="deleteMod('${mod.fileName || mod.name || mod.file}')">
                        <span class="material-symbols-outlined">delete</span>
                    </button>
                </div>
            `;
        } else {
            // Checkbox selected state
            const isSelected = modState.selected.has(String(mod.id));
            const selectedClass = isSelected ? 'selected' : '';
            const mcVersionLabel = mod.mcVersion || modState.mcVersion || '';

            return `
                <div class="mcmm-modpack-card ${selectedClass}" style="display: flex; gap: 1.5rem; padding: 1.5rem; align-items: center; min-height: 110px; position: relative;" onclick="toggleModSelection('${mod.id}')">
                    <div style="width: 80px; height: 80px; background-image: url('${mod.icon}'); background-size: cover; background-position: center; border-radius: 16px; flex-shrink: 0; background-color: #2a2a2a; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                    <div style="flex: 1; overflow: hidden; display: flex; flex-direction: column; height: 100%; justify-content: center;">
                        <div style="margin-bottom: 0.5rem; padding-right: 2rem;">
                            <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.1rem;">
                                <div style="font-weight: 700; font-size: 1.15rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-main);" title="${mod.name}">${mod.name}</div>
                                ${mcVersionLabel ? `<span class="mcmm-ver-chip">MC ${mcVersionLabel}</span>` : ''}
                            </div>
                            <div style="font-size: 0.9rem; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">by ${mod.author}</div>
                        </div>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                            <div style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.4rem;">
                                <span class="material-symbols-outlined" style="font-size: 1rem;">download</span>
                                <span>${mod.downloads}</span>
                            </div>
                            ${needsUpdate
                    ? `<button class="mcmm-btn mcmm-btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.9rem; font-weight: 600;" onclick="event.stopPropagation(); installMod('${mod.id}', '${safeModName}', this)">Update</button>`
                    : (isInstalled
                        ? `<div style="display: flex; align-items: center; gap: 0.4rem; color: var(--success); font-weight: 700;">
                             <span class="material-symbols-outlined" style="font-size: 1.2rem;">check_circle</span>
                             <span>Installed</span>
                           </div>`
                        : `<button class="mcmm-btn mcmm-btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.9rem; font-weight: 600;" onclick="event.stopPropagation(); installMod('${mod.id}', '${safeModName}', this)">Install</button>`
                    )
                }
                        </div>
                    </div>
                    <div class="mcmm-checkbox"></div>
                </div>
            `;
        }
    }).join('');
}

function sortMods(list) {
    const items = Array.isArray(list) ? [...list] : [];
    const downloadsVal = (m) => {
        if (typeof m.downloadsRaw === 'number') return m.downloadsRaw;
        if (m.downloadsRaw) return Number(m.downloadsRaw) || 0;
        if (typeof m.downloads === 'string') {
            const n = parseInt(m.downloads.replace(/[^0-9]/g, ''), 10);
            return Number.isFinite(n) ? n : 0;
        }
        return 0;
    };

    if (modState.sort === 'name') {
        return items.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    }
    if (modState.sort === 'name_desc') {
        return items.sort((a, b) => (b.name || '').localeCompare(a.name || ''));
    }
    if (modState.sort === 'author') {
        return items.sort((a, b) => (a.author || '').localeCompare(b.author || ''));
    }
    if (modState.sort === 'downloads') {
        return items.sort((a, b) => downloadsVal(b) - downloadsVal(a));
    }
    // default: popular (downloads desc)
    return items.sort((a, b) => downloadsVal(b) - downloadsVal(a));
}

function renderModsPagination() {
    const bar = document.getElementById('modsPaginationBar');
    if (!bar) return;

    const page = modState.page || 1;
    const pageSize = modState.pageSize || 20;
    const total = Number.isFinite(parseInt(modState.total, 10)) ? parseInt(modState.total, 10) : 0;
    const totalPages = total > 0 ? Math.max(1, Math.ceil(total / pageSize)) : (modState.hasMore ? page + 1 : page);

    if (totalPages <= 1) {
        bar.innerHTML = '';
        return;
    }

    const buttons = [];
    const addBtn = (label, targetPage, disabled = false, active = false) => {
        buttons.push(`<button class="mcmm-page-btn${active ? ' active' : ''}" onclick="changeModsPage(${targetPage})" ${disabled ? 'disabled' : ''}>${label}</button>`);
    };

    addBtn('&laquo;', page - 1, modState.loading || page <= 1);

    const windowSize = 2;
    let start = Math.max(1, page - windowSize);
    let end = Math.min(totalPages, page + windowSize);

    if (page <= windowSize) end = Math.min(totalPages, windowSize * 2 + 1);
    if (page > totalPages - windowSize) start = Math.max(1, totalPages - (windowSize * 2));

    for (let i = start; i <= end; i++) {
        addBtn(i, i, modState.loading, i === page);
    }

    addBtn('&raquo;', page + 1, modState.loading || page >= totalPages);

    bar.innerHTML = buttons.join('');
}

function changeModsPage(page) { // eslint-disable-line no-unused-vars
    if (modState.loading) return;
    const target = parseInt(page, 10);
    if (!Number.isFinite(target) || target < 1) return;
    const totalPages = modState.total ? Math.max(1, Math.ceil(modState.total / (modState.pageSize || 20))) : null;
    if (totalPages && target > totalPages) return;
    if ((modState.page || 1) === target) return;
    modState.page = target;
    loadMods(modState.search || '');
}

// --- Version Selection State ---
let versionState = {
    modId: null,
    files: [],
    selectedFileId: null,
    btnElement: null,
    originalText: '',
    modIcon: ''
};

function openVersionSelect(modId, modName, btnElement) {
    versionState.modId = modId;
    versionState.btnElement = btnElement;
    versionState.selectedFileId = null;
    versionState.files = [];
    versionState.modIcon = '';

    if (btnElement) {
        versionState.originalText = btnElement.textContent;
    }

    // Try to capture mod icon for the header/thumb
    const list = modState.source === 'modrinth' ? modState.modrinth : modState.curseforge;
    const matchMod = list.find(m => String(m.id) === String(modId));
    if (matchMod && matchMod.icon) {
        versionState.modIcon = matchMod.icon;
    }

    // Build filter display
    const filterParts = [];
    if (modState.loader) filterParts.push(modState.loader.charAt(0).toUpperCase() + modState.loader.slice(1));
    if (modState.mcVersion) filterParts.push(modState.mcVersion);
    const filterText = filterParts.length ? ` (${filterParts.join(' ')})` : '';

    document.getElementById('versionSelectSubtitle').textContent = 'Available versions for ' + modName + filterText;
    document.getElementById('versionSelectModal').classList.add('open');
    document.getElementById('btnConfirmVersion').disabled = true;
    document.getElementById('versionListContainer').innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--text-secondary);"><div class="mcmm-spinner"></div><br>Loading versions...</div>';

    console.log(`Fetching versions for ${modName} with filters:`, { mcVersion: modState.mcVersion, loader: modState.loader });
    fetchVersions(modId);
}

function closeVersionSelect() {
    document.getElementById('versionSelectModal').classList.remove('open');
    versionState.modId = null;
    versionState.selectedFileId = null;
    versionState.btnElement = null;
}

async function fetchVersions(modId) {
    try {
        // If MC version/loader are still unknown, fetch fresh server_details once more
        if ((!modState.mcVersion || !modState.loader) && currentServerId) {
            try {
                const srvRes = await fetch('/plugins/mcmm/api.php?action=server_details&id=' + currentServerId);
                const srvData = await srvRes.json();
                if (srvData.success && srvData.data) {
                    if (!modState.mcVersion && srvData.data.mcVersion) modState.mcVersion = srvData.data.mcVersion;
                    if (!modState.loader && srvData.data.loader) modState.loader = srvData.data.loader;
                }
            } catch (e) { // eslint-disable-line no-unused-vars
                // ignore refresh errors
            }
        }

        const params = new URLSearchParams();
        params.set('mod_id', modId);
        if (modState.mcVersion) params.set('mc_version', modState.mcVersion);
        if (modState.loader) params.set('loader', modState.loader);
        if (modState.source) params.set('source', modState.source);
        if (currentServerId) params.set('server_id', currentServerId);

        const res = await fetch('/plugins/mcmm/api.php?action=mod_files&' + params.toString());
        const data = await res.json();

        if (data.success) {
            let files = data.data || [];

            // Helper to infer loader from file payload
            const inferLoader = (file) => {
                const versions = (file.gameVersions || []).map(v => String(v).toLowerCase());

                // Prioritize explicit property if available
                if (typeof file.modLoaderType !== 'undefined' && file.modLoaderType !== null) {
                    const map = { 1: 'forge', 2: 'cauldron', 3: 'liteloader', 4: 'fabric', 5: 'quilt', 6: 'neoforge' };
                    if (map[file.modLoaderType]) return map[file.modLoaderType];
                }

                if (versions.some(v => v.includes('neoforge'))) return 'neoforge';
                if (versions.some(v => v.includes('forge'))) return 'forge';
                if (versions.some(v => v.includes('fabric'))) return 'fabric';
                if (versions.some(v => v.includes('quilt'))) return 'quilt';

                return '';
            };

            // The backend filters by loader/version, but enforce client-side strictly (exact MC match)
            if (modState.mcVersion) {
                files = files.filter(file => {
                    const versions = file.gameVersions || [];
                    return versions.some(v => v === modState.mcVersion);
                });
            }
            if (modState.loader) {
                files = files.filter(file => {
                    const loader = inferLoader(file);
                    if (loader) return loader === modState.loader;
                    const versions = (file.gameVersions || []).map(v => String(v).toLowerCase());
                    return versions.some(v => v === modState.loader.toLowerCase());
                });
            }

            versionState.files = files;
            versionState.inferLoader = inferLoader;
            renderVersionList();
        } else {
            document.getElementById('versionListContainer').innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--danger);">Error: ${data.error}</div>`;
        }
    } catch (e) {
        document.getElementById('versionListContainer').innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--danger);">Error: ${e.message}</div>`;
    }
}

function renderVersionList() {
    const container = document.getElementById('versionListContainer');
    if (!versionState.files.length) {
        const filterParts = [];
        if (modState.loader) filterParts.push(modState.loader.charAt(0).toUpperCase() + modState.loader.slice(1));
        if (modState.mcVersion) filterParts.push(modState.mcVersion);
        const filterText = filterParts.length ? ` for ${filterParts.join(' ')}` : '';

        container.innerHTML = `
            <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                <div style="margin-bottom: 1rem; font-size: 1.1rem;">No compatible versions found${filterText}</div>
                <div style="font-size: 0.85rem; color: var(--text-muted);">This mod may not support ${filterParts.join(' ')} yet.</div>
            </div>`;
        return;
    }

    // Sort newest first by fileDate if present
    const sorted = [...versionState.files].sort((a, b) => {
        const da = a.fileDate ? new Date(a.fileDate).getTime() : 0;
        const db = b.fileDate ? new Date(b.fileDate).getTime() : 0;
        return db - da;
    });

    // Auto-select first item if none selected
    if (!versionState.selectedFileId && sorted.length > 0) {
        versionState.selectedFileId = sorted[0].id;
        const confirmBtn = document.getElementById('btnConfirmVersion');
        if (confirmBtn) confirmBtn.disabled = false;
    }

    container.innerHTML = sorted.map(file => {
        const isSelected = String(file.id) === String(versionState.selectedFileId);
        // Extract MC version (first match like 1.21.1)
        const mcVersion = (file.gameVersions || []).find(v => /^\d+\.\d+(\.\d+)?$/.test(v)) || 'Unknown';
        const loader = versionState.inferLoader ? versionState.inferLoader(file) : '';
        const loaderLabel = loader ? (loader.charAt(0).toUpperCase() + loader.slice(1)) : (modState.loader ? (modState.loader.charAt(0).toUpperCase() + modState.loader.slice(1)) : 'Unknown');

        const typeMap = { 1: { cls: 'success', label: 'Release' }, 2: { cls: 'warning', label: 'Beta' }, 3: { cls: 'danger', label: 'Alpha' } };
        const type = typeMap[file.releaseType] || { cls: 'muted', label: 'Other' };
        const displayName = file.displayName || file.fileName || 'Version';
        const fileLabel = file.fileName || displayName;
        const icon = versionState.modIcon;

        return `
            <div class="mcmm-version-card ${isSelected ? 'selected' : ''}" onclick="selectVersionFile('${file.id}')">
                <div class="mcmm-version-thumb" style="background-image: url('${icon || ''}');"></div>
                <div class="mcmm-version-body">
                    <div class="mcmm-version-top">
                        <div class="mcmm-version-name">${displayName}</div>
                    </div>
                    <div class="mcmm-version-meta">
                        <span class="mcmm-chip">MC ${mcVersion}</span>
                        <span class="mcmm-chip">Loader: ${loaderLabel}</span>
                        <span class="mcmm-chip" style="color: var(--primary-hover); border-color: var(--primary-dim);">Java ${pickJavaVersionLocal(mcVersion)}</span>
                        <span class="mcmm-chip subtle">${fileLabel}</span>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 0.8rem; margin-left: auto;">
                    <span class="mcmm-version-pill ${type.cls}">${type.label}</span>
                    <div class="mcmm-version-radio">
                        ${isSelected ? '<div class="dot"></div>' : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function selectVersionFile(fileId) {
    versionState.selectedFileId = fileId;
    renderVersionList();
    document.getElementById('btnConfirmVersion').disabled = false;
}

function confirmVersionInstall() {
    if (!versionState.selectedFileId || !versionState.modId) return;
    performModInstall(versionState.modId, versionState.selectedFileId, versionState.btnElement);
    closeVersionSelect();
}

async function installMod(modId, modNameOrBtn, btnElement) {
    if (!currentServerId) return;

    // Handle overload: if 2nd arg is object (button), then it's the old call or button passed directly
    // The render loop calls: installMod(id, this) -> installMod(id, btnElement)
    // Wait, the new logic needs mod name for subtitle.
    // I need to update renderMods to pass mod name too? Or just "Mod".
    // Let's check how it's called in renderMods:
    // installMod('${mod.id}', this) -> 2nd arg is button.

    let btn = btnElement;
    let name = 'Mod';
    const list = modState.source === 'modrinth' ? modState.modrinth : modState.curseforge;

    if (typeof modNameOrBtn === 'object') {
        // It's the button
        btn = modNameOrBtn;

        // Try to find name from mod list if possible
        const mod = list.find(m => String(m.id) === String(modId));
        if (mod) name = mod.name;
    } else if (typeof modNameOrBtn === 'string') {
        name = modNameOrBtn;
        btn = btnElement; // 3rd arg
    }

    openVersionSelect(modId, name, btn);
}

async function performModInstall(modId, fileId, btnElement) {
    if (!currentServerId) return;
    const btn = btnElement;
    let originalText = '';

    if (btn) {
        originalText = btn.textContent;
        btn.textContent = '...';
        btn.disabled = true;
    }

    try {
        // Find mod in current list to get metadata
        const list = modState.source === 'modrinth' ? modState.modrinth : modState.curseforge;
        const mod = list.find(m => String(m.id) === String(modId));

        const params = new URLSearchParams();
        params.set('action', 'mod_install');
        params.set('id', currentServerId);
        params.set('mod_id', modId);
        if (fileId) params.set('file_id', fileId);
        if (modState.source) params.set('source', modState.source);

        // Send metadata for persistence
        if (mod) {
            params.set('mod_name', mod.name);
            params.set('logo', mod.icon || mod.logo || '');
            params.set('author', mod.author || '');
            params.set('summary', mod.summary || '');
            params.set('downloads', mod.downloads || '');
            params.set('mc_version', mod.mcVersion || modState.mcVersion || '');
        }

        const res = await fetch('/plugins/mcmm/api.php?' + params.toString());
        const data = await res.json();
        if (data.success) {
            if (btn) {
                btn.textContent = 'Installed';
                btn.style.background = 'var(--success)';
                // Don't revert originalText instantly if we want it to stay "Installed"
                // But renderMods will handle the actual persistent state when called below
            }
            await loadInstalledMods(); // Refresh installed list
            // Force a re-render of the current view to show metadata/installed state
            renderMods();
        } else {
            alert('Error: ' + data.error);
            if (btn) {
                btn.textContent = 'Failed';
                btn.style.background = 'var(--danger)';
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.disabled = false;
                    btn.style.background = '';
                }, 3000);
            }
        }
    } catch (e) {
        alert('Error: ' + e.message);
        if (btn) {
            btn.textContent = originalText;
            btn.disabled = false;
        }
    }
}

async function deleteMod(fileName) {
    if (!currentServerId || !confirm('Delete ' + fileName + '?')) return;

    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=mod_delete&id=${currentServerId}&file=${encodeURIComponent(fileName)}`);
        const data = await res.json();
        if (data.success) {
            loadInstalledMods();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// --- Deploy Logic ---

async function openDeployModal(pack) {
    if (!pack) return;
    selectedModpack = pack;

    document.getElementById('deployTitle').textContent = 'Deploy ' + pack.name;
    document.getElementById('deploySubtitle').textContent = pack.author ? `by ${pack.author}` : '';

    document.getElementById('deploy_name').value = pack.name;

    // Reset version list
    const versionList = document.getElementById('deployVersionList');
    const versionStatus = document.getElementById('deployVersionStatus');
    const versionInput = document.getElementById('deploy_version');
    if (versionList) versionList.innerHTML = '<div style="color: var(--text-secondary);">Loading versions...</div>';
    if (versionStatus) versionStatus.textContent = 'Loading versions...';
    if (versionInput) versionInput.value = '';

    // Fetch Modpack versions and render as buttons
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=mod_files&mod_id=' + pack.id);
        const data = await res.json();

        if (data.success && data.data && data.data.length > 0) {
            renderDeployVersions(data.data);
        } else {
            renderDeployVersions([]);
        }
    } catch (e) {
        console.error('Failed to load modpack versions', e);
        renderDeployVersions([]);
    }

    // Load latest defaults from API so deploy reflects current settings
    const defaults = await loadSettingsDefaults();
    const cfg = defaults || (typeof mcmmConfig !== 'undefined' ? mcmmConfig : {});

    document.getElementById('deploy_port').value = cfg.default_port || 25565;
    document.getElementById('deploy_memory').value = cfg.default_memory || '4G';
    if (document.getElementById('deploy_max_players')) {
        document.getElementById('deploy_max_players').value = cfg.default_max_players || 20;
    }
    if (document.getElementById('deploy_ip')) {
        document.getElementById('deploy_ip').value = cfg.default_ip || '0.0.0.0';
    }
    document.getElementById('deploy_whitelist').value = cfg.default_whitelist || '';
    // Use modpack icon if available, otherwise use default
    document.getElementById('deploy_icon_url').value = pack.img || cfg.default_icon_url || '';

    setChecked('deploy_pvp', cfg.default_pvp);
    setChecked('deploy_hardcore', cfg.default_hardcore);
    setChecked('deploy_allow_flight', cfg.default_allow_flight);
    setChecked('deploy_command_blocks', cfg.default_command_blocks);
    setChecked('deploy_rolling_logs', cfg.default_rolling_logs);
    setChecked('deploy_log_timestamp', cfg.default_log_timestamp);
    setChecked('deploy_aikar_flags', cfg.default_aikar_flags);
    setChecked('deploy_meowice_flags', cfg.default_meowice_flags);
    setChecked('deploy_graalvm_flags', cfg.default_graalvm_flags);
    const jvmField = document.getElementById('deploy_jvm_flags');
    if (jvmField) jvmField.value = cfg.jvm_flags || '';

    setDeployStatus('');
    document.getElementById('deployModal').classList.add('open');
}

function closeDeploy() {
    document.getElementById('deployModal').classList.remove('open');
    selectedModpack = null;
    setDeployStatus('');
}

function setChecked(id, value) {
    const el = document.getElementById(id);
    if (el) el.checked = !!value;
}

async function loadSettingsDefaults() {
    if (settingsCache) return settingsCache;
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=settings');
        const data = await res.json();
        if (data && data.success && data.data) {
            // Normalize boolean-ish strings to real booleans
            const cfg = data.data;
            const boolKeys = [
                'default_pvp',
                'default_hardcore',
                'default_allow_flight',
                'default_command_blocks',
                'default_rolling_logs',
                'default_log_timestamp',
                'default_direct_console',
                'default_aikar_flags',
                'default_meowice_flags',
                'default_graalvm_flags'
            ];
            boolKeys.forEach(k => {
                if (k in cfg) {
                    const v = cfg[k];
                    if (typeof v === 'string') {
                        cfg[k] = v.toLowerCase() === 'true' || v === '1';
                    } else {
                        cfg[k] = !!v;
                    }
                }
            });
            settingsCache = cfg;
            return settingsCache;
        }
    } catch (e) {
        console.warn('Failed to load settings defaults', e);
    }
    return null;
}

function setDeployStatus(message, isError = false) {
    const el = document.getElementById('deployStatus');
    if (!el) return;
    if (!message) {
        el.style.display = 'none';
        return;
    }
    el.style.display = 'block';
    el.className = 'mcmm-status ' + (isError ? 'error' : 'success');
    el.textContent = message;
}

function setDeployConsole(lines) {
    const wrapper = document.getElementById('deployConsole');
    const textEl = document.getElementById('deployConsoleText');
    if (!wrapper || !textEl) return;
    if (lines && lines.length) {
        wrapper.style.display = 'block';
        textEl.textContent = Array.isArray(lines) ? lines.join('\n') : String(lines);
    } else {
        wrapper.style.display = 'none';
        textEl.textContent = '';
    }
}

// Deploy Progress helpers
function openDeployProgress(subtitle = 'Starting...') {
    document.getElementById('deployProgressSubtitle').textContent = subtitle;
    const steps = document.querySelectorAll('#deploySteps .deploy-step');
    steps.forEach((step, idx) => {
        const dot = step.querySelector('.step-dot');
        const status = step.querySelector('.step-status');
        if (idx === 0) {
            dot.style.background = 'var(--warning)';
            status.textContent = 'Pending';
            status.style.color = 'var(--text-secondary)';
        } else {
            dot.style.background = 'var(--border)';
            status.textContent = 'Pending';
            status.style.color = 'var(--text-secondary)';
        }
    });
    setDeployProgressConsole('Waiting for logs...');
    document.getElementById('deployProgressViewBtn').style.display = 'none';
    document.getElementById('deployProgressModal').classList.add('open');
}

function closeDeployProgress() {
    stopDeployLogPolling();
    document.getElementById('deployProgressModal').classList.remove('open');
}

function setDeployProgressConsole(text) {
    const el = document.getElementById('deployProgressConsoleText');
    const box = document.getElementById('deployProgressConsole');
    if (!el || !box) return;
    el.textContent = text;
    box.scrollTop = box.scrollHeight;
}

function updateDeployStep(stepKey, state, desc) {
    const step = document.querySelector(`#deploySteps .deploy-step[data-step="${stepKey}"]`);
    if (!step) return;
    const dot = step.querySelector('.step-dot');
    const status = step.querySelector('.step-status');
    if (desc) {
        const d = step.querySelector('.step-desc');
        if (d) d.textContent = desc;
    }
    if (state === 'active') {
        dot.style.background = 'var(--warning)';
        status.textContent = 'In progress';
        status.style.color = 'var(--warning)';
    } else if (state === 'success') {
        dot.style.background = 'var(--success)';
        status.textContent = 'Done';
        status.style.color = 'var(--success)';
    } else if (state === 'error') {
        dot.style.background = 'var(--danger)';
        status.textContent = 'Failed';
        status.style.color = 'var(--danger)';
    } else {
        dot.style.background = 'var(--border)';
        status.textContent = 'Pending';
        status.style.color = 'var(--text-secondary)';
    }
}

function startDeployLogPolling(containerId) {
    // deployLogContainerId = containerId;
    if (!containerId) return;
    stopDeployLogPolling();
    deployLogInterval = setInterval(async () => {
        try {
            const res = await fetch('/plugins/mcmm/api.php?action=console_logs&id=' + containerId);
            const data = await res.json();
            if (data.success) {
                const clean = (data.logs || '').replace(/\x1B\[[0-9;]*[a-zA-Z]/g, ''); // eslint-disable-line no-control-regex
                setDeployProgressConsole(clean || 'Waiting for logs...');
            }
        } catch (e) { // eslint-disable-line no-unused-vars
            // swallow errors during polling
        }
    }, 2000);
}

function stopDeployLogPolling() {
    if (deployLogInterval) clearInterval(deployLogInterval);
    deployLogInterval = null;
}

function finishDeployAndView() {
    stopDeployLogPolling();
    closeDeployProgress();
    // Switch to servers tab
    const tabEl = document.querySelector('.mcmm-tab:nth-child(1)');
    if (tabEl) tabEl.click();
    else {
        switchTab('servers');
        loadServers();
    }
}

async function loadServers() {
    const container = document.getElementById('tab-servers');
    if (!container) return;

    try {
        const res = await fetch('/plugins/mcmm/api.php?action=servers');
        const data = await res.json();

        console.group("%c MCMM %c Servers Dashboard Update", "background:#7c3aed;color:#fff;font-weight:700;padding:2px 6px;border-radius:4px;", "font-weight:700;");
        console.log("Status:", data.success ? "✅ Success" : "❌ Failed");

        if (data.data) {
            data.data.forEach(s => {
                const statusColor = s.isRunning ? "#10b981" : "#ef4444";
                const ramSource = s.ramDetails?.source || 'unavailable';
                console.groupCollapsed(`%c ${s.name} %c ${s.status} %c ${ramSource} `,
                    "background:#1e293b;color:#38bdf8;font-weight:700;padding:2px 4px;border-radius:4px 0 0 4px;",
                    `background:${statusColor};color:#fff;font-weight:700;padding:2px 4px;`,
                    "background:#64748b;color:#fff;font-size:0.7rem;padding:2px 4px;border-radius:0 4px 4px 0;"
                );
                console.log(`- Metrics: ${s.ramUsedMb}MB / ${s.ramLimitMb}MB (${s.ram}%) | CPU: ${s.cpu}%`);
                if (s.ramDetails) {
                    console.log("- RAM Details:", s.ramDetails);
                }
                if (s.debug) {
                    console.log("- Debug Diagnostics:");
                    console.table(s.debug);
                }
                console.groupEnd();
            });
        }
        console.groupEnd();

        if (data.success) {
            renderServers(data.data);
        }
    } catch (e) {
        console.error('Failed to load servers:', e);
    }
}

function renderServers(servers) {
    console.log("MCMM Rendering Servers:", servers);
    const container = document.getElementById('tab-servers');
    if (!container) return;

    if (!servers || servers.length === 0) {
        container.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.25rem;">Servers Status</h2>
                <button class="mcmm-btn mcmm-btn-primary" onclick="switchTab('catalog', document.querySelector('.mcmm-tab:nth-child(2)'))">
                    Deploy New Server
                </button>
            </div>
            <div class="mcmm-empty">
                <h3>No servers found</h3>
                <p>Get started by deploying your first modpack server.</p>
                <button class="mcmm-btn mcmm-btn-primary" onclick="switchTab('catalog', document.querySelector('.mcmm-tab:nth-child(2)'))">Browse Catalog</button>
            </div>
        `;
        return;
    }

    let html = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="font-size: 1.25rem;">Servers Status</h2>
            <button class="mcmm-btn mcmm-btn-primary" onclick="switchTab('catalog', document.querySelector('.mcmm-tab:nth-child(2)'))">
                Deploy New Server
            </button>
        </div>
        <div class="mcmm-server-list" id="serverListContainer">
    `;

    servers.forEach(server => {
        const icon = server.icon || "https://media.forgecdn.net/avatars/855/527/638260492657788102.png";
        const statusClass = server.isRunning ? 'running' : 'stopped';
        const playersOnline = server.players?.online || 0;
        const playersMax = server.players?.max || 0;

        const ramPercent = Math.min(Math.max(server.ram || 0, 0), 100);
        const ramUsedLabel = (server.ramUsedMb || 0) > 0 ? (server.ramUsedMb / 1024).toFixed(1) + ' GB' : '0 GB';
        const ramCapLabel = (server.ramLimitMb || 0) > 0 ? (server.ramLimitMb / 1024).toFixed(1) + ' GB' : 'N/A';
        const rssValue = (server.ramDetails?.rssMb || 0);
        const rssLabel = rssValue > 0 ? `<span style="opacity: 0.6; font-size: 0.75rem; margin-left: 4px;">RSS: ${(rssValue / 1024).toFixed(1)}GB</span>` : '';
        const cpuUsage = server.cpu || 0;

        html += `
            <div class="mcmm-server-row ${statusClass}">
                <div class="mcmm-server-icon" style="background-image: url('${icon}');"></div>
                
                <div class="mcmm-server-info">
                    <div class="mcmm-server-title">${server.name}</div>
                    <div class="mcmm-server-subtitle">
                        <span>Port: ${server.ports}</span>
                        <span style="opacity:0.5;">|</span>
                        <span>${server.isRunning ? 'Online' : 'Offline'}</span>
                        ${server.isRunning ? `
                            <span style="opacity:0.5;">|</span>
                            <span id="players-${server.id}" data-server-id="${server.id}" data-port="${server.ports}" data-running="1">
                                ${playersOnline} / ${playersMax > 0 ? playersMax : '?'} players
                            </span>
                        ` : ''}
                    </div>
                </div>
                
                <div class="mcmm-server-metrics">
                    <div class="mcmm-metric">
                        <div class="mcmm-metric-label">
                            <span>RAM</span>
                            <span>${ramUsedLabel} / ${ramCapLabel} ${rssLabel}</span>
                        </div>
                        <div class="mcmm-metric-bar">
                            <div class="mcmm-metric-fill" style="width: ${ramPercent}%; background: linear-gradient(90deg, #a855f7, #ec4899);"></div>
                        </div>
                    </div>
                    <div class="mcmm-metric">
                        <div class="mcmm-metric-label">
                            <span>CPU</span>
                            <span>${cpuUsage}%</span>
                        </div>
                        <div class="mcmm-metric-bar">
                            <div class="mcmm-metric-fill" style="width: ${cpuUsage}%; background: linear-gradient(90deg, #3b82f6, #06b6d4);"></div>
                        </div>
                    </div>
                </div>
                
                <div class="mcmm-server-actions">
                    ${server.isRunning ? `
                        <button class="mcmm-btn-icon danger" title="Stop Server" onclick="controlServer('${server.id}', 'stop')">⏹</button>
                        <button class="mcmm-btn-icon" title="Console" onclick="openConsole('${server.id}', '${server.name}')">_></button>
                        <button class="mcmm-btn-icon" title="Players" onclick="openPlayersModal('${server.id}', '${server.name}', '${server.ports}')">👥</button>
                    ` : `
                        <button class="mcmm-btn-icon success" title="Start Server" onclick="controlServer('${server.id}', 'start')">▶</button>
                        <button class="mcmm-btn-icon" style="opacity:0.5; cursor:not-allowed;" title="Console Offline">_></button>
                        <button class="mcmm-btn-icon" style="opacity:0.5; cursor:not-allowed;" title="Players Offline">👥</button>
                    `}
                    <button class="mcmm-btn-icon" title="Mods" onclick="openModManager('${server.id}', '${server.name}')">🧩</button>
                    <button class="mcmm-btn-icon" title="Backup" onclick="createBackup('${server.id}')">☁️</button>
                    <button class="mcmm-btn-icon" title="Settings" onclick="openServerSettings('${server.id}')">⚙️</button>
                    <button class="mcmm-btn-icon danger" title="Delete Server" onclick="deleteServer('${server.id}')">🗑️</button>
                </div>
            </div>
        `;
    });

    html += `
        </div>
        <div style="margin-top: 1rem;">
            <button class="mcmm-btn mcmm-btn-primary" onclick="startAgents()">Restart Metrics Agents</button>
        </div>
    `;

    container.innerHTML = html;
}

// Render Modpack version buttons in deploy modal
function renderDeployVersions(files) {
    const list = document.getElementById('deployVersionList');
    const status = document.getElementById('deployVersionStatus');
    const hidden = document.getElementById('deploy_version');
    if (!list || !status || !hidden) return;

    const fallbackIcon = 'https://media.forgecdn.net/avatars/855/527/638260492657788102.png';
    const packImg = (selectedModpack && (selectedModpack.img || selectedModpack.logo || selectedModpack.icon))
        || (document.getElementById('deploy_icon_url')?.value)
        || fallbackIcon;
    const safePackImg = String(packImg || '').replace(/'/g, "\\'");

    if (!files || files.length === 0) {
        list.innerHTML = '<div style="color: var(--text-secondary);">No specific versions found. Using latest available.</div>';
        status.textContent = 'Latest will be used.';
        hidden.value = '';
        return;
    }

    status.textContent = 'Select a version:';
    list.innerHTML = files.map((file, index) => {
        const isSelected = index === 0;
        const type = file.releaseType === 1 ? 'Release' : (file.releaseType === 2 ? 'Beta' : 'Alpha');
        const typeClass = file.releaseType === 1 ? 'release' : (file.releaseType === 2 ? 'beta' : 'alpha');
        const mcVersions = (file.gameVersions || []).filter(v => /^\d+\.\d+(\.\d+)?$/.test(v)).join(', ') || 'Unknown MC';

        // Detect Java version
        const firstMcVersion = (file.gameVersions || []).find(v => /^\d+\.\d+(\.\d+)?$/.test(v)) || '';
        const javaVer = pickJavaVersionLocal(firstMcVersion);

        return `
            <button class="mcmm-version-card ${typeClass} ${isSelected ? 'selected' : ''}" data-file-id="${file.id}" onclick="setDeployVersion('${file.id}', this)">
                <div class="mcmm-version-thumb" style="background-image: url('${safePackImg}');"></div>
                <div class="mcmm-version-main">
                    <div class="mcmm-version-top">
                        <div class="mcmm-version-name">${file.displayName}</div>
                    </div>
                    <div classmcmm-version-meta">
                        <span class="mcmm-chip subtle">MC ${mcVersions}</span>
                        <span class="mcmm-chip subtle" style="color: var(--primary-hover); border-color: var(--primary-dim);">Java ${javaVer}</span>
                    </div>
                </div>
                <div class="mcmm-version-select">
                    <span class="mcmm-chip ${typeClass}">${type}</span>
                    <span>${isSelected ? 'Selected' : 'Choose'}</span>
                </div>
            </button>
        `;
    }).join('');

    // Preselect first item
    const first = list.querySelector('button[data-file-id]');
    if (first) {
        setDeployVersion(first.getAttribute('data-file-id'), first);
    }
}

function pickJavaVersionLocal(mcVersion) {
    if (!mcVersion) return '17';
    const v = mcVersion.split('.').map(Number);
    if (v[0] === 1) {
        if (v[1] >= 20) return '21';
        if (v[1] >= 17) return '17';
        if (v[1] >= 16) return '11';
    }
    return '8';
}

function setDeployVersion(fileId, buttonEl) {
    const hidden = document.getElementById('deploy_version');
    const javaHidden = document.getElementById('deploy_java_version');
    if (hidden) hidden.value = fileId;

    // Extract metadata from button if present
    if (buttonEl) {
        if (javaHidden) {
            const javaChip = buttonEl.querySelector('.mcmm-chip.subtle[style*="var(--primary-hover)"]');
            if (javaChip) javaHidden.value = javaChip.textContent.replace('Java ', '');
        }
        const mcHidden = document.getElementById('deploy_mc_version');
        const loaderHidden = document.getElementById('deploy_loader');
        if (mcHidden) {
            const mcChip = buttonEl.querySelector('.mcmm-chip.subtle:nth-child(1)');
            if (mcChip) mcHidden.value = mcChip.textContent.replace('MC ', '');
        }
        if (loaderHidden) {
            // const loaderChip = buttonEl.querySelector('.mcmm-chip.subtle:nth-child(2)'); // unused
            // Let's use text content search
            const chips = Array.from(buttonEl.querySelectorAll('.mcmm-chip.subtle'));
            const lChip = chips.find(c => c.textContent.includes('Loader:'));
            if (lChip) loaderHidden.value = lChip.textContent.replace('Loader: ', '').toLowerCase();
        }
    }

    // Update selected styles
    const list = document.getElementById('deployVersionList');
    if (!list) return;
    list.querySelectorAll('button[data-file-id]').forEach(btn => {
        btn.classList.remove('selected');
        const label = btn.querySelector('.mcmm-version-select span:last-child');
        if (label) label.textContent = 'Choose';
    });
    if (buttonEl) {
        buttonEl.classList.add('selected');
        const label = buttonEl.querySelector('.mcmm-version-select span:last-child');
        if (label) label.textContent = 'Selected';
    }
}

async function submitDeploy() {
    if (!selectedModpack) return;
    setDeployStatus('Deploying server...', false);

    const payload = {
        modpack_id: selectedModpack.id,
        modpack_name: selectedModpack.name,
        modpack_author: selectedModpack.author || 'Unknown',
        modpack_slug: selectedModpack.slug || selectedModpack.name || '',
        modpack_file_id: document.getElementById('deploy_version').value || '',
        server_name: document.getElementById('deploy_name').value,
        port: parseInt(document.getElementById('deploy_port').value, 10),
        memory: document.getElementById('deploy_memory').value,
        max_players: parseInt(document.getElementById('deploy_max_players').value, 10),
        server_ip: document.getElementById('deploy_ip').value || '0.0.0.0',
        whitelist: document.getElementById('deploy_whitelist').value,
        icon_url: document.getElementById('deploy_icon_url').value || selectedModpack.img || '',
        pvp: document.getElementById('deploy_pvp').checked,
        hardcore: document.getElementById('deploy_hardcore').checked,
        allow_flight: document.getElementById('deploy_allow_flight').checked,
        command_blocks: document.getElementById('deploy_command_blocks').checked,
        rolling_logs: document.getElementById('deploy_rolling_logs').checked,
        log_timestamp: document.getElementById('deploy_log_timestamp').checked,
        aikar_flags: document.getElementById('deploy_aikar_flags').checked,
        meowice_flags: document.getElementById('deploy_meowice_flags').checked,
        graalvm_flags: document.getElementById('deploy_graalvm_flags').checked,
        java_version: document.getElementById('deploy_java_version')?.value || '',
        mc_version: document.getElementById('deploy_mc_version')?.value || '',
        loader: document.getElementById('deploy_loader')?.value || '',
        jvm_flags: document.getElementById('deploy_jvm_flags').value,
        csrf_token: typeof csrfToken !== 'undefined' ? csrfToken : ''
    };

    // Show progress UI
    openDeployProgress('Submitting deployment...');
    updateDeployStep('submit', 'active');

    // Use $.ajax form-urlencoded like saveSettings to avoid JSON parse issues on Unraid
    if (typeof $ !== 'undefined' && $.ajax) {
        $.ajax({
            url: '/plugins/mcmm/api.php?action=deploy',
            type: 'POST',
            data: payload,
            dataType: 'json',
            headers: {
                'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : ''
            },
            success: function (result) {
                setDeployConsole(result && result.output ? result.output : '');
                if (result && result.success) {
                    updateDeployStep('submit', 'success');
                    updateDeployStep('create', 'success');
                    updateDeployStep('start', 'active', 'Streaming logs...');
                    setDeployStatus('Deployment started successfully. Creating container...', false);
                    // Poll logs for new container
                    if (result.id) {
                        startDeployLogPolling(result.id);
                    }
                    // Show "View Server" button after a few seconds
                    document.getElementById('deployProgressViewBtn').style.display = 'inline-flex';
                    // Also close the small deploy modal
                    closeDeploy();
                } else {
                    let msg = (result && result.error) ? result.error : 'Deployment failed';
                    if (result && result.output && result.output.length) {
                        msg += '\n' + result.output.join('\n');
                    }
                    updateDeployStep('submit', 'error', msg);
                    updateDeployStep('create', 'error');
                    updateDeployStep('start', 'error');
                    setDeployStatus(msg, true);
                }
            },
            error: function (xhr) {
                let msg = 'Deployment failed';
                try {
                    const j = JSON.parse(xhr.responseText);
                    if (j.error) msg = j.error;
                    if (j.output && j.output.length) msg += '\n' + j.output.join('\n');
                    setDeployConsole(j.output || []);
                } catch (e) { // eslint-disable-line no-unused-vars
                    if (xhr.responseText) msg = xhr.responseText.slice(0, 200);
                    setDeployConsole(xhr.responseText);
                }
                updateDeployStep('submit', 'error', msg);
                updateDeployStep('create', 'error');
                updateDeployStep('start', 'error');
                setDeployStatus(msg, true);
            }
        });
    } else {
        // Fallback to fetch with manual JSON parse/diagnostics
        fetch('/plugins/mcmm/api.php?action=deploy', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : ''
            },
            body: JSON.stringify(payload)
        })
            .then(res => res.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) { // eslint-disable-line no-unused-vars
                    throw new Error('Server returned invalid JSON: ' + text.substring(0, 200));
                }
                if (!data.success) {
                    const extra = data.output && data.output.length ? '\n' + data.output.join('\n') : '';
                    throw new Error((data.error || 'Deployment failed') + extra);
                }
                updateDeployStep('submit', 'success');
                updateDeployStep('create', 'success');
                updateDeployStep('start', 'active', 'Streaming logs...');
                setDeployConsole(data.output || []);
                setDeployStatus('Deployment started successfully. Creating container...', false);
                if (data.id) {
                    startDeployLogPolling(data.id);
                }
                document.getElementById('deployProgressViewBtn').style.display = 'inline-flex';
                closeDeploy();
                setTimeout(() => {
                    closeDeploy();
                    // keep progress modal open until user closes or views server
                }, 1200);
            })
            .catch(err => {
                setDeployConsole(err.message);
                updateDeployStep('submit', 'error', err.message);
                updateDeployStep('create', 'error');
                updateDeployStep('start', 'error');
                setDeployStatus(err.message, true);
            });
    }
}

// --- Console ---
let consoleInterval;
let currentConsoleId = null;

function openConsole(serverId, serverName) {
    const modal = document.getElementById('consoleModal');
    const output = document.getElementById('consoleOutput');
    document.getElementById('consoleTitle').textContent = serverName + ' - Console';
    currentConsoleId = serverId;

    modal.classList.add('open');
    output.innerHTML = '<div style="color: #666; padding: 1rem;">Loading logs...</div>';

    fetchLogs();
    consoleInterval = setInterval(fetchLogs, 2000);

    document.getElementById('consoleInput').focus();
}

async function fetchLogs() {
    if (!currentConsoleId) return;
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=console_logs&id=' + currentConsoleId);
        const data = await res.json();
        if (data.success) {
            const output = document.getElementById('consoleOutput');
            const wasAtBottom = output.scrollTop + output.clientHeight >= output.scrollHeight - 50;

            // Clean up logs: Strip ANSI color codes
            // ANSI escape codes regex: /\x1B\[[0-9;]*[a-zA-Z]/g
            let cleanLogs = (data.logs || '').replace(/\x1B\[[0-9;]*[a-zA-Z]/g, ''); // eslint-disable-line no-control-regex

            output.textContent = cleanLogs;

            if (wasAtBottom) {
                output.scrollTop = output.scrollHeight;
            }
        }
    } catch (e) {
        console.error('Console fetch error:', e);
    }
}

function closeConsole() {
    document.getElementById('consoleModal').classList.remove('open');
    if (consoleInterval) clearInterval(consoleInterval);
    currentConsoleId = null;
}

// --- Players Modal ---
async function openPlayersModal(serverId, serverName, port) {
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
                    <div classmcmm-player-name">${name}</div>
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

function closePlayersModal() {
    const modal = document.getElementById('playersModal');
    if (modal) modal.classList.remove('open');
}

async function playerAction(serverId, playerName, action) { // eslint-disable-line no-unused-vars
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

// --- Server Control ---
function controlServer(id, action) {
    if (confirm(`Are you sure you want to ${action} this server?`)) {
        fetch('/plugins/mcmm/api.php?action=server_control&id=' + id + '&cmd=' + action)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    loadServers();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }
}

function deleteServer(id) {
    if (!confirm('Delete this server container?')) return;
    fetch('/plugins/mcmm/api.php?action=server_delete&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Server deleted');
                loadServers();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// --- Settings ---
function saveSettings(e) {
    e.preventDefault();

    const statusEl = document.getElementById('settingsStatus');
    const data = {
        curseforge_api_key: document.getElementById('curseforge_api_key').value,
        default_server_name: document.getElementById('default_server_name').value,
        default_port: parseInt(document.getElementById('default_port').value),
        default_memory: document.getElementById('default_memory').value,
        default_max_players: parseInt(document.getElementById('default_max_players').value),
        default_whitelist: document.getElementById('default_whitelist').value,
        default_icon_url: document.getElementById('default_icon_url').value,
        default_pvp: document.getElementById('default_pvp').checked,
        default_hardcore: document.getElementById('default_hardcore').checked,
        default_allow_flight: document.getElementById('default_allow_flight').checked,
        default_command_blocks: document.getElementById('default_command_blocks').checked,
        default_rolling_logs: document.getElementById('default_rolling_logs').checked,
        default_log_timestamp: document.getElementById('default_log_timestamp').checked,
        default_direct_console: document.getElementById('default_direct_console').checked,
        default_aikar_flags: document.getElementById('default_aikar_flags').checked,
        default_meowice_flags: document.getElementById('default_meowice_flags').checked,
        default_graalvm_flags: document.getElementById('default_graalvm_flags').checked,
        jvm_flags: document.getElementById('jvm_flags').value,
        csrf_token: typeof csrfToken !== 'undefined' ? csrfToken : ''
    };

    // Use $.ajax for Unraid compatibility if available, otherwise standard fetch
    if (typeof $ !== 'undefined' && $.ajax) {
        $.ajax({
            url: '/plugins/mcmm/api.php?action=save_settings',
            type: 'POST',
            data: data, // Pass object directly (form-urlencoded)
            // contentType: 'application/json', // Removed to allow form-urlencoded
            dataType: 'json',
            headers: {
                'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : ''
            },
            success: function (result) {
                // console.log("Save Response:", result); // Debug response
                handleSaveSuccess(result, statusEl);
            },
            error: function (xhr, status, error) {
                console.error("jQuery Ajax Error:", xhr.responseText);
                // Try to parse JSON error from response if possible
                let msg = error || "Request failed";
                try {
                    const json = JSON.parse(xhr.responseText);
                    if (json.error) msg = json.error;
                } catch (e) { // eslint-disable-line no-unused-vars
                }
                handleSaveError(new Error(msg), statusEl);
            },
        });
    } else {
        // Fallback to fetch
        fetch('/plugins/mcmm/api.php?action=save_settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : ''
            },
            body: JSON.stringify(data)
        })
            .then(res => res.text())
            .then(text => {
                let result;
                try {
                    result = JSON.parse(text);
                } catch (e) { // eslint-disable-line no-unused-vars
                    console.error('API Error (Non-JSON response):', text);
                    throw new Error('Server returned invalid JSON. Raw output: ' + text.substring(0, 100));
                }
                handleSaveSuccess(result, statusEl);
            })
            .catch(err => handleSaveError(err, statusEl));
    }
}

function handleSaveSuccess(result, statusEl) {
    if (typeof result === 'string') {
        console.error("API returned string instead of JSON:", result.substring(0, 200));
        handleSaveError(new Error("Invalid server response (HTML/String)"), statusEl);
        return;
    }

    statusEl.style.display = 'block';
    if (result && result.success) {
        statusEl.className = 'mcmm-status success';
        statusEl.textContent = 'Settings saved successfully!';
        setTimeout(() => statusEl.style.display = 'none', 5000);
    } else {
        statusEl.className = 'mcmm-status error';
        const errorMsg = (result && result.error) ? result.error : 'Unknown error (success=false)';
        statusEl.textContent = 'Error: ' + errorMsg;

        // Auto-fetch log if error is unknown
        // if (!result || !result.error) {
        //      fetch('/plugins/mcmm/api.php?action=get_log')
        //         .then(r => r.json())
        //         .then(d => {
        //             if(d.success) console.log("MCMM Log:\n" + d.log);
        //         });
        // }
    }
}

function handleSaveError(err, statusEl) {
    statusEl.style.display = 'block';
    statusEl.className = 'mcmm-status error';
    statusEl.textContent = 'Error: ' + err.message;

    // Auto-fetch log on error
    // fetch('/plugins/mcmm/api.php?action=get_log')
    //     .then(r => r.json())
    //     .then(d => {
    //         if(d.success) console.log("MCMM Log:\n" + d.log);
    //     });
}

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
    // const grid = document.getElementById('modList'); // unused

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

function clearQueue() {
    modState.selected.clear();
    renderMods();
    renderQueue();
}

async function startAgents() {
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=start_agents');
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) { // eslint-disable-line no-unused-vars
            console.error('Non-JSON response from start_agents:', text);
            alert('Error: start_agents did not return JSON. Check server logs.');
            return;
        }
        if (data.success) {
            alert('Metrics agents restarted for running servers.');
            await logRamDebug();
            setTimeout(() => loadServers(), 1000);
        } else {
            alert('Error: ' + (data.error || 'Failed to start agents'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function logRamDebug() {
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=servers&_=' + Date.now());
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) { // eslint-disable-line no-unused-vars
            console.error('Non-JSON response from servers:', text);
            return;
        }
        if (!data.success || !Array.isArray(data.data)) {
            console.error('Servers response error:', data.error || 'unknown', data);
            return;
        }
        data.data.forEach(s => {
            const d = s.ramDetails || {};
            const a = d.agent || {};
            const cg = d.cgroup || {};
            console.log(
                `[RAM DEBUG] ${s.name}: used=${s.ramUsedMb || 0} MB limit=${s.ramLimitMb || 0} MB pct=${s.ram || 0}% source=${d.source || 'n/a'} | agent exists=${a.exists ? 'yes' : 'no'} ageSec=${a.ageSec ?? 'n/a'} ts=${a.ts ?? 'n/a'} | cgroup used=${cg.memUsedMb ?? 'n/a'} cap=${cg.memCapMb ?? 'n/a'} cpu=${cg.cpuPercent ?? 'n/a'}`,
                d
            );
        });
    } catch (err) {
        console.error('Failed to fetch servers for RAM debug:', err);
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
    // let failCount = 0;

    // Process sequentially to avoid overwhelming server/API
    for (let i = 0; i < modsToInstall.length; i++) {
        const mod = modsToInstall[i];
        btn.textContent = `Installing ${i + 1}/${modsToInstall.length}...`;

        try {
            const params = new URLSearchParams();
            params.set('action', 'mod_install');
            params.set('id', currentServerId);
            params.set('mod_id', mod.id);
            if (modState.source) params.set('source', modState.source);
            // No file_id here; backend will pick latest compatible
            const res = await fetch('/plugins/mcmm/api.php?' + params.toString());
            const data = await res.json();

            if (data.success) {
                successCount++;
                // Mark as installed in UI immediately?
                // Ideally we update local state.
                const installedMod = { ...mod, file: mod.name + '.jar' }; // Rough approx until reload
                modState.installed.push(installedMod);
            } else {
                console.error(`Failed to install ${mod.name}: ${data.error}`);
                // failCount++;
            }
        } catch (e) {
            console.error(`Error installing ${mod.name}:`, e);
            // failCount++;
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
function toggleImageFit(checkbox) { // eslint-disable-line no-unused-vars
    const grid = document.getElementById('modpackGrid');
    if (checkbox.checked) {
        grid.classList.add('fit-images');
    } else {
        grid.classList.remove('fit-images');
    }
}

function setRam(amount) {
    const defaultInput = document.getElementById('default_memory');
    if (defaultInput) defaultInput.value = amount;
    document.querySelectorAll('.mcmm-ram-pill').forEach(p => {
        p.classList.toggle('active', p.textContent === amount);
    });
    const deployInput = document.getElementById('deploy_memory');
    if (deployInput) deployInput.value = amount;
}

function toggleSelect(id) {
    const select = document.getElementById(id);
    document.querySelectorAll('.mcmm-select').forEach(s => {
        if (s.id !== id) s.classList.remove('open');
    });
    select.classList.toggle('open');
}

function selectOption(selectId, value, text) {
    const select = document.getElementById(selectId);
    const trigger = select.querySelector('.mcmm-select-trigger');
    const targetId = select.dataset.target;
    const hidden = targetId ? document.getElementById(targetId) : (select.parentElement.querySelector('input[type="hidden"]') || select.querySelector('input[type="hidden"]'));

    trigger.textContent = text;
    if (hidden) hidden.value = value;

    select.querySelectorAll('.mcmm-option').forEach(o => o.classList.remove('selected'));
    if (typeof event !== 'undefined' && event.target) event.target.classList.add('selected');
    select.classList.remove('open');
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('.mcmm-select')) {
        document.querySelectorAll('.mcmm-select').forEach(s => s.classList.remove('open'));
    }
});

document.getElementById('consoleInput')?.addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
        const cmd = this.value;
        if (!cmd || !currentConsoleId) return;

        const inputField = this;
        inputField.disabled = true;

        // Optimistic UI update
        const output = document.getElementById('consoleOutput');
        output.textContent += `\n> ${cmd}\n`;
        output.scrollTop = output.scrollHeight;

        fetch('/plugins/mcmm/api.php?action=console_command&id=' + currentConsoleId + '&cmd=' + encodeURIComponent(cmd))
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    // Output usually comes back via logs, but we can show immediate response if any
                    if (d.message) output.textContent += d.message + '\n';
                } else {
                    output.textContent += 'Error: ' + (d.error || 'Command failed') + '\n';
                }
                output.scrollTop = output.scrollHeight;
            })
            .catch(err => {
                output.textContent += 'Error: ' + err.message + '\n';
            })
            .finally(() => {
                inputField.value = '';
                inputField.disabled = false;
                inputField.focus();
            });
    }
});

// --- Server Settings ---
async function openServerSettings(id) {
    const modal = document.getElementById('serverSettingsModal');
    const title = document.getElementById('serverSettingsTitle');
    document.getElementById('edit_server_id').value = id;
    modal.classList.add('open');
    title.textContent = 'Loading...';

    // Reset fields
    document.querySelectorAll('#serverSettingsModal input').forEach(i => {
        if (i.type === 'checkbox') i.checked = false;
        else if (i.type !== 'hidden') i.value = '';
    });

    try {
        const res = await fetch('/plugins/mcmm/api.php?action=server_details&id=' + id);
        const data = await res.json();

        if (!data.success) throw new Error(data.error);

        const d = data.data;
        const env = d.env || {};

        title.textContent = 'Settings: ' + d.name;

        document.getElementById('edit_port').value = d.port;
        document.getElementById('edit_memory').value = env.MEMORY || '4G';
        document.getElementById('edit_max_players').value = env.MAX_PLAYERS || (mcmmConfig.default_max_players || 20);
        document.getElementById('edit_ip').value = env.SERVER_IP || (mcmmConfig.default_ip || '0.0.0.0');

        document.getElementById('edit_whitelist').value = env.WHITELIST || '';
        document.getElementById('edit_icon_url').value = env.ICON || '';
        document.getElementById('edit_jvm_flags').value = env.JVM_OPTS || '';

        const iconPrev = document.getElementById('edit_icon_preview');
        if (iconPrev) {
            const imgUrl = env.ICON || '';
            if (imgUrl) {
                iconPrev.innerHTML = `<div style="width:48px; height:48px; border:1px solid var(--border); border-radius:10px; background-size:cover; background-position:center; background-image:url('${imgUrl}');"></div><div style="color: var(--text-secondary); font-size: 0.85rem; word-break: break-all;">${imgUrl}</div>`;
            } else {
                iconPrev.innerHTML = `<div style="width:48px; height:48px; border:1px solid var(--border); border-radius:10px; background: rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; color: var(--text-muted); font-size:0.8rem;">No Image</div><div style="color: var(--text-secondary); font-size: 0.85rem;">Preview</div>`;
            }
        }

        // Toggles (Env string 'TRUE' -> bool)
        const isTrue = val => val && val.toUpperCase() === 'TRUE';

        setChecked('edit_pvp', isTrue(env.PVP));
        setChecked('edit_hardcore', isTrue(env.HARDCORE));
        setChecked('edit_allow_flight', isTrue(env.ALLOW_FLIGHT));
        setChecked('edit_command_blocks', isTrue(env.ENABLE_COMMAND_BLOCK));
        setChecked('edit_rolling_logs', isTrue(env.ENABLE_ROLLING_LOGS));
        setChecked('edit_log_timestamp', isTrue(env.USE_LOG_TIMESTAMP));
        setChecked('edit_aikar_flags', isTrue(env.USE_AIKAR_FLAGS));

        // Meowice detection (simple)
        setChecked('edit_meowice_flags', (env.JVM_OPTS || '').includes('-XX:+UseZGC'));

        setChecked('edit_graalvm_flags', isTrue(env.USE_GRAALVM_JDK));

        // Store java version for submission check
        document.getElementById('edit_server_id').dataset.javaVersion = env.JAVA_VERSION_DETECTED || 'latest';

    } catch (e) {
        alert('Failed to load server details: ' + e.message);
        closeServerSettings();
    }
}

function closeServerSettings() {
    document.getElementById('serverSettingsModal').classList.remove('open');
}

async function submitServerSettings() {
    const id = document.getElementById('edit_server_id').value;
    const statusEl = document.getElementById('editStatus');

    if (!confirm('This will restart the server. Continue?')) return;

    statusEl.style.display = 'block';
    statusEl.className = 'mcmm-status';
    statusEl.textContent = 'Updating server...';

    const payload = {
        id: id,
        port: parseInt(document.getElementById('edit_port').value),
        env: {
            MEMORY: document.getElementById('edit_memory').value,
            MAX_PLAYERS: parseInt(document.getElementById('edit_max_players').value),
            SERVER_IP: document.getElementById('edit_ip').value || '0.0.0.0',
            WHITELIST: document.getElementById('edit_whitelist').value,
            ENABLE_WHITELIST: document.getElementById('edit_whitelist').value ? 'TRUE' : 'FALSE',
            ICON: document.getElementById('edit_icon_url').value,
            JVM_OPTS: document.getElementById('edit_jvm_flags').value,

            PVP: document.getElementById('edit_pvp').checked ? 'TRUE' : 'FALSE',
            HARDCORE: document.getElementById('edit_hardcore').checked ? 'TRUE' : 'FALSE',
            ALLOW_FLIGHT: document.getElementById('edit_allow_flight').checked ? 'TRUE' : 'FALSE',
            ENABLE_COMMAND_BLOCK: document.getElementById('edit_command_blocks').checked ? 'TRUE' : 'FALSE',
            ENABLE_ROLLING_LOGS: document.getElementById('edit_rolling_logs').checked ? 'TRUE' : 'FALSE',
            USE_LOG_TIMESTAMP: document.getElementById('edit_log_timestamp').checked ? 'TRUE' : 'FALSE',
            USE_AIKAR_FLAGS: document.getElementById('edit_aikar_flags').checked ? 'TRUE' : 'FALSE',
            USE_GRAALVM_JDK: document.getElementById('edit_graalvm_flags').checked ? 'TRUE' : 'FALSE'
        }
    };

    // Meowice logic
    if (document.getElementById('edit_meowice_flags').checked) {
        const javaVer = document.getElementById('edit_server_id').dataset.javaVersion || 'latest';
        if (javaVer !== '8') {
            if (!payload.env.JVM_OPTS.includes('-XX:+UseZGC')) {
                payload.env.JVM_OPTS = (payload.env.JVM_OPTS + ' -XX:+UseZGC').trim();
            }
        } else {
            // Guard: If Java 8, ensure ZGC is NOT in the string, maybe add G1GC
            payload.env.JVM_OPTS = payload.env.JVM_OPTS.replace('-XX:+UseZGC', '').trim();
            if (!payload.env.JVM_OPTS.includes('-XX:+UseG1GC')) {
                payload.env.JVM_OPTS = (payload.env.JVM_OPTS + ' -XX:+UseG1GC').trim();
            }
        }
    }

    // Use $.ajax form-urlencoded to avoid JSON parse errors on Unraid (same fix as save_settings)
    if (typeof $ !== 'undefined' && $.ajax) {
        $.ajax({
            url: '/plugins/mcmm/api.php?action=server_update',
            type: 'POST',
            data: payload,
            dataType: 'json',
            headers: {
                'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : ''
            },
            success: function (data) {
                if (data.success) {
                    statusEl.className = 'mcmm-status success';
                    statusEl.textContent = 'Server updated!';
                    setTimeout(() => {
                        closeServerSettings();
                        loadServers();
                    }, 1000);
                } else {
                    statusEl.className = 'mcmm-status error';
                    statusEl.textContent = 'Error: ' + (data.error || 'Update failed');
                }
            },
            error: function (xhr) {
                let msg = 'Update failed';
                try {
                    const j = JSON.parse(xhr.responseText);
                    if (j.error) msg = j.error;
                } catch (e) { // eslint-disable-line no-unused-vars
                    if (xhr.responseText) msg = xhr.responseText.slice(0, 200);
                }
                statusEl.className = 'mcmm-status error';
                statusEl.textContent = 'Error: ' + msg;
            }
        });
    } else {
        try {
            const res = await fetch('/plugins/mcmm/api.php?action=server_update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : ''
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                statusEl.className = 'mcmm-status success';
                statusEl.textContent = 'Server updated! Reloading...';
                setTimeout(() => location.reload(), 1500);
            } else {
                throw new Error(data.error);
            }
        } catch (e) {
            statusEl.className = 'mcmm-status error';
            statusEl.textContent = 'Error: ' + e.message;
        }
    }
}


// Init
document.addEventListener('DOMContentLoaded', function () {
    initServerPlayerCounts();
});

async function initServerPlayerCounts() {
    const spans = document.querySelectorAll('span[id^="players-"][data-server-id]');
    spans.forEach((span, idx) => {
        const running = span.getAttribute('data-running') === '1';
        if (!running) return;
        const serverId = span.getAttribute('data-server-id');
        const port = span.getAttribute('data-port') || '25565';
        // Stagger requests slightly
        setTimeout(() => refreshServerPlayerCount(span, serverId, port), idx * 150);
    });
}

async function refreshServerPlayerCount(span, serverId, port) {
    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=server_players&id=${encodeURIComponent(serverId)}&port=${encodeURIComponent(port)}`);
        const data = await res.json();
        if (data && data.success) {
            const online = data.data.online ?? 0;
            const max = data.data.max ?? '?';
            span.textContent = `${online} / ${max} players`;
        }
    } catch (exc) { // eslint-disable-line no-unused-vars
        // ignore failures
    }
}

async function loadBackups() {
    const container = document.getElementById('backups-list-container');
    if (!container) return;

    try {
        const res = await fetch('/plugins/mcmm/api.php?action=backups_list');
        const data = await res.json();
        if (data.success) {
            renderBackups(data.data);
        }
    } catch (e) {
        console.error('Failed to load backups:', e);
    }
}

function renderBackups(backups) {
    const container = document.getElementById('backups-list-container');
    if (!container) return;

    if (!backups || backups.length === 0) {
        container.innerHTML = `
            <div class="mcmm-empty">
                <span class="material-symbols-outlined" style="font-size: 3rem; opacity: 0.3; margin-bottom: 1rem;">backup</span>
                <h3>No backups found</h3>
                <p>Backups you create will appear here. You can reinstall a server to a previous state from these archives.</p>
            </div>
        `;
        return;
    }

    let html = '<div class="mcmm-modpack-grid">';
    backups.forEach(b => {
        const sizeMb = (b.size / (1024 * 1024)).toFixed(1);
        const dateObj = new Date(b.date * 1000);
        const dateStr = dateObj.toLocaleDateString();
        const timeStr = dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const iconUrl = b.icon || '';
        const serverName = b.server || 'Unknown';
        const modpackName = b.modpack || serverName;

        html += `
            <div class="mcmm-modpack-card" style="position: relative; height: 100%;">
                <div class="mcmm-backup-info-trigger" title="Technical Details">
                    <span class="material-symbols-outlined">info</span>
                    <div class="mcmm-backup-info-panel">
                        <div class="mcmm-backup-panel-item">
                            <span class="material-symbols-outlined">dns</span>
                            <span>${serverName}</span>
                        </div>
                        <div class="mcmm-backup-panel-item">
                            <span class="material-symbols-outlined">database</span>
                            <span>${sizeMb} MB</span>
                        </div>
                        <div class="mcmm-backup-panel-item">
                            <span class="material-symbols-outlined">schedule</span>
                            <span>${dateStr} ${timeStr}</span>
                        </div>
                    </div>
                </div>

                <div class="mcmm-modpack-thumb" style="background-image: url('${iconUrl}'); background-color: rgba(255,255,255,0.03); height: 180px !important;">
                    ${!iconUrl ? `<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-muted);"><span class="material-symbols-outlined" style="font-size: 2.5rem; opacity: 0.5;">inventory</span></div>` : ''}
                </div>
                <div class="mcmm-modpack-info">
                    <div class="mcmm-modpack-name" title="${modpackName}">${modpackName}</div>
                    <div class="mcmm-modpack-meta">
                        <span style="font-size: 0.75rem; opacity: 0.6;">by ${b.author || 'Unknown'}</span>
                        <span class="mcmm-backup-tag">BACKUP</span>
                    </div>
                </div>

                <div class="mcmm-backup-overlay">
                    <div class="mcmm-backup-actions">
                        <button class="mcmm-btn mcmm-btn-primary" style="flex: 1;" onclick="reinstallFromBackup('${b.name}')">Restore</button>
                        <button class="mcmm-btn" style="background: rgba(248, 113, 113, 0.1); color: var(--danger); border: 1px solid rgba(248, 113, 113, 0.2); padding: 0.6rem;" onclick="deleteBackup('${b.name}')">
                            <span class="material-symbols-outlined" style="font-size: 1.1rem;">delete</span>
                        </button>
                    </div>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

async function createBackup(serverId) {
    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=backup_create&id=${serverId}`);
        const data = await res.json();
        if (data.success) {
            alert('Backup created successfully!');
            if (document.getElementById('tab-backups').classList.contains('active')) {
                loadBackups();
            }
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Failed to trigger backup: ' + e.message);
    }
}

async function deleteBackup(name) {
    if (!confirm(`Are you sure you want to delete backup ${name}?`)) return;
    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=backup_delete&name=${encodeURIComponent(name)}`);
        const data = await res.json();
        if (data.success) {
            loadBackups();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Failed to delete backup: ' + e.message);
    }
}

async function reinstallFromBackup(name) {
    if (!confirm(`WARNING: This will replace the CURRENT server data with this backup. The existing data will be moved to a .reinstall_old folder. Proceed?`)) return;

    // Show loading state
    const container = document.getElementById('backups-list-container');
    const originalHtml = container.innerHTML;
    container.innerHTML = `
        <div class="mcmm-empty">
            <div class="mcmm-loader"></div>
            <h3>Reinstalling...</h3>
            <p>Please wait while we restore the world, mods, and container settings.</p>
        </div>
    `;

    try {
        const res = await fetch(`/plugins/mcmm/api.php?action=backup_reinstall&name=${encodeURIComponent(name)}`);
        const data = await res.json();
        if (data.success) {
            alert('Server reinstalled successfully!');
            switchTab('servers', document.querySelector('.mcmm-tab:first-child'));
        } else {
            alert('Error: ' + data.error);
            container.innerHTML = originalHtml;
        }
    } catch (e) {
        alert('Failed to reinstall: ' + e.message);
        container.innerHTML = originalHtml;
    }
}
