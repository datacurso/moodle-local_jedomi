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
 * Server calls of the Jedomi settings tabs: the set_telegram_enabled web service and the plugin AJAX scripts.
 *
 * @module     local_jedomi/local/admin/repository
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import Config from 'core/config';

/**
 * URL of a plugin AJAX script with the session key and the given query parameters.
 *
 * @param {string} script Script file name inside local/jedomi/ajax.
 * @param {Object} params Query parameters; empty values are left out.
 * @returns {string}
 */
const scriptUrl = (script, params = {}) => {
    const url = new URL(`${Config.wwwroot}/local/jedomi/ajax/${script}`);
    url.searchParams.set('sesskey', Config.sesskey);
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, value);
        }
    });
    return url.toString();
};

/**
 * GET a plugin AJAX script and decode its JSON answer.
 *
 * @param {string} script Script file name.
 * @param {Object} params Query parameters.
 * @returns {Promise<Object>}
 */
const getJson = async(script, params = {}) => {
    const response = await fetch(scriptUrl(script, params));
    return response.json();
};

/**
 * POST form data to a plugin AJAX script and decode its JSON answer.
 *
 * @param {string} script Script file name.
 * @param {Object|FormData} data Fields to post.
 * @returns {Promise<Object>}
 */
const postJson = async(script, data) => {
    const body = new URLSearchParams(data);
    body.set('sesskey', Config.sesskey);
    const response = await fetch(`${Config.wwwroot}/local/jedomi/ajax/${script}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString(),
    });
    return response.json();
};

/**
 * Enable or disable the Telegram integration.
 *
 * @param {boolean} enabled New state.
 * @returns {Promise<{enabled: boolean}>}
 */
export const setTelegramEnabled = (enabled) => fetchMany([{
    methodname: 'local_jedomi_set_telegram_enabled',
    args: {enabled},
}])[0];

/**
 * Live status of the Telegram webhook (getWebhookInfo).
 *
 * @returns {Promise<Object>}
 */
export const getWebhookStatus = () => getJson('telegram_webhook_status.php');

/**
 * Drop the pending Telegram updates and register the webhook again.
 *
 * @returns {Promise<Object>}
 */
export const dropPendingUpdates = () => getJson('telegram_drop_pending.php');

/**
 * One page of the web service table of the APIs tab.
 *
 * @param {Object} state Table state: role, page, search, component, type and perpage.
 * @returns {Promise<Object>}
 */
export const getWsTable = (state) => getJson('ws_table.php', state);

/**
 * Data of the charts of the Agents tab, for the agents that match the filters of the agents report.
 *
 * @returns {Promise<Object>}
 */
export const getAgentStats = () => getJson('agent_stats.php');

/**
 * Indicators of the Query profiler tab, for the queries that match the filters of the recent queries report.
 *
 * @returns {Promise<Object>}
 */
export const getProfilerStats = () => getJson('profiler_stats.php');

/**
 * Run an agent action: create, update, get, details, toggle, run or delete.
 *
 * @param {Object|FormData} data Action fields, including "action".
 * @returns {Promise<Object>}
 */
export const agentAction = (data) => postJson('agent_create.php', data);

/**
 * Preview a prompt against the AI service and the tools.
 *
 * @param {string} prompt Agent prompt.
 * @param {string} contexttype Context type of the agent.
 * @param {string} contextid Context instance of the agent.
 * @param {string} agentid Agent being edited; empty for a new agent.
 * @returns {Promise<Object>}
 */
export const previewAgent = (prompt, contexttype, contextid, agentid = '') =>
    postJson('agent_preview.php', {prompt, context_type: contexttype, context_id: contextid, agent_id: agentid});

/**
 * Delete a cached preview.
 *
 * @param {string} hash Cache key of the prompt.
 * @returns {Promise<Object>}
 */
export const clearPreviewCache = (hash) => postJson('agent_clear_cache.php', {hash});

/**
 * Delete agents by context or role.
 *
 * @param {FormData} data Purge form fields: type and id.
 * @returns {Promise<Object>}
 */
export const purgeAgents = (data) => postJson('agent_purge.php', data);
