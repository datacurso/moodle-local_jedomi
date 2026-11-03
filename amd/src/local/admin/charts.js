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
 * Small canvas charts of the Agents tab.
 *
 * @module     local_jedomi/local/admin/charts
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {string[]} Series colours. */
const COLORS = ['#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

/**
 * Size the canvas to its container and return a cleared drawing context.
 *
 * @param {HTMLCanvasElement} canvas Chart canvas.
 * @returns {CanvasRenderingContext2D}
 */
const prepare = (canvas) => {
    const parent = canvas.parentElement;
    canvas.width = (parent ? parent.clientWidth : 200) || 200;
    canvas.height = (parent ? parent.clientHeight : 160) || 160;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    return ctx;
};

/**
 * Draw a centred message instead of a chart.
 *
 * @param {HTMLCanvasElement|null} canvas Chart canvas.
 * @param {string} message Text to show.
 */
export const drawMessage = (canvas, message) => {
    if (!canvas) {
        return;
    }
    const ctx = prepare(canvas);
    ctx.fillStyle = '#9ca3af';
    ctx.font = '12px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(message, canvas.width / 2, canvas.height / 2);
};

/**
 * Draw a pie chart with a legend.
 *
 * @param {HTMLCanvasElement|null} canvas Chart canvas.
 * @param {Array<{label: string, count: number}>} data Slices.
 * @param {string} nodata Text shown when there is no data.
 */
export const drawPie = (canvas, data, nodata) => {
    if (!canvas) {
        return;
    }
    const total = (data || []).reduce((sum, item) => sum + (parseInt(item.count, 10) || 0), 0);
    if (!total) {
        drawMessage(canvas, nodata);
        return;
    }
    const ctx = prepare(canvas);
    const cx = canvas.width / 2;
    const cy = canvas.height / 2 - 10;
    const radius = Math.min(cx, cy) - 5;
    let start = -Math.PI / 2;
    data.forEach((item, i) => {
        const angle = (item.count / total) * 2 * Math.PI;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, radius, start, start + angle);
        ctx.closePath();
        ctx.fillStyle = COLORS[i % COLORS.length];
        ctx.fill();
        if (angle > 0.3) {
            const middle = start + angle / 2;
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 10px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(Math.round(item.count / total * 100) + '%',
                cx + Math.cos(middle) * radius * 0.6, cy + Math.sin(middle) * radius * 0.6);
        }
        start += angle;
    });
    let legendy = 5;
    data.forEach((item, i) => {
        ctx.fillStyle = COLORS[i % COLORS.length];
        ctx.fillRect(4, legendy, 8, 8);
        ctx.fillStyle = '#374151';
        ctx.font = '9px sans-serif';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';
        ctx.fillText((item.label || '') + ' (' + item.count + ')', 15, legendy);
        legendy += 13;
    });
};

/**
 * Draw a bar chart with labels and values.
 *
 * @param {HTMLCanvasElement|null} canvas Chart canvas.
 * @param {Array<{label: string, count: number}>} data Bars.
 * @param {string} nodata Text shown when there is no data.
 */
export const drawBar = (canvas, data, nodata) => {
    if (!canvas) {
        return;
    }
    if (!data || !data.length) {
        drawMessage(canvas, nodata);
        return;
    }
    const ctx = prepare(canvas);
    const pad = {t: 20, b: 18, l: 5, r: 5};
    const width = canvas.width - pad.l - pad.r;
    const height = canvas.height - pad.t - pad.b;
    const slot = width / data.length;
    const barwidth = Math.min(28, slot - 2);
    const max = data.reduce((m, item) => Math.max(m, parseInt(item.count, 10) || 0), 1);
    data.forEach((item, i) => {
        const value = parseInt(item.count, 10) || 0;
        const barheight = (value / max) * height;
        const x = pad.l + slot * i + (slot - barwidth) / 2;
        const y = pad.t + height - barheight;
        ctx.fillStyle = COLORS[i % COLORS.length];
        ctx.fillRect(x, y, barwidth, barheight);
        ctx.fillStyle = '#374151';
        ctx.font = '8px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        let label = item.label || '';
        if (label.length > 8) {
            label = label.substring(0, 8) + '…';
        }
        ctx.fillText(label, x + barwidth / 2, canvas.height - pad.b + 3);
        ctx.fillStyle = '#6b7280';
        ctx.font = 'bold 9px sans-serif';
        ctx.textBaseline = 'bottom';
        ctx.fillText(String(value), x + barwidth / 2, y - 1);
    });
};
