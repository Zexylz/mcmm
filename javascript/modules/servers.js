import { formatGbFromMb } from './utils.js';

export function controlServer(id, action) {
    if (confirm(`Are you sure you want to ${action} this server?`)) {
        fetch('/plugins/mcmm/api.php?action=server_control&id=' + id + '&cmd=' + action)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }
}

export function deleteServer(id) {
    if (!confirm('Delete this server container?')) return;
    fetch('/plugins/mcmm/api.php?action=server_delete&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Server deleted');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

export async function startAgents() {
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=start_agents');
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON response from start_agents:', text);
            alert('Error: start_agents did not return JSON. Check server logs.');
            return;
        }
        if (data.success) {
            alert('Metrics agents restarted for running servers.');
            await logRamDebug();
            setTimeout(() => location.reload(), 1200);
        } else {
            alert('Error: ' + (data.error || 'Failed to start agents'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

export async function logRamDebug() {
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=servers&_=' + Date.now());
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
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

async function refreshServerMetricsOnce() {
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=servers&_=' + Date.now());
        const data = await res.json();
        if (!data || !data.success || !Array.isArray(data.data)) return;

        data.data.forEach(s => {
            const id = String(s.id || '');
            if (!id) return;

            const row = document.querySelector(`.mcmm-server-row[data-server-id="${CSS.escape(id)}"]`);
            if (!row) return;

            const usedMb = Number(s.ramUsedMb || 0);
            const capMb = Number(s.ramLimitMb || 0);

            const pct = capMb > 0 ? (usedMb / capMb) * 100 : Number(s.ram || 0);
            const pctClamped = Math.max(0, Math.min(100, pct));

            const ramText = row.querySelector('.mcmm-ram-text');
            if (ramText) {
                ramText.textContent = `${formatGbFromMb(usedMb)} / ${capMb > 0 ? formatGbFromMb(capMb) : 'N/A'}`;
            }
            const ramFill = row.querySelector('.mcmm-ram-fill');
            if (ramFill) ramFill.style.width = `${pctClamped}%`;

            const cpu = Number(s.cpu || 0);
            const cpuClamped = Math.max(0, Math.min(100, cpu));
            const cpuText = row.querySelector('.mcmm-cpu-text');
            if (cpuText) cpuText.textContent = `${Math.round(cpuClamped)}%`;
            const cpuFill = row.querySelector('.mcmm-cpu-fill');
            if (cpuFill) cpuFill.style.width = `${cpuClamped}%`;
        });
    } catch (_) {
        // ignore polling failures
    }
}

let serverMetricsInterval = null;
export function startServerMetricsPolling() {
    // Only run if server rows exist
    if (!document.querySelector('.mcmm-server-row[data-server-id]')) return;
    // initial refresh
    refreshServerMetricsOnce();
    if (serverMetricsInterval) clearInterval(serverMetricsInterval);
    serverMetricsInterval = setInterval(refreshServerMetricsOnce, 5000);
}

export async function initServerPlayerCounts() {
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
    } catch (_) {
        // ignore failures
    }
}
