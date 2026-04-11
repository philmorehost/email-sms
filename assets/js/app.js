/**
 * PhilmoreHost Marketing Suite — app.js
 * Handles: real-time security log, SMS counter, email builder, CSV import,
 * tab switching, toast notifications, modal management, sidebar toggle.
 */

'use strict';

// ─── DOM Ready ────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initThemeToggle();
    initSidebar();
    initTabs();
    initToasts();
    initModals();
    initSMSCounter();
    initSecurityLogPolling();
    initEmailBuilder();
    initCSVImport();
    initCountrySearch();
    initFormValidation();
    initButtonLoaders();
});

// ─── Theme Toggle ─────────────────────────────────────────────────────────────
function initThemeToggle() {
    const btn = document.getElementById('themeToggle');
    if (!btn) return;
    const html = document.documentElement;
    function apply(theme) {
        html.setAttribute('data-theme', theme);
        btn.querySelector('.theme-icon').textContent = theme === 'dark' ? '🌙' : '☀️';
    }
    btn.addEventListener('click', () => {
        const current = html.getAttribute('data-theme') || 'dark';
        const next = current === 'dark' ? 'light' : 'dark';
        apply(next);
        document.cookie = `theme=${next};path=/;max-age=${60 * 60 * 24 * 365}`;
    });
}

// ─── Sidebar ──────────────────────────────────────────────────────────────────
function initSidebar() {
    const toggle  = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    if (!toggle || !sidebar) return;

    toggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
    });

    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && e.target !== toggle) {
                sidebar.classList.remove('open');
            }
        }
    });
}

// ─── Tab Switching ────────────────────────────────────────────────────────────
function initTabs() {
    document.querySelectorAll('.tabs').forEach(tabGroup => {
        const buttons = tabGroup.querySelectorAll('.tab-btn');
        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.dataset.tab;

                buttons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                const container = tabGroup.closest('.tab-container') || document;
                container.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });

                const targetEl = container.querySelector(`#tab-${target}`) ||
                                  document.getElementById(`tab-${target}`);
                if (targetEl) targetEl.classList.add('active');

                history.replaceState(null, '', '#' + target);
            });
        });
    });

    const hash = location.hash.replace('#', '');
    if (hash) {
        const btn = document.querySelector(`.tab-btn[data-tab="${hash}"]`);
        if (btn) btn.click();
    } else {
        document.querySelectorAll('.tabs').forEach(tabGroup => {
            const first = tabGroup.querySelector('.tab-btn');
            if (first && !tabGroup.querySelector('.tab-btn.active')) first.click();
        });
    }
}

// ─── Toast Notifications ──────────────────────────────────────────────────────
function initToasts() {
    window.showToast = function(message, type = 'info', duration = 3000) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `<span>${icons[type] || 'ℹ️'}</span><span>${escapeHtml(message)}</span>`;

        container.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideInRight 0.3s ease reverse';
            toast.addEventListener('animationend', () => toast.remove());
        }, duration);

        toast.addEventListener('click', () => toast.remove());
    };

    const flash = document.getElementById('flashMessage');
    if (flash) {
        showToast(flash.dataset.message, flash.dataset.type || 'info');
    }
}

// ─── Modal Management ─────────────────────────────────────────────────────────
function initModals() {
    document.querySelectorAll('[data-modal]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = trigger.dataset.modal;
            openModal(modalId);
        });
    });

    document.querySelectorAll('.modal-close, [data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            const overlay = btn.closest('.modal-overlay');
            if (overlay) closeModal(overlay.id);
        });
    });

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal(overlay.id);
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(o => closeModal(o.id));
        }
    });

    window.openModal  = (id) => document.getElementById(id)?.classList.add('open');
    window.closeModal = (id) => document.getElementById(id)?.classList.remove('open');
}

// ─── SMS Character Counter ────────────────────────────────────────────────────
function initSMSCounter() {
    const textareas = document.querySelectorAll('[data-sms-counter]');
    textareas.forEach(textarea => {
        const counterId = textarea.dataset.smsCounter;
        const counter   = document.getElementById(counterId);
        if (!counter) return;

        function updateCounter() {
            const text   = textarea.value;
            const len    = text.length;
            const isGsm  = isGSM7(text);
            const limit1 = isGsm ? 160 : 70;
            const limitN = isGsm ? 153 : 67;

            let units, remaining;
            if (len <= limit1) {
                units     = 1;
                remaining = limit1 - len;
            } else {
                units     = Math.ceil(len / limitN);
                remaining = (units * limitN) - len;
            }

            counter.innerHTML = `
                <span>Chars: <strong class="count-chars">${len}</strong></span>
                <span>SMS Units: <strong class="count-units">${units}</strong></span>
                <span>Remaining: <strong class="count-remaining">${remaining}</strong></span>
                <span>Encoding: <strong>${isGsm ? 'GSM-7' : 'Unicode'}</strong></span>
            `;
        }

        textarea.addEventListener('input', updateCounter);
        updateCounter();
    });
}

function isGSM7(text) {
    const gsm7 = new Set(
        '@\u00a3$\u00a5\u00e8\u00e9\u00f9\u00ec\u00f2\u00c7\nØø\rÅå\u0394_\u03a6\u0393\u039b\u03a9\u03a0\u03a8\u03a3\u0398\u039e\x1b\u00c6\u00e6\u00df\u00c9 !"#\u00a4%&\'()*+,-./0123456789:;<=>?' +
        '\u00a1ABCDEFGHIJKLMNOPQRSTUVWXYZ\u00c4\u00d6\u00d1\u00dc\u00a7\u00bfabcdefghijklmnopqrstuvwxyz\u00e4\u00f6\u00f1\u00fc\u00e0'
    );
    return [...text].every(c => gsm7.has(c));
}

// ─── Real-time Security Log Polling ──────────────────────────────────────────
let securityLogInterval = null;

function initSecurityLogPolling() {
    const logContainer = document.getElementById('securityLog');
    if (!logContainer) return;

    async function fetchLogs() {
        try {
            const res  = await fetch('/api/security-log.php');
            const data = await res.json();
            if (!data.logs) return;

            const icons = {
                'failed_login':     '🔴',
                'successful_login': '🟢',
                'auto_ban':         '🚫',
                'blocked_ip':       '🛑',
                'blocked_country':  '🌍',
            };

            const html = data.logs.map(log => `
                <div class="log-entry ${log.is_trusted ? 'trusted' : ''}">
                    <span class="log-icon">${log.is_trusted ? '👑' : (icons[log.event_type] || '📋')}</span>
                    <span class="log-event">${escapeHtml(log.event_type)}</span>
                    <span class="log-ip">${escapeHtml(log.ip_address || '-')}</span>
                    <span class="log-detail">${escapeHtml((log.details || '').substring(0, 60))}</span>
                    <span class="log-time">${escapeHtml(log.time_ago || '')}</span>
                </div>
            `).join('');

            logContainer.innerHTML = html || '<p class="empty-state">No security events yet.</p>';
        } catch (err) {
            // Silently fail
        }
    }

    fetchLogs();
    securityLogInterval = setInterval(fetchLogs, 5000);
}

// ─── Email Drag-and-Drop Builder ──────────────────────────────────────────────
function initEmailBuilder() {
    const canvas   = document.getElementById('emailCanvas');
    const dropZone = document.getElementById('canvasDropZone');
    if (!canvas || !dropZone) return;

    const blockTypes = {
        header:  { icon: '🔤', label: 'Header',   template: '<h1 style="text-align:center;color:#333;font-family:sans-serif;padding:20px">Your Header Text</h1>' },
        text:    { icon: '📝', label: 'Text',      template: '<p style="color:#555;font-family:sans-serif;padding:10px 20px;line-height:1.6">Your paragraph text goes here. Click to edit.</p>' },
        image:   { icon: '🖼️', label: 'Image',     template: '<div style="text-align:center;padding:10px"><img src="https://via.placeholder.com/600x200" style="max-width:100%;height:auto" alt="Image"></div>' },
        button:  { icon: '🔘', label: 'Button',    template: '<div style="text-align:center;padding:20px"><a href="#" style="background:#6c63ff;color:#fff;padding:12px 30px;border-radius:8px;text-decoration:none;font-family:sans-serif;font-weight:bold">Click Here</a></div>' },
        divider: { icon: '➖', label: 'Divider',   template: '<hr style="border:none;border-top:1px solid #eee;margin:10px 20px">' },
        spacer:  { icon: '⬜', label: 'Spacer',    template: '<div style="height:20px"></div>' },
    };

    let blocks    = [];
    let dragData  = null;

    const blockPanel = document.querySelector('.builder-blocks-list');
    if (blockPanel) {
        Object.entries(blockTypes).forEach(([type, info]) => {
            const el = document.createElement('div');
            el.className    = 'block-item';
            el.draggable    = true;
            el.dataset.type = type;
            el.innerHTML    = `<span>${info.icon}</span><span>${info.label}</span>`;
            el.addEventListener('dragstart', (e) => {
                dragData = type;
                e.dataTransfer.effectAllowed = 'copy';
            });
            blockPanel.appendChild(el);
        });
    }

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('drag-over');
        e.dataTransfer.dropEffect = 'copy';
    });

    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        if (dragData) {
            addBlock(dragData);
            dragData = null;
        }
    });

    function addBlock(type, html = null) {
        const id   = 'block_' + Date.now();
        const tmpl = html || blockTypes[type]?.template || '';
        blocks.push({ id, type, html: tmpl });
        renderCanvas();
        updateHiddenFields();
    }

    function renderCanvas() {
        if (blocks.length === 0) {
            dropZone.style.display = 'flex';
            return;
        }
        dropZone.style.display = 'none';

        canvas.querySelectorAll('.canvas-block').forEach(b => b.remove());

        blocks.forEach((block, idx) => {
            const el = document.createElement('div');
            el.className  = 'canvas-block';
            el.dataset.id = block.id;
            el.innerHTML  = `
                <div class="canvas-block-actions">
                    <button class="btn btn-sm btn-secondary" onclick="editBlock('${block.id}')" title="Edit">✏️</button>
                    <button class="btn btn-sm btn-secondary" onclick="moveBlockUp(${idx})" title="Up" ${idx === 0 ? 'disabled' : ''}>↑</button>
                    <button class="btn btn-sm btn-secondary" onclick="moveBlockDown(${idx})" title="Down" ${idx === blocks.length - 1 ? 'disabled' : ''}>↓</button>
                    <button class="btn btn-sm btn-danger"    onclick="removeBlock('${block.id}')" title="Delete">🗑️</button>
                </div>
                <div class="canvas-block-content">${block.html}</div>
            `;
            canvas.insertBefore(el, dropZone);
        });
    }

    window.editBlock = function(id) {
        const block = blocks.find(b => b.id === id);
        if (!block) return;
        const newHtml = prompt('Edit HTML:', block.html);
        if (newHtml !== null) {
            block.html = newHtml;
            renderCanvas();
            updateHiddenFields();
        }
    };

    window.removeBlock = function(id) {
        blocks = blocks.filter(b => b.id !== id);
        renderCanvas();
        updateHiddenFields();
        if (blocks.length === 0) dropZone.style.display = 'flex';
    };

    window.moveBlockUp = function(idx) {
        if (idx > 0) {
            [blocks[idx - 1], blocks[idx]] = [blocks[idx], blocks[idx - 1]];
            renderCanvas();
            updateHiddenFields();
        }
    };

    window.moveBlockDown = function(idx) {
        if (idx < blocks.length - 1) {
            [blocks[idx], blocks[idx + 1]] = [blocks[idx + 1], blocks[idx]];
            renderCanvas();
            updateHiddenFields();
        }
    };

    function updateHiddenFields() {
        const htmlField   = document.getElementById('templateHtml');
        const designField = document.getElementById('templateDesign');

        const fullHtml = `<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:sans-serif;background:#fff;margin:0;padding:0}.email-wrap{max-width:600px;margin:0 auto;background:#fff}</style></head><body><div class="email-wrap">${blocks.map(b => b.html).join('\n')}</div></body></html>`;

        if (htmlField)   htmlField.value   = fullHtml;
        if (designField) designField.value = JSON.stringify({ blocks });
    }

    const designField = document.getElementById('templateDesign');
    if (designField && designField.value) {
        try {
            const design = JSON.parse(designField.value);
            if (design.blocks) {
                blocks = design.blocks;
                renderCanvas();
            }
        } catch (e) { /* ignore invalid design data */ }
    }
}

// ─── CSV Import ───────────────────────────────────────────────────────────────
function initCSVImport() {
    const dropZone = document.querySelector('.csv-drop-zone');
    if (!dropZone) return;

    const fileInput = dropZone.querySelector('input[type=file]');

    dropZone.addEventListener('click', () => fileInput?.click());
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        const file = e.dataTransfer.files[0];
        if (file) handleCSVFile(file);
    });

    fileInput?.addEventListener('change', () => {
        if (fileInput.files[0]) handleCSVFile(fileInput.files[0]);
    });

    async function handleCSVFile(file) {
        if (!file.name.endsWith('.csv') && file.type !== 'text/csv') {
            showToast('Please upload a CSV file', 'error');
            return;
        }

        const text = await file.text();
        const rows = text.split('\n').filter(r => r.trim()).map(r => r.split(',').map(c => c.trim().replace(/^"|"$/g, '')));
        if (rows.length < 2) {
            showToast('CSV appears to be empty', 'warning');
            return;
        }

        const headers = rows[0];
        const preview = rows.slice(1, 6);

        const previewContainer = document.getElementById('csvPreview');
        if (previewContainer) {
            previewContainer.innerHTML = `
                <div class="csv-preview">
                    <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:.5rem">Preview (first 5 rows):</p>
                    <div class="table-wrap">
                    <table class="table">
                        <thead><tr>${headers.map(h => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead>
                        <tbody>${preview.map(row => `<tr>${row.map(c => `<td>${escapeHtml(c)}</td>`).join('')}</tr>`).join('')}</tbody>
                    </table>
                    </div>
                </div>
            `;
        }

        const csvField = document.getElementById('csvData');
        if (csvField) csvField.value = text;

        dropZone.innerHTML = `<p>✅ ${escapeHtml(file.name)} (${rows.length - 1} contacts)</p><p style="font-size:.8rem;color:var(--text-muted)">Click to change</p>`;
        showToast(`CSV loaded: ${rows.length - 1} contacts`, 'success');
    }
}

// ─── Country Search ───────────────────────────────────────────────────────────
function initCountrySearch() {
    const searchInput = document.getElementById('countrySearch');
    const countryList = document.getElementById('countryList');
    if (!searchInput || !countryList) return;

    searchInput.addEventListener('input', () => {
        const query = searchInput.value.toLowerCase();
        countryList.querySelectorAll('tr[data-country]').forEach(row => {
            const name = (row.dataset.country || '').toLowerCase();
            const code = (row.dataset.code || '').toLowerCase();
            row.style.display = (name.includes(query) || code.includes(query)) ? '' : 'none';
        });
    });
}

// ─── Form Validation ──────────────────────────────────────────────────────────
function initFormValidation() {
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', (e) => {
            let valid = true;

            form.querySelectorAll('[required]').forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = 'var(--danger)';
                    const hint = field.nextElementSibling;
                    if (hint?.classList.contains('form-error')) hint.remove();
                    const err = document.createElement('span');
                    err.className   = 'form-error';
                    err.textContent = 'This field is required';
                    field.after(err);
                } else {
                    field.style.borderColor = '';
                    const hint = field.nextElementSibling;
                    if (hint?.classList.contains('form-error')) hint.remove();
                }
            });

            form.querySelectorAll('[type=email]').forEach(field => {
                if (field.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
                    valid = false;
                    field.style.borderColor = 'var(--danger)';
                }
            });

            const pass1 = form.querySelector('[name=password]');
            const pass2 = form.querySelector('[name=password_confirm]');
            if (pass1 && pass2 && pass1.value !== pass2.value) {
                valid = false;
                pass2.style.borderColor = 'var(--danger)';
                showToast('Passwords do not match', 'error');
            }

            if (!valid) e.preventDefault();
        });
    });
}

// ─── Button Loaders ───────────────────────────────────────────────────────────
function initButtonLoaders() {
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('[type=submit]');
            if (btn && !btn.dataset.noLoader) {
                const original = btn.innerHTML;
                btn.disabled  = true;
                btn.innerHTML = '<span>⏳</span> Processing...';
                setTimeout(() => {
                    btn.disabled  = false;
                    btn.innerHTML = original;
                }, 10000);
            }
        });
    });
}

// ─── AJAX Helper ─────────────────────────────────────────────────────────────
async function ajaxPost(url, data) {
    const formData = new FormData();
    Object.entries(data).forEach(([k, v]) => formData.append(k, v));
    const res = await fetch(url, { method: 'POST', body: formData });
    return res.json();
}

// ─── Utilities ────────────────────────────────────────────────────────────────
function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
}

function timeAgo(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

// ─── Confirm Delete ───────────────────────────────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', (e) => {
        if (!confirm(el.dataset.confirm || 'Are you sure?')) {
            e.preventDefault();
        }
    });
});

// ─── Select All Checkboxes ────────────────────────────────────────────────────
document.querySelectorAll('[data-select-all]').forEach(checkbox => {
    checkbox.addEventListener('change', () => {
        const target = checkbox.dataset.selectAll;
        document.querySelectorAll(`input[name="${target}"]`).forEach(cb => {
            cb.checked = checkbox.checked;
        });
    });
});

// ─── Auto-dismiss alerts ─────────────────────────────────────────────────────
document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    setTimeout(() => {
        alert.style.opacity    = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(() => alert.remove(), 500);
    }, parseInt(alert.dataset.autoDismiss) || 3000);
});
