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
 * Only the models evaluated for Jedomi are offered, in one catalogue with what each model can do:
 * chat, vision (images and PDF pages) or audio (transcription). plugins-ai rejects any other model
 * with 400 jedomi_invalid_model, and the Datacurso Token Manager has a price for each of them.
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

    /** @var string[] Every capability, in the order of the settings page. */
    public const CAPABILITIES = [self::CAPABILITY_CHAT, self::CAPABILITY_VISION, self::CAPABILITY_AUDIO];

    /** @var string Default chat model: best quality for its price in the model evaluation. */
    public const DEFAULT_MODEL = 'jedomi/google/gemma-4-26B-A4B-it';

    /** @var string Default vision model: exact and cheap in the phase 10 tests. */
    public const DEFAULT_VISION_MODEL = 'jedomi/Qwen/Qwen3-VL-30B-A3B-Instruct';

    /** @var string Default audio model: the only one that wrote "Jedomi" right in the phase 10 tests. */
    public const DEFAULT_AUDIO_MODEL = 'jedomi/Qwen/Qwen3-ASR-1.7B';

    /** @var array<string, string> Config name of the model of each capability. */
    public const MODEL_SETTINGS = [
        self::CAPABILITY_CHAT => 'model',
        self::CAPABILITY_VISION => 'vision_model',
        self::CAPABILITY_AUDIO => 'audio_model',
    ];

    /** @var int Default answer size limit, in tokens. */
    public const DEFAULT_MAX_TOKENS = 16384;

    /** @var int Smallest accepted answer size limit, in tokens. */
    public const MIN_MAX_TOKENS = 256;

    /** @var int Largest answer size limit accepted by the settings form, in tokens. */
    public const MAX_MAX_TOKENS = 128000;

    /** @var string[] Legacy settings removed when the plugin moved to the provider route. */
    private const LEGACY_SETTINGS = ['gateway_url', 'api_key'];

    /**
     * Every model Jedomi can use, keyed by model id, with its display name and capabilities.
     *
     * Ids are exact and case-sensitive. Keep in sync with the chat, vision and audio lists of plugins-ai.
     *
     * @return array<string, array{name: string, capabilities: string[]}>
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
            self::DEFAULT_AUDIO_MODEL => ['name' => 'Qwen3-ASR 1.7B', 'capabilities' => [self::CAPABILITY_AUDIO]],
            'jedomi/openai/whisper-large-v3-turbo' => [
                'name' => 'Whisper large-v3 turbo',
                'capabilities' => [self::CAPABILITY_AUDIO],
            ],
            'jedomi/mistralai/Voxtral-Mini-3B-2507' => ['name' => 'Voxtral Mini 3B', 'capabilities' => [self::CAPABILITY_AUDIO]],
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
        return self::get_configured_model(self::CAPABILITY_CHAT);
    }

    /**
     * Model that reads images and PDF pages.
     *
     * @return string The configured model, or the default vision model when it is missing or not in the list.
     */
    public static function get_vision_model(): string {
        return self::get_configured_model(self::CAPABILITY_VISION);
    }

    /**
     * Model that transcribes audio.
     *
     * @return string The configured model, or the default audio model when it is missing or not in the list.
     */
    public static function get_audio_model(): string {
        return self::get_configured_model(self::CAPABILITY_AUDIO);
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
     * Whether images, PDF pages and attached files can be processed. On until it is saved.
     *
     * @return bool
     */
    public static function is_files_enabled(): bool {
        $value = get_config('local_jedomi', 'files_enabled');
        return $value === false || (bool) (int) $value;
    }

    /**
     * Whether audio can be transcribed. Off until it is saved.
     *
     * @return bool
     */
    public static function is_audio_enabled(): bool {
        return (bool) (int) get_config('local_jedomi', 'audio_enabled');
    }

    /**
     * Answer size limit for every AI call.
     *
     * @return int
     */
    public static function get_max_tokens(): int {
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
}
