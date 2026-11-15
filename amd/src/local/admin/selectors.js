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
 * Selectors used by the modules of the Jedomi settings tabs.
 *
 * @module     local_jedomi/local/admin/selectors
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Selector of a form field by name.
 *
 * @param {string} name Field name.
 * @returns {string}
 */
const field = (name) => `[name="${name}"]`;

export default {
    field,
    integrations: {
        card: '[data-region="local_jedomi/admin/integrations/telegram"]',
        toggle: '[data-action="local_jedomi/admin/integrations/toggle-telegram"]',
        body: '[data-region="local_jedomi/admin/integrations/body"]',
        checkWebhook: '[data-action="local_jedomi/admin/integrations/check-webhook"]',
        webhookLive: '[data-region="local_jedomi/admin/integrations/webhook-live"]',
        dropPending: '[data-action="local_jedomi/admin/integrations/drop-pending"]',
        dropPendingResult: '[data-region="local_jedomi/admin/integrations/drop-pending-result"]',
        regenerateSecret: '[data-action="local_jedomi/admin/integrations/regenerate-secret"]',
        buttonLabel: '[data-region="local_jedomi/admin/integrations/button-label"]',
        buttonBusy: '[data-region="local_jedomi/admin/integrations/button-busy"]',
    },
    apis: {
        root: '[data-region="local_jedomi/admin/apis"]',
        typePill: '[data-action="local_jedomi/admin/apis/type"]',
        activeType: '[data-action="local_jedomi/admin/apis/type"][aria-pressed="true"]',
        count: '[data-region="local_jedomi/admin/apis/count"]',
        search: '[data-action="local_jedomi/admin/apis/search"]',
        filter: '[data-action="local_jedomi/admin/apis/filter"]',
        component: '[data-action="local_jedomi/admin/apis/filter"][name="ap_component"]',
        perpage: '[data-action="local_jedomi/admin/apis/filter"][name="ap_perpage"]',
        checkAll: '[data-action="local_jedomi/admin/apis/check-all"]',
        wsCheck: '[data-region="local_jedomi/admin/apis/ws-check"]',
        rows: '[data-region="local_jedomi/admin/apis/rows"]',
        tableWrap: '[data-region="local_jedomi/admin/apis/table-wrap"]',
        pagination: '[data-region="local_jedomi/admin/apis/pagination"]',
        pageLink: '[data-action="local_jedomi/admin/apis/page"]',
        activePage: '[data-action="local_jedomi/admin/apis/page"][aria-current="page"]',
        saveForm: '[data-form="local_jedomi/admin/apis/save"]',
    },
    profiler: {
        root: '[data-region="local_jedomi/admin/profiler"]',
        recent: '[data-region="local_jedomi/admin/profiler/recent"]',
        indicator: '[data-indicator]',
    },
    agents: {
        root: '[data-region="local_jedomi/admin/agents"]',
        chart: (name) => `[data-region="local_jedomi/admin/agents/chart"][data-chart="${name}"]`,
        charts: '[data-region="local_jedomi/admin/agents/chart"]',
        tableMessage: '[data-region="local_jedomi/admin/agents/table-message"]',
        report: '[data-region="core_reportbuilder/report"]',
        agentAction: '[data-agent-action]',
        createForm: '[data-form="local_jedomi/admin/agents/create"]',
        formHeading: '[data-region="local_jedomi/admin/agents/form-heading"]',
        formMessage: '[data-region="local_jedomi/admin/agents/form-message"]',
        editId: '[data-region="local_jedomi/admin/agents/edit-id"]',
        submitLabel: '[data-region="local_jedomi/admin/agents/submit-label"]',
        schedule: '[data-action="local_jedomi/admin/agents/schedule"]',
        customCron: '[data-region="local_jedomi/admin/agents/custom-cron"]',
        contextType: '[data-action="local_jedomi/admin/agents/context-type"]',
        contextIdWrap: '[data-region="local_jedomi/admin/agents/context-id-wrap"]',
        roleCheckboxes: 'input[type="checkbox"][name="role_filter[]"]',
        approvedWrites: '[data-region="local_jedomi/admin/agents/approved-writes"]',
        enabled: '[data-region="local_jedomi/admin/agents/enabled"]',
        preview: '[data-action="local_jedomi/admin/agents/preview"]',
        cancelEdit: '[data-action="local_jedomi/admin/agents/cancel-edit"]',
        previewPanel: '[data-region="local_jedomi/admin/agents/preview-panel"]',
        previewSpinner: '[data-region="local_jedomi/admin/agents/preview-spinner"]',
        previewBody: '[data-region="local_jedomi/admin/agents/preview-body"]',
        clearCache: '[data-action="local_jedomi/admin/agents/clear-cache"]',
        purgeForm: '[data-form="local_jedomi/admin/agents/purge"]',
        purgeType: '[data-action="local_jedomi/admin/agents/purge-context"]',
        purgeIdWrap: '[data-region="local_jedomi/admin/agents/purge-id-wrap"]',
        purgeRoleWrap: '[data-region="local_jedomi/admin/agents/purge-role-wrap"]',
        purgeMessage: '[data-region="local_jedomi/admin/agents/purge-message"]',
    },
};
