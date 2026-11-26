<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Media pipeline of the messaging channels.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

use local_jedomi\ai\model_settings;
use local_jedomi\local\anonymizer;
use local_jedomi\local\service\audio_service;
use local_jedomi\local\service\file_extraction_service;
use local_jedomi\local\service\vision_service;

/**
 * Text of an inbound message for the chat model, whatever the channel: its own text, the transcription of an audio, a
 * photo or PDF read by the vision model, or the text of a Word or PowerPoint document (read for free), with its caption
 * as the question. The attachment is downloaded through the media source of the channel.
 */
final class media_pipeline {
    /**
     * Constructor.
     *
     * @param channel $channel Channel of the message.
     */
    public function __construct(
        /** @var channel Channel of the message. */
        private readonly channel $channel,
    ) {
    }

    /**
     * Text of a message for the chat model.
     *
     * Answers the user and returns null when the message must not reach the AI: the feature is off, the file cannot be
     * read, or the message has no text (sticker, location...).
     *
     * @param inbound_message $message Message.
     * @param int $userid Linked Moodle user.
     * @param array $anonymizermap Set to the placeholders of the conversation when the caption of a file read by the
     *        vision model was anonymised with them (placeholder => real value); left as it is otherwise.
     * @return string|null
     */
    public function text(inbound_message $message, int $userid, array &$anonymizermap = []): ?string {
        $chatkey = $message->chatkey;
        $transport = $this->channel->transport();
        $caption = $message->caption;
        $media = $message->media;

        if ($media !== null && $media->kind === media_ref::AUDIO) {
            if (!model_settings::is_audio_enabled()) {
                $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_AUDIO_DISABLED));
                return null;
            }
            $transport->send_activity($chatkey);
            $filename = self::audio_filename($media->filename, $media->mimetype);
            try {
                $transcription = $this->channel->media_source()->with_file(
                    $media,
                    static fn(string $path): array => audio_service::transcribe_upload($userid, $path, $filename)
                );
            } catch (\Throwable $e) {
                $transport->send_text($chatkey, audio_service::error_for($e)['message']);
                return null;
            }
            // The user sees what was understood before the answer.
            $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_HEARD, $transcription['text']));
            return $transcription['text'];
        }

        if ($media !== null) {
            if (!model_settings::is_files_enabled()) {
                $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_FILES_DISABLED));
                return null;
            }
            $filename = clean_param($media->filename, PARAM_FILE);
            if ($media->kind === media_ref::DOCUMENT && file_extraction_service::is_office_document($filename)) {
                // Word and PowerPoint: their text is read without a model, so it is not billed.
                $transport->send_activity($chatkey);
                try {
                    $extracted = $this->channel->media_source()->with_file(
                        $media,
                        static fn(string $path): array => file_extraction_service::extract_upload($userid, $path, $filename)
                    );
                } catch (\Throwable $e) {
                    $transport->send_text($chatkey, file_extraction_service::error_for($e)['message']);
                    return null;
                }
                return file_extraction_service::compose_message($caption, $filename, $extracted['text']);
            }
            if (!vision_service::is_supported($filename)) {
                $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_FILE_UNSUPPORTED));
                return null;
            }
            $transport->send_activity($chatkey);
            // The caption gets the placeholders of the conversation, so a placeholder the vision model writes back is
            // restored with the value it stands for in this conversation, never with another one (CTR-011).
            $state = $this->channel->store()->get_context('tool_loop_' . $chatkey);
            $anonymizer = anonymizer::from_map((array) ($state['anonymizer'] ?? []));
            try {
                $read = $this->channel->media_source()->with_file(
                    $media,
                    static fn(string $path): array =>
                        vision_service::read_upload($userid, $path, $filename, $caption, null, $anonymizer)
                );
            } catch (\Throwable $e) {
                $transport->send_text($chatkey, vision_service::error_for($e)['message']);
                return null;
            }
            $anonymizermap = $anonymizer->export_map();
            return vision_service::compose_message($caption, $filename, $read['text']);
        }

        if ($message->text === '') {
            $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_TEXT_ONLY));
            return null;
        }
        return $message->text;
    }

    /**
     * File name of an audio, with an extension the transcription service knows: its own name when supported,
     * otherwise one from its type, otherwise its name as it is (or "audio" when it has none).
     *
     * @param string $filename Name of the audio as the channel gives it.
     * @param string $mimetype Type of the audio as the channel gives it.
     * @return string
     */
    public static function audio_filename(string $filename, string $mimetype): string {
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename !== '' && audio_service::is_supported($filename)) {
            return $filename;
        }
        $extension = match (strtolower($mimetype)) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/x-m4a', 'audio/m4a' => 'm4a',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/opus' => 'ogg',
            default => '',
        };
        return $extension !== '' ? 'audio.' . $extension : ($filename !== '' ? $filename : 'audio');
    }
}
