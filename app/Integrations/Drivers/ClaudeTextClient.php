<?php

namespace App\Integrations\Drivers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Integrations\IntegrationFailed;
use App\Integrations\IntegrationNotConfigured;

/**
 * The only place that calls the Claude API, through the official Anthropic SDK. It sends
 * one system prompt and one user message and returns the text. Callers must mask the text
 * first; this class never logs what it sends or receives.
 */
class ClaudeTextClient
{
    public const NAME = 'AI drafting and summaries';

    public function send(string $system, string $prompt): string
    {
        $config = (array) config('integrations.ai.anthropic');
        $key = (string) ($config['api_key'] ?? '');
        if ($key === '') {
            throw new IntegrationNotConfigured(self::NAME);
        }

        $client = new Client(apiKey: $key, requestOptions: ['timeout' => (float) ($config['timeout'] ?? 120)]);
        try {
            $message = $client->beta->messages->create(
                maxTokens: (int) ($config['max_tokens'] ?? 16000),
                messages: [['role' => 'user', 'content' => $prompt]],
                model: (string) ($config['model'] ?? 'claude-opus-5-5'),
                system: $system,
                outputConfig: ['effort' => (string) ($config['effort'] ?? 'medium')],
                // If the model declines for policy reasons, the server retries on its default fallback.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException $e) {
            throw new IntegrationFailed(self::NAME, 'the API key was not accepted.', $e);
        } catch (RateLimitException $e) {
            throw new IntegrationFailed(self::NAME, 'too many requests just now. Try again in a minute.', $e);
        } catch (APIStatusException $e) {
            throw new IntegrationFailed(self::NAME, 'the service returned an error.', $e);
        } catch (APIConnectionException $e) {
            throw new IntegrationFailed(self::NAME, 'the service could not be reached.', $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new IntegrationFailed(self::NAME, 'the request was declined.');
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        if (trim($text) === '') {
            throw new IntegrationFailed(self::NAME, 'no text came back.');
        }

        return $text;
    }
}
