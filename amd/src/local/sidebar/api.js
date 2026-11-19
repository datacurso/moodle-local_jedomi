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
 * Requests of the Jedomi sidebar to its endpoints: the streamed chat proxy and the file reading endpoints.
 *
 * @module     local_jedomi/local/sidebar/api
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {ensureFreshPermissions, pageCourseId, renewPermissions, signedPermissions} from './permissions';
import {cfg} from './state';

// Send the messages to the chat proxy and return its answer, once its permissions and its session are checked.
const openChatStream = async(messages, extra, signal) => {
    // Context truncation: at most 30 messages to avoid a context overflow. Only the role and the content are sent:
    // the text shown in the chat and the id of an answer stay in the browser.
    const truncated = messages.slice(-30).map(({role, content}) => ({role, content}));

    // The page context (location, course, activity and path) is the one the server read from the page; the server
    // reads the names again and never uses free text of the browser. The payload is built after a renewal, so it
    // carries the renewed permissions.
    const request = () => fetch(cfg.chatproxy_url, {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            messages: truncated,
            spt:      cfg.spt,
            sesskey:  cfg.sesskey,
            context:  {
                ...cfg.context,
                course_id:  pageCourseId(),
            },
            ...extra,
        }),
        signal: signal || undefined,
    });

    await ensureFreshPermissions();
    let res = await request();
    // Refused permissions (expired or no longer valid): they are renewed while the Moodle session is active and
    // the request is sent once more.
    if (res.status === 403 && await renewPermissions()) {
        res = await request();
    }

    if (!res.ok) {
        // Detect an expired session (403 from chatproxy.php → invalid sesskey/SPT that could not be renewed).
        if (res.status === 403) {
            throw new Error('__SESSION_EXPIRED__');
        }
        const err = await res.json().catch(() => ({}));
        const error = new Error(err.detail || err.error?.message || `HTTP ${res.status}`);
        // A refusal with a message ready to show (such as a request above the size cap) is shown as it is.
        error.jedomiServerError = !!err.error?.message;
        throw error;
    }

    // Security: detect when require_login() redirected to the HTML login page.
    const contentType = res.headers.get('content-type') || '';
    if (contentType.includes('text/html')) {
        throw new Error('__SESSION_EXPIRED__');
    }

    return res;
};

// Handle one event of the stream: a signal of life, a tool running, an action waiting for confirmation, metadata,
// a server error or a content token.
const handleStreamChunk = (raw, {onToken, onToolEvent, onPendingAction, onHeartbeat}) => {
    const chunk = JSON.parse(raw);

    // A signal of life while a slow AI round waits.
    if (chunk._jedomi_heartbeat) {
        if (onHeartbeat) {
            onHeartbeat(chunk._jedomi_heartbeat);
        }
        return;
    }
    // A tool is running.
    if (chunk._jedomi_tool_executing && onToolEvent) {
        onToolEvent(chunk._jedomi_tool_executing);
        return;
    }
    // An action waiting for the user's confirmation (the server holds it).
    if (chunk._jedomi_pending_action) {
        if (onPendingAction) {
            onPendingAction(chunk._jedomi_pending_action);
        }
        return;
    }
    // Metadata at the end.
    if (chunk._meta) {
        return;
    }
    // Server error: {"error": {"code", "message"}} with a message ready to show.
    if (chunk.error) {
        const serverError = new Error(chunk.error.message
            || cfg.strings?.ai_error_connection || '');
        serverError.jedomiServerError = true;
        throw serverError;
    }

    // Content token.
    const token = chunk.choices?.[0]?.delta?.content;
    if (token && onToken) {
        onToken(token);
    }
};

// Call the chat proxy (streaming).
// The proxy receives the messages, adds the tools and the anti-hallucination system prompt,
// runs the tool loop against the real Moodle DB and returns SSE.
// extra adds fields to the request, such as voice_mode for the voice conversation.
// signal, when given, cancels the request (the voice conversation does it when the user speaks over Jedomi).
// onHeartbeat gets the signals of life ({elapsed} seconds) the proxy sends while a slow AI round waits.
export const callGatewayStream = async(
    messages, onToken, onToolEvent, onPendingAction, extra = {}, signal = null, onHeartbeat = null
) => {
    const res = await openChatStream(messages, extra, signal);
    const handlers = {onToken, onToolEvent, onPendingAction, onHeartbeat};
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const {done, value} = await reader.read();
        if (done) {
            break;
        }

        buffer += decoder.decode(value, {stream: true});
        const lines = buffer.split('\n');
        buffer = lines.pop(); // Last, incomplete fragment.

        for (const line of lines) {
            if (!line.startsWith('data: ')) {
                continue;
            }
            const raw = line.slice(6).trim();
            if (raw === '[DONE]') {
                return;
            }

            try {
                handleStreamChunk(raw, handlers);
            } catch (e) {
                if (e.message !== 'Unexpected end of JSON input') {
                    throw e;
                }
            }
        }
    }
};

// Send a file to a Moodle endpoint that answers JSON, or {"error": {"code", "message"}} with a message to show.
export const uploadForText = async(url, file, fields = {}, signal = null) => {
    const S = cfg.strings || {};
    // Built for each try, so a retry after a renewal carries the renewed permissions.
    const request = async() => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('sesskey', cfg.sesskey || '');
        formData.append('spt', signedPermissions());
        formData.append('courseid', String(pageCourseId()));
        Object.entries(fields).forEach(([name, value]) => formData.append(name, value));
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            signal: signal || undefined,
        });
        return {res: response, data: await response.json().catch(() => ({}))};
    };

    await ensureFreshPermissions();
    let {res, data} = await request();
    // Expired permissions are renewed while the Moodle session is active and the file is sent once more; nothing
    // was read nor charged on the refused try.
    if (res.status === 403 && data.error?.code === 'invalid_spt' && await renewPermissions()) {
        ({res, data} = await request());
    }
    if (data.error || !res.ok) {
        const error = new Error((data.error ? data.error.message || S.unknown_error : S.ai_error_connection) || '');
        // The status and the code tell a refusal (expired session, voice no longer allowed) from a failure.
        error.status = res.status;
        error.code = data.error?.code || data.errorcode || '';
        throw error;
    }
    return data;
};
