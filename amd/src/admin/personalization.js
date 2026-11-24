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
 * Personalization tab of the Jedomi settings: live preview of the floating button.
 *
 * The preview follows the avatar, corner, distances and panel side fields of the form. Dragging the button in the
 * preview, or moving it with the arrow keys, writes its distances back to the form fields, which are what is saved.
 *
 * @module     local_jedomi/admin/personalization
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {number} Maximum distance of the button from the edges of its corner, in pixels (personalization::OFFSET_MAX). */
const OFFSET_MAX = 500;

const SELECTORS = {
    preview: '[data-region="local_jedomi/personalization/preview"]',
    button: '[data-region="local_jedomi/personalization/button"]',
    panel: '[data-region="local_jedomi/personalization/panel"]',
    avatars: 'input[type="radio"][name="avatar"]',
};

/**
 * A distance typed in the form, as a whole number of pixels within range.
 *
 * @param {string} value Field value.
 * @returns {number}
 */
const clampOffset = (value) => {
    const number = Math.round(Number(value));
    return Number.isFinite(number) ? Math.max(0, Math.min(OFFSET_MAX, number)) : 0;
};

/**
 * Wire the preview to the form fields.
 */
export const init = () => {
    const preview = document.querySelector(SELECTORS.preview);
    const form = preview?.closest('form');
    if (!preview || !form || preview.dataset.initialised) {
        return;
    }
    preview.dataset.initialised = '1';

    const button = preview.querySelector(SELECTORS.button);
    const panel = preview.querySelector(SELECTORS.panel);
    const image = button.querySelector('img');
    const icon = button.querySelector('span');
    const fields = {
        corner: form.querySelector('[name="position_corner"]'),
        x: form.querySelector('[name="position_x"]'),
        y: form.querySelector('[name="position_y"]'),
        side: form.querySelector('[name="position_side"]'),
    };
    if (Object.values(fields).some((field) => !field)) {
        return;
    }

    // The preview stands for the current screen: distances are scaled by its width.
    const scale = () => preview.clientWidth / Math.max(window.innerWidth, 1);
    const isRight = () => fields.corner.value.endsWith('right');
    const isTop = () => fields.corner.value.startsWith('top');

    const update = () => {
        const x = clampOffset(fields.x.value) * scale();
        const y = clampOffset(fields.y.value) * scale();
        button.style.left = isRight() ? 'auto' : `${x}px`;
        button.style.right = isRight() ? `${x}px` : 'auto';
        button.style.top = isTop() ? `${y}px` : 'auto';
        button.style.bottom = isTop() ? 'auto' : `${y}px`;
        panel.classList.toggle('local_jedomi-position-preview-panel-right', fields.side.value === 'right');
    };

    // A custom avatar is shown whatever preset is chosen; otherwise the preview shows the chosen preset.
    const updateAvatar = (radio) => {
        if (preview.dataset.customavatar === '1' || !radio) {
            return;
        }
        const src = radio.closest('label')?.querySelector('img')?.getAttribute('src') || '';
        image.setAttribute('src', src);
        image.classList.toggle('d-none', src === '');
        icon.classList.toggle('d-none', src !== '');
    };

    // Write the distances of the button from the edges of its corner, from a point of the preview.
    const moveTo = (left, top) => {
        const width = preview.clientWidth - button.offsetWidth;
        const height = preview.clientHeight - button.offsetHeight;
        const boundedleft = Math.max(0, Math.min(width, left));
        const boundedtop = Math.max(0, Math.min(height, top));
        const x = isRight() ? width - boundedleft : boundedleft;
        const y = isTop() ? boundedtop : height - boundedtop;
        fields.x.value = clampOffset(x / scale());
        fields.y.value = clampOffset(y / scale());
        update();
    };

    let drag = null;
    button.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        button.setPointerCapture(e.pointerId);
        drag = {
            startx: e.clientX,
            starty: e.clientY,
            left: button.offsetLeft,
            top: button.offsetTop,
        };
    });
    button.addEventListener('pointermove', (e) => {
        if (drag) {
            moveTo(drag.left + e.clientX - drag.startx, drag.top + e.clientY - drag.starty);
        }
    });
    const endDrag = () => {
        drag = null;
    };
    button.addEventListener('pointerup', endDrag);
    button.addEventListener('pointercancel', endDrag);

    button.addEventListener('keydown', (e) => {
        const steps = {ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1]};
        const step = steps[e.key];
        if (!step) {
            return;
        }
        e.preventDefault();
        const distance = (e.shiftKey ? 10 : 1) * scale();
        moveTo(button.offsetLeft + step[0] * distance, button.offsetTop + step[1] * distance);
    });

    Object.values(fields).forEach((field) => {
        field.addEventListener('input', update);
        field.addEventListener('change', update);
    });
    form.querySelectorAll(SELECTORS.avatars).forEach((radio) => {
        radio.addEventListener('change', () => updateAvatar(radio));
    });
    window.addEventListener('resize', update);
    update();
};
