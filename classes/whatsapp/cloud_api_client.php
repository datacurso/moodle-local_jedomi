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

namespace local_jedomi\whatsapp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Thin client of the WhatsApp Cloud API (Meta Graph API): messages, read receipts with typing indicator, media and the
 * health check of the phone number.
 *
 * Every request goes through Moodle's \curl, so the proxy of the site and its cURL security apply; redirects are never
 * followed and TLS is verified, because every request carries the access token in its Authorization header. The token
 * and the recipient never reach the log nor the text of an exception.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cloud_api_client {
    /** @var string Base address of the Graph API; the version and the path follow. */
    public const BASE_URL = 'https://graph.facebook.com/';

    /** @var int Most reply buttons Meta accepts in one interactive message. */
    public const MAX_BUTTONS = 3;

    /** @var int Most rows Meta accepts in one list message. */
    public const MAX_LIST_ROWS = 10;

    /** @var int Largest media file downloaded (16 MB, the audio and video limit of WhatsApp). */
    public const MAX_MEDIA_BYTES = 16 * 1024 * 1024;

    /** @var int Seconds a request may take to connect. */
    public const CONNECT_TIMEOUT = 5;

    /** @var string[] Hosts (and their subdomains) a media file is downloaded from with the access token. */
    private const MEDIA_HOSTS = ['fbsbx.com', 'facebook.com', 'fbcdn.net', 'whatsapp.net'];

    /** @var string Pattern of a numeric identifier of Meta (phone number ID, WABA ID, media ID), shared by the settings. */
    public const ID_PATTERN = '/^\d+$/D';

    /**
     * @var int HTTP status of the failure of a successful (2xx) answer that cannot be used: not JSON, or without the
     *      field the operation needs. The exact 2xx status is not kept, so the plain success status stands for all.
     */
    public const HTTP_OK_UNUSABLE = 200;

    /** @var int Seconds a request may take in total, connection included. */
    private int $timeout = 30;

    /** @var string Access token of the system user. */
    private string $token;

    /** @var string Phone number ID that sends the messages. */
    private string $phonenumberid;

    /** @var string Graph API version, such as v25.0. */
    private string $version;

    /** @var cloud_api_client|null Replacement returned by {@see instance()} in PHPUnit tests. */
    private static ?cloud_api_client $testinstance = null;

    /**
     * Client to use: the test replacement when one is set, otherwise a client with the saved settings.
     *
     * @return cloud_api_client
     */
    public static function instance(): cloud_api_client {
        return self::$testinstance ?? new cloud_api_client();
    }

    /**
     * Replace the client returned by {@see instance()}. Only for PHPUnit tests.
     *
     * @param cloud_api_client|null $client Replacement, or null to restore the default.
     */
    public static function set_instance_for_testing(?cloud_api_client $client): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('cloud_api_client::set_instance_for_testing() is only for PHPUnit tests');
        }
        self::$testinstance = $client;
    }

    /**
     * Client with the given settings; each one left null is read from the saved settings.
     *
     * @param string|null $token Access token.
     * @param string|null $phonenumberid Phone number ID.
     * @param string|null $version Graph API version.
     */
    public function __construct(?string $token = null, ?string $phonenumberid = null, ?string $version = null) {
        $this->token = $token ?? manager::get_access_token();
        $this->phonenumberid = $phonenumberid ?? manager::get_phone_number_id();
        $version = $version ?? manager::get_graph_api_version();
        if (!manager::is_valid_graph_api_version($version)) {
            throw new \coding_exception('Invalid Graph API version');
        }
        $this->version = $version;
    }

    /**
     * Limit the total time of each request.
     *
     * @param int $seconds Seconds a request may take in total; the connect timeout never exceeds it.
     */
    public function set_timeout(int $seconds): void {
        $this->timeout = max(1, $seconds);
    }

    /**
     * Send a text message, without link preview.
     *
     * @param recipient_ref $to Recipient.
     * @param string $text Text (WhatsApp formatting).
     * @return array Answer of Meta (messages[0].id is the wamid).
     * @throws cloud_api_exception
     */
    public function send_text(recipient_ref $to, string $text): array {
        return $this->send_message($to, 'text', ['text' => ['preview_url' => false, 'body' => $text]]);
    }

    /**
     * Send an approved template.
     *
     * @param recipient_ref $to Recipient.
     * @param string $name Template name.
     * @param string $languagecode Language code of the template, such as es or en_US.
     * @param array $components Components with their parameters, as Meta documents them; none when empty.
     * @return array Answer of Meta.
     * @throws cloud_api_exception
     */
    public function send_template(recipient_ref $to, string $name, string $languagecode, array $components = []): array {
        $template = ['name' => $name, 'language' => ['code' => $languagecode]];
        if ($components !== []) {
            $template['components'] = array_values($components);
        }
        return $this->send_message($to, 'template', ['template' => $template]);
    }

    /**
     * Send an interactive message with reply buttons.
     *
     * @param recipient_ref $to Recipient.
     * @param string $body Text of the message.
     * @param array<string, string> $buttons Title of each button, keyed by the id Meta sends back (1 to 3).
     * @return array Answer of Meta.
     * @throws \coding_exception When there are no buttons or more than three.
     * @throws cloud_api_exception
     */
    public function send_buttons(recipient_ref $to, string $body, array $buttons): array {
        self::check_buttons($buttons);
        $replies = [];
        foreach ($buttons as $id => $title) {
            $replies[] = ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => (string) $title]];
        }
        return $this->send_message($to, 'interactive', ['interactive' => [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => ['buttons' => $replies],
        ]]);
    }

    /**
     * Send an interactive list message with one section.
     *
     * @param recipient_ref $to Recipient.
     * @param string $body Text of the message.
     * @param string $buttontext Text of the button that opens the list.
     * @param array<string, string> $rows Title of each row, keyed by the id Meta sends back (1 to 10).
     * @param string $sectiontitle Title of the section; none when empty.
     * @return array Answer of Meta.
     * @throws \coding_exception When there are no rows or more than ten.
     * @throws cloud_api_exception
     */
    public function send_list(
        recipient_ref $to,
        string $body,
        string $buttontext,
        array $rows,
        string $sectiontitle = ''
    ): array {
        self::check_list_rows($rows);
        $section = [];
        if ($sectiontitle !== '') {
            $section['title'] = $sectiontitle;
        }
        foreach ($rows as $id => $title) {
            $section['rows'][] = ['id' => (string) $id, 'title' => (string) $title];
        }
        return $this->send_message($to, 'interactive', ['interactive' => [
            'type' => 'list',
            'body' => ['text' => $body],
            'action' => ['button' => $buttontext, 'sections' => [$section]],
        ]]);
    }

    /**
     * Mark a received message as read and show the typing indicator to its sender.
     *
     * @param string $messageid Id (wamid) of the received message.
     * @return array Answer of Meta.
     * @throws cloud_api_exception
     */
    public function mark_read_typing(string $messageid): array {
        $answer = $this->request('mark_read_typing', $this->messages_url(), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageid,
            'typing_indicator' => ['type' => 'text'],
        ]);
        // Meta confirms a read receipt with {"success": true}.
        if (($answer['success'] ?? null) !== true) {
            $this->fail('mark_read_typing', self::HTTP_OK_UNUSABLE, 0, 'answer without success');
        }
        return $answer;
    }

    /**
     * Download address of a received media file; it expires a few minutes after this call.
     *
     * @param string $mediaid Numeric media id of the webhook.
     * @return array{url: string, mime_type: string, file_size: int}
     * @throws \coding_exception When the media id is not numeric.
     * @throws cloud_api_exception
     */
    public function get_media_url(string $mediaid): array {
        self::check_media_id($mediaid);
        $answer = $this->request('get_media_url', $this->url($mediaid), null);
        $url = (string) ($answer['url'] ?? '');
        if ($url === '') {
            $this->fail('get_media_url', self::HTTP_OK_UNUSABLE, 0, 'answer without url');
        }
        return [
            'url' => $url,
            'mime_type' => (string) ($answer['mime_type'] ?? ''),
            'file_size' => (int) ($answer['file_size'] ?? 0),
        ];
    }

    /**
     * Download a media file with the access token and write it to a file.
     *
     * Only an https address of a host of Meta is accepted, so the token is never sent elsewhere.
     *
     * @param string $url Address returned by {@see get_media_url()}.
     * @param string $destfile Path of the file to write.
     * @param int $maxbytes Largest size accepted.
     * @return int Bytes written.
     * @throws \coding_exception When the address is not an https address of a host of Meta.
     * @throws cloud_api_exception When the download fails (404 when the address expired) or is too large.
     */
    public function download_media(string $url, string $destfile, int $maxbytes = self::MAX_MEDIA_BYTES): int {
        self::check_media_url($url);
        $fh = $this->open_destination($destfile);
        if (!is_resource($fh)) {
            $this->fail('download_media', 0, 0, 'the file could not be opened');
        }
        $written = 0;
        $abort = '';
        // The body goes straight to the file and the limit is held chunk by chunk: CURLOPT_MAXFILESIZE only stops an
        // answer whose Content-Length is known in advance.
        $options = [
            'CURLOPT_MAXFILESIZE' => $maxbytes,
            'CURLOPT_WRITEFUNCTION' => static function ($handle, string $data) use ($fh, $maxbytes, &$written, &$abort): int {
                if ($written + strlen($data) > $maxbytes) {
                    $abort = 'file larger than ' . $maxbytes . ' bytes';
                    return 0;
                }
                if (fwrite($fh, $data) !== strlen($data)) {
                    $abort = 'the file could not be written';
                    return 0;
                }
                $written += strlen($data);
                return strlen($data);
            },
        ] + $this->curl_options();
        try {
            $this->transfer('download_media', $url, null, $options, [], function (int $httpcode) use (&$abort): void {
                if ($abort !== '') {
                    $this->fail('download_media', $httpcode, 0, $abort);
                }
            });
            $closed = fclose($fh);
            clearstatcache(true, $destfile);
            if (!$closed || @filesize($destfile) !== $written) {
                $this->fail('download_media', 0, 0, 'the file could not be written');
            }
        } catch (\Throwable $e) {
            // No partial file is ever left behind: whatever stopped the download, the file is removed.
            if (is_resource($fh)) {
                fclose($fh);
            }
            if (file_exists($destfile)) {
                unlink($destfile);
            }
            throw $e;
        }
        return $written;
    }

    /**
     * Open the file a download is written to, emptied. Tests override it to make writing fail.
     *
     * @param string $destfile Path of the file.
     * @return resource|false Handle open for writing, or false when the file cannot be opened.
     */
    protected function open_destination(string $destfile) {
        return @fopen($destfile, 'wb');
    }

    /**
     * Read the phone number from the Graph API, to check the token, the phone number ID and the version.
     *
     * @return array Answer of Meta (id, display_phone_number, verified_name, quality_rating...).
     * @throws cloud_api_exception
     */
    public function health_check(): array {
        return $this->request('health_check', $this->url($this->phone_number_id()), null);
    }

    /**
     * Check the reply buttons of an interactive message. Shared with the test double, so both reject the same calls.
     *
     * @param array $buttons Buttons.
     * @throws \coding_exception When there are no buttons or more than {@see MAX_BUTTONS}.
     */
    public static function check_buttons(array $buttons): void {
        if ($buttons === [] || count($buttons) > self::MAX_BUTTONS) {
            throw new \coding_exception('A WhatsApp button message needs 1 to ' . self::MAX_BUTTONS . ' buttons');
        }
    }

    /**
     * Check the rows of a list message. Shared with the test double, so both reject the same calls.
     *
     * @param array $rows Rows.
     * @throws \coding_exception When there are no rows or more than {@see MAX_LIST_ROWS}.
     */
    public static function check_list_rows(array $rows): void {
        if ($rows === [] || count($rows) > self::MAX_LIST_ROWS) {
            throw new \coding_exception('A WhatsApp list message needs 1 to ' . self::MAX_LIST_ROWS . ' rows');
        }
    }

    /**
     * Check a media id of the webhook, which becomes part of a Graph API path. Shared with the test double.
     *
     * @param string $mediaid Media id.
     * @throws \coding_exception When it is not numeric.
     */
    public static function check_media_id(string $mediaid): void {
        if (preg_match(self::ID_PATTERN, $mediaid) !== 1) {
            throw new \coding_exception('Invalid WhatsApp media id');
        }
    }

    /**
     * Check that a download address is an https address of a host of Meta, without credentials, so the access token
     * is never sent elsewhere. Shared with the test double.
     *
     * @param string $url Address.
     * @throws \coding_exception When it is not.
     */
    public static function check_media_url(string $url): void {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $allowed = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user'])
            && !isset($parts['pass']) && $host !== '';
        if ($allowed) {
            $allowed = false;
            foreach (self::MEDIA_HOSTS as $mediahost) {
                if ($host === $mediahost || str_ends_with($host, '.' . $mediahost)) {
                    $allowed = true;
                    break;
                }
            }
        }
        if (!$allowed) {
            throw new \coding_exception('WhatsApp media is only downloaded from an https address of Meta');
        }
    }

    /**
     * Options of every request, for Moodle's \curl: the timeouts, redirects never followed and TLS verified.
     *
     * @return array \curl options keyed by option name.
     */
    public function curl_options(): array {
        return [
            'CURLOPT_CONNECTTIMEOUT' => min(self::CONNECT_TIMEOUT, $this->timeout),
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];
    }

    /**
     * New transport for one request: Moodle's \curl, with the proxy and the cURL security of the site.
     *
     * Tests override it to observe the request without network.
     *
     * @return \curl
     */
    protected function new_curl(): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        return new \curl();
    }

    /**
     * Send a message of a type to a recipient.
     *
     * @param recipient_ref $to Recipient.
     * @param string $type Message type.
     * @param array $content Fields of the type.
     * @return array Answer of Meta.
     * @throws cloud_api_exception
     */
    private function send_message(recipient_ref $to, string $type, array $content): array {
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual']
            + $to->to_payload()
            + ['type' => $type]
            + $content;
        $answer = $this->request('send_' . $type, $this->messages_url(), $payload, [$to->value() => '[recipient]']);
        $wamid = $answer['messages'][0]['id'] ?? null;
        if (!is_string($wamid) || $wamid === '') {
            $this->fail('send_' . $type, self::HTTP_OK_UNUSABLE, 0, 'answer without message id');
        }
        return $answer;
    }

    /**
     * Address of the messages of the phone number.
     *
     * @return string
     * @throws cloud_api_exception When the phone number ID is not configured.
     */
    private function messages_url(): string {
        return $this->url($this->phone_number_id() . '/messages');
    }

    /**
     * Phone number ID, checked.
     *
     * @return string
     * @throws cloud_api_exception When it is not configured or not numeric.
     */
    private function phone_number_id(): string {
        if (preg_match(self::ID_PATTERN, $this->phonenumberid) !== 1) {
            $this->fail('config', 0, 0, 'phone number ID not configured');
        }
        return $this->phonenumberid;
    }

    /**
     * Address of a path of the Graph API, in the configured version.
     *
     * @param string $path Path after the version.
     * @return string
     */
    private function url(string $path): string {
        return self::BASE_URL . $this->version . '/' . $path;
    }

    /**
     * Call the Graph API and decode its JSON answer.
     *
     * @param string $op Operation, for the log.
     * @param string $url Address.
     * @param array|null $payload JSON body of a POST; null for a GET.
     * @param array<string, string> $redact Values hidden from the log and the errors, with their replacement.
     * @return array Decoded answer.
     * @throws cloud_api_exception
     */
    private function request(string $op, string $url, ?array $payload, array $redact = []): array {
        $body = null;
        if ($payload !== null) {
            try {
                $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \coding_exception('The WhatsApp request body cannot be encoded as JSON');
            }
        }
        $response = $this->transfer($op, $url, $body, $this->curl_options(), $redact);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->fail($op, self::HTTP_OK_UNUSABLE, 0, 'the answer is not JSON');
        }
        return $decoded;
    }

    /**
     * Make one request with the access token and return the body of a successful answer.
     *
     * @param string $op Operation, for the log.
     * @param string $url Address.
     * @param string|null $body JSON body of a POST; null for a GET.
     * @param array $options \curl options.
     * @param array<string, string> $redact Values hidden from the log and the errors, with their replacement.
     * @param \Closure|null $aborted Called with the HTTP status right after the request, before its errors are read, so
     *        a request a write callback stopped fails with its own reason.
     * @return string Body of the answer; empty when a write callback took it.
     * @throws cloud_api_exception
     */
    private function transfer(
        string $op,
        string $url,
        ?string $body,
        array $options,
        array $redact = [],
        ?\Closure $aborted = null
    ): string {
        if ($this->token === '') {
            $this->fail($op, 0, 0, 'access token not configured');
        }
        $curl = $this->new_curl();
        $curl->setHeader('Authorization: Bearer ' . $this->token);
        if ($body !== null) {
            $curl->setHeader('Content-Type: application/json');
            $response = $curl->post($url, $body, $options);
        } else {
            $response = $curl->get($url, [], $options);
        }
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($aborted !== null) {
            $aborted($httpcode);
        }
        $curlerror = (string) $curl->error;
        if (!empty($curl->errno) || $curlerror !== '') {
            $this->fail($op, $httpcode, 0, $this->redact('transport error ' . (int) $curl->errno . ': ' . $curlerror, $redact));
        }
        $response = is_string($response) ? $response : '';
        if ($httpcode < 200 || $httpcode >= 300) {
            $decoded = json_decode($response, true);
            $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $message = (string) ($error['message'] ?? 'no error message');
            $this->fail($op, $httpcode, (int) ($error['code'] ?? 0), $this->redact(mb_substr($message, 0, 300), $redact));
        }
        return $response;
    }

    /**
     * Log a failure and throw it. The detail is redacted by the caller, so the token never is an argument of a frame of
     * the trace of the exception.
     *
     * @param string $op Operation.
     * @param int $httpcode HTTP status; 0 when no answer arrived.
     * @param int $metacode Error code of Meta; 0 when there is none.
     * @param string $detail Redacted description of the failure.
     * @return never
     * @throws cloud_api_exception
     */
    private function fail(string $op, int $httpcode, int $metacode, string $detail): never {
        \local_jedomi_log('[local_jedomi] WhatsApp Cloud API error: op=' . $op . ' http=' . $httpcode
            . ' code=' . $metacode . ' detail=' . $detail);
        throw new cloud_api_exception($httpcode, $metacode, $detail);
    }

    /**
     * Text with the access token and the given values hidden.
     *
     * @param string $text Text.
     * @param array<string, string> $redact Values hidden, with their replacement.
     * @return string
     */
    private function redact(string $text, array $redact = []): string {
        if ($this->token !== '') {
            $redact[$this->token] = '[token]';
        }
        $redact = array_filter($redact, static fn($key): bool => (string) $key !== '', ARRAY_FILTER_USE_KEY);
        return $redact === [] ? $text : strtr($text, array_map('strval', $redact));
    }
}
