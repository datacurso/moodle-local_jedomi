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
 * Page context label and quick actions of the Jedomi sidebar, by the role of the user and the page.
 *
 * @module     local_jedomi/local/sidebar/quick_actions
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {sendMessage} from './composer';
import {g, renderInto} from './dom';
import {cfg} from './state';

// Show the location of the page (course, activity, grades...) at the top of the panel.
export const updateContextLabel = () => {
    const ctx = cfg.context || {};
    const loc = ctx.location || 'unknown';
    const S = cfg.strings || {};
    const labels = {
        course:    '\uD83D\uDCDA ' + (ctx.course_name || S.ctx_course || ''),
        activity:  '\uD83D\uDCDD ' + (ctx.activity_name || ctx.course_name || S.ctx_activity || ''),
        grades:    '\uD83D\uDCCA ' + (S.ctx_grades || ''),
        admin:     '\u2699\uFE0F ' + (S.ctx_admin || ''),
        dashboard: '\uD83C\uDFE0 ' + (S.ctx_dashboard || ''),
        messages:  '\uD83D\uDCAC ' + (S.ctx_messages || ''),
        profile:   '\uD83D\uDC64 ' + (S.ctx_profile || ''),
        calendar:  '\uD83D\uDCC5 ' + (S.ctx_calendar || ''),
        files:     '\uD83D\uDCC1 ' + (S.ctx_files || ''),
    };
    // Fallback: the page title (without the "| Moodle" suffix).
    const rawTitle = document.title.replace(/\s*[|\-]\s*Moodle.*$/i, '').trim();
    const pageTitle = rawTitle.length > 35 ? rawTitle.substring(0, 33) + '\u2026' : rawTitle;
    const label = labels[loc] || (pageTitle ? '\uD83D\uDCC4 ' + pageTitle : '');
    const el = g('jedomi-context-label');
    if (el) {
        el.textContent = label;
        el.classList.toggle('jedomi-hidden', !label);
    }
};

// Quick actions of an administrator: in a course, in the administration or elsewhere.
const adminActions = ({S, loc, inCourse, courseName}) => {
    let actions = [];
    if (inCourse) {
        actions = [
            {icon: '🏆', label: S.qa_admin_top_student_label || '',
              msg: (S.qa_admin_top_student_msg || '').replace('{$a}', courseName)},
            {icon: '⚠️', label: S.qa_admin_at_risk_label || '',
              msg: (S.qa_admin_at_risk_msg || '').replace('{$a}', courseName)},
            {icon: '📊', label: S.qa_admin_performance_label || '',
              msg: (S.qa_admin_performance_msg || '').replace('{$a}', courseName)},
            {icon: '👻', label: S.qa_admin_inactive_label || '',
              msg: (S.qa_admin_inactive_msg || '').replace('{$a}', courseName)},
            {icon: '📝', label: S.qa_admin_missing_work_label || '',
              msg: (S.qa_admin_missing_work_msg || '').replace('{$a}', courseName)},
        ];
    } else if (loc === 'admin') {
        actions = [
            {icon: '👥', label: S.qa_admin_active_users_label || '',
              msg: S.qa_admin_active_users_msg || ''},
            {icon: '🔐', label: S.qa_admin_external_emails_label || '',
              msg: S.qa_admin_external_emails_msg || ''},
            {icon: '📋', label: S.qa_admin_empty_cohorts_label || '',
              msg: S.qa_admin_empty_cohorts_msg || ''},
            {icon: '⚠️', label: S.qa_admin_gdpr_pending_label || '',
              msg: S.qa_admin_gdpr_pending_msg || ''},
            {icon: '🔒', label: S.qa_admin_security_label || '',
              msg: S.qa_admin_security_msg || ''},
        ];
    } else {
        actions = [
            {icon: '📊', label: S.qa_admin_overview_label || '',
              msg: S.qa_admin_overview_msg || ''},
            {icon: '⚠️', label: S.qa_admin_alerts_label || '',
              msg: S.qa_admin_alerts_msg || ''},
            {icon: '🏆', label: S.qa_admin_top_courses_label || '',
              msg: S.qa_admin_top_courses_msg || ''},
            {icon: '💾', label: S.qa_admin_storage_label || '',
              msg: S.qa_admin_storage_msg || ''},
        ];
    }
    return actions;
};

// Quick actions of a manager: in a course or elsewhere.
const managerActions = ({S, inCourse, courseName}) => {
    let actions = [];
    if (inCourse) {
        actions = [
            {icon: 'chart', label: S.qa_manager_overview_label || '',
              msg: (S.qa_manager_overview_msg || '').replace('{$a}', courseName)},
            {icon: 'alert', label: S.qa_manager_at_risk_label || '',
              msg: (S.qa_manager_at_risk_msg || '').replace('{$a}', courseName)},
            {icon: 'users', label: S.qa_manager_enrolled_label || '',
              msg: (S.qa_manager_enrolled_msg || '').replace('{$a}', courseName)},
            {icon: 'ghost', label: S.qa_manager_inactive_label || '',
              msg: (S.qa_manager_inactive_msg || '').replace('{$a}', courseName)},
        ];
    } else {
        actions = [
            {icon: 'users', label: S.qa_manager_inactive_users_label || '',
              msg: S.qa_manager_inactive_users_msg || ''},
            {icon: 'chart', label: S.qa_manager_top_courses_label || '',
              msg: S.qa_manager_top_courses_msg || ''},
            {icon: 'alert', label: S.qa_manager_global_risk_label || '',
              msg: S.qa_manager_global_risk_msg || ''},
            {icon: 'task', label: S.qa_manager_no_teacher_label || '',
              msg: S.qa_manager_no_teacher_msg || ''},
        ];
    }
    return actions;
};

// Quick actions of a teacher: in a course (by their capabilities) or elsewhere.
const teacherActions = ({S, caps, loc, cid, courseName}) => {
    let actions = [];
    if ((loc === 'course' || loc === 'activity' || loc === 'grades') && cid) {
        actions = [
            {icon: 'chart', label: S.qa_teacher_performance_label || '',
              msg: (S.qa_teacher_performance_msg || '').replace('{$a}', courseName)},
            {icon: 'alert', label: S.qa_teacher_at_risk_label || '',
              msg: (S.qa_teacher_at_risk_msg || '').replace('{$a}', courseName)},
            {icon: 'ghost', label: S.qa_teacher_never_logged_label || '',
              msg: (S.qa_teacher_never_logged_msg || '').replace('{$a}', courseName)},
            {icon: 'task', label: S.qa_teacher_missing_work_label || '',
              msg: (S.qa_teacher_missing_work_msg || '').replace('{$a}', courseName)},
        ];
        if (caps.can_send_messages) {
            actions.push({icon: 'mic', label: S.qa_teacher_notify_group_label || '',
              msg: (S.qa_teacher_notify_group_msg || '').replace('{$a}', courseName)});
        }
        if (caps.can_view_all_grades) {
            actions.push({icon: 'star', label: S.qa_teacher_top_students_label || '',
              msg: (S.qa_teacher_top_students_msg || '').replace('{$a}', courseName)});
        }
    } else {
        actions = [
            {icon: 'chart', label: S.qa_teacher_my_courses_label || '',
              msg: S.qa_teacher_my_courses_msg || ''},
            {icon: 'alert', label: S.qa_teacher_my_alerts_label || '',
              msg: S.qa_teacher_my_alerts_msg || ''},
        ];
    }
    return actions;
};

// Quick actions of a student: in a course or elsewhere.
const studentActions = ({S, cid, courseName}) => {
    let actions = [];
    if (cid) {
        actions = [
            {icon: 'task', label: S.qa_student_my_tasks_label || '',
              msg: (S.qa_student_my_tasks_msg || '').replace('{$a}', courseName)},
            {icon: 'chart', label: S.qa_student_my_grade_label || '',
              msg: (S.qa_student_my_grade_msg || '').replace('{$a}', courseName)},
            {icon: 'task', label: S.qa_student_my_progress_label || '',
              msg: (S.qa_student_my_progress_msg || '').replace('{$a}', courseName)},
            {icon: 'help', label: S.qa_student_help_label || '',
              msg: (S.qa_student_help_msg || '').replace('{$a}', courseName)},
        ];
    } else {
        actions = [
            {icon: 'chart', label: S.qa_student_my_courses_label || '',
              msg: S.qa_student_my_courses_msg || ''},
            {icon: 'task', label: S.qa_student_my_grades_label || '',
              msg: S.qa_student_my_grades_msg || ''},
            {icon: 'task', label: S.qa_student_this_week_label || '',
              msg: S.qa_student_this_week_msg || ''},
        ];
    }
    return actions;
};

// Quick actions for the role of the user on the page.
const roleActions = (role, page) => {
    if (role === 'admin') {
        return adminActions(page);
    }
    if (role === 'manager') {
        return managerActions(page);
    }
    if (role === 'teacher') {
        return teacherActions(page);
    }
    return studentActions(page);
};

// Show the quick action buttons for the role of the user and the page; each one sends its question.
export const loadQuickActions = async() => {
    const container = g('jedomi-quick-actions');
    if (!container) {
        return;
    }

    const ctx = cfg.context || {};
    const S = cfg.strings || {}; // ← FIX: needed by all quick action labels
    const spt = cfg.spt || {};
    const role = cfg.user_role || ctx.user_role || 'student';
    const caps = spt.capabilities || {};
    // The location and the course come from the server, which reads them from the page; never from the address,
    // whose id is not a course on the pages of activities, profiles and others.
    const loc = ctx.location || 'unknown';
    const cid = parseInt(ctx.course_id, 10) || 0;
    // Use the course name when available, not the ID.
    const courseName = ctx.course_name
        ? `"${ctx.course_name}"`
        : (cid ? (S.qa_course_with_id || '').replace('{$a}', cid) : (S.qa_course_current || ''));

    // ── Inside a course: the course of the page, as the server gave it ──
    const inCourse = cid > 0 && loc !== 'admin' && loc !== 'dashboard';

    const actions = roleActions(role, {S, caps, loc, cid, courseName, inCourse});

    // Emoji icons of the actions → names of the icons in local_jedomi/local/sidebar/icon.
    const iconMap = {
        '📊': 'chart', '⚠️': 'alert', '👻': 'ghost', '📝': 'task',
        '🌙': 'moon', '👥': 'users', '🔐': 'shield', '📋': 'lock',
        '🏆': 'star', '❓': 'help', '💰': 'cpu', '✅': 'task',
        '📚': 'chart', '💬': 'mic', '🔒': 'shield', '📅': 'task',
    };
    const iconName = (icon) => iconMap[icon] || (/^[a-z]+$/.test(icon) ? icon : 'chart');
    await renderInto(container, 'local_jedomi/local/sidebar/quick_actions', {
        actions: actions.map(a => ({label: a.label, msg: a.msg, icon: {[iconName(a.icon)]: true}})),
    });

    container.querySelectorAll('.jedomi-quick-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = g('jedomi-input');
            if (input) {
                input.value = btn.dataset.msg;
                input.dispatchEvent(new Event('input'));
            }
            sendMessage();
        });
    });
};
