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

/**
 * Test double for the WhatsApp Cloud API client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\whatsapp;

/**
 * WhatsApp client that records each call instead of calling the Graph API, and answers with configurable responses.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_cloud_api_client extends cloud_api_client {
    /** @var string Address prefix of the media served by this double. */
    public const MEDIA_URL = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=';

    /** @var array<int, array{method: string, args: array}> Calls made, in order. */
    public array $calls = [];

    /**
     * @var array<string, array|\Throwable|\Closure> Answer of each method: an array returned, a Throwable thrown or a
     *      Closure called with the arguments (it may throw). A method without one gets a default answer.
     */
    public array $responses = [];

    /**
     * @var array<string, array{content: string, mime_type: string, file_size?: int}> Media served, keyed by media id. A
     *      file_size given here is announced by get_media_url() instead of the real size of the content.
     */
    public array $media = [];

    /** @var array<string, int> Downloads of each media id that answer 404 as an expired address before one succeeds. */
    public array $expired = [];

    /** @var int Messages sent, for the fake wamids. */
    private int $sentcount = 0;

    /**
     * Use dummy settings so no real configuration is needed.
     */
    public function __construct() {
        parent::__construct('test-token', '123456789', manager::DEFAULT_GRAPH_API_VERSION);
    }

    /**
     * Calls made to one method, in order.
     *
     * @param string $method Method name.
     * @return array<int, array{method: string, args: array}>
     */
    public function calls_to(string $method): array {
        return array_values(array_filter($this->calls, static fn(array $call): bool => $call['method'] === $method));
    }

    #[\Override]
    public function send_text(recipient_ref $to, string $text): array {
        return $this->record('send_text', [$to, $text], fn(): array => $this->sent());
    }

    #[\Override]
    public function send_template(recipient_ref $to, string $name, string $languagecode, array $components = []): array {
        return $this->record('send_template', [$to, $name, $languagecode, $components], fn(): array => $this->sent());
    }

    #[\Override]
    public function send_buttons(recipient_ref $to, string $body, array $buttons): array {
        self::check_buttons($buttons);
        return $this->record('send_buttons', [$to, $body, $buttons], fn(): array => $this->sent());
    }

    #[\Override]
    public function send_list(
        recipient_ref $to,
        string $body,
        string $buttontext,
        array $rows,
        string $sectiontitle = ''
    ): array {
        self::check_list_rows($rows);
        return $this->record('send_list', [$to, $body, $buttontext, $rows, $sectiontitle], fn(): array => $this->sent());
    }

    #[\Override]
    public function mark_read_typing(string $messageid, ?int $timeout = null): array {
        return $this->record('mark_read_typing', [$messageid, $timeout], fn(): array => ['success' => true]);
    }

    #[\Override]
    public function get_media_url(string $mediaid): array {
        self::check_media_id($mediaid);
        return $this->record('get_media_url', [$mediaid], function () use ($mediaid): array {
            if (!isset($this->media[$mediaid])) {
                throw new cloud_api_exception(404, 0, 'unknown media');
            }
            return [
                'url' => self::MEDIA_URL . $mediaid,
                'mime_type' => $this->media[$mediaid]['mime_type'],
                'file_size' => $this->media[$mediaid]['file_size'] ?? strlen($this->media[$mediaid]['content']),
            ];
        });
    }

    #[\Override]
    public function download_media(string $url, string $destfile, int $maxbytes = self::MAX_MEDIA_BYTES): int {
        self::check_media_url($url);
        $args = [$url, $destfile, $maxbytes];
        $answer = $this->record('download_media', $args, function () use ($url, $destfile, $maxbytes): array {
            $mediaid = str_starts_with($url, self::MEDIA_URL) ? substr($url, strlen(self::MEDIA_URL)) : '';
            if (!isset($this->media[$mediaid]) || ($this->expired[$mediaid] ?? 0) > 0) {
                if (isset($this->expired[$mediaid])) {
                    $this->expired[$mediaid]--;
                }
                throw new cloud_api_exception(404, 0, 'expired media address');
            }
            $content = $this->media[$mediaid]['content'];
            // As the real client, a file over the limit fails and nothing is left on disk.
            if (strlen($content) > $maxbytes) {
                throw new cloud_api_exception(200, 0, 'file larger than ' . $maxbytes . ' bytes');
            }
            return ['bytes' => (int) file_put_contents($destfile, $content)];
        });
        return (int) ($answer['bytes'] ?? 0);
    }

    #[\Override]
    public function health_check(): array {
        return $this->record('health_check', [], fn(): array => ['id' => '123456789', 'verified_name' => 'Jedomi test']);
    }

    /**
     * Record a call and answer it.
     *
     * @param string $method Method name.
     * @param array $args Arguments.
     * @param \Closure $default Answer when none is configured.
     * @return array
     */
    private function record(string $method, array $args, \Closure $default): array {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $response = $this->responses[$method] ?? null;
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if ($response instanceof \Closure) {
            return $response(...$args);
        }
        return is_array($response) ? $response : $default();
    }

    /**
     * Answer of Meta to a sent message, with a new fake wamid.
     *
     * @return array
     */
    private function sent(): array {
        $this->sentcount++;
        return [
            'messaging_product' => 'whatsapp',
            'contacts' => [],
            'messages' => [['id' => 'wamid.fake.' . $this->sentcount]],
        ];
    }
}
