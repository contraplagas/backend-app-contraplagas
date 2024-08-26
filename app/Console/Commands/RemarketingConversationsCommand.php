<?php

namespace App\Console\Commands;

use App\Jobs\SendRemarketingMessagesJob;
use App\Models\ConversationHistory;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;


class RemarketingConversationsCommand extends Command
{
    public const CHATWOOT_BASE_URL = 'https://chatwoot.contraplagasc.com';


    public const LABELS = [
        'no_remarketing',
        'citas_confirmadas',

    ];

    public const BASEROW_DAYS_MESSAGE_API = 'https://baserow.contraplagasc.com/api/database/1/rows/table/1';
    public const BASEROW_API_ACCESS_TOKEN = "X2Lcv88vITDXOUInvOvG38clRUVBuWEk";
    public const CHATWOOT_API_ACCESS_TOKEN = "3ypbE6K6B4jbFQMJF2YETPHv";

    public const CONVERSATION_BATCH_SIZE = 5;

    public const NUM_DAYS_TO_REMARKET = 2;

    protected $signature = 'conversations:remarketing';
    protected $description = 'Hacer remarketing de las conversaciones que no han sido respondidas';

    /**
     * @throws ConnectionException
     */
    public function handle(): int
    {
        $pages = $this->getConversationPages();
        $conversations = [];
        for ($i = 1; $i <= $pages; $i++) {
            array_push($conversations, ...$this->filterConversationsByCriteria($i));
        }

        $conversationChunks = array_chunk($conversations, self::CONVERSATION_BATCH_SIZE);
        // Enviar cada lote con una espera de 5 minutos entre cada uno
        foreach ($conversationChunks as $index => $conversationChunk) {
            $delayInMinutes = $index * 5; // Cada lote se retrasará por 5 minutos adicionales
            $this->info("Encolando lote {$index} de " . count($conversationChunk) . " conversaciones con un retraso de {$delayInMinutes} minutos");
            SendRemarketingMessagesJob::dispatch($conversationChunk)->delay(now()->addMinutes($delayInMinutes));
        }


        $this->info('Todas las conversaciones han sido encoladas para procesamiento.');

        return 0;
    }

    private function filterConversationsByCriteria($page): array
    {
        $url = self::CHATWOOT_BASE_URL . "/api/v1/accounts/1/conversations/filter?page={$page}";

        $this->info("Obteniendo conversaciones de la página $page");


        $body = [
            "payload" => [
                [
                    "attribute_key" => "status",
                    "attribute_model" => "standard",
                    "filter_operator" => "equal_to",
                    "values" => [
                        "all"
                    ],
                    "query_operator" => "and",
                    "custom_attribute_type" => ""
                ],
                [
                    "attribute_key" => "labels",
                    "attribute_model" => "standard",
                    "filter_operator" => "not_equal_to",
                    "values" => [
                        "citas_confirmadas",
                        "apagar_bot",
                        "no_remarketing"
                    ],
                    "query_operator" => "and",
                    "custom_attribute_type" => ""
                ],
                [
                    "attribute_key" => "inbox_id",
                    "filter_operator" => "equal_to",
                    "values" => [
                        5
                    ],
                    "custom_attribute_type" => ""
                ]
            ]
        ];


        $response = Http::withoutVerifying()->withHeaders([
            'api_access_token' => self::CHATWOOT_API_ACCESS_TOKEN,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($url, $body);

        if ($response->failed()) {
            $this->error('No se pudo obtener las conversaciones filtradas: ' . $response->body());
            return [];
        }

        $payload = $response->json()['payload'] ?? [];

        $this->info('Conversaciones obtenidas: ' . count($payload));


        // Filtrar las conversaciones que cumplen con los criterios
        $filteredConversations = array_filter($payload, function ($conversation) {
            return $this->shouldSendRemarketingMessage($conversation);
        });

        $this->info('Conversaciones filtradas: ' . count($filteredConversations));

        return $filteredConversations;
    }

    private function shouldSendRemarketingMessage($conversation): bool
    {
        // Verifica si la etiqueta "no_remarketing" está presente
        if (isset($conversation['labels']) && in_array('no_remarketing', $conversation['labels'], true)) {
            $this->info("La conversación {$conversation['id']} tiene la etiqueta no_remarketing. Saltando.");
            return false;
        }

        if (isset($conversation['last_non_activity_message'])) {
            $lastMessage = $conversation['last_non_activity_message'];

            // Verificar si el sender_type es 'Contact' o 'User'
            if (in_array($lastMessage['sender_type'], ['Contact', 'User'])) {
                $messageDate = Carbon::createFromTimestamp($lastMessage['created_at']);

                // Verificar si fue enviado hace self::NUM_DAYS_TO_REMARKET días
                if ($messageDate->lt(Carbon::now()->subDays(self::NUM_DAYS_TO_REMARKET))) {
                    $this->info("La conversación {$conversation['id']} necesita un mensaje de remarketing.");
                    return true;
                }
            }
        }

        $this->info("La conversación {$conversation['id']} no necesita un mensaje de remarketing.");
        return false;
    }

    private function sendMessage($conversationId, $message): void
    {
        $url = self::CHATWOOT_BASE_URL . "/api/v1/accounts/1/conversations/{$conversationId}/messages";
        $response = Http::withHeaders([
            'api_access_token' => self::CHATWOOT_API_ACCESS_TOKEN,
        ])->post($url, [
            'content' => $message,
            'message_type' => 'outgoing',
            'private' => false,
        ]);

        if ($response->failed()) {
            $this->error("Error al enviar mensaje a la conversación {$conversationId}: " . $response->body());
        } else {
            $this->info("Mensaje enviado a la conversación {$conversationId}");
        }
    }

    private function saveConversationHistory($conversation, $message): void
    {
        ConversationHistory::query()->create([
            'conversation_id' => $conversation['id'],
            'sender' => $conversation['meta']['sender']['phone_number'],
            'message' => $message,
            'last_message_sent' => Carbon::now(),
        ]);

        $this->info("La conversación {$conversation['id']} se ha guardado en la base de datos");
    }


    /**
     * Obtiene el número de páginas de conversaciones
     * @return int
     * @throws ConnectionException
     */
    private function getConversationPages(): int
    {
        $url = self::CHATWOOT_BASE_URL . "/api/v1/accounts/1/conversations/meta";

        $response = Http::withHeaders([
            'api_access_token' => self::CHATWOOT_API_ACCESS_TOKEN,
            'Accept' => 'application/json',
        ])->get($url);

        if ($response->successful()) {
            $meta = $response->json()['meta'];
            $totalConversations = $meta['all_count'];

            $pages = (int)ceil($totalConversations / 25);

            $this->info("El número de páginas es: $pages");
            return $pages;
        }

        $this->error('No se pudo obtener el meta de conversaciones');
        return 0;
    }

    private function buildMessage($conversation): string
    {
        return "🪳🪳CUCARACHAS🪳🪳  \n10% de DESCUENTO\n¡No esperes más y toma acción ahora!🏠✨\n\nCon nuestra fumigación garantizada, puedes proteger tu hogar y a tus seres queridos de las cucarachas.  \nEnvía un mensaje ya y obtén un 10% de descuento en tu primera aplicación. Confía en nuestros 12 años de experiencia y únete a más de 309,000 familias satisfechas. ¡Haz de tu hogar un lugar seguro y libre de plagas hoy mismo!\n\nTESTIMONIOS\n\nhttps://www.instagram.com/s/aGlnaGxpZ2h0OjE3ODkwOTYzODg4OTM4OTA4?story_media_id=3404273016128374012&igsh=MWxqcmt3ODdranlxcQ== ";
    }
}
