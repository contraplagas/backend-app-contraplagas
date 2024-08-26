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

class SendRemarketingMessagesJob implements ShouldQueue

{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHATWOOT_BASE_URL = 'https://chatwoot.contraplagasc.com';
    public const CHATWOOT_API_ACCESS_TOKEN = "3ypbE6K6B4jbFQMJF2YETPHv";

    protected array $conversations;

    /**
     * Create a new job instance.
     *
     * @param array $conversations
     */
    public function __construct(array $conversations)
    {
        $this->conversations = $conversations;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws ConnectionException
     */
    public function handle()
    {
        foreach ($this->conversations as $conversation) {

            //si hace menos de 1 hora que se envió el mensaje, no se envía el mensaje de remarketing
            $last_message_sent = ConversationHistory::query()->where('conversation_id', $conversation['id'])->orderBy('last_message_sent', 'desc')->first();


            //if si el ultimo ciclo(remarketing_cycle) es 3, no se envía el mensaje de remarketing
            if ($last_message_sent && $last_message_sent->remarketing_cycle == 3) {
                Log::info("No se envía mensaje de remarketing a la conversación {$conversation['id']} porque ya se envió el ciclo 3.");
                continue;
            }


            if ($last_message_sent && $last_message_sent->last_message_sent->diffInHours(Carbon::now()) < 1) {
                Log::info("No se envía mensaje de remarketing a la conversación {$conversation['id']} porque ya se envió un mensaje hace menos de 1 hora.");
                continue;
            }


            if (isset($conversation['last_non_activity_message'])) {
                $lastMessage = $conversation['last_non_activity_message'];

                // Verificar si el sender_type es 'Contact' o 'User'
                if (in_array($lastMessage['sender_type'], ['Contact', 'User'])) {
                    $messageDate = Carbon::createFromTimestamp($lastMessage['created_at']);

                    // Verificar si fue enviado hace self::NUM_DAYS_TO_REMARKET días
                    if ($messageDate->lt(Carbon::now()->subDays(RemarketingConversationsCommand::NUM_DAYS_TO_REMARKET))) {
                        Log::info("La conversación {$conversation['id']} necesita un mensaje de remarketing.");
                    } else {
                        Log::info("La conversación {$conversation['id']} no necesita un mensaje de remarketing.");
                        continue;
                    }
                }
            }
            // Obtener el ciclo actual de remarketing
            $currentCycle = $last_message_sent->remarketing_cycle ?? 1;

            // Construir mensaje de remarketing según el ciclo
            $message = $this->buildMessage(
                $conversation,
                $currentCycle
            );

            // Enviar mensaje
            $this->sendMessage($conversation['id'], $message);

            // Guardar historial de conversación y actualizar ciclo
            $this->saveConversationHistory($conversation, $message, $currentCycle);


        }
    }

    /**
     * Enviar mensaje de remarketing a la conversación.
     *
     * @param int $conversationId
     * @param string $message
     * @throws ConnectionException
     */
    private function sendMessage(int $conversationId, string $message): void
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
            Log::error("Error al enviar mensaje a la conversación {$conversationId}: " . $response->body());
        } else {
            Log::info("Mensaje enviado a la conversación {$conversationId}");
        }
    }

    /**
     * Guardar el historial de la conversación en la base de datos.
     *
     * @param array $conversation
     * @param string $message
     * @param int $currentCycle
     */
    private function saveConversationHistory(array $conversation, string $message, int $currentCycle): void
    {
        // Aumentar el ciclo para la próxima vez
        $newCycle = $currentCycle < 3 ? $currentCycle + 1 : 3;

        ConversationHistory::query()->create([
            'conversation_id' => $conversation['id'],
            'sender' => $conversation['meta']['sender']['phone_number'] ?? '',
            'message' => $message,
            'remarketing_cycle' => $newCycle,
            'last_message_sent' => Carbon::now(),
        ]);

        Log::info("La conversación {$conversation['id']} se ha guardado en la base de datos.");
    }

    /**
     * Construir el mensaje de remarketing.
     *
     * @param array $conversation
     * @param int $cycle
     * @return string
     */
    private function buildMessage(array $conversation, int $cycle): string
    {
        return "🪳🪳*CUCARACHAS*🪳🪳  \n**10% de DESCUENTO**\n*¡No esperes más y toma acción ahora!*🏠✨\n\nCon nuestra **fumigación garantizada**, puedes proteger tu hogar y a tus seres queridos de las cucarachas.  \nEnvía un mensaje ya y obtén un **10% de descuento** en tu primera aplicación. Confía en nuestros **12 años de experiencia** y únete a más de 309,000 familias satisfechas. **¡Haz de tu hogar un lugar seguro y libre de plagas hoy mismo!**\n\n***TESTIMONIOS***\n\nhttps://www.instagram.com/s/aGlnaGxpZ2h0OjE3ODkwOTYzODg4OTM4OTA4?story*media*id=3404273016128374012\u0026igsh=MWxqcmt3ODdranlxcQ==";
    }

}
