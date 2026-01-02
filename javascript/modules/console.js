let consoleInterval;
let currentConsoleId = null;

export function openConsole(serverId, serverName) {
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

export async function fetchLogs() {
    if (!currentConsoleId) return;
    try {
        const res = await fetch('/plugins/mcmm/api.php?action=console_logs&id=' + currentConsoleId);
        const data = await res.json();
        if (data.success) {
            const output = document.getElementById('consoleOutput');
            const wasAtBottom = output.scrollTop + output.clientHeight >= output.scrollHeight - 50;

            // Clean up logs: Strip ANSI color codes
            let cleanLogs = (data.logs || '').replace(/\\x1B\[[0-9;]*[a-zA-Z]/g, '');

            output.textContent = cleanLogs;

            if (wasAtBottom) {
                output.scrollTop = output.scrollHeight;
            }
        }
    } catch (e) {
        console.error('Console fetch error:', e);
    }
}

export function closeConsole() {
    document.getElementById('consoleModal').classList.remove('open');
    if (consoleInterval) clearInterval(consoleInterval);
    currentConsoleId = null;
}

// Setup console input listener (if element exists)
// Note: This logic was in mcmm.js. Better to init it here or in main.js
// We can export an init function or just rely on main.js to import it and side-effects.
// But modules are strict mode and scoped.
// We can attach the listener if the element exists immediately, or export an init function.
// Since `mcmm.js` had it at top level, it runs on load.
// I'll export an `initConsole` function.

export function initConsole() {
    const input = document.getElementById('consoleInput');
    if (input) {
        input.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                const cmd = this.value;
                if (!cmd || !currentConsoleId) return;

                const inputField = this;
                inputField.disabled = true;

                const output = document.getElementById('consoleOutput');
                output.textContent += `\n> ${cmd}\n`;
                output.scrollTop = output.scrollHeight;

                fetch('/plugins/mcmm/api.php?action=console_command&id=' + currentConsoleId + '&cmd=' + encodeURIComponent(cmd))
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
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
    }
}
