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
 * Configuration of the Jedomi sidebar and renewal of its signed permissions (SPT).
 *
 * @module     local_jedomi/local/sidebar/permissions
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import {cfg, state} from './state';

// Margin before the expiry of the signed permissions in which they are renewed, in milliseconds.
const SPT_RENEW_MARGIN_MS = 120000;

// Fetch the panel configuration. A refusal (an error answer, a login page or a failed request) is an error: a
// configuration without signed permissions is never used.
export const fetchConfig = async() => {
    const res = await fetch(state.configUrl, {credentials: 'same-origin'});
    const data = await res.json();
    if (!res.ok || !data || data.error || !data.spt) {
        throw new Error(data?.message || `HTTP ${res.status}`);
    }
    return data;
};

// Remember when the signed permissions expire, on the clock of the browser: the server sends its own time with them.
export const setPermissionsExpiry = (config) => {
    const lifetime = Number(config?.spt?.expires) - Number(config?.server_time);
    state.sptExpiresAt = Number.isFinite(lifetime) && lifetime > 0 ? Date.now() + lifetime * 1000 : 0;
};

// Ask the server for new signed permissions while the Moodle session is active. Resolves to whether they were
// renewed; the requests in progress share one renewal.
export const renewPermissions = () => {
    if (!state.configUrl) {
        return Promise.resolve(false);
    }
    if (!state.renewing) {
        state.renewing = fetchConfig()
            .then((fresh) => {
                cfg.spt = fresh.spt;
                cfg.sesskey = fresh.sesskey || cfg.sesskey;
                setPermissionsExpiry(fresh);
                return true;
            })
            .catch((error) => {
                Log.debug('Jedomi: the chat permissions could not be renewed', error);
                return false;
            })
            .finally(() => {
                state.renewing = null;
            });
    }
    return state.renewing;
};

// Renew the signed permissions when they expire in less than SPT_RENEW_MARGIN_MS, before a request uses them.
export const ensureFreshPermissions = async() => {
    if (state.configUrl && state.sptExpiresAt && Date.now() >= state.sptExpiresAt - SPT_RENEW_MARGIN_MS) {
        await renewPermissions();
    }
};

// Signed permissions of the panel: the server takes the course of the request from them, and applies the course
// availability and role visibility to it.
export const signedPermissions = () => JSON.stringify(cfg.spt || {});

// Course of the page the panel is on, as the server gave it with the configuration (0 outside a course). It is sent
// with every request: the server refuses it when it is not the course signed in the permissions. It is never read
// from the address, whose id is not a course on the pages of activities, profiles and others.
export const pageCourseId = () => parseInt(cfg.context?.course_id, 10) || 0;
