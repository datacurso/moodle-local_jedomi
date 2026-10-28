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
 * AI model and token settings, and the migration away from the legacy gateway settings.
 *
 * Only the models evaluated for Jedomi are offered. plugins-ai rejects any other model with
 * 400 jedomi_invalid_model, and the Datacurso Token Manager has a price for each of them.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_settings {
    /** @var string Prefix every model routed through plugins-ai must have. */
    public const MODEL_PREFIX = 'jedomi/';

    /** @var string Default model: best quality for its price in the model evaluation. */
    public const DEFAULT_MODEL = 'jedomi/google/gemma-4-26B-A4B-it';

    /** @var int Default answer size limit, in tokens. */
    public const DEFAULT_MAX_TOKENS = 16384;

    /** @var int Smallest accepted answer size limit, in tokens. */
    public const MIN_MAX_TOKENS = 256;

    /** @var string[] Legacy settings removed when the plugin moved to the provider route. */
    private const LEGACY_SETTINGS = ['gateway_url', 'api_key'];

    /**
     * Models offered in the settings, keyed by model id, with the default one first.
     *
     * Ids are exact and case-sensitive. Keep in sync with JEDOMI_MODELS in plugins-ai.
     *
     * @return array<string, string> Model id => display name.
     */
    public static function get_available_models(): array {
        return [
            self::DEFAULT_MODEL => 'Gemma 4 26B A4B',
            'jedomi/deepseek-ai/DeepSeek-V4.1-Flash-1017' => 'DeepSeek V4.1 Flash',
            'jedomi/zai-org/GLM-5.3-Flash' => 'GLM 5.3 Flash',
            'jedomi/XiaomiMiMo/MiMo-V2.6-Flash' => 'MiMo V2.6 Flash',
        ];
    }

    /**
     * Whether a model id is in the list of available models (case-sensitive).
     *
     * @param string $model Model id.
     * @return bool
     */
    public static function is_available(string $model): bool {
        return array_key_exists($model, self::get_available_models());
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
     * Model to use for every AI call.
     *
     * @return string The configured model, or the default one when it is missing or not in the list.
     */
    public static function get_model(): string {
        $model = trim((string) get_config('local_jedomi', 'model'));
        return self::is_available($model) ? $model : self::DEFAULT_MODEL;
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
}
