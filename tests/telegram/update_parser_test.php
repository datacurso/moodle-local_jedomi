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

namespace local_jedomi\telegram;

use local_jedomi\messaging\inbound_message;
use local_jedomi\messaging\media_ref;

/**
 * Tests for the conversion of a Telegram update into an inbound message.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\update_parser
 * @covers     \local_jedomi\messaging\inbound_message
 * @covers     \local_jedomi\messaging\media_ref
 */
final class update_parser_test extends \advanced_testcase {
    /**
     * A text message becomes a typed message of its chat, with its text trimmed.
     */
    public function test_text_message(): void {
        $message = update_parser::parse(['message' => ['message_id' => 41, 'text' => "  Hello  \n",
            'chat' => ['id' => 792000001, 'type' => 'private', 'username' => 'ana']]]);

        $this->assertInstanceOf(inbound_message::class, $message);
        $this->assertSame('telegram', $message->channel);
        $this->assertSame('792000001', $message->chatkey);
        $this->assertSame('41', $message->externalid);
        $this->assertSame('41', $message->messageid);
        $this->assertSame('Hello', $message->text);
        $this->assertNull($message->actionpayload);
        $this->assertFalse($message->is_action());
        $this->assertNull($message->media);
        $this->assertSame('', $message->caption);
        $this->assertTrue($message->isprivate);
        $this->assertSame('ana', $message->username);
    }

    /**
     * Chats with a type other than private, or a negative id, are not private.
     */
    public function test_group_chats_are_not_private(): void {
        $group = update_parser::parse(['message' => ['text' => 'x', 'chat' => ['id' => '792000002', 'type' => 'group']]]);
        $negative = update_parser::parse(['message' => ['text' => 'x', 'chat' => ['id' => '-100792000002']]]);
        $untyped = update_parser::parse(['message' => ['text' => 'x', 'chat' => ['id' => '792000002']]]);

        $this->assertFalse($group->isprivate);
        $this->assertFalse($negative->isprivate);
        $this->assertTrue($untyped->isprivate);
        $this->assertSame('', $untyped->messageid);
    }

    /**
     * A message whose chat id is not a number is rejected and logged without the raw value.
     */
    public function test_message_with_an_invalid_chat_id_is_rejected(): void {
        $this->assertNull(update_parser::parse(['message' => ['text' => 'x', 'chat' => ['id' => "1; DROP TABLE"]]]));
        $this->assertNull(update_parser::parse(['message' => ['text' => 'x']]));
    }

    /**
     * Unknown updates are not messages.
     */
    public function test_unknown_update_is_not_a_message(): void {
        $this->assertNull(update_parser::parse(['channel_post' => ['text' => 'x', 'chat' => ['id' => '-1001']]]));
        $this->assertNull(update_parser::parse([]));
    }

    /**
     * A pressed button carries its payload, the id of the press and the message the button belongs to.
     */
    public function test_callback_query(): void {
        $message = update_parser::parse(['callback_query' => ['id' => 'cb-7', 'data' => 'cmd:new',
            'message' => ['message_id' => 9, 'chat' => ['id' => 792000003, 'type' => 'private']]]]);

        $this->assertSame('792000003', $message->chatkey);
        $this->assertSame('cb-7', $message->externalid);
        $this->assertSame('9', $message->messageid);
        $this->assertSame('cmd:new', $message->actionpayload);
        $this->assertTrue($message->is_action());
        $this->assertSame('', $message->text);
    }

    /**
     * A button press without id, data or a valid chat is ignored.
     *
     * @return void
     */
    public function test_incomplete_callback_query_is_ignored(): void {
        $chat = ['message_id' => 9, 'chat' => ['id' => '792000004']];
        $this->assertNull(update_parser::parse(['callback_query' => ['data' => 'cmd:new', 'message' => $chat]]));
        $this->assertNull(update_parser::parse(['callback_query' => ['id' => 'cb', 'message' => $chat]]));
        $this->assertNull(update_parser::parse(['callback_query' => ['id' => 'cb', 'data' => 'cmd:new',
            'message' => ['chat' => ['id' => 'abc']]]]));
    }

    /**
     * A voice message is audio named voice.ogg; an audio file keeps its name and type for the transcription.
     */
    public function test_voice_and_audio(): void {
        $voice = update_parser::parse(['message' => ['chat' => ['id' => '792000005'],
            'voice' => ['file_id' => 'v-1', 'mime_type' => 'audio/ogg']]]);
        $audio = update_parser::parse(['message' => ['chat' => ['id' => '792000005'], 'caption' => ' Listen ',
            'audio' => ['file_id' => 'a-1', 'file_name' => 'talk.mp3', 'mime_type' => 'audio/mpeg']]]);

        $this->assertEquals(new media_ref(media_ref::AUDIO, 'v-1', 'voice.ogg', 'audio/ogg'), $voice->media);
        $this->assertEquals(new media_ref(media_ref::AUDIO, 'a-1', 'talk.mp3', 'audio/mpeg'), $audio->media);
        $this->assertSame('Listen', $audio->caption);
    }

    /**
     * A photo is its largest size, named photo.jpg; a document keeps its name, "document" when it has none.
     */
    public function test_photo_and_document(): void {
        $photo = update_parser::parse(['message' => ['chat' => ['id' => '792000006'],
            'photo' => [['file_id' => 'small'], ['file_id' => 'big']]]]);
        $document = update_parser::parse(['message' => ['chat' => ['id' => '792000006'],
            'document' => ['file_id' => 'd-1', 'file_name' => 'notes.pdf', 'mime_type' => 'application/pdf']]]);
        $unnamed = update_parser::parse(['message' => ['chat' => ['id' => '792000006'],
            'document' => ['file_id' => 'd-2']]]);

        $this->assertEquals(new media_ref(media_ref::IMAGE, 'big', 'photo.jpg', ''), $photo->media);
        $this->assertEquals(new media_ref(media_ref::DOCUMENT, 'd-1', 'notes.pdf', 'application/pdf'), $document->media);
        $this->assertEquals(new media_ref(media_ref::DOCUMENT, 'd-2', 'document', ''), $unnamed->media);
    }

    /**
     * A message without text nor a known attachment has no media and an empty text.
     */
    public function test_sticker_has_no_media(): void {
        $sticker = update_parser::parse(['message' => ['chat' => ['id' => '792000007'], 'sticker' => ['file_id' => 's']]]);

        $this->assertNull($sticker->media);
        $this->assertSame('', $sticker->text);
    }

    /**
     * The chat id is validated as Telegram sends it: an integer, negative for groups.
     */
    public function test_chat_key(): void {
        $this->assertSame('123', update_parser::chat_key('123'));
        $this->assertSame('-1001', update_parser::chat_key('-1001'));
        $this->assertNull(update_parser::chat_key(''));
        $this->assertNull(update_parser::chat_key('12a'));
        $this->assertNull(update_parser::chat_key(str_repeat('9', 21)));
    }
}
