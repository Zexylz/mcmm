// MCMM Plugin Logic
/* global csrfToken, $, mcmmConfig */
/* exported switchTab, filterModpacks, openModManager, closeModManager, switchModTab, switchSource, clearModFilters, setModSort, filterMods, checkForUpdates, toggleModSelection, removeModFromQueue, clearQueue, installSelectedMods, setRam, toggleSelect, selectOption, controlServer, deleteServer, saveSettings, openServerSettings, closeServerSettings, submitServerSettings, closeDeployProgress, finishDeployAndView, openPlayersModal, closePlayersModal, playerAction, openConsole, closeConsole, changeModsPage, handleLogContextMenu, filterPlayers, copyToClipboard, refreshPlayers, whisperPlayer, switchPlayerTab, togglePasswordVisibility, openGlobalSettings, closeGlobalSettings, switchSettingsCategory */
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
window.handleLogContextMenu = handleLogContextMenu;
window.filterPlayers = filterPlayers;
window.copyToClipboard = copyToClipboard;
window.refreshPlayers = refreshPlayers;
window.whisperPlayer = whisperPlayer;
window.deleteMod = deleteMod;
window.toggleModSelection = toggleModSelection;
window.installSelectedMods = installSelectedMods;
window.removeModFromQueue = removeModFromQueue;
window.clearQueue = clearQueue;
window.openVersionSelect = openVersionSelect;
window.closeVersionSelect = closeVersionSelect;
window.confirmVersionInstall = confirmVersionInstall;
window.selectVersionFile = selectVersionFile;
window.playerAction = playerAction;
window.changeModsPage = changeModsPage;
window.startAgents = startAgents;
window.checkForUpdates = checkForUpdates;
window.switchPlayerTab = switchPlayerTab;

window.clearModFilters = clearModFilters;
window.setModSort = setModSort;
window.togglePasswordVisibility = togglePasswordVisibility;
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
window.openGlobalSettings = openGlobalSettings;
window.closeGlobalSettings = closeGlobalSettings;
window.switchSettingsCategory = switchSettingsCategory;

/**
 * Helper to perform fetch with CSRF token.
 * 
 * @param {string} url - The URL to fetch.
 * @param {Object} [options] - Fetch options.
 * @returns {Promise<Response>}
 */
async function mcmmFetch(url, options = {}) {
    const meta = document.querySelector('meta[name="csrf-token"]');
    const token = meta ? meta.content : '';

    const headers = options.headers || {};
    if (token) {
        headers['X-CSRF-Token'] = token;
    }

    return window.fetch(url, { ...options, headers });
}

/**
 * Opens the global settings modal and activates the first tab.
 *
 * @remarks Logs opening to console and initializes internal state.
 */
function openGlobalSettings() {
    console.log("MCMM: Opening Global Settings Modal...");
    const modal = document.getElementById('globalSettingsModal');
    if (!modal) {
        console.error("MCMM ERR: Modal 'globalSettingsModal' not found in DOM!");
        return;
    }
    modal.classList.add('open');
    const firstTab = document.querySelector('.mcmm-settings-sidebar .mcmm-side-link');
    if (firstTab) switchSettingsCategory('general', firstTab);
}

/**
 * Closes the global settings modal.
 */
function closeGlobalSettings() {
    const modal = document.getElementById('globalSettingsModal');
    if (modal) modal.classList.remove('open');
}

/**
 * Switches the active settings category in the global settings modal.
 *
 * @param {string} categoryId - The ID of the category to switch to.
 * @param {HTMLElement} btn - The button element that was clicked.
 */
function switchSettingsCategory(categoryId, btn) {
    document.querySelectorAll('.mcmm-side-link').forEach(l => l.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.mcmm-settings-pane').forEach(p => p.classList.remove('active'));
    const pane = document.getElementById('settings-' + categoryId);
    if (pane) pane.classList.add('active');
    const container = document.getElementById('settingsCategoryContainer');
    if (container) container.scrollTop = 0;
}

// Hide debug banner
document.addEventListener('DOMContentLoaded', () => {
    const banner = document.getElementById('mcmm-debug-banner');
    if (banner) {
        banner.textContent = 'MCMM UI loaded';
        setTimeout(() => banner.remove(), 1200);
    }

    // Initial load - try to hydrate from cache for instant feel
    const cached = localStorage.getItem('mcmm_servers_cache');
    if (cached) {
        try {
            const data = JSON.parse(cached);
            if (data && data.length > 0) {
                console.log("%c MCMM %c Hydrating from cache...", "background:rgb(124 58 237 / 100%);color:rgb(255 255 255 / 100%);font-weight:700;padding:2px 6px;border-radius:4px;", "");
                renderServers(data);
            }
        } catch (err) {
            console.warn('MCMM: Failed to hydrate from cache', err);
        }
    }

    if (document.getElementById('tab-servers')?.classList.contains('active')) {
        loadServers();
        if (!serverRefreshInterval) {
            serverRefreshInterval = setInterval(loadServers, 3000);
        }
    }

    // --- Interactive Hover Effects ---
    document.addEventListener('mousemove', e => {
        const panels = document.querySelectorAll('.mcmm-panel, .mcmm-version-card');
        for (const panel of panels) {
            const rect = panel.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            panel.style.setProperty('--mouse-x', `${x}px`);
            panel.style.setProperty('--mouse-y', `${y}px`);
        }
    });

    // --- ESC Key to Close Modals ---
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (document.getElementById('versionSelectModal')?.classList.contains('open')) {
                closeVersionSelect();
            } else if (document.getElementById('globalSettingsModal')?.classList.contains('open')) {
                closeGlobalSettings();
            } else if (document.getElementById('modManagerModal')?.classList.contains('open')) {
                closeModManager();
            } else if (document.getElementById('deployModal')?.classList.contains('open')) {
                closeDeploy();
            } else if (document.getElementById('deployProgressModal')?.classList.contains('open')) {
                closeDeployProgress();
            } else if (document.getElementById('consoleModal')?.classList.contains('open')) {
                closeConsole();
            } else if (document.getElementById('playersModal')?.classList.contains('open')) {
                closePlayersModal();
            } else if (document.getElementById('serverSettingsModal')?.classList.contains('open')) {
                closeServerSettings();
            }
        }
    });
});

// Global error handler for debugging
window.addEventListener('error', function (event) {
    console.error("%c MCMM Runtime Error: ", "background:rgb(239  68  68 / 100%);color:rgb(255 255 255 / 100%);font-weight:700;padding:2px 6px;border-radius:4px;", event.message, "at", event.filename, ":", event.lineno);
});

var modpackState = { items: [], loading: false, error: '', source: 'curseforge', sort: 'popularity', page: 1, limit: 12 };
var modpackSearchTimer;

window.switchModpackSource = switchModpackSource;
window.changeModpackPage = changeModpackPage;
window.loadModpacks = loadModpacks;
window.filterModpacks = filterModpacks;
var selectedModpack = null;
var modpacksLoaded = false;
var settingsCache = null;

// Mod Manager State
var currentServerId = null;
var modState = {
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
var modSearchTimer;
var serverRefreshInterval = null;
var consoleInterval = null;

// Deploy progress state
var deployLogInterval = null;
// let deployLogContainerId = null;

/**
 * Starts the global polling for server status updates.
 */
/**
 * Starts the global polling for server status updates.
 */
function startGlobalPolling() {
    if (!serverRefreshInterval) {
        // Initial load
        loadServers();
        serverRefreshInterval = setInterval(() => {
            // Throttle when tab is hidden to save resources
            if (document.hidden) {
                // Poll every ~10 seconds instead of 1s in background
                if (Date.now() % 10000 < 1000) {
                    loadServers();
                }
            } else {
                loadServers();
            }
        }, 1000);
    }
}

// Tab Switching
/**
 * Switches between main application tabs.
 *
 * @param {string} tabId - The ID of the tab to switch to.
 * @param {HTMLElement} [element] - Optional element that triggered the switch.
 */
function switchTab(tabId, element) {
    const tabElement = element || document.getElementById('tab-main-' + tabId);
    if (tabElement) {
        // Only clear active state for tabs in the same container
        const container = tabElement.closest('.mcmm-tabs') || document.querySelector('.mcmm-tabs');
        if (container) {
            container.querySelectorAll('.mcmm-tab').forEach(t => t.classList.remove('active'));
        }
        tabElement.classList.add('active');
    }

    // Switch content
    document.querySelectorAll('.mcmm-tab-content').forEach(c => c.classList.remove('active'));
    const content = document.getElementById('tab-' + tabId);
    if (content) content.classList.add('active');

    if (tabId === 'catalog' && !modpackState.loading && !modpacksLoaded) {
        modpacksLoaded = true;
        loadModpacks(document.getElementById('modpackSearch')?.value || '');
    }

    if (tabId === 'servers') {
        loadServers();
        startGlobalPolling();
    } else {
        // We still want basic server status updates even if not on main tab, mostly for toasts/background tasks
        // But for per-second updates, we might throttle.
        // For now, let's keep the global polling running but it will handle throttling.
        startGlobalPolling();
    }

    if (tabId === 'backups') {
        loadBackups();
    }
}

// --- Modpack Logic ---

/**
 * Renders the list of modpacks into the catalog grid.
 *
 * @param {Array} data - The modpack data objects to render.
 */
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

    // Add Vanilla card as the first option if search is empty
    const searchVal = document.getElementById('modpackSearch')?.value || '';
    if (!searchVal || searchVal.trim() === '') {
        const vanillaCard = document.createElement('div');
        vanillaCard.className = 'mcmm-modpack-card vanilla-card';
        vanillaCard.style.border = '1px dashed var(--primary)';
        vanillaCard.onclick = () => openVanillaDeploy();
        vanillaCard.innerHTML = `
            <div class="mcmm-modpack-thumb" style="background-image: url('/plugins/mcmm/images/vanilla-logo.png'); background-size: cover; background-position: center; background-color: rgb(0 0 0 / 20%);"></div>
            <div class="mcmm-modpack-info">
                 <div class="mcmm-modpack-name">Vanilla Minecraft</div>
                 <div class="mcmm-modpack-meta">
                     <span>Standard Server</span>
                     <span class="mcmm-tag" style="background: var(--primary-dim); color: var(--primary-hover);">Official</span>
                  </div>
                 <div class="mcmm-modpack-desc" style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.5rem;">Pure Minecraft experience. No mods, just blocks.</div>
            </div>
        `;
        grid.appendChild(vanillaCard);
    }

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

    // Update pagination buttons
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    const pageNum = document.getElementById('currentPageNum');

    if (prevBtn) prevBtn.disabled = modpackState.page <= 1;
    if (pageNum) pageNum.textContent = modpackState.page;
    if (nextBtn) nextBtn.disabled = data.length < modpackState.limit;
}

/**
 * Switches the source provider for modpacks (e.g., CurseForge, FTB, Modrinth).
 *
 * @param {string} source - The source provider name.
 * @param {HTMLElement} btn - The button element that was clicked.
 */
function switchModpackSource(source, btn) {
    modpackState.source = source;
    modpackState.page = 1;

    // UI update for tabs
    const container = btn.closest('.mcmm-source-tabs');
    if (container) {
        container.querySelectorAll('.mcmm-source-tab').forEach(t => t.classList.remove('active'));
    }
    btn.classList.add('active');

    loadModpacks(document.getElementById('modpackSearch').value);
}

/**
 * Changes the current page of the modpack catalog.
 *
 * @param {number} delta - The number of pages to move (positive or negative).
 */
function changeModpackPage(delta) {
    modpackState.page = Math.max(1, modpackState.page + delta);
    loadModpacks(document.getElementById('modpackSearch').value);

    // Scroll back to top of grid
    document.getElementById('modpackGrid').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/**
 * Loads modpacks from the API based on current state (source, search, page).
 *
 * @param {string} [query=''] - Optional search query.
 * @returns {Promise<void>}
 */
async function loadModpacks(query = '') {
    // If not FTB, we check for API key (Modrinth also doesn't need key)
    if (modpackState.source === 'curseforge' && (typeof mcmmConfig !== 'undefined' && !mcmmConfig.has_api_key)) {
        renderModpacks([]);
        return;
    }

    modpackState.loading = true;
    modpackState.error = '';

    // Set sort from UI
    modpackState.sort = document.getElementById('modpackSort')?.value || 'popularity';

    renderModpacks(modpackState.items);

    try {
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=modpacks&source=${modpackState.source}&search=${encodeURIComponent(query)}&sort=${modpackState.sort}&page=${modpackState.page}&page_size=${modpackState.limit}`);
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

/**
 * Filters the modpack catalog based on search input with debouncing.
 */
function filterModpacks() {
    const query = document.getElementById('modpackSearch').value;
    clearTimeout(modpackSearchTimer);
    modpackSearchTimer = setTimeout(() => {
        modpackState.page = 1;
        loadModpacks(query);
    }, 500);
}

// --- Mod Manager Logic ---

/**
 * Opens the mod manager modal for a specific server.
 *
 * @param {string} serverId - The unique ID of the server.
 * @param {string} serverName - The display name of the server.
 * @returns {Promise<void>}
 */
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
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=server_details&id=' + serverId);
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

/**
 * Closes the mod manager modal.
 */
function closeModManager() {
    document.getElementById('modManagerModal').classList.remove('open');
    currentServerId = null;
}

/**
 * Switches the active view/tab in the mod manager.
 *
 * @param {string} view - The view to switch to ('all', 'installed', 'updates').
 * @param {HTMLElement} btn - The button element that was clicked.
 */
function switchModTab(view, btn) {
    modState.view = view;
    document.querySelectorAll('#modManagerModal .mcmm-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    renderMods();
    if (view === 'installed' || view === 'updates') {
        checkForUpdates();
    }
}

/**
 * Switches the mod source provider in the mod manager.
 *
 * @param {string} source - The source provider name ('curseforge', 'modrinth').
 * @param {HTMLElement} btn - The button element that was clicked.
 */
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

/**
 * Clears the active version filter in the mod manager.
 */
function clearModFilters() {
    modState.mcVersion = '';
    loadMods(modState.search);
}

/**
 * Sets the sort order for search results in the mod manager.
 *
 * @param {string} value - The sort field/order.
 */
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

/**
 * Filters the mod list or triggers a new search based on current search input.
 */
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

/**
 * Loads mods from the API based on current search, filters, and pagination.
 *
 * @param {string} query - The search query term.
 * @returns {Promise<void>}
 */
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

        const res = await mcmmFetch(url);
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

/**
 * Checks for available updates for installed mods on the current server.
 *
 * @returns {Promise<void>}
 */
async function checkForUpdates() {
    if (!currentServerId) return;
    modState.loading = true;
    renderMods();

    try {
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=check_updates&id=${currentServerId}`);
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

/**
 * Loads the list of installed mods for the current server.
 *
 * @remarks Triggers identification for unknown mods in parallel batches.
 * @returns {Promise<void>}
 */
async function loadInstalledMods() {
    if (!currentServerId) return;
    try {
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=mod_list&id=' + currentServerId);
        const data = await res.json();
        if (data.success) {
            modState.installed = data.data || [];

            // Trigger identification for unknown mods in CHUNKS
            const unknownMods = modState.installed.filter(m => m.needsIdentification).map(m => m.file);
            if (unknownMods.length > 0) {
                console.log(`[MCMM] Identifying ${unknownMods.length} mods...`);
                // Batch in chunks of 20 with minimal delay for speed
                // Parallel batch processing for maximum speed
                const chunkSize = 8;
                const chunks = [];
                for (let i = 0; i < unknownMods.length; i += chunkSize) {
                    chunks.push(unknownMods.slice(i, i + chunkSize));
                }

                // Process k chunks at a time (Concurrency)
                const concurrency = 4;

                // Show floating progress badge
                renderScanningBadge(0, unknownMods.length);

                for (let i = 0; i < chunks.length; i += concurrency) {
                    const batch = chunks.slice(i, i + concurrency);
                    // Fire requests in parallel
                    await Promise.all(batch.map(chunk => identifyModsBatch(chunk)));

                    const processedCount = Math.min((i + concurrency) * chunkSize, unknownMods.length);
                    console.log(`[MCMM] Progress: ${processedCount} / ${unknownMods.length}`);
                    updateScanningBadge(processedCount, unknownMods.length);

                    // Tiny yield to let UI breathe, but essentially zero delay
                    await new Promise(r => setTimeout(r, 10));
                }
                removeScanningBadge();
            }

            if (modState.view === 'installed') renderMods();
        }
    } catch (e) {
        console.error(e);
        removeScanningBadge();
    }
}

/**
 * Renders or updates the mod scanning progress badge.
 *
 * @param {number} current - The number of mods currently scanned.
 * @param {number} total - The total number of mods to scan.
 */
function renderScanningBadge(current, total) {
    let badge = document.getElementById('mcmm-scan-badge');
    if (!badge) {
        badge = document.createElement('div');
        badge.id = 'mcmm-scan-badge';
        Object.assign(badge.style, {
            position: 'fixed',
            bottom: '2rem',
            right: '2rem',
            padding: '1rem 1.5rem',
            background: 'rgba(23, 23, 23, 0.8)',
            backdropFilter: 'blur(12px)',
            '-webkit-backdrop-filter': 'blur(12px)',
            border: '1px solid rgba(255, 255, 255, 0.1)',
            borderRadius: '16px',
            color: 'white',
            boxShadow: '0 8px 32px rgba(0, 0, 0, 0.4)',
            zIndex: '9999',
            display: 'flex',
            flexDirection: 'column',
            gap: '0.5rem',
            fontFamily: "'Inter', sans-serif",
            transition: 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
            opacity: '0',
            transform: 'translateY(20px)'
        });

        badge.innerHTML = `
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div class="mcmm-scan-spinner" style="width: 20px; height: 20px; border: 2px solid rgba(255,255,255,0.1); border-top-color: #a855f7; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                <div style="font-weight: 600; font-size: 0.95rem;">Identifying Mods</div>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.8rem; color: rgba(255,255,255,0.7);">
                <span id="mcmm-scan-text">Initializing...</span>
                <span id="mcmm-scan-percent">0%</span>
            </div>
            <div style="width: 200px; height: 6px; background: rgba(255,255,255,0.1); border-radius: 3px; overflow: hidden; margin-top: 0.2rem;">
                <div id="mcmm-scan-bar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #a855f7, #ec4899); transition: width 0.3s ease;"></div>
            </div>
        `;
        document.body.appendChild(badge);

        // Trigger animation
        requestAnimationFrame(() => {
            badge.style.opacity = '1';
            badge.style.transform = 'translateY(0)';
        });
    }
    updateScanningBadge(current, total);
}

/**
 * Updates the scanning progress bar and text.
 *
 * @param {number} current - Number of items processed.
 * @param {number} total - Total items to process.
 */
function updateScanningBadge(current, total) {
    const badge = document.getElementById('mcmm-scan-badge');
    if (!badge) return;

    const percent = Math.round((current / total) * 100);
    const textEl = document.getElementById('mcmm-scan-text');
    const percentEl = document.getElementById('mcmm-scan-percent');
    const barEl = document.getElementById('mcmm-scan-bar');

    if (textEl) textEl.textContent = `${current} / ${total} scanned`;
    if (percentEl) percentEl.textContent = `${percent}%`;
    if (barEl) barEl.style.width = `${percent}%`;
}

/**
 * Removes the scanning progress badge from the UI with an animation.
 */
function removeScanningBadge() {
    const badge = document.getElementById('mcmm-scan-badge');
    if (badge) {
        badge.style.opacity = '0';
        badge.style.transform = 'translateY(20px)';
        setTimeout(() => badge.remove(), 350);
    }
}

/**
 * Identifies a batch of mod filenames by calling the API.
 *
 * @param {Array<string>} filenames - List of filenames to identify.
 * @returns {Promise<void>}
 */
async function identifyModsBatch(filenames) {
    if (!currentServerId || !filenames.length) return;
    try {
        const queries = `&files=${encodeURIComponent(JSON.stringify(filenames))}`;
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=mod_identify_batch&id=${currentServerId}${queries}`, {
            method: 'GET'
        });
        const data = await res.json();
        if (data.success && data.data) {
            console.log(`[MCMM] Successfully identified batch of ${Object.keys(data.data).length} mods`);
            // Update the state
            modState.installed = modState.installed.map(m => {
                const identified = data.data[m.file];
                if (identified) {
                    return {
                        ...m,
                        ...identified,
                        needsIdentification: false
                    };
                }
                return m;
            });
            if (modState.view === 'installed') renderMods();
        }
    } catch (e) {
        console.error(`[MCMM] Failed to identify mods batch:`, e);
    }
}


/**
 * Renders the current list of mods based on the active view and filters.
 */
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
    } else if (modState.view === 'installed' || modState.view === 'updates') {
        items = modState.installed;

        // Search filtering checks
        if (modState.search) {
            const q = modState.search.toLowerCase();
            items = items.filter(m => (m.name || '').toLowerCase().includes(q) || (m.fileName || '').toLowerCase().includes(q));
        }

        // Filter for Update view
        if (modState.view === 'updates') {
            items = items.filter(installedMod => {
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
                        <div style="font-weight: 600; font-size: 1.25rem; color: var(--text-main); margin-bottom: 0.5rem;">Everything is up to date</div>
                        <div style="font-size: 0.95rem; margin-bottom: 2rem; opacity: 0.7;">
                            No updates found for your installed mods.
                        </div>
                        <button class="mcmm-btn mcmm-btn-primary" style="margin: 0 auto; padding: 0.75rem 2rem;" onclick="checkForUpdates()">
                            Check Again
                        </button>
                    </div>`;
                return;
            }
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
            const remoteLatestFileId = mod.latestFileId || (installedMod ? installedMod.latestFileId : null);
            const remoteLatestFileName = mod.latestFileName || (installedMod ? installedMod.latestFileName : null);

            if (installedMod) {
                const installedFileId = installedMod.fileId || '';
                const installedFileName = installedMod.fileName || installedMod.file || '';

                if (remoteLatestFileId && installedFileId) {
                    needsUpdate = String(remoteLatestFileId) !== String(installedFileId);
                } else if (remoteLatestFileName && installedFileName) {
                    needsUpdate = remoteLatestFileName !== installedFileName;
                }
            }
        }

        const safeModName = (mod.name || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');

        // Custom Render for Installed / Updates
        if (modState.view === 'installed' || modState.view === 'updates') {
            const iconUrl = mod.logo || mod.icon || '';
            const author = mod.author || 'Unknown';
            const size = mod.size || '';
            const mcVer = mod.mcVersion || '';
            const isUpdateAvailable = needsUpdate;
            const isUpdatesTab = modState.view === 'updates';

            // Visual Style: Only emphasized if we are in Updates tab
            const cardStyle = isUpdatesTab
                ? 'border: 1px solid var(--primary); background: rgba(124, 58, 237, 0.05);'
                : 'border: 1px solid transparent; background: var(--card-bg);';

            return `
                <div class="mcmm-modpack-card" style="display: flex; align-items: center; gap: 1.25rem; padding: 1.25rem; min-height: 90px; ${cardStyle}">
                    <div class="mcmm-mod-icon" style="background-image: url('${iconUrl}'); flex-shrink: 0;">
                        ${!iconUrl ? '<span>📦</span>' : ''}
                    </div>
                    
                    <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center;">
                        <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.1rem;">
                            <div style="font-weight: 700; font-size: 1rem; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${mod.name}">
                                ${mod.name}
                            </div>
                            ${mcVer ? `<span class="mcmm-ver-chip" style="font-size: 0.75rem; padding: 0.1rem 0.4rem;">${mcVer}</span>` : ''}
                        </div>
                        <div style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.6rem;">
                            <span>by ${author}</span>
                            ${size ? `<span style="width: 4px; height: 4px; background: var(--text-muted); border-radius: 50%;"></span><span>${size}</span>` : ''}
                            
                            ${isUpdateAvailable
                    ? `<span style="margin-left: 0.5rem; color: var(--warning); font-weight: 700; display: flex; align-items: center; gap: 0.3rem; ${isUpdatesTab ? 'background: rgba(234, 179, 8, 0.1); padding: 0.1rem 0.5rem; border-radius: 4px;' : ''}"><span class="material-symbols-outlined" style="font-size: 1rem;">update</span> Update Available</span>`
                    : `<span style="margin-left: 0.5rem; color: var(--success); font-weight: 600; display: flex; align-items: center; gap: 0.3rem;"><span class="material-symbols-outlined" style="font-size: 1rem;">check</span> Installed</span>`}
                        </div>
                    </div>
                    
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        ${isUpdatesTab ? `
                            <button class="mcmm-btn-icon success" style="width: 140px; height: 40px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; gap: 0.4rem;" onclick="installMod('${mod.modId || mod.id}', '${safeModName}', this)">
                                <span class="material-symbols-outlined">download</span> Update
                            </button>
                        ` : ''}
                        
                        ${!isUpdatesTab && isUpdateAvailable ? `
                             <button class="mcmm-btn-icon" style="width: 40px; height: 40px; border-radius: 8px; color: var(--warning);" title="Go to Updates" onclick="switchModTab('updates')">
                                <span class="material-symbols-outlined">arrow_forward</span>
                            </button>
                        ` : ''}

                        <button class="mcmm-btn-icon danger" style="width: 40px; height: 40px; border-radius: 8px;" title="Delete Mod" onclick="deleteMod('${mod.fileName || mod.name || mod.file}')">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </div>
            `;
        } else {
            // Checkbox selected state
            const isSelected = modState.selected.has(String(mod.id));
            const selectedClass = isSelected ? 'selected' : '';
            const mcVersionLabel = mod.mcVersion || modState.mcVersion || '';

            return `
                <div class="mcmm-modpack-card ${selectedClass}" style="display: flex; gap: 1.5rem; padding: 1.5rem; align-items: center; min-height: 110px; position: relative;" onclick="toggleModSelection('${mod.id}')">
                    <div class="mcmm-mod-icon large" style="background-image: url('${mod.icon}');"></div>
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
                    ? `<button class="mcmm-btn mcmm-btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;" onclick="event.stopPropagation(); installMod('${mod.id}', '${safeModName}', this)"><span class="material-symbols-outlined" style="font-size: 1.1rem;">update</span> Update</button>`
                    : (isInstalled
                        ? `<div style="display: flex; align-items: center; gap: 0.4rem; color: var(--success); font-weight: 700;">
                             <span class="material-symbols-outlined" style="font-size: 1.2rem;">check_circle</span>
                             <span>Installed</span>
                           </div>`
                        : `<button class="mcmm-btn mcmm-btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;" onclick="event.stopPropagation(); installMod('${mod.id}', '${safeModName}', this)"><span class="material-symbols-outlined" style="font-size: 1.1rem;">download</span> Install</button>`
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

/**
 * Sorts a list of mods based on the current sort state.
 *
 * @param {Array} list - The list of mods to sort.
 * @returns {Array} The sorted list.
 */
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

/**
 * Renders the pagination bar for the mod list.
 */
function renderModsPagination() {
    const bar = document.getElementById('modsPaginationBar');
    if (!bar) return;

    if (modState.view !== 'all') {
        bar.innerHTML = '';
        return;
    }

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

/**
 * Changes the active page for mod search results.
 *
 * @param {number|string} page - The target page number.
 */
function changeModsPage(page) {
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
var versionState = {
    modId: null,
    files: [],
    selectedFileId: null,
    btnElement: null,
    originalText: '',
    modIcon: ''
};

/**
 * Opens the version selection modal for a mod.
 *
 * @param {string} modId - The ID of the mod.
 * @param {string} modName - The name of the mod.
 * @param {HTMLElement} btnElement - The button that triggered the selection.
 */
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

/**
 * Closes the version selection modal.
 */
function closeVersionSelect() {
    document.getElementById('versionSelectModal').classList.remove('open');
    versionState.modId = null;
    versionState.selectedFileId = null;
    versionState.btnElement = null;
}

/**
 * Fetches available versions (files) for a specific mod.
 *
 * @param {string} modId - The ID of the mod to fetch versions for.
 * @returns {Promise<void>}
 */
async function fetchVersions(modId) {
    try {
        // If MC version/loader are still unknown, fetch fresh server_details once more
        if ((!modState.mcVersion || !modState.loader) && currentServerId) {
            try {
                const srvRes = await mcmmFetch('/plugins/mcmm/api.php?action=server_details&id=' + currentServerId);
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

        const res = await mcmmFetch('/plugins/mcmm/api.php?action=mod_files&' + params.toString());
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

/**
 * Renders the available versions of a mod in the selection modal.
 */
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

/**
 * Updates the selected version file in the state.
 *
 * @param {string} fileId - The ID of the file to select.
 */
function selectVersionFile(fileId) {
    versionState.selectedFileId = fileId;
    renderVersionList();
    document.getElementById('btnConfirmVersion').disabled = false;
}

/**
 * Confirms the selected version and initiates installation.
 */
function confirmVersionInstall() {
    if (!versionState.selectedFileId || !versionState.modId) return;
    performModInstall(versionState.modId, versionState.selectedFileId, versionState.btnElement);
    closeVersionSelect();
}

/**
 * Triggers the mod installation process by opening the version selection modal.
 *
 * @param {string} modId - The ID of the mod to install.
 * @param {string|HTMLElement} modNameOrBtn - The mod name or the button element.
 * @param {HTMLElement} [btnElement] - Optional button element.
 * @returns {Promise<void>}
 */
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

/**
 * Performs the actual mod installation by calling the API.
 *
 * @param {string} modId - The ID of the mod to install.
 * @param {string} fileId - The ID of the specific file to install.
 * @param {HTMLElement} btnElement - The button element that triggered the install.
 * @returns {Promise<void>}
 */
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

        const res = await mcmmFetch('/plugins/mcmm/api.php?' + params.toString());
        const data = await res.json();
        if (data.success) {
            if (btn) {
                btn.textContent = 'Installed';
                btn.style.background = 'var(--success)';
                // Don't revert originalText instantly if we want it to stay "Installed"
                // But renderMods will handle the actual persistence...
            }
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

/**
 * Deletes a mod from the current server.
 *
 * @param {string} fileName - The filename of the mod to delete.
 * @returns {Promise<void>}
 */
async function deleteMod(fileName) {
    if (!currentServerId || !confirm('Delete ' + fileName + '?')) return;

    try {
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=mod_delete&id=${currentServerId}&file=${encodeURIComponent(fileName)}`);
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

/**
 * Opens the deployment modal for a specific modpack.
 *
 * @param {Object} pack - The modpack data object.
 * @returns {Promise<void>}
 */
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

    // Load latest defaults from API so deploy reflects current settings
    // Load this early to show other fields faster
    const defaultsPromise = loadSettingsDefaults();

    // Show modal immediately
    setDeployStatus('');
    document.getElementById('deployModal').classList.add('open');

    // Fetch defaults and fill fields
    const defaults = await defaultsPromise;
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

    // Fetch Modpack versions in background
    try {
        const source = pack.source || 'curseforge';
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=mod_files&source=${source}&mod_id=` + pack.id);
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
}

/**
 * Opens the deployment modal for a vanilla server.
 *
 * @remarks Fetches official versions dynamically from Mojang's manifest.
 * @returns {Promise<void>}
 */
async function openVanillaDeploy() {
    const pack = {
        id: -1,
        name: 'Vanilla Server',
        author: 'Mojang',
        img: '',
        slug: 'vanilla'
    };

    selectedModpack = pack;
    document.getElementById('deployTitle').textContent = 'Deploy Vanilla Server';
    document.getElementById('deploySubtitle').textContent = 'Official Minecraft Experience';
    document.getElementById('deploy_name').value = 'Vanilla Minecraft';

    const versionList = document.getElementById('deployVersionList');
    const versionStatus = document.getElementById('deployVersionStatus');
    const versionInput = document.getElementById('deploy_version');

    if (versionList.length === 0) throw new Error("No versions found");
    if (versionStatus) versionStatus.textContent = 'Choose Official Version:';
    if (versionInput) versionInput.value = 'LATEST';

    // Fetch official version manifest dynamically
    versionList.innerHTML = '<div style="color: var(--text-secondary);">Fetching official versions...</div>';

    try {
        const res = await mcmmFetch('https://launchermeta.mojang.com/mc/game/version_manifest.json');
        const data = await res.json();

        let vanillaVersions = [];

        // Add specific versions (Release only, filtering out snapshots)
        // Mojang manifest has roughly 80-100 release versions over history.
        // Slice(0, 50) was cutting off older ones. 
        // We can just grab ALL releases.
        const releases = data.versions.filter(v => v.type === 'release'); //.slice(0, 100); 

        releases.forEach(v => {
            vanillaVersions.push({
                id: v.id,
                displayName: `Version: ${v.id}`,
                gameVersions: [v.id],
                releaseType: 1
            });
        });

        renderDeployVersions(vanillaVersions);
    } catch (e) {
        console.error('Failed to fetch vanilla versions:', e);
        // Fallback
        const fallbackVersions = [
            { id: 'LATEST', displayName: 'Version: Latest', gameVersions: ['LATEST'], releaseType: 1 },
            { id: '1.20.4', displayName: 'Version: 1.20.4', gameVersions: ['1.20.4'], releaseType: 1 },
            { id: '1.20.1', displayName: 'Version: 1.20.1', gameVersions: ['1.20.1'], releaseType: 1 },
            { id: '1.19.4', displayName: 'Version: 1.19.4', gameVersions: ['1.19.4'], releaseType: 1 },
            { id: '1.18.2', displayName: 'Version: 1.18.2', gameVersions: ['1.18.2'], releaseType: 1 },
            { id: '1.16.5', displayName: 'Version: 1.16.5', gameVersions: ['1.16.5'], releaseType: 1 },
            { id: '1.12.2', displayName: 'Version: 1.12.2', gameVersions: ['1.12.2'], releaseType: 1 }
        ];
        renderDeployVersions(fallbackVersions);
    }

    const defaults = await loadSettingsDefaults();
    const cfg = defaults || (typeof mcmmConfig !== 'undefined' ? mcmmConfig : {});

    document.getElementById('deploy_port').value = cfg.default_port || 25565;
    document.getElementById('deploy_memory').value = cfg.default_memory || '4G';
    document.getElementById('deploy_whitelist').value = cfg.default_whitelist || '';
    // Leave icon URL empty for vanilla by default to avoid fatal download errors in container
    document.getElementById('deploy_icon_url').value = '';

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

/**
 * Closes the deployment modal.
 */
function closeDeploy() {
    document.getElementById('deployModal').classList.remove('open');
    selectedModpack = null;
    setDeployStatus('');
}

/**
 * Sets the checked state of a checkbox safely.
 *
 * @param {string} id - The ID of the checkbox element.
 * @param {any} value - The value to set (coerced to boolean).
 */
function setChecked(id, value) {
    const el = document.getElementById(id);
    if (el) el.checked = !!value;
}

/**
 * Loads default settings from the API.
 *
 * @remarks Normalizes boolean-ish strings to real booleans.
 * @returns {Promise<Object|null>}
 */
async function loadSettingsDefaults() {
    if (settingsCache) return settingsCache;
    try {
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=settings');
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

/**
 * Sets the deployment status message in the UI.
 *
 * @param {string} message - The message to display.
 * @param {boolean} [isError=false] - Whether the message is an error.
 */
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

/**
 * Formats console log text into HTML with highlighting and grouping.
 *
 * @param {string} text - The raw console log text.
 * @returns {string} The formatted HTML string.
 */
function formatConsoleLog(text) {
    if (!text) return '';
    const lines = String(text).split('\n');
    let output = '';
    let currentGroupLines = [];
    let currentType = null;

    const flushGroup = () => {
        if (currentGroupLines.length === 0) return;
        const typeClass = currentType ? ` log-group-${currentType}` : '';
        const content = currentGroupLines.join('\n');
        output += `<div class="log-group${typeClass}" oncontextmenu="handleLogContextMenu(event, this)">${content}</div>`;
        currentGroupLines = [];
        currentType = null;
    };

    const formatLine = (l) => {
        let text = String(l);
        let timestamp = '';

        // Extract timestamp: [12:34:56]
        const tsMatch = text.match(/^\[(\d{2}:\d{2}:\d{2})\]\s*/);
        if (tsMatch) {
            timestamp = `[<span class="log-timestamp">${tsMatch[1]}</span>] `;
            text = text.slice(tsMatch[0].length);
        }

        let escaped = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        // Full line highlighting for specific categories
        if (/\[(JOIN|LEFT|LOGIN|LOGGED)\]/i.test(escaped)) {
            return timestamp + `<span class="log-join">${escaped}</span>`;
        }
        if (/\[(CHAT|MESSAGE|MSG|ADVANCEMENT)\]/i.test(escaped)) {
            return timestamp + `<span class="log-chat">${escaped}</span>`;
        }
        if (/\[(SUCCESS)\]/i.test(escaped)) {
            return timestamp + `<span class="log-success">${escaped}</span>`;
        }
        if (/\[(ERROR|FATAL|Exception)\]/i.test(escaped)) {
            return timestamp + `<span class="log-error">${escaped}</span>`;
        }
        if (/\[(WARN|WARNING)\]/i.test(escaped)) {
            return timestamp + `<span class="log-warn">${escaped}</span>`;
        }

        // Tag-based full line highlighting for Info
        if (/\[(INFO|SYSTEM)\]/i.test(escaped)) {
            return timestamp + `<span class="log-info">${escaped}</span>`;
        }

        // Final keyword-only highlighting for other lines
        escaped = escaped.replace(/\b(ERROR|FATAL|Exception|failed)\b/gi, '<span class="log-error">$1</span>');
        escaped = escaped.replace(/\b(WARN|WARNING)\b/gi, '<span class="log-warn">$1</span>');
        escaped = escaped.replace(/\b(SUCCESS|done|started)\b/gi, '<span class="log-success">$1</span>');

        return timestamp + escaped;
    };

    for (let line of lines) {
        if (line.trim() === '') {
            if (currentGroupLines.length > 0) currentGroupLines.push('');
            continue;
        }

        // Special Header Support: [ Server Console ]
        if (line.includes('[ Server Console ]')) {
            flushGroup();
            output += `<div class="mcmm-console-header-info">${line}</div>`;
            continue;
        }

        // New entry if starts with timestamp [HH:MM:SS]
        const isNewEntry = /^\[\d{2}:\d{2}:\d{2}\]/.test(line);
        const hasError = /\[(ERROR|FATAL|Exception)\]|\b(ERROR|FATAL|Exception)\b/i.test(line);
        const hasWarn = /\[(WARN|WARNING)\]|\b(WARN|WARNING)\b/i.test(line);

        if (isNewEntry) {
            flushGroup();
            if (hasError) currentType = 'error';
            else if (hasWarn) currentType = 'warn';
        } else if (!currentType && (hasError || hasWarn)) {
            // Case where the first line doesn't have a timestamp but is an error
            flushGroup();
            currentType = hasError ? 'error' : 'warn';
        }

        currentGroupLines.push(formatLine(line));
    }
    flushGroup();
    return output;
}

/**
 * Handles the context menu (right-click) on console logs to copy text.
 *
 * @param {MouseEvent} e - The mouse event.
 * @param {HTMLElement} el - The log element.
 */
function handleLogContextMenu(e, el) {
    e.preventDefault();
    const text = el.innerText;

    const doCopy = (str) => {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(str);
        } else {
            const textArea = document.createElement("textarea");
            textArea.value = str;
            textArea.style.position = "fixed";
            textArea.style.left = "-9999px";
            textArea.style.top = "0";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
                document.body.removeChild(textArea);
                return Promise.resolve();
            } catch (err) {
                document.body.removeChild(textArea);
                return Promise.reject(err);
            }
        }
    };

    doCopy(text).then(() => {
        showToast('Error copied to clipboard');
        el.style.background = 'rgb(255 255 255 / 15%)';
        setTimeout(() => el.style.background = '', 200);
    }).catch(() => {
        showToast('Failed to copy');
    });
}

/**
 * Displays a temporary toast notification message.
 *
 * @param {string} message - The message to display.
 */
function showToast(message) {
    let toast = document.getElementById('mcmm-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'mcmm-toast';
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.className = 'mcmm-toast show';
    setTimeout(() => {
        toast.className = 'mcmm-toast';
    }, 3000);
}

/**
 * Updates the deployment console output with logs.
 *
 * @param {string|string[]} lines - The log lines to display.
 */
function setDeployConsole(lines) {
    const wrapper = document.getElementById('deployConsole');
    const textEl = document.getElementById('deployConsoleText');
    if (!wrapper || !textEl) return;
    if (lines && lines.length) {
        wrapper.style.display = 'block';
        const rawText = Array.isArray(lines) ? lines.join('\n') : String(lines);
        textEl.innerHTML = formatConsoleLog(rawText);
    } else {
        wrapper.style.display = 'none';
        textEl.innerHTML = '';
    }
}

// Deploy Progress helpers
/**
 * Opens the deployment progress modal.
 *
 * @param {string} [subtitle='Starting...'] - The subtitle message to display.
 */
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

/**
 * Closes the deployment progress modal and stops log polling.
 */
function closeDeployProgress() {
    stopDeployLogPolling();
    document.getElementById('deployProgressModal').classList.remove('open');
}

/**
 * Updates the console output inside the deployment progress modal.
 *
 * @param {string} text - The log text to display.
 */
function setDeployProgressConsole(text) {
    const el = document.getElementById('deployProgressConsoleText');
    const box = document.getElementById('deployProgressConsole');
    if (!el || !box) return;
    el.innerHTML = formatConsoleLog(text);
    box.scrollTop = box.scrollHeight;
}

/**
 * Updates the state and description of a specific deployment step.
 *
 * @param {string} stepKey - The unique key identifying the step.
 * @param {'active'|'success'|'error'|'pending'} state - The new state of the step.
 * @param {string} [desc] - Optional description update for the step.
 */
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

/**
 * Starts polling deployment logs for a specific container.
 *
 * @param {string} containerId - The ID of the container to poll.
 */
function startDeployLogPolling(containerId) {
    // deployLogContainerId = containerId;
    if (!containerId) return;
    stopDeployLogPolling();
    deployLogInterval = setInterval(async () => {
        try {
            const res = await mcmmFetch('/plugins/mcmm/api.php?action=console_logs&id=' + containerId);
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

/**
 * Stops polling deployment logs.
 */
function stopDeployLogPolling() {
    if (deployLogInterval) clearInterval(deployLogInterval);
    deployLogInterval = null;
}

/**
 * Finalizes the deployment, closes the progress modal, and switches to the servers tab.
 */
function finishDeployAndView() {
    stopDeployLogPolling();
    closeDeployProgress();
    // Switch to servers tab
    switchTab('servers');
    loadServers();
}

/**
 * Fetches the list of servers from the API and updates the UI.
 *
 * @remarks Handles incremental DOM updates and sorting to maintain a smooth experience.
 * @returns {Promise<void>}
 */
async function loadServers() {
    const container = document.getElementById('tab-servers');
    if (!container) return;

    try {
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=servers');
        const data = await res.json();

        console.group("%c MCMM %c Servers Dashboard Update", "background:rgb(124 58 237 / 100%);color:rgb(255 255 255 / 100%);font-weight:700;padding:2px 6px;border-radius:4px;", "font-weight:700;");
        console.log("Status:", data.success ? "✅ Success" : "❌ Failed");

        if (data.data) {
            data.data.forEach(s => {
                const statusColor = s.isRunning ? "rgb(16 185 129 / 100%)" : "rgb(239 6 68 / 100%)";
                const ramSource = s.ramDetails?.source || 'unavailable';
                console.groupCollapsed(`%c ${s.name} %c ${s.status} %c ${ramSource} `,
                    "background:rgb(30 41 59 / 100%);color:rgb(56 189 248 / 100%);font-weight:700;padding:2px 4px;border-radius:4px 0 0 4px;",
                    `background:${statusColor};color:rgb(255 255 255 / 100%);font-weight:700;padding:2px 4px;`,
                    "background:rgb(100 116 139 / 100%);color:rgb(255 255 255 / 100%);font-size:0.7rem;padding:2px 4px;border-radius:0 4px 4px 0;"
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
            localStorage.setItem('mcmm_servers_cache', JSON.stringify(data.data));

            const tabContainer = document.getElementById('tab-servers');
            const listContainer = document.getElementById('serverListContainer');

            // 1. Handle "No Servers" Empty State Transition
            if (data.data.length === 0) {
                if (tabContainer && (!listContainer || tabContainer.querySelector('.mcmm-empty') === null)) {
                    renderServers([]);
                }
                return;
            }

            // 2. Ensure container exists if data is present
            if (!listContainer && tabContainer) {
                renderServers(data.data);
                return;
            }

            // 3. Sync DOM with Data (Live Add/Remove/Update)
            if (listContainer) {
                const fetchedIds = data.data.map(s => String(s.id));
                const existingRows = Array.from(listContainer.querySelectorAll('.mcmm-server-row'));

                // Remove servers that are no longer in the data
                existingRows.forEach(row => {
                    const sid = String(row.getAttribute('data-server-id'));
                    if (!fetchedIds.includes(sid)) {
                        row.remove();
                    }
                });

                // Sort data to ensure Online servers are at the top
                const sortedData = [...data.data].sort((a, b) => {
                    if (a.isRunning !== b.isRunning) return a.isRunning ? -1 : 1;
                    return a.name.localeCompare(b.name);
                });

                // Update, Add, and REORDER
                sortedData.forEach(s => {
                    let row = listContainer.querySelector(`.mcmm-server-row[data-server-id="${s.id}"]`);

                    if (row) {
                        // SURGICAL UPDATE (Fast, no flicker)
                        const ramUsedLabel = (s.ramUsedMb || 0) > 0 ? (s.ramUsedMb / 1024).toFixed(1) + ' GB' : '0 GB';
                        const ramCapLabel = (s.ramLimitMb || 0) > 0 ? (s.ramLimitMb / 1024).toFixed(1) + ' GB' : 'N/A';
                        const ramPercent = Math.min(Math.max(s.ram || 0, 0), 100);
                        const cpuUsage = s.cpu || 0;

                        const ramTxt = row.querySelector('.mcmm-val-ram');
                        const ramBar = row.querySelector('.mcmm-bar-ram');
                        const cpuTxt = row.querySelector('.mcmm-val-cpu');
                        const cpuBar = row.querySelector('.mcmm-bar-cpu');
                        const playersTxt = row.querySelector('.mcmm-val-players');
                        const statusTxt = row.querySelector('.mcmm-status-text');
                        const actionsArea = row.querySelector('.mcmm-server-actions');

                        if (ramTxt) ramTxt.textContent = `${ramUsedLabel} / ${ramCapLabel}`;
                        if (ramBar) ramBar.style.width = `${ramPercent}%`;
                        if (cpuTxt) cpuTxt.textContent = `${Number(cpuUsage).toFixed(1)}%`;
                        if (cpuBar) cpuBar.style.width = `${Math.min(Math.max(cpuUsage, 0), 100)}%`;

                        const wasRunning = row.classList.contains('running');
                        if (s.isRunning !== wasRunning) {
                            row.classList.toggle('running', s.isRunning);
                            row.classList.toggle('stopped', !s.isRunning);
                            if (statusTxt) statusTxt.textContent = s.isRunning ? 'Online' : 'Offline';

                            // Swap action buttons if status changed
                            if (actionsArea) {
                                actionsArea.innerHTML = `
                                    ${s.isRunning ? `
                                        <button class="mcmm-btn-icon danger" title="Stop Server" onclick="controlServer('${s.id}', 'stop', true)"><span class="material-symbols-outlined">stop</span></button>
                                        <button class="mcmm-btn-icon" title="Console" onclick="openConsole('${s.id}', '${s.name}')"><span class="material-symbols-outlined">terminal</span></button>
                                        <button class="mcmm-btn-icon" title="Players" onclick="openPlayersModal('${s.id}', '${s.name}', '${s.ports}')"><span class="material-symbols-outlined">groups</span></button>
                                    ` : `
                                        <button class="mcmm-btn-icon success" title="Start Server" onclick="controlServer('${s.id}', 'start', true)"><span class="material-symbols-outlined">play_arrow</span></button>
                                        <button class="mcmm-btn-icon" style="opacity:0.5; cursor:not-allowed;" title="Console Offline"><span class="material-symbols-outlined">terminal</span></button>
                                        <button class="mcmm-btn-icon" style="opacity:0.5; cursor:not-allowed;" title="Players Offline"><span class="material-symbols-outlined">groups</span></button>
                                    `}
                                    ${(s.loader || 'Vanilla').toLowerCase() !== 'vanilla' ? `
                                        <button class="mcmm-btn-icon" title="Mods" onclick="openModManager('${s.id}', '${s.name}')"><span class="material-symbols-outlined">extension</span></button>
                                    ` : ''}
                                    <button class="mcmm-btn-icon" title="Backup" onclick="createBackup('${s.id}')"><span class="material-symbols-outlined">cloud_upload</span></button>
                                    <button class="mcmm-btn-icon" title="Settings" onclick="openServerSettings('${s.id}')"><span class="material-symbols-outlined">settings</span></button>
                                    <button class="mcmm-btn-icon danger" title="Delete Server" onclick="deleteServer('${s.id}')"><span class="material-symbols-outlined">delete</span></button>
                                `;
                            }
                        }

                        if (playersTxt) {
                            if (s.isRunning) {
                                const pOnline = s.players?.online || 0;
                                const pMax = s.players?.max || 0;
                                playersTxt.textContent = `${pOnline} / ${pMax > 0 ? pMax : '?'} players`;
                                playersTxt.style.display = 'inline';
                                if (playersTxt.previousElementSibling && playersTxt.previousElementSibling.textContent.trim() === '|') {
                                    playersTxt.previousElementSibling.style.display = 'inline';
                                }
                            } else {
                                playersTxt.style.display = 'none';
                                if (playersTxt.previousElementSibling && playersTxt.previousElementSibling.textContent.trim() === '|') {
                                    playersTxt.previousElementSibling.style.display = 'none';
                                }
                            }
                        }
                    } else {
                        // NEW SERVER (Append)
                        const rowHtml = renderServerRow(s);
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = rowHtml;
                        row = tempDiv.firstElementChild;
                    }

                    // CRITICAL: appendChild moves the element if it already exists.
                    // By doing this in the sorted loop, the DOM order will always match sortedData.
                    listContainer.appendChild(row);
                });
            }
            initServerPlayerCounts();
        }
    } catch (e) {
        console.error('Failed to load servers:', e);
    }
}

/**
 * Renders a single server row as an HTML snippet.
 *
 * @param {Object} server - The server data object.
 * @returns {string} The HTML string for the server row.
 */
function renderServerRow(server) {
    const icon = server.icon || "https://media.forgecdn.net/avatars/855/527/638260492657788102.png";
    const statusClass = server.isRunning ? 'running' : 'stopped';
    const playersOnline = server.players?.online || 0;
    const playersMax = server.players?.max || 0;
    const mcVersion = server.mcVersion || 'Latest';
    const modpackVersion = server.modpackVersion || '';
    const loaderRaw = server.loader || 'Vanilla';
    // Properly capitalize loader names
    const loader = loaderRaw === 'neoforge' ? 'NeoForge' :
        loaderRaw === 'forge' ? 'Forge' :
            loaderRaw === 'fabric' ? 'Fabric' :
                loaderRaw === 'quilt' ? 'Quilt' :
                    loaderRaw.charAt(0).toUpperCase() + loaderRaw.slice(1);

    const ramPercent = Math.min(Math.max(server.ram || 0, 0), 100);
    const ramUsedLabel = (server.ramUsedMb || 0) > 0 ? (server.ramUsedMb / 1024).toFixed(1) + ' GB' : '0 GB';
    const ramCapLabel = (server.ramLimitMb || 0) > 0 ? (server.ramLimitMb / 1024).toFixed(1) + ' GB' : 'N/A';
    const cpuUsage = server.cpu || 0;

    return `
        <div class="mcmm-server-row ${statusClass}" data-server-id="${server.id}">
            <div class="mcmm-server-icon" style="background-image: url('${icon}');"></div>
            
            <!-- Backup Status Overlay -->
            <div class="mcmm-backup-status" id="backup-status-${server.id}" style="display: none; position: absolute; top: 8px; left: 80px; background: rgba(59, 130, 246, 0.95); color: white; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; align-items: center; gap: 6px; z-index: 10;">
                <span class="material-symbols-outlined" style="font-size: 0.9rem; animation: spin 1s linear infinite;">autorenew</span>
                Backing up...
            </div>
            
            <div class="mcmm-server-info">
                <div class="mcmm-server-title">
                    ${server.name}
                    <span class="mcmm-badge">${mcVersion}</span>
                    <span class="mcmm-badge secondary">${loader}</span>
                    ${modpackVersion ? `<span class="mcmm-badge" style="background: rgba(139, 92, 246, 0.15); color: #a78bfa; border: 1px solid rgba(139, 92, 246, 0.25);" title="Modpack Version">${modpackVersion}</span>` : ''}
                    ${server.containerUpdate ? `<span class="mcmm-badge" style="background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);" title="New container image available"><span class="material-symbols-outlined" style="font-size: 1rem; margin-right: 2px; vertical-align: text-bottom;">download</span> Update</span>` : ''}
                </div>
                <div class="mcmm-server-subtitle">
                    <span class="mcmm-status-dot"></span>
                    <span class="mcmm-status-text">${server.isRunning ? 'Online' : 'Offline'}</span>
                    <span style="opacity:0.5;">|</span>
                    <span>Port: ${server.ports}</span>
                    <span style="opacity:0.5; ${server.isRunning ? '' : 'display:none;'}">|</span>
                    <span class="mcmm-val-players" id="players-${server.id}" data-server-id="${server.id}">
                        ${server.isRunning ? `${playersOnline} / ${playersMax > 0 ? playersMax : '?'} players` : ''}
                    </span>
                </div>
            </div>
            <div class="mcmm-server-metrics">
                <div class="mcmm-metric">
                    <div class="mcmm-metric-label">
                        <span>RAM</span>
                        <span class="mcmm-val-ram">${ramUsedLabel} / ${ramCapLabel}</span>
                    </div>
                    <div class="mcmm-metric-bar">
                        <div class="mcmm-metric-fill mcmm-bar-ram" style="width: ${ramPercent}%; background: linear-gradient(90deg, #a855f7, #ec4899);"></div>
                    </div>
                </div>
                <div class="mcmm-metric">
                    <div class="mcmm-metric-label">
                        <span>CPU</span>
                        <span class="mcmm-val-cpu">${Number(cpuUsage).toFixed(1)}%</span>
                    </div>
                    <div class="mcmm-metric-bar">
                        <div class="mcmm-metric-fill mcmm-bar-cpu" style="width: ${Math.min(Math.max(cpuUsage, 0), 100)}%; background: linear-gradient(90deg, #3b82f6, #06b6d4);"></div>
                    </div>
                </div>
            </div>
            <div class="mcmm-server-actions">
                ${server.isRunning ? `
                    <button class="mcmm-btn-icon danger" title="Stop Server" onclick="controlServer('${server.id}', 'stop', true)"><span class="material-symbols-outlined">stop</span></button>
                    <button class="mcmm-btn-icon" title="Console" onclick="openConsole('${server.id}', '${server.name}')"><span class="material-symbols-outlined">terminal</span></button>
                    <button class="mcmm-btn-icon" title="Players" onclick="openPlayersModal('${server.id}', '${server.name}', '${server.ports}')"><span class="material-symbols-outlined">groups</span></button>
                ` : `
                    <button class="mcmm-btn-icon success" title="Start Server" onclick="controlServer('${server.id}', 'start', true)"><span class="material-symbols-outlined">play_arrow</span></button>
                    <button class="mcmm-btn-icon" style="opacity:0.5; cursor:not-allowed;" title="Console Offline"><span class="material-symbols-outlined">terminal</span></button>
                    <button class="mcmm-btn-icon" style="opacity:0.5; cursor:not-allowed;" title="Players Offline"><span class="material-symbols-outlined">groups</span></button>
                `}
                ${loaderRaw.toLowerCase() !== 'vanilla' ? `
                    <button class="mcmm-btn-icon" title="Mods" onclick="openModManager('${server.id}', '${server.name}')"><span class="material-symbols-outlined">extension</span></button>
                ` : ''
        }
                <button class="mcmm-btn-icon" title="Backup" onclick="createBackup('${server.id}')"><span class="material-symbols-outlined">cloud_upload</span></button>
                <button class="mcmm-btn-icon" title="Settings" onclick="openServerSettings('${server.id}')"><span class="material-symbols-outlined">settings</span></button>
                <button class="mcmm-btn-icon danger" title="Delete Server" onclick="deleteServer('${server.id}')"><span class="material-symbols-outlined">delete</span></button>
            </div >
        </div >
        `;
}

/**
 * Renders the server status dashboard view.
 *
 * @param {Object[]} servers - Array of server data objects.
 */
function renderServers(servers) {
    const container = document.getElementById('tab-servers');
    if (!container) return;

    if (!servers || servers.length === 0) {
        container.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.25rem;">Servers Status</h2>
                <button class="mcmm-btn mcmm-btn-primary" onclick="switchTab('catalog')">
                    Deploy New Server
                </button>
            </div>
        <div class="mcmm-empty">
            <span class="material-symbols-outlined" style="font-size: 3rem; opacity: 0.3; margin-bottom: 1rem;">dns</span>
            <h3>No servers found</h3>
            <p>Get started by deploying your first modpack server.</p>
            <button class="mcmm-btn mcmm-btn-primary" onclick="switchTab('catalog')">Browse Catalog</button>
        </div>
    `;
        return;
    }

    let html = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.25rem;">Servers Status</h2>
                <button class="mcmm-btn mcmm-btn-primary" onclick="switchTab('catalog')">
                    Deploy New Server
                </button>
            </div>
            <div class="mcmm-server-list" id="serverListContainer">
    `;

    const sortedServers = [...servers].sort((a, b) => {
        if (a.isRunning !== b.isRunning) return a.isRunning ? -1 : 1;
        return a.name.localeCompare(b.name);
    });

    sortedServers.forEach(server => {
        html += renderServerRow(server);
    });

    html += `
        </div>
        <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
             <button class="mcmm-btn" style="background: rgba(255,255,255,0.05); display: flex; align-items: center; gap: 0.5rem;" onclick="startAgents()">
                <span class="material-symbols-outlined" style="font-size: 1.2rem;">restart_alt</span> Restart Agents
            </button>
        </div>
    `;
    container.innerHTML = html;
}

// Render Modpack version buttons in deploy modal
/**
 * Renders modpack version buttons inside the deployment modal.
 *
 * @param {Object[]} files - Array of modpack version/file objects.
 */
function renderDeployVersions(files) {
    const list = document.getElementById('deployVersionList');
    const status = document.getElementById('deployVersionStatus');
    const hidden = document.getElementById('deploy_version');
    if (!list || !status || !hidden) return;

    const fallbackIcon = 'https://media.forgecdn.net/avatars/855/527/638260492657788102.png';
    const packImg = (selectedModpack && (selectedModpack.img || selectedModpack.logo || selectedModpack.icon))
        || (document.getElementById('deploy_icon_url')?.value)
        || fallbackIcon;
    const safePackImg = String(packImg || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");

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
        let mcVersions = (file.gameVersions || []).filter(v => v && v !== 'Unknown' && /^\d+\.\d+(\.\d+)?$/.test(v)).join(', ');
        if (!mcVersions && selectedModpack && selectedModpack.mcVersion && selectedModpack.mcVersion !== 'Unknown') {
            mcVersions = selectedModpack.mcVersion;
        }
        if (!mcVersions) mcVersions = 'Unknown';

        // Detect Java version
        const firstMcVersion = (file.gameVersions || []).find(v => /^\d+\.\d+(\.\d+)?$/.test(v)) || '';
        const javaVer = pickJavaVersionLocal(firstMcVersion);

        return `
        <button class="mcmm-version-card ${typeClass} ${isSelected ? 'selected' : ''}" data-file-id="${file.id}" onclick="setDeployVersion('${file.id}', this)">
                <div class="mcmm-version-thumb" style="background-image: url('${safePackImg}');"></div>
                <div class="mcmm-version-main">
                    <div class="mcmm-version-name">${file.displayName}</div>
                    <div class="mcmm-version-meta">
                        <span class="mcmm-chip subtle">MC ${mcVersions}</span>
                        <span class="mcmm-chip subtle" style="color: var(--primary-hover); border-color: var(--primary-dim);">Java ${javaVer}</span>
                        <span class="mcmm-chip ${typeClass}">${type}</span>
                    </div>
                </div>
                <div class="mcmm-version-select">
                    <div class="mcmm-version-action-text">
                        <div class="action-title">${isSelected ? 'Active' : 'Pick'}</div>
                    </div>
                    <div class="mcmm-version-status-indicator"></div>
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

/**
 * Picks the recommended Java version based on the Minecraft version.
 *
 * @param {string} mcVersion - The Minecraft version string.
 * @returns {string} The Java version (e.g., '8', '11', '17', '21').
 */
function pickJavaVersionLocal(mcVersion) {
    if (!mcVersion || mcVersion === 'LATEST' || mcVersion === 'SNAPSHOT') return '21';
    const v = mcVersion.split('.').map(Number);
    if (v[0] === 1) {
        if (v[1] >= 20) return '21';
        if (v[1] >= 17) return '17';
        if (v[1] >= 16) return '11';
    }
    return '8';
}

/**
 * Sets the selected deployment version and updates hidden fields.
 *
 * @param {string} fileId - The ID of the version/file.
 * @param {HTMLElement} buttonEl - The button element that was clicked.
 */
function setDeployVersion(fileId, buttonEl) {
    const hidden = document.getElementById('deploy_version');
    const javaHidden = document.getElementById('deploy_java_version');
    const nameHidden = document.getElementById('deploy_modpack_version_name');
    if (hidden) hidden.value = fileId;
    if (nameHidden && buttonEl) {
        nameHidden.value = buttonEl.querySelector('.mcmm-version-name')?.textContent || '';
    }

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

/**
 * Submits the deployment request to the API.
 * 
 * @remarks Handles both jQuery.ajax (for Unraid compatibility) and fetch fallback.
 * @returns {Promise<void>}
 */
async function submitDeploy() {
    if (!selectedModpack) return;
    setDeployStatus('Deploying server...', false);

    const payload = {
        source: selectedModpack.source || 'curseforge',
        modpack_id: selectedModpack.id,
        modpack_name: selectedModpack.name,
        modpack_author: selectedModpack.author || 'Unknown',
        modpack_slug: selectedModpack.slug || selectedModpack.name || '',
        modpack_file_id: document.getElementById('deploy_version').value || '',
        modpack_version_name: document.getElementById('deploy_modpack_version_name')?.value || '',
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
        mcmmFetch('/plugins/mcmm/api.php?action=deploy', {
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
var currentConsoleId = null;
var currentConsoleName = null;

/**
 * Opens the console modal for a specific server and starts log polling.
 *
 * @param {string} serverId - The ID of the server.
 * @param {string} serverName - The name of the server.
 */
function openConsole(serverId, serverName) {
    const modal = document.getElementById('consoleModal');
    const output = document.getElementById('consoleOutput');

    document.getElementById('consoleTitle').textContent = serverName + ' - Console';
    currentConsoleId = serverId;
    currentConsoleName = serverName;

    modal.classList.add('open');
    output.innerHTML = '<div style="color: rgb(102 102 102 / 100%); padding: 1rem;">Loading logs...</div>';

    fetchLogs();
    fetchLogs();
    if (consoleInterval) clearInterval(consoleInterval);
    consoleInterval = setInterval(fetchLogs, 2000);

    document.getElementById('consoleInput').focus();
}

/**
 * Fetches server console logs from the API and updates the UI.
 *
 * @returns {Promise<void>}
 */
async function fetchLogs() {
    if (!currentConsoleId) return;
    try {
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=console_logs&id=' + currentConsoleId);
        const data = await res.json();
        if (data.success) {
            const output = document.getElementById('consoleOutput');
            const wasAtBottom = output.scrollTop + output.clientHeight >= output.scrollHeight - 50;

            // Clean up logs: Strip ANSI color codes
            let cleanLogs = (data.logs || '').replace(/\x1B\[[0-9;]*[a-zA-Z]/g, ''); // eslint-disable-line no-control-regex

            // Prepend Server Header
            const headerLine = `[Server Console ]- ${currentConsoleName || 'Minecraft Server'} \n`;
            output.innerHTML = formatConsoleLog(headerLine + cleanLogs);

            if (wasAtBottom) {
                output.scrollTop = output.scrollHeight;
            }
        }
    } catch (e) {
        console.error('Console fetch error:', e);
    }
}

/**
 * Closes the console modal and stops log polling.
 */
function closeConsole() {
    if (consoleInterval) clearInterval(consoleInterval);
    consoleInterval = null;
    currentConsoleId = null;
    document.getElementById('consoleModal').classList.remove('open');
}

// --- Players Modal ---
var localCurrentPlayers = [];
var localCurrentServerId = null;
var currentPlayersTab = 'active';

/**
 * Opens the players management modal and fetches player data.
 *
 * @param {string} serverId - The ID of the server.
 * @param {string} serverName - The name of the server.
 * @param {string} port - The server port.
 */
function openPlayersModal(serverId, serverName, port) {
    const modal = document.getElementById('playersModal');
    document.getElementById('playersTitle').textContent = serverName + ' - Players';
    currentPlayersId = serverId;
    currentPlayersName = serverName;
    currentPlayersPort = port;

    modal.classList.add('open');
    currentPlayerTab = 'online';
    fetchTabPlayers();
}

/**
 * Updates the UI highlights and counts for player management tabs.
 *
 * @param {Object} [data] - The player data containing counts.
 */
function updatePlayerTabIndicator(data) {
    const tabs = document.querySelectorAll('.mcmm-player-tab');
    tabs.forEach(tab => {
        const type = tab.getAttribute('data-tab');
        tab.classList.toggle('active', type === currentPlayerTab);

        if (data) {
            const countEl = tab.querySelector('.mcmm-tab-count');
            if (countEl) {
                if (type === 'online') {
                    countEl.textContent = data.online ? data.online.length : 0;
                    countEl.style.display = 'inline-flex';
                } else if (type === 'banned') {
                    countEl.textContent = data.banned ? data.banned.length : 0;
                    countEl.style.display = 'inline-flex';
                } else {
                    countEl.style.display = 'none';
                }
            }
        }
    });

    const searchInput = document.getElementById('playerSearchInput');
    if (currentPlayerTab === 'online') {
        searchInput.placeholder = "Search online players...";
    } else if (currentPlayerTab === 'banned') {
        searchInput.placeholder = "Search banned players...";
    } else {
        searchInput.placeholder = "Search history...";
    }
}

/**
 * Switches the active player tab (online, banned, history).
 *
 * @param {'online'|'banned'|'history'} tab - The tab to switch to.
 * @returns {Promise<void>}
 */
async function switchPlayerTab(tab) {
    if (currentPlayersTab === tab) return;
    currentPlayersTab = tab;
    updatePlayerTabIndicator();
    fetchTabPlayers();
}

/**
 * Fetches player data for the currently active tab from the API.
 *
 * @returns {Promise<void>}
 */
async function fetchTabPlayers() {
    const container = document.getElementById('playersList');
    container.innerHTML = '<div style="color: var(--text-secondary); padding: 1rem;">Scanning players...</div>';
    updatePlayerTabIndicator();

    try {
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=players&id=${currentPlayersId}&port=${currentPlayersPort}`);
        const data = await res.json();
        if (data.success) {
            lastPlayersData = data.data;
            renderPlayers();
            updatePlayerTabIndicator(data.data);
        } else {
            container.innerHTML = '<div class="mcmm-error">Failed to fetch player data</div>';
        }
    } catch (e) {
        container.innerHTML = '<div class="mcmm-error">Connection error</div>';
    }
}

/**
 * Reloads player data for the current server.
 */
function refreshPlayers() {
    fetchTabPlayers();
}

/**
 * Filters the displayed players based on search input.
 */
function filterPlayers() {
    const query = document.getElementById('playerSearchInput').value.toLowerCase();

    // The filter applies whenever the user types, but we only re-render if we have data
    if (lastPlayersData) {
        renderPlayers(query);
    }
}

/**
 * Renders the player list based on the active tab and search query.
 *
 * @param {string} [query=''] - The search filter query.
 */
function renderPlayers(query = '') {
    const container = document.getElementById('playersList');
    if (!lastPlayersData) return;

    let players = [];
    if (currentPlayerTab === 'online') players = lastPlayersData.online || [];
    else if (currentPlayerTab === 'banned') players = lastPlayersData.banned || [];
    else players = lastPlayersData.history || [];

    if (query) {
        players = players.filter(p => p.name.toLowerCase().includes(query));
    }

    if (players.length === 0) {
        container.innerHTML = `
            <div class="mcmm-empty-players">
                <span class="material-symbols-outlined">person_off</span>
                <p>No players found in this category.</p>
            </div>
        `;
        return;
    }

    container.innerHTML = players.map(p => {
        const isBanned = currentPlayerTab === 'banned';
        const isOnline = currentPlayerTab === 'online';

        return `
            <div class="mcmm-player-row">
                <div class="mcmm-player-avatar">
                   <img src="https://mc-heads.net/avatar/${p.name}/40" alt="${p.name}">
                </div>
                <div class="mcmm-player-info">
                    <div class="mcmm-player-name">
                        ${p.name}
                        ${isOnline ? '<span class="mcmm-player-status-dot online"></span>' : ''}
                    </div>
                    <div class="mcmm-player-meta">
                        ${isBanned ? `Banned on: ${p.date || 'Unknown'}` : (p.lastSeen ? `Last seen: ${p.lastSeen}` : 'Player')}
                    </div>
                </div>
                <div class="mcmm-player-actions">
                    ${isOnline ? `
                        <button class="mcmm-btn-icon" title="Whisper" onclick="whisperPlayer('${p.name}')"><span class="material-symbols-outlined">chat</span></button>
                        <button class="mcmm-btn-icon danger" title="Kick" onclick="playerAction('kick', '${p.name}')"><span class="material-symbols-outlined">logout</span></button>
                    ` : ''}
                    
                    ${isBanned ? `
                        <button class="mcmm-btn-icon success" title="Unban" onclick="playerAction('unban', '${p.name}')"><span class="material-symbols-outlined">gavel</span></button>
                    ` : `
                        <button class="mcmm-btn-icon danger" title="Ban" onclick="playerAction('ban', '${p.name}')"><span class="material-symbols-outlined">gavel</span></button>
                    `}
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Closes the players management modal.
 */
function closePlayersModal() {
    currentPlayersId = null;
    document.getElementById('playersModal').classList.remove('open');
}

/**
 * Performs an action on a player (kick, ban, unban).
 *
 * @param {'kick'|'ban'|'unban'} action - The action to perform.
 * @param {string} playerName - The name of the player.
 * @returns {Promise<void>}
 */
async function playerAction(action, playerName) {
    if (!currentPlayersId) return;

    if (action === 'ban' && !confirm(`Are you sure you want to ban ${playerName}?`)) return;

    try {
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=player_action&id=${currentPlayersId}&type=${action}&player=${playerName}`);
        const data = await res.json();
        if (data.success) {
            showToast(`Player ${playerName} ${action}ed`);
            fetchTabPlayers();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Action failed');
    }
}

/**
 * Prompts the user to whisper a message to a player.
 *
 * @param {string} playerName - The name of the player.
 */
function whisperPlayer(playerName) {
    const msg = prompt(`Enter message for ${playerName}: `);
    if (msg) {
        playerAction('whisper', playerName + ' ' + msg);
    }
}

/**
 * Copies the specified text to the clipboard and shows a toast notification.
 * 
 * @param {string} text - The text to copy.
 * @param {string} [successMsg] - Optional message to show in the toast on success.
 */
function copyToClipboard(text, successMsg) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => showToast(successMsg || 'Copied!'));
    } else {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showToast(successMsg || 'Copied!');
    }
}

// --- Server Control ---
/**
 * Sends a control command (start, stop, etc.) to a server.
 * 
 * @param {string} id - The ID of the server to control.
 * @param {string} action - The action command (e.g., 'start', 'stop').
 * @param {boolean} [skipConfirm=false] - Whether to skip the confirmation prompt.
 */
function controlServer(id, action, skipConfirm = false) {
    if (!skipConfirm && !confirm(`Are you sure you want to ${action} this server?`)) {
        return;
    }

    // Visual feedback: disable the button or show loading state? 
    // For now, next poll will show it.

    mcmmFetch('/plugins/mcmm/api.php?action=server_control&id=' + id + '&cmd=' + action)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Immediate refresh for snappy feel
                loadServers();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

/**
 * Permanently deletes a server container and its data.
 * 
 * @param {string} id - The ID of the server to delete.
 */
function deleteServer(id) {
    if (!confirm('EXTREMELY IMPORTANT: This will PERMANENTLY delete this server container.\n\nAre you sure you want to proceed?')) return;

    mcmmFetch('/plugins/mcmm/api.php?action=server_delete&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // No more alert('Server deleted') - the live refresh will just make it vanish!
                loadServers();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// --- Settings ---

/**
 * Collects settings from the UI and submits them to the API.
 * 
 * @param {Event} e - The form submission event.
 */
function saveSettings(e) {
    e.preventDefault();

    const statusEl = document.getElementById('settingsStatus');
    const getVal = (id) => {
        const el = document.getElementById(id);
        return el ? el.value : '';
    };
    const getChecked = (id) => {
        const el = document.getElementById(id);
        return el ? el.checked : false;
    };

    const data = {
        curseforge_api_key: getVal('curseforge_api_key'),
        default_server_name: getVal('default_server_name'),
        default_port: parseInt(getVal('default_port')) || 25565,
        default_memory: getVal('default_memory'),
        default_max_players: parseInt(getVal('default_max_players')) || 20,
        default_whitelist: getVal('default_whitelist'),
        default_icon_url: getVal('default_icon_url'),
        default_pvp: getChecked('default_pvp'),
        default_hardcore: getChecked('default_hardcore'),
        default_allow_flight: getChecked('default_allow_flight'),
        default_command_blocks: getChecked('default_command_blocks'),
        default_rolling_logs: getChecked('default_rolling_logs'),
        default_log_timestamp: getChecked('default_log_timestamp'),
        default_direct_console: getChecked('default_direct_console'),
        default_aikar_flags: getChecked('default_aikar_flags'),
        default_meowice_flags: getChecked('default_meowice_flags'),
        default_graalvm_flags: getChecked('default_graalvm_flags'),
        jvm_flags: getVal('jvm_flags'),
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
        mcmmFetch('/plugins/mcmm/api.php?action=save_settings', {
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

/**
 * Toggles the visibility of a password/key input field.
 * 
 * @param {string} id - The ID of the input element.
 * @param {HTMLElement} btn - The toggle button element.
 */
function togglePasswordVisibility(id, btn) {
    const input = document.getElementById(id);
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<span class="material-symbols-outlined">visibility_off</span>';
        btn.title = 'Hide Key';
    } else {
        input.type = 'password';
        btn.innerHTML = '<span class="material-symbols-outlined">visibility</span>';
        btn.title = 'Show Key';
    }
}

/**
 * Processes a successful settings save response.
 * 
 * @param {Object|string} result - The API response object or an error string.
 * @param {HTMLElement} statusEl - The status message element.
 */
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
        setTimeout(() => {
            statusEl.style.display = 'none';
            closeGlobalSettings();
        }, 1500);
    } else {
        statusEl.className = 'mcmm-status error';
        const errorMsg = (result && result.error) ? result.error : 'Unknown error (success=false)';
        statusEl.textContent = 'Error: ' + errorMsg;

        // Auto-fetch log if error is unknown
        // if (!result || !result.error) {
        //      mcmmFetch('/plugins/mcmm/api.php?action=get_log')
        //         .then(r => r.json())
        //         .then(d => {
        //             if(d.success) console.log("MCMM Log:\n" + d.log);
        //         });
        // }
    }
}

/**
 * Processes an error during settings save.
 * 
 * @param {Error} err - The error object.
 * @param {HTMLElement} statusEl - The status message element.
 */
function handleSaveError(err, statusEl) {
    statusEl.style.display = 'block';
    statusEl.className = 'mcmm-status error';
    statusEl.textContent = 'Error: ' + err.message;

    // Auto-fetch log on error
    // mcmmFetch('/plugins/mcmm/api.php?action=get_log')
    //     .then(r => r.json())
    //     .then(d => {
    //         if(d.success) console.log("MCMM Log:\n" + d.log);
    //     });
}

// --- Mod Selection Queue ---

/**
 * Toggles the selection of a mod for the installation queue.
 * 
 * @param {string} modId - The ID of the mod to toggle.
 */
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

/**
 * Renders the mod installation queue panel.
 */
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

    count.textContent = `${modState.selected.size} item${modState.selected.size !== 1 ? 's' : ''} `;
    btn.textContent = `Install ${modState.selected.size} Mod${modState.selected.size !== 1 ? 's' : ''} `;

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

/**
 * Removes a specific mod from the installation queue.
 * 
 * @param {string} modId - The ID of the mod to remove.
 */
function removeModFromQueue(modId) {
    const key = String(modId);
    if (modState.selected.has(key)) {
        modState.selected.delete(key);
        renderMods(); // To uncheck items in grid
        renderQueue();
    }
}

/**
 * Clears all mods from the installation queue.
 */
function clearQueue() {
    modState.selected.clear();
    renderMods();
    renderQueue();
}

/**
 * Restarts the metrics agents for all running servers.
 * 
 * @returns {Promise<void>}
 */
async function startAgents() {
    try {
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=start_agents');
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

/**
 * Fetches and logs detailed RAM and metrics information for clinical debugging.
 * 
 * @returns {Promise<void>}
 */
async function logRamDebug() {
    try {
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=servers&_=' + Date.now());
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
                `[RAM DEBUG] ${s.name}: used = ${s.ramUsedMb || 0} MB limit = ${s.ramLimitMb || 0} MB pct = ${s.ram || 0}% source=${d.source || 'n/a'} | agent exists = ${a.exists ? 'yes' : 'no'} ageSec = ${a.ageSec ?? 'n/a'} ts = ${a.ts ?? 'n/a'} | cgroup used = ${cg.memUsedMb ?? 'n/a'} cap = ${cg.memCapMb ?? 'n/a'} cpu = ${cg.cpuPercent ?? 'n/a'} `,
                d
            );
        });
    } catch (err) {
        console.error('Failed to fetch servers for RAM debug:', err);
    }
}

/**
 * Initiates installation of all mods currently in the selection queue.
 * 
 * @returns {Promise<void>}
 */
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
            const res = await mcmmFetch('/plugins/mcmm/api.php?' + params.toString());
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


/**
 * Sets the RAM limit value in the global and deployment settings.
 *
 * @param {string} amount - The RAM amount string (e.g., '2G', '4G').
 */
function setRam(amount) {
    const defaultInput = document.getElementById('default_memory');
    if (defaultInput) defaultInput.value = amount;
    document.querySelectorAll('.mcmm-ram-pill').forEach(p => {
        p.classList.toggle('active', p.textContent === amount);
    });
    const deployInput = document.getElementById('deploy_memory');
    if (deployInput) deployInput.value = amount;
}

/**
 * Toggles the open state of a custom dropdown select.
 *
 * @param {string} id - The ID of the select element.
 */
function toggleSelect(id) {
    const select = document.getElementById(id);
    document.querySelectorAll('.mcmm-select').forEach(s => {
        if (s.id !== id) s.classList.remove('open');
    });
    select.classList.toggle('open');
}

/**
 * Selects an option from a custom dropdown select and updates the hidden target.
 *
 * @param {string} selectId - The ID of the select element.
 * @param {string} value - The value of the selected option.
 * @param {string} text - The display text of the selected option.
 */
function selectOption(selectId, value, text) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const trigger = select.querySelector('.mcmm-select-trigger');
    const targetId = select.dataset.target;
    const hidden = targetId ? document.getElementById(targetId) : (select.parentElement.querySelector('input[type="hidden"]') || select.querySelector('input[type="hidden"]'));

    if (trigger) trigger.textContent = text;
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

        mcmmFetch('/plugins/mcmm/api.php?action=console_command&id=' + currentConsoleId + '&cmd=' + encodeURIComponent(cmd))
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
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=server_get&id=' + id);
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
                iconPrev.innerHTML = `<div style="width:48px; height:48px; border:1px solid var(--border); border-radius:10px; background: rgb(255 255 255 / 4%); display:flex; align-items:center; justify-content:center; color: var(--text-muted); font-size:0.8rem;">No Image</div><div style="color: var(--text-secondary); font-size: 0.85rem;">Preview</div>`;
            }
        }

        // Toggles (Handles both 'TRUE' string and actual boolean)
        const isTrue = val => {
            if (typeof val === 'boolean') return val;
            if (typeof val === 'string') return val.toUpperCase() === 'TRUE';
            return !!val;
        };

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

    const portVal = parseInt(document.getElementById('edit_port').value);
    const payload = {
        id: id,
        port: portVal,
        env: {
            SERVER_PORT: portVal,
            QUERY_PORT: portVal,
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
            const res = await mcmmFetch('/plugins/mcmm/api.php?action=server_update', {
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

/**
 * Initializes player count polling for all running servers.
 *
 * @returns {Promise<void>}
 */
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

/**
 * Refreshes the player count for a specific server.
 *
 * @param {HTMLElement} span - The span element to update.
 * @param {string} serverId - The ID of the server.
 * @param {string} port - The server port.
 * @returns {Promise<void>}
 */
async function refreshServerPlayerCount(span, serverId, port) {
    try {
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=server_players&id=${encodeURIComponent(serverId)}&port=${encodeURIComponent(port)}`);
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

/**
 * Fetches the list of backups from the API and updates the UI.
 *
 * @returns {Promise<void>}
 */
async function loadBackups() {
    const container = document.getElementById('backups-list-container');
    if (!container) return;

    try {
        const res = await mcmmFetch('/plugins/mcmm/api.php?action=backups_list');
        const data = await res.json();
        if (data.success) {
            renderBackups(data.data);
        }
    } catch (e) {
        console.error('Failed to load backups:', e);
    }
}

/**
 * Renders the list of backups in the UI.
 *
 * @param {Object[]} backups - Array of backup data objects.
 */
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
                    <div class="mcmm-backup-info-panel">
                        <div style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.4; margin-bottom: 0.75rem; font-weight: 800; border-bottom: 1px solid rgb(255 255 255 / 10%); padding-bottom: 0.5rem;">Modpack Manifest</div>
                        
                        <div class="mcmm-backup-panel-item">
                            <span class="material-symbols-outlined">dns</span>
                            <div class="mcmm-backup-panel-text">
                                <label>Container</label>
                                <span>${serverName}</span>
                            </div>
                        </div>
                        <div class="mcmm-backup-panel-item">
                            <span class="material-symbols-outlined">database</span>
                            <div class="mcmm-backup-panel-text">
                                <label>Archive Size</label>
                                <span>${sizeMb} MB</span>
                            </div>
                        </div>
                        <div class="mcmm-backup-panel-item">
                            <span class="material-symbols-outlined">calendar_today</span>
                            <div class="mcmm-backup-panel-text">
                                <label>Created</label>
                                <span>${dateStr} ${timeStr}</span>
                            </div>
                        </div>
                        ${b.mc_version ? `
                        <div class="mcmm-backup-panel-item">
                            <span class="material-symbols-outlined">layers</span>
                            <div class="mcmm-backup-panel-text">
                                <label>Version</label>
                                <span>Minecraft ${b.mc_version}</span>
                            </div>
                        </div>` : ''}
                        ${b.loader ? `
                        <div class="mcmm-backup-panel-item">
                            <span class="material-symbols-outlined">settings_input_component</span>
                            <div class="mcmm-backup-panel-text">
                                <label>Loader</label>
                                <span style="text-transform: capitalize;">${b.loader}</span>
                            </div>
                        </div>` : ''}
                    </div>
                </div>

                <div class="mcmm-backup-card-thumb-container">
                    <div class="mcmm-backup-card-bg-blur" style="background-image: url('${iconUrl}');"></div>
                    <div class="mcmm-backup-card-icon" style="background-image: url('${iconUrl}');">
                        ${!iconUrl ? `
                            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted); gap: 0.25rem;">
                                <span class="material-symbols-outlined" style="font-size: 1.8rem; opacity: 0.3;">inventory_2</span>
                            </div>
                        ` : ''}
                    </div>
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
                        <button class="mcmm-btn" style="background: rgb(248 113 113 / 10%); color: var(--danger); border: 1px solid rgb(248 113 113 / 20%); padding: 0.6rem;" onclick="deleteBackup('${b.name}')">
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

/**
 * Creates a backup for a specific server.
 *
 * @param {string} serverId - The ID of the server to backup.
 * @returns {Promise<void>}
 */
async function createBackup(serverId) {
    // Show backup status indicator
    const statusEl = document.getElementById(`backup-status-${serverId}`);
    if (statusEl) {
        statusEl.style.display = 'flex';
    }

    try {
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=backup_create&id=${serverId}`);
        const data = await res.json();

        // Hide backup status indicator
        if (statusEl) {
            statusEl.style.display = 'none';
        }

        if (data.success) {
            // Show success notification briefly
            if (statusEl) {
                statusEl.style.background = 'rgba(34, 197, 94, 0.95)';
                statusEl.innerHTML = '<span class="material-symbols-outlined" style="font-size: 0.9rem; color: white;">check_circle</span>Backup done';
                statusEl.style.display = 'flex';
                setTimeout(() => {
                    statusEl.style.display = 'none';
                    statusEl.style.background = 'rgba(59, 130, 246, 0.95)';
                    statusEl.innerHTML = '<span class="material-symbols-outlined" style="font-size: 0.9rem; animation: spin 1s linear infinite;">autorenew</span>Backing up...';
                }, 2000);
            }

            if (document.getElementById('tab-backups').classList.contains('active')) {
                loadBackups();
            }
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        // Hide indicator on error
        if (statusEl) {
            statusEl.style.display = 'none';
        }
        alert('Failed to trigger backup: ' + e.message);
    }
}

/**
 * Deletes a specific backup by name.
 *
 * @param {string} name - The name of the backup archive.
 * @returns {Promise<void>}
 */
async function deleteBackup(name) {
    if (!confirm(`Are you sure you want to delete backup ${name}?`)) return;
    try {
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=backup_delete&name=${encodeURIComponent(name)}`);
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

/**
 * Reinstalls a server from a specific backup archive.
 *
 * @param {string} name - The name of the backup archive.
 * @returns {Promise<void>}
 */
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
        const res = await mcmmFetch(`/plugins/mcmm/api.php?action=backup_reinstall&name=${encodeURIComponent(name)}`);
        const data = await res.json();
        if (data.success) {
            alert('Server reinstalled successfully!');
            switchTab('servers');
        } else {
            alert('Error: ' + data.error);
            container.innerHTML = originalHtml;
        }
    } catch (e) {
        alert('Failed to reinstall: ' + e.message);
        container.innerHTML = originalHtml;
    }
}
