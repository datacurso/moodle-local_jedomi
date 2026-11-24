// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Markdown format of the Jedomi answers: code, links (site and external), tables with CSV, lists and headings.
 *
 * @module     local_jedomi/local/sidebar/markdown
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {cfg} from './state';

// An address as the browser reads it: TAB, LF and CR are removed anywhere ("/<TAB>/evil.example" opens
// "//evil.example"), and control characters and spaces at both ends (JEDOMI-SEC-005).
// eslint-disable-next-line no-control-regex
const normaliseUrl = (url) => url.replace(/[\t\n\r]/g, '').replace(/^[\u0000-\u0020]+|[\u0000-\u0020]+$/g, '');

// Whether an address opens a page of this site: a site-relative path, or an address with the origin of the site once
// resolved against it. An address that merely contains the site address (in its query, or as user name before an @)
// is external.
const isSiteUrl = (url) => {
    url = normaliseUrl(url);
    if (/^\/(?![\/\\])/.test(url)) {
        return true;
    }
    try {
        return !!cfg.wwwroot && new URL(url, cfg.wwwroot).origin === new URL(cfg.wwwroot).origin;
    } catch (e) {
        return false;
    }
};

// Host of an absolute address, shown next to an external link; empty when it cannot be read.
const urlHost = (url) => {
    try {
        return new URL(url).host;
    } catch (e) {
        return '';
    }
};

// Detect the Moodle page type from the URL.
const getMoodlePageInfo = (url) => {
    if (!isSiteUrl(url)) {
        return null;
    }

    // The type labels are language strings, in the language of the user.
    const L = cfg.strings || {};
    const patterns = [
        {re: /\/course\/view\.php\?id=(\d+)/, icon: '📚', type: L.link_type_course},
        {re: /\/user\/view\.php\?id=(\d+)/, icon: '👤', type: L.link_type_profile},
        {re: /\/mod\/assign\/view\.php\?id=(\d+)/, icon: '📝', type: L.link_type_assign},
        {re: /\/mod\/quiz\/view\.php\?id=(\d+)/, icon: '❓', type: L.link_type_quiz},
        {re: /\/grade\/report\/user/, icon: '📊', type: L.link_type_grades},
        {re: /\/admin\//, icon: '⚙️', type: L.link_type_admin},
        {re: /\/message\//, icon: '💬', type: L.link_type_messages},
    ];

    for (const p of patterns) {
        if (p.re.test(url)) {
            const match = url.match(p.re);
            return {icon: p.icon, type: p.type, id: match?.[1]};
        }
    }
    return {icon: '🔗', type: L.link_type_moodle || 'Moodle', id: null};
};

// Italic text: _text_ only when the underscores are not part of a word, a path or an address, so names such as
// course_modules or mod_assign, and addresses with underscores, are shown as they are.
const ITALIC_PATTERN = /(^|[^\w\\/.:=&?%-])_([^_\s](?:[^_\n]*[^_\s])?)_(?![\w/])/g;

export const renderMarkdown = (text) => {
    if (!text) {
        return '';
    }
    // Code and links are set aside while the rest is formatted, so their content (names and addresses with
    // underscores, marks of lists or headings) is never formatted; they are put back at the end.
    const kept = [];
    const keep = (html) => `\u0000${kept.push(html) - 1}\u0000`;
    // eslint-disable-next-line no-control-regex
    let html = text.replace(/\u0000/g, '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
        // Code blocks
        .replace(/```[\w]*\n([\s\S]*?)```/g, (match, code) => keep(`<pre><code>${code}</code></pre>`))
        // Inline code
        .replace(/`([^`\n]+)`/g, (match, code) => keep(`<code>${code}</code>`))
        // Bold
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        // Rich Moodle links [text](url): a preview card for Moodle URLs.
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
            url = normaliseUrl(url);
            // Only web and site-relative links: model output can carry javascript: or data: URLs. A path starting
            // with /\ is not site-relative: browsers read it as another host.
            if (!/^(https?:\/\/|\/(?![\/\\]))/i.test(url)) {
                return linkText;
            }
            const pageInfo = getMoodlePageInfo(url);
            if (pageInfo) {
                return keep(`<a href="${url}" class="jedomi-moodle-link" data-type="${pageInfo.type}">` +
                       `${pageInfo.icon} <span data-link-text>${linkText}</span> ` +
                       `<span class="jedomi-link-badge">${pageInfo.type} ↗</span></a>`);
            }
            // A link to another site is marked as external with its host, opens apart from the panel and sends no
            // referrer (JEDOMI-SEC-005): the AI may write a link prompted by untrusted content.
            const S = cfg.strings || {};
            const host = urlHost(url).replace(/[&<>"']/g, '');
            const label = (S.link_external || '').replace(/[&<>"']/g, '');
            return keep(`<a href="${url}" class="jedomi-external-link" target="_blank" rel="noopener noreferrer"` +
                   ` title="${label}">` +
                   `<span data-link-text>${linkText}</span> ` +
                   `<span class="jedomi-external-badge" aria-hidden="true">↗ ${host}</span>` +
                   `<span class="sr-only">(${label}: ${host})</span></a>`);
        })
        // Bare web addresses keep their underscores.
        .replace(/\bhttps?:\/\/[^\s<]+/g, (url) => keep(url))
        // Italic
        .replace(ITALIC_PATTERN, '$1<em>$2</em>')
        // Headings: # to ###### become small headings that fit in the panel.
        .replace(/^(#{1,6}) +(.+?)#*\s*$/gm, (match, marks, title) =>
            `<h${Math.min(marks.length + 3, 6)} class="jedomi-md-heading">${title}</h${Math.min(marks.length + 3, 6)}>`)
        // ── Markdown tables: ONE table per block, with a working CSV button ──
        // Step 1: mark rows and separators.
        .replace(/^\|(.+)\|$/gm, (row) => {
            if (row.replace(/[\|\s\-:]/g, '') === '') {
                return '§SEP§';
            }
            const cells = row.slice(1, -1).split('|').map(c => c.trim());
            return '§ROW§' + cells.join('§CELL§');
        })
        // Step 2: remove separators.
        .replace(/§SEP§\n?/g, '')
        // Step 3: group ALL consecutive §ROW§ into ONE table.
        .replace(/((?:§ROW§[^\n]*\n?)+)/g, (block) => {
            const rows = block.trim().split('\n')
                .filter(r => r.startsWith('§ROW§'))
                .map(r => r.replace('§ROW§', '').split('§CELL§'));

            // At least 2 real rows (header + 1 data row) to build a table.
            if (rows.length < 2) {
                // A single row: render it as plain text.
                return '<p>' + rows[0].join(' | ') + '</p>';
            }

            // First row = <th> headers.
            const thead = '<thead><tr>' +
                rows[0].map(c => `<th>${c}</th>`).join('') +
                '</tr></thead>';

            // Rest = data <td>.
            const tbody = '<tbody>' + rows.slice(1).map(r =>
                '<tr>' + r.map(c => `<td>${c}</td>`).join('') + '</tr>'
            ).join('') + '</tbody>';

            // CSV button through a data attribute (event delegation, no inline onclick).
            const csvBtn = `<button class="jedomi-csv-btn" data-csv-download="true">${cfg.strings?.csv_download || ''}</button>`;

            return `<div class="jedomi-table-wrap">${csvBtn}<div class="jedomi-table-scroll">` +
                `<table class="jedomi-table">${thead}${tbody}</table></div></div>`;
        })
        // Unordered lists
        .replace(/^[-*] (.+)$/gm, '<li>$1</li>')
        .replace(/((?:<li>.*<\/li>\n?)+)/g, (block) => `<ul>${block.replace(/\n/g, '')}</ul>\n`)
        // Numbered lists, which keep their first number.
        .replace(/^(\d+)[.)] (.+)$/gm, '<li data-number="$1">$2</li>')
        .replace(/((?:<li data-number="\d+">.*<\/li>\n?)+)/g, (block) => {
            const start = parseInt(block.match(/data-number="(\d+)"/)[1], 10);
            const items = block.replace(/<li data-number="\d+">/g, '<li>').replace(/\n/g, '');
            return `<ol${start !== 1 ? ` start="${start}"` : ''}>${items}</ol>\n`;
        })
        // No line break right after a heading or a list.
        .replace(/(<\/h[4-6]>|<\/[ou]l>)\n/g, '$1')
        // Paragraphs
        .replace(/\n\n/g, '</p><p>')
        .replace(/\n/g, '<br>');

    // eslint-disable-next-line no-control-regex
    html = html.replace(/\u0000(\d+)\u0000/g, (match, index) => kept[index]);
    return '<p>' + html + '</p>';
};
