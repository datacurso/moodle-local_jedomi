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

namespace local_jedomi\whatsapp;

/**
 * Tests for the X-Hub-Signature-256 check of the WhatsApp webhook.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\meta_signature
 */
final class meta_signature_test extends \basic_testcase {
    /** @var string App Secret of the vectors. */
    private const SECRET = 'app-secret-0123456789abcdef';

    /** @var string Raw body of the vectors, as Meta sends it. */
    private const BODY = '{"object":"whatsapp_business_account","entry":[{"id":"102290129340398","changes":[]}]}';

    /**
     * Header Meta sends for a body: "sha256=" and the hexadecimal HMAC-SHA256 of the raw body with the App Secret.
     *
     * @param string $body Raw body.
     * @param string $secret App Secret.
     * @return string
     */
    private static function header_for(string $body, string $secret): string {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function test_valid_signature_is_accepted(): void {
        $this->assertTrue(meta_signature::verify(self::BODY, self::header_for(self::BODY, self::SECRET), self::SECRET));
    }

    public function test_known_vector_is_accepted(): void {
        // RFC 4231 test case 2: key "Jefe", data "what do ya want for nothing?".
        $header = 'sha256=5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843';

        $this->assertTrue(meta_signature::verify('what do ya want for nothing?', $header, 'Jefe'));
        $this->assertFalse(meta_signature::verify('what do ya want for nothing!', $header, 'Jefe'));
    }

    public function test_uppercase_hex_is_accepted(): void {
        $header = 'sha256=' . strtoupper(hash_hmac('sha256', self::BODY, self::SECRET));

        $this->assertTrue(meta_signature::verify(self::BODY, $header, self::SECRET));
    }

    public function test_raw_body_is_signed_not_a_reencoded_json(): void {
        // Meta escapes non-ASCII characters as \uXXXX; decoding and encoding again changes the bytes.
        $raw = '{"text":{"body":"Café 😀"},"n":1.0, "s":"a\/b"}';
        $reencoded = json_encode(json_decode($raw, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $header = self::header_for($raw, self::SECRET);

        $this->assertNotSame($raw, $reencoded);
        $this->assertTrue(meta_signature::verify($raw, $header, self::SECRET));
        $this->assertFalse(meta_signature::verify($reencoded, $header, self::SECRET));
    }

    public function test_raw_utf8_bytes_are_signed_as_they_are(): void {
        $raw = "{\"body\":\"Café 😀 ñ\"}";
        $header = self::header_for($raw, self::SECRET);

        $this->assertTrue(meta_signature::verify($raw, $header, self::SECRET));
        $this->assertFalse(meta_signature::verify(json_encode(json_decode($raw, true)), $header, self::SECRET));
    }

    public function test_one_byte_changed_in_the_body_is_rejected(): void {
        $header = self::header_for(self::BODY, self::SECRET);
        $tampered = str_replace('"changes":[]', '"changes": []', self::BODY);

        $this->assertFalse(meta_signature::verify($tampered, $header, self::SECRET));
    }

    public function test_wrong_secret_is_rejected(): void {
        $header = self::header_for(self::BODY, 'another-secret');

        $this->assertFalse(meta_signature::verify(self::BODY, $header, self::SECRET));
    }

    public function test_empty_secret_is_rejected_even_with_its_own_signature(): void {
        $header = self::header_for(self::BODY, '');

        $this->assertFalse(meta_signature::verify(self::BODY, $header, ''));
    }

    /**
     * Headers that are never accepted.
     *
     * @return array
     */
    public static function malformed_header_provider(): array {
        $hex = hash_hmac('sha256', self::BODY, self::SECRET);
        return [
            'missing' => [null],
            'empty' => [''],
            'no prefix' => [$hex],
            'sha1 prefix' => ['sha1=' . $hex],
            'prefix only' => ['sha256='],
            'short hex' => ['sha256=' . substr($hex, 0, 63)],
            'long hex' => ['sha256=' . $hex . '0'],
            'not hex' => ['sha256=' . substr($hex, 0, 63) . 'g'],
            'two signatures' => ['sha256=' . $hex . ',sha256=' . $hex],
            'uppercase prefix' => ['SHA256=' . $hex],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider malformed_header_provider
     * @param string|null $header Header value.
     */
    public function test_malformed_header_is_rejected(?string $header): void {
        $this->assertFalse(meta_signature::verify(self::BODY, $header, self::SECRET));
    }
}
