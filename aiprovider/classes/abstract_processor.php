<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Base processor — reads endpoint/model from local_jedomi config as fallback.
 *
 * @package   aiprovider_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiprovider_jedomi;

use core\http_client;
use core_ai\process_base;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

defined('MOODLE_INTERNAL') || die();

abstract class abstract_processor extends process_base {

    /**
     * Endpoint — provider-specific config > local_jedomi config > default.
     */
    protected function get_endpoint(): UriInterface {
        $base = $this->provider->config['gatewayurl']
            ?? get_config('local_jedomi', 'gateway_url')
            ?? 'http://localhost:8765';
        return new Uri(rtrim($base, '/') . '/v1/chat/completions');
    }

    /**
     * Model — action config > provider default > local_jedomi config > hardcoded default.
     */
    protected function get_model(): string {
        return $this->provider->actionconfig[$this->action::class]['settings']['model']
            ?? $this->provider->config['defaultmodel']
            ?? get_config('local_jedomi', 'model')
            ?? 'jedomi-e4b';
    }

    abstract protected function get_system_instruction(): string;

    #[\Override]
    protected function create_request_object(string $userid): RequestInterface {
        $prompttext  = $this->action->get_configuration('prompttext');
        $usercontent = $this->build_user_content($prompttext);

        $body = [
            'model'       => $this->get_model(),
            'temperature' => 0.2,
            'messages'    => [
                ['role' => 'system', 'content' => $this->get_system_instruction()],
                ['role' => 'user',   'content' => $usercontent],
            ],
        ];

        return new \GuzzleHttp\Psr7\Request(
            method:  'POST',
            uri:     '',
            body:    json_encode($body),
            headers: ['Content-Type' => 'application/json'],
        );
    }

    #[\Override]
    public function process(): array {
        try {
            $request  = $this->create_request_object('0');
            $endpoint = $this->get_endpoint();
            $authreq  = $this->provider->add_authentication_headers($request);
            $finalreq = $authreq->withUri($endpoint);
            $client   = new http_client();
            $response = $client->sendRequest($finalreq);

            $statuscode = $response->getStatusCode();
            if ($statuscode >= 200 && $statuscode < 300) {
                return $this->handle_api_success($response);
            } else {
                return $this->handle_api_error($response);
            }
        } catch (RequestException $e) {
            return ['success' => false, 'errorcode' => 'apierror', 'errormessage' => $e->getMessage()];
        } catch (\Exception $e) {
            return ['success' => false, 'errorcode' => 'exception', 'errormessage' => $e->getMessage()];
        }
    }

    protected function build_user_content(string $prompttext): string|array {
        $contextid = $this->action->get_configuration('contextid') ?? 0;
        $images    = $this->get_context_images((int) $contextid);

        if (empty($images)) {
            return $prompttext;
        }

        $content = [['type' => 'text', 'text' => $prompttext]];
        foreach ($images as $image_b64) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => 'data:image/jpeg;base64,' . $image_b64],
            ];
        }
        return $content;
    }

    protected function get_context_images(int $contextid): array {
        return [];
    }

    protected function handle_api_success(ResponseInterface $response): array {
        $body    = json_decode($response->getBody()->getContents(), true);
        $content = $body['choices'][0]['message']['content'] ?? '';
        $model   = $body['model'] ?? $this->get_model();

        return [
            'success'          => true,
            'generatedcontent' => $content,
            'finishreason'     => $body['choices'][0]['finish_reason'] ?? 'stop',
            'prompttokens'     => $body['usage']['prompt_tokens'] ?? 0,
            'completiontokens' => $body['usage']['completion_tokens'] ?? 0,
            'model'            => $model,
        ];
    }

    protected function handle_api_error(ResponseInterface $response): array {
        $body = json_decode($response->getBody()->getContents(), true);
        return [
            'success'      => false,
            'errorcode'    => 'httperror_' . $response->getStatusCode(),
            'errormessage' => $body['detail'] ?? 'Unknown error from Jedomi Gateway',
        ];
    }
}
