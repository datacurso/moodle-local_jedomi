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
 * Agents tab of the Jedomi settings: charts that follow the agents report, row actions, create/edit form, preview and
 * purge.
 *
 * @module     local_jedomi/admin/agents
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {dispatchEvent} from 'core/event_dispatcher';
import {getString, getStrings} from 'core/str';
import ReportEvents from 'core_reportbuilder/local/events';
import Selectors from 'local_jedomi/local/admin/selectors';
import * as Repository from 'local_jedomi/local/admin/repository';
import * as Charts from 'local_jedomi/local/admin/charts';

/** @type {number} Milliseconds a success message stays visible. */
const MESSAGE_DELAY = 4000;

/** @type {string[]} Keys of the strings loaded once when the tab starts. */
const STRING_KEYS = [
    'agent_chart_no_data', 'agent_error_load', 'agent_create', 'agent_edit', 'agent_run_queued', 'agent_update_success',
    'agent_created_no_name', 'agent_error_update', 'agent_error_create', 'agent_preview_error', 'agent_preview_prompt_required',
    'agent_delete', 'agent_delete_confirm', 'agent_purge', 'agent_purge_confirm', 'agent_unnamed',
    'agent_preview_role_user', 'agent_preview_role_assistant', 'agent_preview_role_tool', 'agent_preview_role_system',
];

/** @type {Object<string, string>} Loaded strings, by key. */
let strings = {};

/** @type {string} Approved writes options of the form as the page rendered them, for the current user. */
let initialApprovedWrites = '';

/**
 * Escape text for places that take HTML, such as the modal title.
 *
 * @param {string} text Plain text.
 * @returns {string}
 */
const escapeText = (text) => {
    const holder = document.createElement('div');
    holder.textContent = text;
    return holder.innerHTML;
};

/**
 * Show a message in an alert region.
 *
 * @param {HTMLElement} element Alert region.
 * @param {string} type Bootstrap alert type: success, danger or warning.
 * @param {string} text Message.
 * @param {boolean} autohide Whether to hide it after a few seconds.
 */
const showMessage = (element, type, text, autohide = false) => {
    element.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
    element.classList.add('alert-' + type);
    element.textContent = text;
    if (autohide) {
        setTimeout(() => element.classList.add('d-none'), MESSAGE_DELAY);
    }
};

/**
 * Turn an object of counts into chart data.
 *
 * @param {Object<string, number>} counts Counts by key.
 * @param {Object<string, string>} labels Readable name of each key; a key without one is shown as it is.
 * @returns {Array<{label: string, count: number}>}
 */
const toPairs = (counts, labels = {}) => Object.keys(counts).map((key) => ({label: labels[key] || key, count: counts[key]}));

/**
 * Load the statistics and draw the charts; they count the agents that match the filters of the agents report.
 *
 * @param {HTMLElement} root Tab root.
 */
const loadStats = async(root) => {
    const pending = new Pending('local_jedomi/admin/agents:stats');
    const nodata = strings.agent_chart_no_data;
    try {
        const data = await Repository.getAgentStats();
        if (!data.ok) {
            throw new Error(data.error);
        }
        const charts = data.charts || {};
        Charts.drawPie(root.querySelector(Selectors.agents.chart('role')),
            toPairs(charts.by_role || {}, charts.role_labels || {}), nodata);
        Charts.drawBar(root.querySelector(Selectors.agents.chart('status')),
            toPairs(charts.by_status || {}, charts.status_labels || {}), nodata);
        const executions = (charts.executions_30d || []).map((item) => ({label: item.label || item.day || '', count: item.count}));
        Charts.drawBar(root.querySelector(Selectors.agents.chart('executions')), executions, nodata);
    } catch (error) {
        root.querySelectorAll(Selectors.agents.charts).forEach((canvas) => Charts.drawMessage(canvas, strings.agent_error_load));
    }
    pending.resolve();
};

/**
 * Template context of an agent row.
 *
 * @param {Object} row Agent from the details action of agent_create.php.
 * @returns {Object}
 */
const rowContext = (row) => ({
    id: parseInt(row.id, 10),
    userid: parseInt(row.userid, 10) || 0,
    creator: row.user_fullname || '',
    name: row.name || '',
    schedule: row.schedule_cron || '',
    schedulelabel: row.schedule_label || row.schedule_cron || '',
    enabled: parseInt(row.enabled, 10) === 1,
    lastrun: parseInt(row.lastrun, 10) || 0,
    nextrun: parseInt(row.nextrun, 10) || 0,
    failcount: parseInt(row.failcount, 10) || 0,
    lasterror: row.lasterror || '',
    lastresult: row.lastresult || '',
    contexttype: row.context_type || '',
    contextid: parseInt(row.context_id, 10) || 0,
    contextlabel: row.context_label || row.context_type || '',
    prompt: row.prompt || '',
    prompthidden: row.prompthidden === true,
    canmanage: row.canmanage === true,
    approvedwrites: Array.isArray(row.approved_writes) ? row.approved_writes : [],
});

/**
 * Reload the agents report, keeping its page, and the charts.
 *
 * @param {HTMLElement} root Tab root.
 */
const refresh = (root) => {
    const report = root.querySelector(Selectors.agents.report);
    if (report) {
        // The tableReload listener of the tab reloads the charts too.
        dispatchEvent(ReportEvents.tableReload, {preservePagination: true}, report);
    } else {
        loadStats(root);
    }
};

/**
 * Show or hide the optional fields of the agent form and the purge form.
 *
 * @param {HTMLElement} root Tab root.
 */
const updateOptionalFields = (root) => {
    const schedule = root.querySelector(Selectors.agents.schedule).value;
    root.querySelector(Selectors.agents.customCron).classList.toggle('d-none', schedule !== 'custom');
    const contexttype = root.querySelector(Selectors.agents.contextType).value;
    root.querySelector(Selectors.agents.contextIdWrap).classList.toggle('d-none', contexttype === 'site');
    const purgetype = root.querySelector(Selectors.agents.purgeType).value;
    root.querySelector(Selectors.agents.purgeIdWrap).classList.toggle('d-none', purgetype === 'site' || purgetype === 'role');
    root.querySelector(Selectors.agents.purgeRoleWrap).classList.toggle('d-none', purgetype !== 'role');
};

/**
 * Put the agent form back in create mode.
 *
 * @param {HTMLElement} root Tab root.
 */
const resetForm = (root) => {
    root.querySelector(Selectors.agents.createForm).reset();
    // The options of a new agent are the write functions of the current user.
    root.querySelector(Selectors.agents.approvedWrites).innerHTML = initialApprovedWrites;
    root.querySelector(Selectors.agents.editId).value = '';
    root.querySelector(Selectors.agents.cancelEdit).classList.add('d-none');
    root.querySelector(Selectors.agents.formHeading).textContent = strings.agent_create;
    root.querySelector(Selectors.agents.submitLabel).textContent = strings.agent_create;
    updateOptionalFields(root);
};

/**
 * Load an agent into the form to edit it.
 *
 * @param {HTMLElement} root Tab root.
 * @param {number|string} id Agent id.
 */
const editAgent = async(root, id) => {
    const pending = new Pending('local_jedomi/admin/agents:edit');
    const tablemessage = root.querySelector(Selectors.agents.tableMessage);
    try {
        const data = await Repository.agentAction({action: 'get', id});
        if (!data.ok || !data.agent) {
            showMessage(tablemessage, 'danger', data.error || strings.agent_error_load);
            pending.resolve();
            return;
        }
        const agent = data.agent;
        const form = root.querySelector(Selectors.agents.createForm);
        form.querySelector(Selectors.field('name')).value = agent.name || '';
        form.querySelector(Selectors.field('prompt')).value = agent.prompt || '';
        // A schedule that is not one of the presets is shown as "Custom" with its expression.
        const schedule = form.querySelector(Selectors.field('schedule_cron'));
        const cron = (agent.schedule_cron || '').trim();
        const preset = Array.from(schedule.options).some((option) => option.value === cron && option.value !== 'custom');
        schedule.value = preset ? cron : 'custom';
        form.querySelector(Selectors.field('custom_cron')).value = preset ? '' : cron;
        form.querySelector(Selectors.agents.enabled).checked = parseInt(agent.enabled, 10) === 1;
        form.querySelector(Selectors.field('context_type')).value = agent.context_type || 'site';
        form.querySelector(Selectors.field('context_id')).value = agent.context_id || '';
        const roleids = (agent.role_ids || []).map((roleid) => parseInt(roleid, 10));
        form.querySelectorAll(Selectors.agents.roleCheckboxes).forEach((box) => {
            box.checked = roleids.includes(parseInt(box.value, 10));
        });
        // The writes the owner of the agent may approve, with those approved for the agent checked.
        const approved = agent.approved_writes || [];
        const {html, js} = await Templates.renderForPromise('local_jedomi/admin/agent_approved_writes', {
            approvablewrites: (agent.approvable_writes || []).map((name) => ({name, checked: approved.includes(name)})),
        });
        Templates.replaceNodeContents(root.querySelector(Selectors.agents.approvedWrites), html, js);
        root.querySelector(Selectors.agents.editId).value = agent.id;
        root.querySelector(Selectors.agents.cancelEdit).classList.remove('d-none');
        const heading = root.querySelector(Selectors.agents.formHeading);
        heading.textContent = strings.agent_edit;
        root.querySelector(Selectors.agents.submitLabel).textContent = strings.agent_edit;
        root.querySelector(Selectors.agents.formMessage).classList.add('d-none');
        updateOptionalFields(root);
        heading.scrollIntoView({behavior: 'smooth', block: 'start'});
    } catch (error) {
        showMessage(tablemessage, 'danger', error.message);
    }
    pending.resolve();
};

/**
 * Show the full configuration of an agent in a modal.
 *
 * @param {HTMLElement} root Tab root.
 * @param {number|string} id Agent id.
 */
const showDetails = async(root, id) => {
    let data;
    try {
        data = await Repository.agentAction({action: 'details', id});
    } catch (error) {
        data = {ok: false, error: error.message};
    }
    if (!data.ok || !data.agent) {
        showMessage(root.querySelector(Selectors.agents.tableMessage), 'danger', data.error || strings.agent_error_load);
        return;
    }
    const row = rowContext(data.agent);
    const title = row.name || strings.agent_unnamed + ' #' + row.id;
    await Modal.create({
        title: escapeText(title),
        body: Templates.render('local_jedomi/admin/agent_details', row),
        large: true,
        show: true,
        removeOnClose: true,
    });
};

/**
 * Run a row action (toggle, run or delete) and refresh the list.
 *
 * @param {HTMLElement} root Tab root.
 * @param {string} action Action name.
 * @param {number|string} id Agent id.
 */
const runAgentAction = async(root, action, id) => {
    const pending = new Pending('local_jedomi/admin/agents:action');
    const tablemessage = root.querySelector(Selectors.agents.tableMessage);
    try {
        const data = await Repository.agentAction({action, id});
        if (!data.ok) {
            showMessage(tablemessage, 'danger', data.error || strings.agent_error_load);
        } else {
            if (action === 'run') {
                showMessage(tablemessage, 'success', strings.agent_run_queued, true);
            } else if (data.notice) {
                // Approved writes that are no longer allowed were removed from the agent.
                showMessage(tablemessage, 'warning', data.notice);
            }
            refresh(root);
        }
    } catch (error) {
        showMessage(tablemessage, 'danger', error.message);
    }
    pending.resolve();
};

/**
 * Handle a click on an item of the row action menu.
 *
 * @param {HTMLElement} root Tab root.
 * @param {HTMLElement} item Menu item.
 */
const handleAgentAction = async(root, item) => {
    const action = item.dataset.agentAction;
    const id = item.dataset.agentId;
    if (!id) {
        return;
    }
    if (action === 'details') {
        await showDetails(root, id);
    } else if (action === 'edit') {
        await editAgent(root, id);
    } else if (action === 'delete') {
        try {
            await Notification.deleteCancelPromise(strings.agent_delete, strings.agent_delete_confirm);
        } catch (error) {
            return;
        }
        await runAgentAction(root, action, id);
    } else {
        await runAgentAction(root, action, id);
    }
};

/**
 * Create or update the agent in the form.
 *
 * @param {HTMLElement} root Tab root.
 * @param {HTMLFormElement} form Agent form.
 */
const saveAgent = async(root, form) => {
    const pending = new Pending('local_jedomi/admin/agents:save');
    const message = root.querySelector(Selectors.agents.formMessage);
    const editid = root.querySelector(Selectors.agents.editId).value;
    const isupdate = editid !== '';
    const data = new FormData(form);
    data.set('action', isupdate ? 'update' : 'create');
    if (isupdate) {
        data.set('id', editid);
    }
    try {
        const result = await Repository.agentAction(data);
        if (result.error) {
            showMessage(message, 'danger', result.error || (isupdate ? strings.agent_error_update : strings.agent_error_create));
        } else {
            let text = strings.agent_update_success;
            if (!isupdate) {
                const name = (result.name || '').trim();
                text = name ? await getString('agent_created_with_name', 'local_jedomi', name) : strings.agent_created_no_name;
            }
            showMessage(message, 'success', text, true);
            resetForm(root);
            refresh(root);
        }
    } catch (error) {
        showMessage(message, 'danger', error.message);
    }
    pending.resolve();
};

/**
 * Text of a value that may be a string or a structure.
 *
 * @param {*} value Value to show.
 * @returns {string}
 */
const asText = (value) => (typeof value === 'string' ? value : JSON.stringify(value, null, 2));

/**
 * Template context of a preview result.
 *
 * @param {Object} data Answer of agent_preview.php.
 * @returns {Object}
 */
const previewContext = (data) => {
    if (!data.ok) {
        return {ok: false, error: data.error || strings.agent_preview_error};
    }
    const conversation = (data.conversation || []).map((message) => {
        const role = message.role || 'unknown';
        const content = message.content ? message.content.trim() : '';
        const toolcalls = (message.tool_calls || []).map((call) => ({
            name: (call.function && call.function.name) || call.name || 'tool',
            args: asText((call.function && call.function.arguments) || call.arguments || '{}'),
        }));
        const toolresults = (message.tool_results || []).map((result, index) => ({
            index: index + 1,
            text: asText(result).substring(0, 5000),
        }));
        return {
            rolelabel: strings['agent_preview_role_' + role] || role.charAt(0).toUpperCase() + role.slice(1),
            roleclass: ['user', 'assistant'].includes(role) ? role : 'other',
            round: (message.round || 0) + 1,
            hascontent: content !== '',
            content,
            hastoolcalls: toolcalls.length > 0,
            toolcalls,
            hastoolresults: toolresults.length > 0,
            toolresults,
        };
    });
    const profiler = (data.profiler || []).map((entry) => ({
        tool: entry.tool || '',
        duration: Number(entry.duration_ms || 0).toFixed(1),
        rows: parseInt(entry.row_count, 10) || 0,
        haserror: !!entry.error,
        error: entry.error || '',
        hasresultpreview: !!entry.result_preview,
        resultpreview: entry.result_preview || '',
        hasarguments: !!entry.arguments,
        arguments: entry.arguments ? asText(entry.arguments) : '',
    }));
    return {
        ok: true,
        cached: !!data.cached,
        cachedat: parseInt(data.cached_at, 10) || 0,
        hash: data.cache_hash || '',
        hasconversation: conversation.length > 0,
        conversation,
        hasprofiler: profiler.length > 0,
        profiler,
        hastotaltime: !!data.total_time_ms,
        totaltime: Number(data.total_time_ms || 0).toFixed(1),
        answer: data.answer || '',
    };
};

/**
 * Preview the prompt of the form against the AI service and show the result.
 *
 * @param {HTMLElement} root Tab root.
 */
const previewAgent = async(root) => {
    const prompt = root.querySelector(Selectors.agents.createForm).querySelector(Selectors.field('prompt'));
    if (!prompt.value.trim()) {
        showMessage(root.querySelector(Selectors.agents.formMessage), 'warning', strings.agent_preview_prompt_required);
        return;
    }
    const button = root.querySelector(Selectors.agents.preview);
    if (button && button.disabled) {
        return;
    }
    const pending = new Pending('local_jedomi/admin/agents:preview');
    const body = root.querySelector(Selectors.agents.previewBody);
    const spinner = root.querySelector(Selectors.agents.previewSpinner);
    const setBusy = (busy) => {
        if (button) {
            button.disabled = busy;
            button.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
        body.setAttribute('aria-busy', busy ? 'true' : 'false');
        spinner.classList.toggle('d-none', !busy);
    };
    root.querySelector(Selectors.agents.previewPanel).classList.remove('d-none');
    setBusy(true);
    body.textContent = '';
    let context;
    try {
        const form = root.querySelector(Selectors.agents.createForm);
        context = previewContext(await Repository.previewAgent(
            prompt.value,
            form.querySelector(Selectors.field('context_type')).value,
            form.querySelector(Selectors.field('context_id')).value,
            root.querySelector(Selectors.agents.editId).value
        ));
    } catch (error) {
        context = {ok: false, error: error.message};
    }
    try {
        const {html, js} = await Templates.renderForPromise('local_jedomi/admin/agent_preview', context);
        Templates.replaceNodeContents(body, html, js);
    } catch (error) {
        Notification.exception(error);
    } finally {
        setBusy(false);
        pending.resolve();
    }
};

/**
 * Delete the cached preview and preview again.
 *
 * @param {HTMLElement} root Tab root.
 * @param {string} hash Cache key of the prompt.
 */
const clearCacheAndPreview = async(root, hash) => {
    try {
        const result = await Repository.clearPreviewCache(hash);
        if (result.ok) {
            await previewAgent(root);
        }
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Delete agents by context or role after confirmation.
 *
 * @param {HTMLElement} root Tab root.
 * @param {HTMLFormElement} form Purge form.
 */
const purgeAgents = async(root, form) => {
    try {
        await Notification.deleteCancelPromise(strings.agent_purge, strings.agent_purge_confirm);
    } catch (error) {
        return;
    }
    const pending = new Pending('local_jedomi/admin/agents:purge');
    const message = root.querySelector(Selectors.agents.purgeMessage);
    try {
        const result = await Repository.purgeAgents(new FormData(form));
        if (!result.ok) {
            showMessage(message, 'danger', result.error || strings.agent_error_load);
        } else {
            showMessage(message, 'success', await getString('agent_purge_success', 'local_jedomi', result.deleted), true);
            form.reset();
            updateOptionalFields(root);
            refresh(root);
        }
    } catch (error) {
        showMessage(message, 'danger', error.message);
    }
    pending.resolve();
};

/**
 * Register the listeners of the tab.
 *
 * @param {HTMLElement} root Tab root.
 */
const registerEventListeners = (root) => {
    // The report reloads its table after its filters are applied or reset, and after the actions of the tab.
    root.addEventListener(ReportEvents.tableReload, () => loadStats(root));

    root.addEventListener('click', (e) => {
        const item = e.target.closest(Selectors.agents.agentAction);
        if (item) {
            e.preventDefault();
            handleAgentAction(root, item);
            return;
        }
        if (e.target.closest(Selectors.agents.cancelEdit)) {
            resetForm(root);
            root.querySelector(Selectors.agents.formMessage).classList.add('d-none');
            return;
        }
        if (e.target.closest(Selectors.agents.preview)) {
            previewAgent(root);
            return;
        }
        const clearcache = e.target.closest(Selectors.agents.clearCache);
        if (clearcache) {
            clearCacheAndPreview(root, clearcache.dataset.hash);
        }
    });

    root.addEventListener('change', (e) => {
        if (e.target.closest(Selectors.agents.schedule) || e.target.closest(Selectors.agents.contextType)
                || e.target.closest(Selectors.agents.purgeType)) {
            updateOptionalFields(root);
        }
    });

    root.addEventListener('submit', (e) => {
        const createform = e.target.closest(Selectors.agents.createForm);
        if (createform) {
            e.preventDefault();
            saveAgent(root, createform);
            return;
        }
        const purgeform = e.target.closest(Selectors.agents.purgeForm);
        if (purgeform) {
            e.preventDefault();
            purgeAgents(root, purgeform);
        }
    });
};

/**
 * Open the agent given in the page URL (links from the Profiler tab) and drop it from the address bar.
 *
 * @param {HTMLElement} root Tab root.
 */
const openRequestedAgent = async(root) => {
    const id = parseInt(root.dataset.editAgentId, 10);
    if (!id) {
        return;
    }
    await editAgent(root, id);
    const params = new URLSearchParams(window.location.search);
    params.delete('edit_agent');
    window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
};

/**
 * Initialise the Agents tab.
 */
export const init = async() => {
    const root = document.querySelector(Selectors.agents.root);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';
    initialApprovedWrites = root.querySelector(Selectors.agents.approvedWrites).innerHTML;
    const values = await getStrings(STRING_KEYS.map((key) => ({key, component: 'local_jedomi'})));
    strings = Object.fromEntries(STRING_KEYS.map((key, i) => [key, values[i]]));
    registerEventListeners(root);
    updateOptionalFields(root);
    await loadStats(root);
    await openRequestedAgent(root);
};
