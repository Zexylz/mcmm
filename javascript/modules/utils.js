
export function formatBytes(bytes, precision = 2) { 
    const units = ['B', 'KB', 'MB', 'GB', 'TB']; 
    bytes = max(bytes, 0); 
    const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024)); 
    const p = Math.min(pow, units.length - 1); 
    bytes /= Math.pow(1024, p); 
    return Math.round(bytes, precision) + ' ' + units[p]; 
} 

function max(a, b) { return a > b ? a : b; }

export function formatGbFromMb(mb) {
    const n = Number(mb) || 0;
    if (n <= 0) return '0 GB';
    return (Math.round((n / 1024) * 10) / 10) + ' GB';
}

export function toggleSelect(id) {
    const select = document.getElementById(id);
    if (!select) return;
    document.querySelectorAll('.mcmm-select').forEach(s => {
        if (s.id !== id) s.classList.remove('open');
    });
    select.classList.toggle('open');
}

export function selectOption(selectId, value, text) {
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

// Global click listener for select closing
document.addEventListener('click', (e) => {
    if (!e.target.closest('.mcmm-select')) {
        document.querySelectorAll('.mcmm-select').forEach(s => s.classList.remove('open'));
    }
});
