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

use local_jedomi\messaging\media_ref;

/**
 * Tests for the parser of the messages of a WhatsApp webhook.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\inbound_parser
 * @covers     \local_jedomi\whatsapp\inbound
 */
final class inbound_parser_test extends \basic_testcase {
    /** @var string BSUID of the sender. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Message id of Meta. */
    private const WAMID = 'wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQUZCMTY0MDc2MUYwNzBDNTY5MAA=';

    /**
     * A text message.
     *
     * @param array $extra Fields that replace or add to the default ones.
     * @return array
     */
    private static function text_message(array $extra = []): array {
        return array_merge([
            'from_user_id' => self::BSUID,
            'id' => self::WAMID,
            'timestamp' => '1777000000',
            'type' => 'text',
            'text' => ['body' => '  Hello Jedomi  '],
        ], $extra);
    }

    /**
     * A text message with only the BSUID and no phone is parsed; the phone is optional.
     */
    public function test_a_text_message_without_phone_is_parsed(): void {
        $message = inbound_parser::parse(self::text_message());

        $this->assertNotNull($message);
        $this->assertSame(self::WAMID, $message->wamid);
        $this->assertSame(self::BSUID, $message->bsuid);
        $this->assertNull($message->waid);
        $this->assertSame('text', $message->type);
        $this->assertSame('Hello Jedomi', $message->text);
        $this->assertSame(1777000000, $message->timestamp);
        $this->assertFalse($message->is_identity_change());
    }

    /**
     * Without from_user_id the BSUID comes from contacts[].user_id.
     */
    public function test_the_bsuid_falls_back_to_the_contact(): void {
        $raw = self::text_message(['from' => '16505551234']);
        unset($raw['from_user_id']);
        $contacts = [['wa_id' => '16505551234', 'user_id' => self::BSUID, 'profile' => ['name' => 'Ana']]];

        $message = inbound_parser::parse($raw, $contacts);

        $this->assertSame(self::BSUID, $message->bsuid);
        $this->assertSame('16505551234', $message->waid);
    }

    /**
     * The wa_id of the contact completes the phone when the message has no from.
     */
    public function test_the_phone_falls_back_to_the_contact_wa_id(): void {
        $contacts = [['wa_id' => '16505551234', 'user_id' => self::BSUID]];

        $message = inbound_parser::parse(self::text_message(), $contacts);

        $this->assertSame('16505551234', $message->waid);
    }

    /**
     * A message without BSUID nor phone still parses, with both empty, so the intake can store it as failed.
     */
    public function test_a_message_without_any_sender_has_empty_identity(): void {
        $raw = self::text_message();
        unset($raw['from_user_id']);

        $message = inbound_parser::parse($raw);

        $this->assertSame('', $message->bsuid);
        $this->assertNull($message->waid);
        $this->assertSame('', inbound_parser::sender_bsuid($raw, []));
        $this->assertNull(inbound_parser::sender_waid($raw, []));
    }

    /**
     * Values that are not a BSUID, a phone or a message id are dropped.
     *
     * @dataProvider invalid_identity_provider
     * @param array $extra Fields of the message.
     * @param string $bsuid Expected BSUID.
     * @param string|null $waid Expected phone.
     */
    public function test_invalid_identity_values_are_dropped(array $extra, string $bsuid, ?string $waid): void {
        $message = inbound_parser::parse(self::text_message($extra));

        $this->assertSame($bsuid, $message->bsuid);
        $this->assertSame($waid, $message->waid);
    }

    /**
     * Invalid identity values.
     *
     * @return array
     */
    public static function invalid_identity_provider(): array {
        return [
            'bsuid without country' => [['from_user_id' => '13491208655302741918'], '', null],
            'bsuid with spaces' => [['from_user_id' => 'US.1349 1208'], '', null],
            'bsuid too long' => [['from_user_id' => 'US.' . str_repeat('a', 129)], '', null],
            'phone with plus' => [['from' => '+16505551234'], self::BSUID, null],
            'phone as empty string' => [['from' => ''], self::BSUID, null],
        ];
    }

    /**
     * The longest BSUID (country, dot and 128 characters) and a parent-style BSUID are kept as they come.
     */
    public function test_long_and_enterprise_bsuids_are_kept_as_they_come(): void {
        $long = 'CO.' . str_repeat('A1', 64);
        $this->assertSame($long, inbound_parser::parse(self::text_message(['from_user_id' => $long]))->bsuid);

        $enterprise = 'US.ENT.11815799212886844830';
        $this->assertSame($enterprise, inbound_parser::parse(self::text_message(['from_user_id' => $enterprise]))->bsuid);
    }

    /**
     * A message without a valid id cannot be deduplicated and is not parsed.
     */
    public function test_a_message_without_id_is_not_parsed(): void {
        $raw = self::text_message();
        unset($raw['id']);

        $this->assertNull(inbound_parser::parse($raw));
        $this->assertNull(inbound_parser::parse(self::text_message(['id' => 'wamid with spaces'])));
        $this->assertNull(inbound_parser::parse(self::text_message(['id' => str_repeat('a', 129)])));
    }

    /**
     * Profile name, username and parent ids are ignored: none of them is ever the key.
     */
    public function test_profile_and_parent_ids_are_ignored(): void {
        $raw = self::text_message(['from_parent_user_id' => 'US.ENT.999']);
        unset($raw['from_user_id']);
        $contacts = [[
            'profile' => ['name' => 'Ana', 'username' => 'ana_wa'],
            'parent_user_id' => 'US.ENT.999',
        ]];

        $message = inbound_parser::parse($raw, $contacts);

        $this->assertSame('', $message->bsuid);
        $this->assertNull($message->to_message());
    }

    /**
     * Button and list replies carry the id of the option.
     *
     * @dataProvider interactive_provider
     * @param array $interactive Interactive part of the message.
     * @param string $expected Expected reply id.
     */
    public function test_interactive_replies_carry_their_id(array $interactive, string $expected): void {
        $message = inbound_parser::parse(self::text_message(['type' => 'interactive', 'interactive' => $interactive]));

        $this->assertSame($expected, $message->replyid);
        $this->assertTrue($message->to_message()->is_action());
        $this->assertSame($expected, $message->to_message()->actionpayload);
    }

    /**
     * Interactive replies.
     *
     * @return array
     */
    public static function interactive_provider(): array {
        return [
            'button' => [['type' => 'button_reply', 'button_reply' => ['id' => 'confirm:abc', 'title' => 'Yes']], 'confirm:abc'],
            'list' => [['type' => 'list_reply', 'list_reply' => ['id' => 'course:12', 'title' => 'Maths']], 'course:12'],
        ];
    }

    /**
     * Audio, image and document messages carry the media id, type, name and caption.
     *
     * @dataProvider media_provider
     * @param string $type Message type.
     * @param array $media Media part.
     * @param string $kind Expected media kind.
     * @param string $filename Expected file name.
     * @param string $caption Expected caption.
     */
    public function test_media_messages_carry_the_media_id(
        string $type,
        array $media,
        string $kind,
        string $filename,
        string $caption
    ): void {
        $message = inbound_parser::parse(self::text_message(['type' => $type, $type => $media, 'text' => null]));

        $this->assertInstanceOf(media_ref::class, $message->media);
        $this->assertSame($kind, $message->media->kind);
        $this->assertSame('1037543291543636', $message->media->id);
        $this->assertSame($filename, $message->media->filename);
        $this->assertSame($media['mime_type'], $message->media->mimetype);
        $this->assertSame($caption, $message->caption);
        $this->assertSame('', $message->text);
    }

    /**
     * Media messages.
     *
     * @return array
     */
    public static function media_provider(): array {
        return [
            'voice note' => ['audio', ['id' => '1037543291543636', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true],
                media_ref::AUDIO, '', ''],
            'image' => ['image', ['id' => '1037543291543636', 'mime_type' => 'image/jpeg', 'caption' => ' My work '],
                media_ref::IMAGE, '', 'My work'],
            'document' => ['document', ['id' => '1037543291543636', 'mime_type' => 'application/pdf',
                'filename' => 'essay.pdf', 'caption' => 'Essay'], media_ref::DOCUMENT, 'essay.pdf', 'Essay'],
        ];
    }

    /**
     * A message of a type the channel does not read keeps its type and has no text nor media.
     */
    public function test_unsupported_types_have_no_content(): void {
        $message = inbound_parser::parse(self::text_message(['type' => 'sticker', 'sticker' => ['id' => '1'], 'text' => null]));

        $this->assertSame('sticker', $message->type);
        $this->assertSame('', $message->text);
        $this->assertNull($message->media);
    }

    /**
     * The identity change messages carry the previous BSUID, and the new phone when the number changed.
     *
     * @dataProvider identity_change_provider
     * @param array $system System part.
     * @param string|null $newwaid Expected new phone.
     */
    public function test_identity_change_messages_carry_the_previous_bsuid(array $system, ?string $newwaid): void {
        $raw = self::text_message(['type' => 'system', 'system' => $system, 'text' => null]);

        $message = inbound_parser::parse($raw);

        $this->assertTrue($message->is_identity_change());
        $this->assertSame($system['type'], $message->systemtype);
        $this->assertSame('US.11111111111111111111', $message->previousbsuid);
        $this->assertSame(self::BSUID, $message->bsuid);
        $this->assertSame($newwaid, $message->newwaid);
        $this->assertNull($message->to_message());
    }

    /**
     * Identity change messages.
     *
     * @return array
     */
    public static function identity_change_provider(): array {
        return [
            'user id changed' => [['type' => 'user_changed_user_id', 'body' => 'User changed',
                'previous_user_id' => 'US.11111111111111111111'], null],
            'number changed' => [['type' => 'user_changed_number', 'body' => 'User changed',
                'previous_user_id' => 'US.11111111111111111111', 'new_wa_id' => '16505559999'], '16505559999'],
        ];
    }

    /**
     * A system message of another kind, or without a valid previous BSUID, is not an identity change.
     */
    public function test_other_system_messages_are_not_identity_changes(): void {
        $other = inbound_parser::parse(self::text_message(['type' => 'system',
            'system' => ['type' => 'customer_identity_changed', 'previous_user_id' => 'US.111']]));
        $invalid = inbound_parser::parse(self::text_message(['type' => 'system',
            'system' => ['type' => 'user_changed_user_id', 'previous_user_id' => 'not a bsuid']]));

        $this->assertFalse($other->is_identity_change());
        $this->assertFalse($invalid->is_identity_change());
    }

    /**
     * A typed message becomes a neutral inbound message keyed by the chat key of the BSUID.
     */
    public function test_a_text_message_becomes_a_neutral_message(): void {
        $message = inbound_parser::parse(self::text_message())->to_message();

        $this->assertSame('whatsapp', $message->channel);
        $this->assertSame('wa_' . self::BSUID, $message->chatkey);
        $this->assertSame(self::WAMID, $message->externalid);
        $this->assertSame(self::WAMID, $message->messageid);
        $this->assertSame('Hello Jedomi', $message->text);
        $this->assertFalse($message->is_action());
        $this->assertTrue($message->isprivate);
        $this->assertSame('', $message->username);
    }
}
