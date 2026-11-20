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
 * Chat of the Jedomi sidebar: sends a question to the chat proxy and shows its streamed answer.
 *
 * @module     local_jedomi/local/sidebar/chat
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import Templates from 'core/templates';
import {buildActionConfirmUI} from './actions';
import {callGatewayStream} from './api';
import {g, renderInto, setThinking} from './dom';
import {addMessageActions} from './feedback';
import {saveConversation, undoLastQuestion} from './history';
import {renderMarkdown} from './markdown';
import {addMessage, createStreamBubble, editLastMessage, newMessageId, refreshEditButton} from './messages';
import {cfg, state} from './state';

// Stop the written answer in progress: what was received stays in the chat, marked as stopped.
export const stopAnswer = () => {
    state.chatController?.abort();
};

// Display of a spoken answer: each sentence appears in the bubble, as plain text, when it starts to be heard, and
// the complete answer (tables, code, links, confirmation card) replaces it when the turn ends. finish() can be
// asked before the answer arrives (an interruption): the complete answer is then shown as soon as it arrives.
const createVoiceDisplay = (bubble) => {
    const display = {
        shown: false, done: false, spoken: '', showAnswer: null, finishRequested: false,
        showSpoken: (text) => {
            if (display.done || !text) {
                return;
            }
            if (!display.shown) {
                display.shown = true;
                setThinking(false);
                bubble.thinking = false;
                bubble.el.classList.remove('jedomi-hidden');
            }
            display.spoken = display.spoken ? `${display.spoken} ${text}` : text;
            bubble.version++;
            bubble.inner.textContent = display.spoken;
            const msgs = g('jedomi-messages');
            if (msgs) {
                msgs.scrollTop = msgs.scrollHeight;
            }
        },
        setFinish: (showAnswer) => {
            display.showAnswer = showAnswer;
            if (display.finishRequested) {
                display.finish();
            }
        },
        finish: () => {
            display.finishRequested = true;
            if (display.done || !display.showAnswer) {
                return;
            }
            display.done = true;
            setThinking(false);
            display.showAnswer();
        },
    };
    return display;
};

// Technical action blocks (JEDOMI_ACTION / JEDOMI_PENDING_ACTION) came from the legacy
// gateway; confirmations now arrive as the _jedomi_pending_action event, so any such
// block the model still writes is removed from the answer.
const withoutActionBlocks = (text) => text
    .replace(/JEDOMI_ACTION[\s\S]*?END_ACTION/g, '')
    .replace(/JEDOMI_PENDING_ACTION:[\s\S]*?:END_PENDING/g, '')
    .trim();

// Icon of each tool shown while it runs; any other tool shows 'zap'.
const TOOL_ICONS = {
    search_tables:  'search',
    execute_sql:    'db',
    describe_table: 'clipboard',
    get_site_config: 'gear',
    site_overview:  'chart',
    ws_search:      'search',
    ws_describe:    'book',
    ws_execute:     'zap',
};

// Context of the tool_status template for a tool that is running.
// toolEvent is {tool: "search_tables", args: {...}}
const toolStatusContext = (toolEvent) => {
    const toolName = typeof toolEvent === 'string' ? toolEvent : (toolEvent?.tool || 'unknown');
    const S2 = cfg.strings || {};
    const toolLabels = {
        search_tables:  S2.tool_search_tables || '',
        execute_sql:    S2.tool_execute_sql || '',
        describe_table: S2.tool_describe_table || '',
        get_site_config: S2.tool_get_site_config || '',
        site_overview:  S2.tool_site_overview || '',
        ws_search:      S2.tool_ws_search || '',
        ws_describe:    S2.tool_ws_describe || '',
        ws_execute:     S2.tool_ws_execute || '',
    };

    const label = toolLabels[toolName] || (S2.tool_unknown || '');
    const icon = TOOL_ICONS[toolName] || 'zap';
    return {label, icon: {[icon]: true}};
};

// Show a status (a tool running, or a slow AI round still working) in the answer bubble until the answer starts.
// A token that arrives while the status renders wins: the status only replaces older content.
const showStreamStatus = (streamBubble, context, failure) => {
    const version = ++streamBubble.version;
    Templates.renderForPromise('local_jedomi/local/sidebar/tool_status', context)
        .then(({html, js}) => {
            if (streamBubble.version === version) {
                Templates.replaceNodeContents(streamBubble.inner, html, js);
            }
            return null;
        })
        .catch(error => Log.error(failure, error));
    streamBubble.el.classList.remove('jedomi-hidden');
    setThinking(false);
    streamBubble.thinking = false;
};

// Show why the answer failed in its bubble: an expired session, a message of the server or a connection error.
const showChatError = (streamBubble, err) => {
    if (err.message === '__SESSION_EXPIRED__') {
        streamBubble.inner.innerHTML = renderMarkdown(
            `⚠️ **${cfg.strings?.session_expired_title || ''}**\n\n` +
            `${cfg.strings?.session_expired_hint || ''}`
        );
    } else {
        const message = err.jedomiServerError
            ? err.message
            : (cfg.strings?.ai_error_connection || '');
        streamBubble.inner.innerHTML = renderMarkdown(`⚠️ ${message}`);
    }
    streamBubble.el.classList.remove('jedomi-hidden');
};

// End a spoken answer the user spoke over: it was cancelled on purpose and leaves nothing in the chat, unless the
// server already holds an action.
const endInterruptedAnswer = (streamBubble, fullText, pendingAction) => {
    setThinking(false);
    if (pendingAction) {
        // The server already holds an action: its card is shown, so it can still be confirmed or cancelled.
        streamBubble.version++;
        streamBubble.inner.innerHTML = renderMarkdown(withoutActionBlocks(fullText));
        streamBubble.inner.appendChild(buildActionConfirmUI(pendingAction));
        streamBubble.el.classList.remove('jedomi-hidden');
        return;
    }
    // The user spoke over Jedomi: the answer was cancelled on purpose and leaves nothing in the chat.
    streamBubble.wrap?.remove();
};

// End a written answer stopped by the stop button or by a new conversation.
const endStoppedAnswer = (controller, streamBubble, fullText, pendingAction) => {
    setThinking(false);
    // A new conversation stopped it: the chat was already cleared.
    if (controller.cleared) {
        streamBubble.wrap?.remove();
        return;
    }
    const partial = withoutActionBlocks(fullText);
    if (!partial && !pendingAction) {
        // Stopped before any text: the question goes back to the message field, as when it is edited.
        editLastMessage();
        renderInto(g('jedomi-messages'), 'local_jedomi/local/sidebar/notice',
            {text: cfg.strings?.answer_stopped || ''}, true);
        return;
    }
    // The text received so far stays, marked as stopped, and is kept in the conversation.
    const messageId = newMessageId();
    streamBubble.version++;
    streamBubble.inner.innerHTML = renderMarkdown(partial);
    if (pendingAction) {
        streamBubble.inner.appendChild(buildActionConfirmUI(pendingAction));
    }
    const note = document.createElement('p');
    note.className = 'jedomi-stopped-note text-muted small mb-0';
    note.textContent = cfg.strings?.answer_stopped || '';
    streamBubble.inner.appendChild(note);
    streamBubble.el.classList.remove('jedomi-hidden');
    addMessageActions(streamBubble.wrap, partial, messageId);
    state.conversation.push({role: 'assistant', content: partial, id: messageId});
    saveConversation();
};

// Show the user message, add it to the conversation and stream the answer of the chat proxy.
// displayText is what the chat shows, modelText what the model receives and image the attached image, if any.
// voiceHooks, given by the voice conversation, asks for a spoken answer (voice_mode) and receives the answer as it
// arrives: {onToken(token), onPendingAction(pending), onDone(), onError(err)}.
export const runChat = async(displayText, modelText, image = null, voiceHooks = null) => {
    const input = g('jedomi-input');
    const sendBtn = g('jedomi-send');

    const userWrap = addMessage('user', displayText, image);
    const userMessage = {role: 'user', content: modelText};
    // What the chat shows for the question (without the text read from its files), kept for a reload.
    if (displayText && displayText !== modelText) {
        userMessage.display = displayText;
    }
    // Only the number of images is kept for a reload, never the images themselves.
    if (image) {
        userMessage.images = 1;
    }
    state.conversation.push(userMessage);
    saveConversation(); // Save at once.

    setThinking(true);
    state.sending = true;
    if (sendBtn) {
        sendBtn.disabled = true;
    }
    // A written answer can be stopped: the stop button replaces the send button while it is in progress. The
    // voice conversation stops its answers by speaking over Jedomi.
    const controller = voiceHooks ? null : new AbortController();
    state.chatController = controller;
    const stopBtn = g('jedomi-stop');
    if (controller) {
        sendBtn?.classList.add('jedomi-hidden');
        stopBtn?.classList.remove('jedomi-hidden');
    }
    // Screen readers announce the answer once it is complete, not every token.
    g('jedomi-messages')?.setAttribute('aria-busy', 'true');

    // Create an empty bubble to fill while streaming.
    const streamBubble = createStreamBubble();
    // A spoken answer is shown as it is heard (see createVoiceDisplay), not as it arrives.
    const voiceDisplay = voiceHooks ? createVoiceDisplay(streamBubble) : null;
    voiceHooks?.onDisplay?.(voiceDisplay);

    let fullText = '';
    let pendingAction = null;
    try {
        await callGatewayStream(
            state.conversation,
            // OnToken: add each token to the bubble.
            (token) => {
                fullText += token;
                voiceHooks?.onToken(token);
                if (voiceDisplay) {
                    return;
                }
                if (streamBubble.thinking) {
                    // First answer: hide thinking and show the bubble.
                    setThinking(false);
                    streamBubble.thinking = false;
                    streamBubble.el.classList.remove('jedomi-hidden');
                }
                streamBubble.rawText += token;
                streamBubble.version++;
                streamBubble.inner.innerHTML = renderMarkdown(streamBubble.rawText);
                // Smooth scroll to the bottom.
                const msgs = g('jedomi-messages');
                if (msgs) {
                    msgs.scrollTop = msgs.scrollHeight;
                }
            },
            // OnToolEvent: show which tool is running.
            (toolEvent) => {
                showStreamStatus(streamBubble, toolStatusContext(toolEvent), 'Jedomi: tool status could not be rendered');
            },
            // OnPendingAction: a tool that changes data waits for the user's confirmation.
            (pending) => {
                pendingAction = pending;
                voiceHooks?.onPendingAction(pending);
            },
            voiceHooks ? {voice_mode: true} : {},
            voiceHooks?.signal || controller?.signal || null,
            // OnHeartbeat: a slow AI round is still working; shown until the answer starts.
            () => {
                if (voiceDisplay || streamBubble.rawText !== '') {
                    return;
                }
                showStreamStatus(streamBubble, {label: cfg.strings?.still_working || '', icon: {cpu: true}},
                    'Jedomi: working status could not be rendered');
            }
        );

        fullText = withoutActionBlocks(fullText);
        const messageId = newMessageId();
        const showAnswer = () => {
            streamBubble.version++;
            if (voiceDisplay) {
                streamBubble.el.classList.remove('jedomi-hidden');
            }
            streamBubble.inner.innerHTML = renderMarkdown(fullText);
            if (pendingAction) {
                streamBubble.el.classList.remove('jedomi-hidden');
                streamBubble.inner.appendChild(buildActionConfirmUI(pendingAction));
            }
            addMessageActions(streamBubble.wrap, fullText, messageId);

            // Links: open in the same tab directly (no preventDefault).
            streamBubble.inner.querySelectorAll('a[href]').forEach(a => {
                if (!a.getAttribute('target')) {
                    a.setAttribute('target', '_self');
                }
            });
        };
        if (voiceDisplay) {
            voiceDisplay.setFinish(showAnswer);
        } else {
            showAnswer();
        }

        state.conversation.push({role: 'assistant', content: fullText, id: messageId});
        saveConversation(); // Save it on the server.
        voiceHooks?.onDone();

    } catch (err) {
        if (voiceDisplay) {
            voiceDisplay.done = true;
        }
        if (voiceHooks && err?.name === 'AbortError') {
            endInterruptedAnswer(streamBubble, fullText, pendingAction);
            return;
        }
        if (controller && err?.name === 'AbortError') {
            endStoppedAnswer(controller, streamBubble, fullText, pendingAction);
            return;
        }
        Log.error('Jedomi error', err);
        setThinking(false);
        streamBubble.version++;
        showChatError(streamBubble, err);
        // The question has no answer: it leaves the history (here and on the server), so it is not sent again
        // as if it had been answered. It stays in the chat with the error, and can no longer be edited.
        if (!pendingAction) {
            undoLastQuestion();
            if (userWrap) {
                userWrap.dataset.orphan = '1';
            }
            refreshEditButton();
        }
        voiceHooks?.onError(err);
    } finally {
        // A spoken answer keeps the thinking indicator until its first sentence is heard.
        if (!voiceDisplay || voiceDisplay.shown || voiceDisplay.done) {
            setThinking(false);
        }
        state.sending = false;
        if (sendBtn) {
            sendBtn.disabled = false;
        }
        if (controller) {
            if (state.chatController === controller) {
                state.chatController = null;
            }
            stopBtn?.classList.add('jedomi-hidden');
            sendBtn?.classList.remove('jedomi-hidden');
        }
        g('jedomi-messages')?.setAttribute('aria-busy', 'false');
        input?.focus();
    }
};
