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
 * APIs tab of the Jedomi settings: filters, type pills and pagination of the web service table, loaded through AJAX.
 *
 * @module     local_jedomi/admin/apis
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import Selectors from 'local_jedomi/local/admin/selectors';
import * as Repository from 'local_jedomi/local/admin/repository';

/** @type {number} Milliseconds to wait after typing before searching. */
const SEARCH_DELAY = 350;

/**
 * Current state of the table, read from the page.
 *
 * @param {HTMLElement} root Tab root.
 * @returns {Object} role, page, search, component, type and perpage.
 */
const getState = (root) => {
    const type = root.querySelector(Selectors.apis.activeType);
    const page = root.querySelector(Selectors.apis.activePage);
    return {
        role: root.dataset.role,
        page: parseInt(page ? page.dataset.page : '1', 10),
        search: root.querySelector(Selectors.apis.search).value,
        component: root.querySelector(Selectors.apis.component).value,
        type: type ? type.dataset.type : 'all',
        perpage: parseInt(root.querySelector(Selectors.apis.perpage).value, 10),
    };
};

/**
 * Keep the filters in the address bar, so a reload shows the same table.
 *
 * @param {Object} state Table state.
 */
const replaceUrl = (state) => {
    const params = new URLSearchParams({
        tab: 'apis',
        role: state.role,
        'ap_search': state.search,
        'ap_component': state.component,
        'ap_type': state.type,
        'ap_page': state.page,
        'ap_perpage': state.perpage,
    });
    window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
};

/**
 * Load the table for a state and replace the rows, the pagination and the counters.
 *
 * @param {HTMLElement} root Tab root.
 * @param {Object} state Table state.
 */
const reload = async(root, state) => {
    const pending = new Pending('local_jedomi/admin/apis:reload');
    const tbody = root.querySelector(Selectors.apis.rows);
    tbody.classList.add('local_jedomi-admin-loading');
    try {
        const data = await Repository.getWsTable(state);
        if (data.error) {
            throw new Error(data.error);
        }
        const table = await Templates.renderForPromise('local_jedomi/admin/ws_table', {
            role: data.role,
            rows: data.rows,
            hasrows: data.hasrows,
        });
        Templates.replaceNodeContents(root.querySelector(Selectors.apis.tableWrap), table.html, table.js);
        const pagination = await Templates.renderForPromise('local_jedomi/admin/ws_pagination', data.pagination);
        Templates.replaceNodeContents(root.querySelector(Selectors.apis.pagination), pagination.html, pagination.js);
        root.querySelectorAll(Selectors.apis.count).forEach((counter) => {
            counter.textContent = data.counts[counter.dataset.type];
        });
        root.querySelector(Selectors.apis.checkAll).checked = false;
        replaceUrl({...state, page: data.pagination.page});
    } catch (error) {
        Notification.exception(error);
    }
    tbody.classList.remove('local_jedomi-admin-loading');
    pending.resolve();
};

/**
 * Mark a type pill as the active one.
 *
 * @param {HTMLElement} root Tab root.
 * @param {HTMLElement} active Pill to activate.
 */
const activateType = (root, active) => {
    root.querySelectorAll(Selectors.apis.typePill).forEach((pill) => {
        const isactive = pill === active;
        pill.setAttribute('aria-pressed', isactive ? 'true' : 'false');
        pill.classList.toggle('btn-primary', isactive);
        pill.classList.toggle('btn-outline-secondary', !isactive);
    });
};

/**
 * Register the listeners of the tab.
 *
 * @param {HTMLElement} root Tab root.
 */
const registerEventListeners = (root) => {
    let searchtimer = null;

    root.addEventListener('input', (e) => {
        if (!e.target.closest(Selectors.apis.search)) {
            return;
        }
        clearTimeout(searchtimer);
        searchtimer = setTimeout(() => reload(root, {...getState(root), page: 1}), SEARCH_DELAY);
    });

    root.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.closest(Selectors.apis.search)) {
            e.preventDefault();
        }
    });

    root.addEventListener('change', (e) => {
        if (e.target.closest(Selectors.apis.filter)) {
            reload(root, {...getState(root), page: 1});
            return;
        }
        const checkall = e.target.closest(Selectors.apis.checkAll);
        if (checkall) {
            root.querySelectorAll(Selectors.apis.wsCheck).forEach((box) => {
                box.checked = checkall.checked;
            });
        }
    });

    root.addEventListener('click', (e) => {
        const pill = e.target.closest(Selectors.apis.typePill);
        if (pill) {
            e.preventDefault();
            activateType(root, pill);
            reload(root, {...getState(root), type: pill.dataset.type, page: 1});
            return;
        }
        const link = e.target.closest(Selectors.apis.pageLink);
        if (link) {
            e.preventDefault();
            reload(root, {...getState(root), page: parseInt(link.dataset.page, 10) || 1});
        }
    });
};

/**
 * Initialise the APIs tab.
 */
export const init = () => {
    const root = document.querySelector(Selectors.apis.root);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';
    registerEventListeners(root);
};
