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
 * AI service Jedomi uses (Datacurso or a custom OpenAI-compatible service) and the custom service settings.
 *
 * The service is chosen with the "AI service" select of the Jedomi page in the Datacurso AI provider, and stored in
 * the single ai_service setting. A site that never chose, or any other saved value, uses the Datacurso service, as
 * before the setting existed. The custom service has its own settings (custom_*), so
 * switching services never overwrites the Datacurso ones. The custom API key is a secret: it is only read on the
 * server to call the service, and never sent back to a browser, written to a log or put in an event.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_service {
    /** @var string Service: Datacurso, through the Datacurso AI provider. */
    public const DATACURSO = 'datacurso';

    /** @var string Service: a custom OpenAI-compatible service. */
    public const CUSTOM = 'custom';

    /** @var string Config name of the service in use. */
    public const CONFIG = 'ai_service';

    /** @var array Config name of the custom model of each capability. */
    public const CUSTOM_MODEL_SETTINGS = [
        model_settings::CAPABILITY_CHAT => 'custom_model',
        model_settings::CAPABILITY_VISION => 'custom_vision_model',
        model_settings::CAPABILITY_AUDIO => 'custom_audio_model',
        model_settings::CAPABILITY_SPEECH => 'custom_speech_model',
    ];

    /** @var string Plugin of the settings. */
    private const COMPONENT = 'local_jedomi';

    /**
     * Service in use.
     *
     * @return string CUSTOM or DATACURSO; DATACURSO when nothing (or any other value) is saved.
     */
    public static function get_active(): string {
        return get_config(self::COMPONENT, self::CONFIG) === self::CUSTOM ? self::CUSTOM : self::DATACURSO;
    }

    /**
     * Whether the custom service is in use.
     *
     * @return bool
     */
    public static function is_custom(): bool {
        return self::get_active() === self::CUSTOM;
    }

    /**
     * Saved base address of the custom service, normalised to the root of its API (see custom_endpoint::normalise()).
     *
     * @return string Empty when none is saved.
     */
    public static function get_custom_url(): string {
        return custom_endpoint::normalise((string) get_config(self::COMPONENT, 'custom_gateway_url'));
    }

    /**
     * Saved API key of the custom service. Only for the server-side calls: never send it to a browser or a log.
     *
     * The key is stored encrypted with the key of the site, in dataroot (JEDOMI-SEC-010).
     *
     * @return string Empty when none is saved.
     */
    public static function get_custom_key(): string {
        return trim(\local_jedomi\local\secret_store::get('custom_api_key'));
    }

    /**
     * Key to use for an address: the typed one, or, when none is typed, the saved key, but only for the saved address.
     *
     * The saved key is never sent to an address it was not saved for, so changing the address needs the key again.
     *
     * @param string $url Address.
     * @param string $typed Key typed in the form; empty when the field was left empty.
     * @return string|null The key (empty for none), or null when the key must be typed again.
     */
    public static function effective_key(string $url, string $typed): ?string {
        $typed = trim($typed);
        if ($typed !== '') {
            return $typed;
        }
        $saved = self::get_custom_key();
        if ($saved === '' || custom_endpoint::normalise($url) === self::get_custom_url()) {
            return $saved;
        }
        return null;
    }

    /**
     * Whether "Allow HTTP for local models" is on. Off until it is saved.
     *
     * @return bool
     */
    public static function allows_custom_http(): bool {
        return (bool) (int) get_config(self::COMPONENT, 'custom_allow_http');
    }

    /**
     * Saved custom model of a capability.
     *
     * @param string $capability One of the model_settings::CAPABILITY_* constants.
     * @return string Empty when none is saved: the feature is then off.
     */
    public static function get_custom_model(string $capability): string {
        return trim((string) get_config(self::COMPONENT, self::CUSTOM_MODEL_SETTINGS[$capability]));
    }

    /**
     * Saved voice of the custom speech model.
     *
     * @return string Empty when none is saved.
     */
    public static function get_custom_voice(): string {
        return trim((string) get_config(self::COMPONENT, 'custom_speech_voice'));
    }

    /**
     * Saved answer size limit of the custom service.
     *
     * @return int The saved value, or the default one ({@see self::default_custom_max_tokens()}) when it is empty or too
     *         small.
     */
    public static function get_custom_max_tokens(): int {
        $maxtokens = (int) get_config(self::COMPONENT, 'custom_max_tokens');
        return $maxtokens >= model_settings::MIN_MAX_TOKENS
            ? $maxtokens
            : self::default_custom_max_tokens(self::get_custom_context_window());
    }

    /**
     * Default max tokens of the custom service: the default of Jedomi, but at most half the context window when it is
     * known, so the default answer always fits next to the conversation.
     *
     * @param int $window Context window, in tokens; 0 when it is not known.
     * @return int
     */
    public static function default_custom_max_tokens(int $window): int {
        if ($window <= 0) {
            return model_settings::DEFAULT_MAX_TOKENS;
        }
        return max(model_settings::MIN_MAX_TOKENS, min(model_settings::DEFAULT_MAX_TOKENS, intdiv($window, 2)));
    }

    /**
     * Token parameter of the answer limit the custom service accepts (decision 3): found by "Verify connection", or by a
     * chat request that was refused with the other one. It belongs to the address it was found for, so another address
     * starts again from max_tokens.
     *
     * @return string custom_api::TOKEN_PARAM_COMPLETION when saved for the saved address, otherwise
     *         custom_api::TOKEN_PARAM_MAX.
     */
    public static function get_custom_token_param(): string {
        $saved = json_decode((string) get_config(self::COMPONENT, 'custom_token_param'), true);
        $found = is_array($saved) && ($saved['url'] ?? null) === self::get_custom_url() && self::get_custom_url() !== '';
        return $found && ($saved['param'] ?? null) === custom_api::TOKEN_PARAM_COMPLETION
            ? custom_api::TOKEN_PARAM_COMPLETION
            : custom_api::TOKEN_PARAM_MAX;
    }

    /**
     * Keep the token parameter of the answer limit a custom service accepts.
     *
     * @param string $url Address of the service, normalised ({@see custom_endpoint::normalise()}).
     * @param string $param custom_api::TOKEN_PARAM_MAX or custom_api::TOKEN_PARAM_COMPLETION.
     * @return void
     */
    public static function remember_token_param(string $url, string $param): void {
        $param = $param === custom_api::TOKEN_PARAM_COMPLETION ? $param : custom_api::TOKEN_PARAM_MAX;
        $value = json_encode(['url' => custom_endpoint::normalise($url), 'param' => $param]);
        if (get_config(self::COMPONENT, 'custom_token_param') !== $value) {
            set_config('custom_token_param', $value, self::COMPONENT);
        }
    }

    /**
     * Saved address of the document service of the custom service (PDF to page images), without trailing slashes.
     *
     * @return string Empty when none is saved.
     */
    public static function get_docservice_url(): string {
        return self::normalise_docservice_url((string) get_config(self::COMPONENT, 'custom_docservice_url'));
    }

    /**
     * Address of a document service as it is saved: without surrounding spaces or trailing slashes.
     *
     * @param string $url Address typed by the administrator.
     * @return string
     */
    public static function normalise_docservice_url(string $url): string {
        return rtrim(trim($url), '/');
    }

    /**
     * Context window of the chat model of the custom service, in tokens.
     *
     * @return int The saved value; otherwise the length the service told for the saved chat model on the last
     *         verification; otherwise 0, when it is not known (the requests are then never trimmed).
     */
    public static function get_custom_context_window(): int {
        $saved = trim((string) get_config(self::COMPONENT, 'custom_context_window'));
        if (preg_match('/^\d+$/', $saved) && (int) $saved > 0) {
            return (int) $saved;
        }
        return self::get_detected_context_window(self::get_custom_model(model_settings::CAPABILITY_CHAT));
    }

    /**
     * Context length the service told for a model on the last verification.
     *
     * @param string $model Model id.
     * @return int 0 when it is not known.
     */
    public static function get_detected_context_window(string $model): int {
        return self::get_context_lengths()[$model] ?? 0;
    }

    /**
     * Context lengths of the models the service told on the last verification.
     *
     * @return array<string, int> Model id => tokens.
     */
    public static function get_context_lengths(): array {
        $lengths = json_decode((string) get_config(self::COMPONENT, 'custom_context_lengths'), true);
        if (!is_array($lengths)) {
            return [];
        }
        return array_filter(
            array_map('intval', array_filter($lengths, 'is_numeric')),
            static fn(int $tokens): bool => $tokens > 0
        );
    }

    /**
     * Keep the context lengths a verification found.
     *
     * @param int[] $lengths Tokens keyed by model id.
     * @return void
     */
    public static function remember_context_lengths(array $lengths): void {
        set_config('custom_context_lengths', json_encode($lengths), self::COMPONENT);
    }

    /**
     * Remember that an address and key answered as an OpenAI-compatible service.
     *
     * Only a keyed fingerprint and the time are stored, never the address or the key. Saving the custom service
     * requires its address and key to match the last verified pair.
     *
     * @param string $url Address that was verified.
     * @param string $key Key that was used; empty for none.
     * @param int|null $time Moment of the verification; null for now.
     * @return void
     */
    public static function remember_verified(string $url, string $key, ?int $time = null): void {
        $record = json_encode(['hash' => self::fingerprint($url, $key), 'time' => $time ?? time()]);
        set_config('custom_verified', $record, self::COMPONENT);
    }

    /**
     * Whether an address and key are the last pair that was verified.
     *
     * @param string $url Address.
     * @param string $key Key; empty for none.
     * @return bool
     */
    public static function is_verified(string $url, string $key): bool {
        $record = json_decode((string) get_config(self::COMPONENT, 'custom_verified'), true);
        if (!is_array($record) || !is_string($record['hash'] ?? null)) {
            return false;
        }
        return hash_equals($record['hash'], self::fingerprint($url, $key));
    }

    /**
     * Keyed fingerprint of an address and key, with a random secret of the site created the first time it is needed.
     *
     * The address is normalised to the root of its API first, so two addresses of the same root share a fingerprint
     * and addresses of different roots never do.
     *
     * @param string $url Address; normalised first.
     * @param string $key Key.
     * @return string
     */
    private static function fingerprint(string $url, string $key): string {
        $secret = (string) get_config(self::COMPONENT, 'custom_verify_secret');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            set_config('custom_verify_secret', $secret, self::COMPONENT);
        }
        return hash_hmac('sha256', custom_endpoint::normalise($url) . "\n" . trim($key), $secret);
    }
}
