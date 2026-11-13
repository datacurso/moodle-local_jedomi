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
 * Query profiler tab of the Jedomi settings: refreshes the indicators when the filters of the recent queries report
 * change, so they keep counting the same queries as the report.
 *
 * @module     local_jedomi/admin/profiler
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import ReportEvents from 'core_reportbuilder/local/events';
import Selectors from 'local_jedomi/local/admin/selectors';
import * as Repository from 'local_jedomi/local/admin/repository';

/**
 * Load the indicators and show them.
 *
 * @param {HTMLElement} root Tab root.
 */
const refreshIndicators = async(root) => {
    const pending = new Pending('local_jedomi/admin/profiler:indicators');
    try {
        const data = await Repository.getProfilerStats();
        if (!data.ok) {
            throw new Error(data.error);
        }
        root.querySelectorAll(Selectors.profiler.indicator).forEach((indicator) => {
            indicator.textContent = data.indicators[indicator.dataset.indicator];
        });
    } catch (error) {
        Notification.exception(error);
    }
    pending.resolve();
};

/**
 * Initialise the Query profiler tab.
 */
export const init = () => {
    const root = document.querySelector(Selectors.profiler.root);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';
    const recent = root.querySelector(Selectors.profiler.recent);
    if (recent) {
        recent.addEventListener(ReportEvents.tableReload, () => refreshIndicators(root));
    }
};
