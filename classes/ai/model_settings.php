<?php
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

namespace local_jedomi\ai;

/**
 * AI models, feature switches and token settings, and the migration of older settings.
 *
 * With the Datacurso service, only the models evaluated for Jedomi are offered, in one catalogue with what each model
 * can do: chat, vision (images and PDF pages), audio (transcription) or speech (text to speech, with its voices).
 * plugins-ai rejects any other model with 400 jedomi_invalid_model, and the Datacurso Token Manager has a price for
 * each of them.
 *
 * The getters Jedomi uses (get_model(), is_files_enabled()...) follow the service in use ({@see ai_service}): with
 * the custom service they answer its free-text models, and a feature is on only while its model is set. The
 * get_configured_*() methods always answer the Datacurso settings.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_settings {
    /** @var string Prefix every model routed through plugins-ai must have. */
    public const MODEL_PREFIX = 'jedomi/';

    /** @var string Capability: answer chat messages and call tools. */
    public const CAPABILITY_CHAT = 'chat';

    /** @var string Capability: read images and PDF pages. */
    public const CAPABILITY_VISION = 'vision';

    /** @var string Capability: transcribe audio. */
    public const CAPABILITY_AUDIO = 'audio';

    /** @var string Capability: read text aloud (text to speech) with one of its voices. */
    public const CAPABILITY_SPEECH = 'speech';

    /** @var string[] Every capability, in the order of the settings page. */
    public const CAPABILITIES = [
        self::CAPABILITY_CHAT,
        self::CAPABILITY_VISION,
        self::CAPABILITY_AUDIO,
        self::CAPABILITY_SPEECH,
    ];

    /** @var string Default chat model: best quality for its price in the model evaluation. */
    public const DEFAULT_MODEL = 'jedomi/google/gemma-4-26B-A4B-it';

    /** @var string Default vision model: exact and cheap in the phase 10 tests. */
    public const DEFAULT_VISION_MODEL = 'jedomi/Qwen/Qwen3-VL-30B-A3B-Instruct';

    /** @var string Default audio model: fast transcription for the voice conversation (Qwen3-ASR 1.7B stays listed). */
    public const DEFAULT_AUDIO_MODEL = 'jedomi/openai/whisper-large-v3-turbo';

    /** @var string Default speech model: the fastest to start reading aloud (Qwen3-TTS stays listed). */
    public const DEFAULT_SPEECH_MODEL = 'jedomi/inworld-ai/inworld-tts-1.5-mini';

    /** @var array<string, string> Config name of the model of each capability. */
    public const MODEL_SETTINGS = [
        self::CAPABILITY_CHAT => 'model',
        self::CAPABILITY_VISION => 'vision_model',
        self::CAPABILITY_AUDIO => 'audio_model',
        self::CAPABILITY_SPEECH => 'speech_model',
    ];

    /** @var string Prefix of the form field with the voices of each speech model. */
    public const VOICE_FIELD_PREFIX = 'speech_voice_';

    /** @var int Default answer size limit, in tokens. */
    public const DEFAULT_MAX_TOKENS = 16384;

    /** @var int Smallest accepted answer size limit, in tokens. */
    public const MIN_MAX_TOKENS = 256;

    /** @var int Largest answer size limit accepted by the settings form, in tokens. */
    public const MAX_MAX_TOKENS = 128000;

    /**
     * @var string[] Legacy settings removed when the plugin moved to the provider route. The custom service of
     *      phase 20 has its own settings (custom_gateway_url, custom_api_key...), which are never removed.
     */
    private const LEGACY_SETTINGS = ['gateway_url', 'api_key'];

    /**
     * Every model Jedomi can use, keyed by model id, with its display name, capabilities and, for speech
     * models, its voices (the first one is the default voice).
     *
     * Ids and voices are exact and case-sensitive. Keep in sync with the chat, vision, audio and speech
     * lists of plugins-ai (JEDOMI_TTS_MODELS for speech) and the prices of the Token Manager.
     *
     * @return array<string, array{name: string, capabilities: string[], voices?: string[]}>
     */
    public static function get_catalogue(): array {
        $chatvision = [self::CAPABILITY_CHAT, self::CAPABILITY_VISION];
        // Vision-only models come first, so the vision selector lists them before the multimodal chat models.
        return [
            self::DEFAULT_VISION_MODEL => ['name' => 'Qwen3-VL 30B A3B', 'capabilities' => [self::CAPABILITY_VISION]],
            'jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct' => [
                'name' => 'Qwen3-VL 235B A22B',
                'capabilities' => [self::CAPABILITY_VISION],
            ],
            self::DEFAULT_MODEL => ['name' => 'Gemma 4 26B A4B', 'capabilities' => $chatvision],
            'jedomi/deepseek-ai/DeepSeek-V4.1-Flash-1017' => ['name' => 'DeepSeek V4.1 Flash', 'capabilities' => $chatvision],
            'jedomi/zai-org/GLM-5.3-Flash' => ['name' => 'GLM 5.3 Flash', 'capabilities' => $chatvision],
            'jedomi/XiaomiMiMo/MiMo-V2.6-Flash' => ['name' => 'MiMo V2.6 Flash', 'capabilities' => $chatvision],
            'jedomi/moonshotai/Kimi-K3.1-Pro' => ['name' => 'Kimi K3.1 Pro', 'capabilities' => $chatvision],
            self::DEFAULT_AUDIO_MODEL => ['name' => 'Whisper large-v3 turbo', 'capabilities' => [self::CAPABILITY_AUDIO]],
            'jedomi/Qwen/Qwen3-ASR-1.7B' => ['name' => 'Qwen3-ASR 1.7B', 'capabilities' => [self::CAPABILITY_AUDIO]],
            'jedomi/mistralai/Voxtral-Mini-3B-2507' => ['name' => 'Voxtral Mini 3B', 'capabilities' => [self::CAPABILITY_AUDIO]],
            // Voices accepted in Spanish in the phase 11 tests; any other voice answers an error.
            self::DEFAULT_SPEECH_MODEL => [
                'name' => 'Inworld TTS 1.5 mini',
                'capabilities' => [self::CAPABILITY_SPEECH],
                'voices' => ['Rafael', 'Diego', 'Lupita', 'Miguel'],
            ],
            'jedomi/Qwen/Qwen3-TTS' => [
                'name' => 'Qwen3-TTS',
                'capabilities' => [self::CAPABILITY_SPEECH],
                'voices' => ['Vivian', 'Serena', 'Uncle_Fu', 'Dylan', 'Eric', 'Ryan', 'Aiden', 'Ono_Anna', 'Sohee'],
            ],
        ];
    }

    /**
     * Models with a capability, keyed by model id, with the default one of that capability first.
     *
     * @param string $capability One of the CAPABILITY_* constants.
     * @return array<string, string> Model id => display name.
     */
    public static function get_available_models(string $capability = self::CAPABILITY_CHAT): array {
        $models = [];
        foreach (self::get_catalogue() as $model => $info) {
            if (in_array($capability, $info['capabilities'], true)) {
                $models[$model] = $info['name'];
            }
        }
        $default = self::get_default_model($capability);
        if (isset($models[$default])) {
            $models = [$default => $models[$default]] + $models;
        }
        return $models;
    }

    /**
     * Default model of a capability.
     *
     * @param string $capability One of the CAPABILITY_* constants.
     * @return string
     */
    public static function get_default_model(string $capability): string {
        return match ($capability) {
            self::CAPABILITY_VISION => self::DEFAULT_VISION_MODEL,
            self::CAPABILITY_AUDIO => self::DEFAULT_AUDIO_MODEL,
            self::CAPABILITY_SPEECH => self::DEFAULT_SPEECH_MODEL,
            default => self::DEFAULT_MODEL,
        };
    }

    /**
     * Whether a model id is in the list of a capability (case-sensitive).
     *
     * @param string $model Model id.
     * @param string $capability One of the CAPABILITY_* constants.
     * @return bool
     */
    public static function is_available(string $model, string $capability = self::CAPABILITY_CHAT): bool {
        return array_key_exists($model, self::get_available_models($capability));
    }

    /**
     * Whether a model id can be sent to the Jedomi route.
     *
     * @param string $model Model id.
     * @return bool
     */
    public static function is_routable(string $model): bool {
        return str_starts_with($model, self::MODEL_PREFIX) && strlen($model) > strlen(self::MODEL_PREFIX);
    }

    /**
     * Chat model for every AI call.
     *
     * @return string The configured model, or the default one when it is missing or not in the list.
     */
    public static function get_model(): string {
        return self::get_model_in_use(self::CAPABILITY_CHAT);
    }

    /**
     * Model that reads images and PDF pages.
     *
     * @return string The configured model, or the default vision model when it is missing or not in the list.
     */
    public static function get_vision_model(): string {
        return self::get_model_in_use(self::CAPABILITY_VISION);
    }

    /**
     * Model that transcribes audio.
     *
     * @return string The configured model, or the default audio model when it is missing or not in the list.
     */
    public static function get_audio_model(): string {
        return self::get_model_in_use(self::CAPABILITY_AUDIO);
    }

    /**
     * Model that reads the answers aloud in the voice conversation.
     *
     * @return string The configured model, or the default speech model when it is missing or not in the list.
     */
    public static function get_speech_model(): string {
        return self::get_model_in_use(self::CAPABILITY_SPEECH);
    }

    /**
     * Model of a capability for the service in use.
     *
     * @param string $capability One of the CAPABILITY_* constants.
     * @return string The custom model (empty when not set) with the custom service, otherwise the Datacurso one.
     */
    private static function get_model_in_use(string $capability): string {
        return ai_service::is_custom() ? ai_service::get_custom_model($capability) : self::get_configured_model($capability);
    }

    /**
     * Voices of a speech model, with its default voice first.
     *
     * @param string $model Model id.
     * @return string[] Empty for a model that is not a speech model.
     */
    public static function get_voices(string $model): array {
        $info = self::get_catalogue()[$model] ?? [];
        return in_array(self::CAPABILITY_SPEECH, $info['capabilities'] ?? [], true) ? ($info['voices'] ?? []) : [];
    }

    /**
     * Default voice of a speech model: the first one of its list.
     *
     * @param string $model Model id.
     * @return string Empty for a model that is not a speech model.
     */
    public static function get_default_voice(string $model): string {
        return self::get_voices($model)[0] ?? '';
    }

    /**
     * Voice that reads the answers aloud.
     *
     * @return string The custom voice with the custom service; otherwise the configured voice when it belongs to the
     *         speech model in use, or the default voice of that model.
     */
    public static function get_speech_voice(): string {
        return ai_service::is_custom() ? ai_service::get_custom_voice() : self::get_configured_voice();
    }

    /**
     * Voice of the Datacurso service.
     *
     * @return string The configured voice when it belongs to the configured speech model, or the default voice of that model.
     */
    public static function get_configured_voice(): string {
        $model = self::get_configured_model(self::CAPABILITY_SPEECH);
        $voice = trim((string) get_config('local_jedomi', 'speech_voice'));
        return in_array($voice, self::get_voices($model), true) ? $voice : self::get_default_voice($model);
    }

    /**
     * Name of the form field with the voices of a speech model, for example speech_voice_qwen_qwen3_tts.
     *
     * @param string $model Speech model id.
     * @return string
     */
    public static function voice_field(string $model): string {
        $name = str_starts_with($model, self::MODEL_PREFIX) ? substr($model, strlen(self::MODEL_PREFIX)) : $model;
        return self::VOICE_FIELD_PREFIX . trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    }

    /**
     * Configured model of a capability, or its default one when missing or not in its list.
     *
     * @param string $capability One of the CAPABILITY_* constants.
     * @return string
     */
    public static function get_configured_model(string $capability): string {
        $model = trim((string) get_config('local_jedomi', self::MODEL_SETTINGS[$capability]));
        return self::is_available($model, $capability) ? $model : self::get_default_model($capability);
    }

    /**
     * Whether images, PDF pages and attached files can be processed.
     *
     * With the Datacurso service, the switch, on until it is saved; with the custom service, while its vision model is
     * set (only images then: see {@see self::is_documents_enabled()}).
     *
     * @return bool
     */
    public static function is_files_enabled(): bool {
        return match (ai_service::get_active()) {
            ai_service::CUSTOM => ai_service::get_custom_model(self::CAPABILITY_VISION) !== '',
            default => self::is_switch_on('files_enabled', true),
        };
    }

    /**
     * Whether documents (PDF, Word, PowerPoint, text) can be read: only with the Datacurso service, which reads their
     * text, and only while file processing is on. A generic OpenAI-compatible service has no such route.
     *
     * @return bool
     */
    public static function is_documents_enabled(): bool {
        return ai_service::get_active() === ai_service::DATACURSO && self::is_files_enabled();
    }

    /**
     * Whether audio can be transcribed.
     *
     * With the Datacurso service, the switch, off until it is saved; with the custom service, while its audio model is
     * set.
     *
     * @return bool
     */
    public static function is_audio_enabled(): bool {
        return match (ai_service::get_active()) {
            ai_service::CUSTOM => ai_service::get_custom_model(self::CAPABILITY_AUDIO) !== '',
            default => self::is_switch_on('audio_enabled', false),
        };
    }

    /**
     * Whether the voice conversation of the sidebar can be used. Off while audio processing is off, because what the
     * user says is transcribed by the audio model.
     *
     * With the Datacurso service, the switch, off until it is saved; with the custom service, while its speech model
     * and voice are set.
     *
     * @return bool
     */
    public static function is_voice_enabled(): bool {
        $on = match (ai_service::get_active()) {
            ai_service::CUSTOM => ai_service::get_custom_model(self::CAPABILITY_SPEECH) !== ''
                && ai_service::get_custom_voice() !== '',
            default => self::is_switch_on('voice_enabled', false),
        };
        return $on && self::is_audio_enabled();
    }

    /**
     * Saved state of a feature switch of the Datacurso service.
     *
     * @param string $name Switch: files_enabled, audio_enabled or voice_enabled.
     * @param bool $default State until it is saved.
     * @return bool
     */
    public static function is_switch_on(string $name, bool $default): bool {
        $value = get_config('local_jedomi', $name);
        return $value === false ? $default : (bool) (int) $value;
    }

    /**
     * Answer size limit for every AI call, of the service in use.
     *
     * @return int
     */
    public static function get_max_tokens(): int {
        return ai_service::is_custom() ? ai_service::get_custom_max_tokens() : self::get_configured_max_tokens();
    }

    /**
     * Answer size limit of the Datacurso service.
     *
     * @return int
     */
    public static function get_configured_max_tokens(): int {
        $maxtokens = (int) get_config('local_jedomi', 'max_tokens');
        return $maxtokens >= self::MIN_MAX_TOKENS ? $maxtokens : self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Remove the legacy gateway URL and key, and replace a model that is not in the list with the default one.
     *
     * Every other setting is left untouched. Safe to run more than once.
     */
    public static function migrate_legacy_settings(): void {
        foreach (self::LEGACY_SETTINGS as $name) {
            unset_config($name, 'local_jedomi');
        }
        self::reset_unlisted_model();
    }

    /**
     * Replace a saved model that is missing or not in the list with the default one.
     *
     * Safe to run more than once.
     */
    public static function reset_unlisted_model(): void {
        $model = trim((string) get_config('local_jedomi', 'model'));
        if (!self::is_available($model)) {
            set_config('model', self::DEFAULT_MODEL, 'local_jedomi');
        }
    }

    /**
     * Save the defaults of the file and audio settings that are not set yet, and remove the
     * document-to-image service URL, replaced by the vision model.
     *
     * Values already saved are kept. Safe to run more than once.
     */
    public static function apply_media_defaults(): void {
        $defaults = [
            'files_enabled' => '1',
            'audio_enabled' => '0',
            'vision_model' => self::DEFAULT_VISION_MODEL,
            'audio_model' => self::DEFAULT_AUDIO_MODEL,
        ];
        foreach ($defaults as $name => $value) {
            if (get_config('local_jedomi', $name) === false) {
                set_config($name, $value, 'local_jedomi');
            }
        }
        unset_config('docservice_url', 'local_jedomi');
    }

    /**
     * Save the defaults of the voice conversation settings that are not set yet: off, with the default
     * speech model and its default voice.
     *
     * Values already saved are kept. Safe to run more than once.
     */
    public static function apply_voice_defaults(): void {
        $defaults = [
            'voice_enabled' => '0',
            'speech_model' => self::DEFAULT_SPEECH_MODEL,
            'speech_voice' => self::get_default_voice(self::DEFAULT_SPEECH_MODEL),
        ];
        foreach ($defaults as $name => $value) {
            if (get_config('local_jedomi', $name) === false) {
                set_config($name, $value, 'local_jedomi');
            }
        }
    }
}
