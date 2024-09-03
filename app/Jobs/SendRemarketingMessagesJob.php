<?php

namespace App\Jobs;

use App\Console\Commands\RemarketingConversationsCommand;
use App\Models\ConversationHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SendRemarketingMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHATWOOT_BASE_URL = 'https://chatwoot.contraplagasc.com';
    public const CHATWOOT_API_ACCESS_TOKEN = '3ypbE6K6B4jbFQMJF2YETPHv';

    public function __construct(
        public array $conversations,
        public array $remarketing_messages
    )
    {
    }

    public function handle(): void
    {
        foreach ($this->conversations as $conversation) {
            try {
                dump($conversation);
                if ($this->shouldSkipConversation($conversation)) {
                    Log::info("La conversación {$conversation['id']} se ha omitido.");
                    continue;
                }

                $currentCycle = $this->getCurrentRemarketingCycle($conversation);
                $messages = $this->buildMessage($conversation, $currentCycle);

                if (!empty($messages)) {
                    $this->sendMessage($conversation['id'], $messages);
                    $this->saveConversationHistory($conversation, $messages, $currentCycle);
                }

            } catch (Throwable $e) {
                if (isset($conversation['id'])) {
                    Log::error("Error al procesar la conversación {$conversation['id']}: " . $e->getMessage());
                }
            }
        }
    }

    private function shouldSkipConversation(array $conversation): bool
    {
        $lastMessageSent = ConversationHistory::query()
            ->where('conversation_id', $conversation['id'])
            ->orderBy('last_message_sent', 'desc')
            ->first();

        if ($lastMessageSent && $lastMessageSent->remarketing_cycle == 3) {
            Log::info("No se envía mensaje de remarketing a la conversación {$conversation['id']} porque ya se envió el ciclo 3.");
            return true;
        }

        if (isset($conversation['last_non_activity_message'])) {
            $lastMessage = $conversation['last_non_activity_message'];

            if (in_array($lastMessage['sender_type'], ['Contact', 'User'])) {
                $messageDate = Carbon::createFromTimestamp($lastMessage['created_at']);
                if ($messageDate->gt(Carbon::now()->subDays(RemarketingConversationsCommand::NUM_DAYS_TO_REMARKET))) {
                    Log::info("La conversación {$conversation['id']} no necesita un mensaje de remarketing.");
                    return true;
                }
            }
        }

        return false;
    }

    private function getCurrentRemarketingCycle(array $conversation): int
    {
        $lastMessageSent = ConversationHistory::query()
            ->where('conversation_id', $conversation['id'])
            ->orderBy('last_message_sent', 'desc')
            ->first();

        return $lastMessageSent->remarketing_cycle ?? 1;
    }

    /**
     * @throws ConnectionException
     */
    private function sendMessage(int $conversationId, array $messages): void
    {
        $url = self::CHATWOOT_BASE_URL . "/api/v1/accounts/1/conversations/{$conversationId}/messages";

        foreach ($messages as $message) {
            if ($message['type'] === 'attachment') {
                //Sen Text Message if exists
                if (Str::of($message['content'])->isNotEmpty()) {
                    $response = Http::withHeaders([
                        'api_access_token' => self::CHATWOOT_API_ACCESS_TOKEN,
                    ])->post($url, [
                        'content' => $message['content'],
                        'message_type' => $message['message_type'],
                        'private' => $message['private'],
                    ]);

                    if ($response->failed()) {
                        Log::error("Error al enviar mensaje a la conversación {$conversationId}: " . $response->body());
                    } else {
                        Log::info("Mensaje enviado a la conversación {$conversationId}");
                    }
                }
                foreach ($message['attachments'] as $attachment) {
                    try {
                        $response = Http::withHeaders([
                            'api_access_token' => '3ypbE6K6B4jbFQMJF2YETPHv'
                        ])
                            ->asMultipart()
                            ->attach('attachments[]', file_get_contents($attachment['url']), $attachment['name'])
                            ->post($url);

                        if ($response->failed()) {
                            Log::error("Error al enviar mensaje a la conversación {$conversationId}: " . $response->body());
                        } else {
                            Log::info("Mensaje enviado a la conversación {$conversationId}");
                        }

                    } catch (\Exception $e) {
                        Log::error("Error al enviar mensaje a la conversación {$conversationId}: " . $e->getMessage());
                    }

                }
            } elseif ($message['type'] === 'text') {
                $response = Http::withHeaders([
                    'api_access_token' => self::CHATWOOT_API_ACCESS_TOKEN,
                ])->post($url, [
                    'content' => $message['content'],
                    'message_type' => $message['message_type'],
                    'private' => $message['private'],
                ]);

                if ($response->failed()) {
                    Log::error("Error al enviar mensaje a la conversación {$conversationId}: " . $response->body());
                } else {
                    Log::info("Mensaje enviado a la conversación {$conversationId}");
                }
            }

        }
    }

    private function saveConversationHistory(array $conversation, array $messages, int $currentCycle): void
    {

        ConversationHistory::query()->create([
            'conversation_id' => $conversation['id'],
            'sender' => $conversation['meta']['sender']['phone_number'] ?? '',
            'message' => $messages,
            'remarketing_cycle' => $currentCycle,
            'last_message_sent' => Carbon::now(),
        ]);

        Log::info("La conversación {$conversation['id']} se ha guardado en la base de datos.");
    }

    private function buildMessage(array $conversation, int $cycle): array
    {
        $remarketingMessage = collect($this->remarketing_messages)->firstWhere('Ciclo', $cycle);

        if (!$remarketingMessage) {
            return [];
        }

        $messages = collect();


        if (!empty($remarketingMessage['Attachments'])) {
            $messages->push([
                'type' => 'attachment',
                'content' => $remarketingMessage['Mensaje'] ?? '',
                'attachments' => $remarketingMessage['Attachments'],
                'message_type' => 'outgoing',
                'private' => false,
            ]);
        } else if (Str::of($remarketingMessage['Mensaje'])->isNotEmpty()) {
            $messages->push([
                'type' => 'text',
                'content' => $remarketingMessage['Mensaje'],
                'message_type' => 'outgoing',
                'private' => false,
            ]);
        }

        return $messages->toArray();
    }

}
