<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\TwiML\MessagingResponse;

class WhatsAppController extends Controller
{
    /**
     * Handle incoming WhatsApp messages from Twilio.
     */
    public function handleWebhook(Request $request)
    {
        $fromNumber = $request->input('From');
        $messageBody = trim($request->input('Body'));

        Log::info("Webhook received from {$fromNumber}: {$messageBody}");

        $conversation = Conversation::firstOrCreate(
            ['from_number' => $fromNumber],
            ['status' => 'awaiting_menu_response']
        );

        $conversation->messages()->create([
            'body' => $messageBody,
            'direction' => 'inbound',
            'twilio_sid' => $request->input('MessageSid')
        ]);

        if ($conversation->wasRecentlyCreated) {
            return $this->sendMainMenu();
        }

        switch ($conversation->status) {
            case 'awaiting_menu_response':
                return $this->handleMenuResponse($messageBody, $conversation);
            case 'with_agent':
                $this->forwardMessageToChatwoot($messageBody, $conversation);
                return response(new MessagingResponse(), 200)->header('Content-Type', 'text/xml');
            default:
                Log::error("Unknown chat status: {$conversation->status}");
                return $this->sendMainMenu();
        }
    }

    /**
     * Sends the main menu of options to the user.
     */
    private function sendMainMenu(): Response
    {
        $twiml = new MessagingResponse();
        $twiml->message("Olá! Bem-vindo(a) à Private. Por favor, escolha uma opção:\n\n*1.* Consultar status do meu seguro.\n*2.* Falar com um atendente.");
        return response($twiml, 200)->header('Content-Type', 'text/xml');
    }

    /**
     * Processes the user's choice in the menu.
     */
    private function handleMenuResponse(string $choice, Conversation $conversation): Response
    {
        $twiml = new MessagingResponse();

        switch ($choice) {
            case '1':
                // Option 1 logic: Returns dummy message.
                $twiml->message("O status do seu seguro é: ATIVO. Validade até 31/12/2025.");
                // Keeps the status so that he can choose another option or return to the menu.
                $conversation->status = 'awaiting_menu_response';
                $conversation->save();
                break;
            case '2':
                // Option 2 logic: Starts the transfer to Chatwoot.
                return $this->initiateChatwootHandover($conversation);
            default:
                $twiml->message("Opção inválida. Por favor, responda com *1* ou *2*.");
                break;
        }

        return response($twiml, 200)->header('Content-Type', 'text/xml');
    }

    /**
     * Starts the Chatwoot conversation and notifies the user.
     */
    private function initiateChatwootHandover(Conversation $conversation): Response
    {
        $twiml = new MessagingResponse();

        // @todo: Idealmente, mover esta lógica para uma classe de serviço (e.g., ChatwootService)
        try {
            $chatwootContactId = null;

            // Step 1: SEARCH if the contact already exists in chatwoot.
            $searchEndpoint = config('services.chatwoot.url') . '/api/v1/accounts/' . config('services.chatwoot.account_id') . '/contacts/search';
            $searchResponse = Http::withHeaders(['api_access_token' => config('services.chatwoot.api_token')])
                ->get($searchEndpoint, ['q' => $conversation->from_number]);

            if ($searchResponse->successful() && count($searchResponse->json('payload')) > 0) {
                $chatwootContactId = $searchResponse->json('payload.0.id');
                Log::info("Contact found on Chatwoot with ID: {$chatwootContactId}");
            } else {
                Log::info("Contact not found, creating a new...");
                $contactEndpoint = config('services.chatwoot.url') . '/api/v1/accounts/' . config('services.chatwoot.account_id') . '/contacts';
                $contactResponse = Http::withHeaders(['api_access_token' => config('services.chatwoot.api_token')])
                    ->post($contactEndpoint, [
                        'inbox_id' => config('services.chatwoot.inbox_id'),
                        'name' => 'Cliente WhatsApp ' . substr($conversation->from_number, -4),
                        'phone_number' => $conversation->from_number
                    ]);

                if (!$contactResponse->successful()) {
                    throw new \Exception('Failed to create contact in Chatwoot: ' . $contactResponse->body());
                }
                $chatwootContactId = $contactResponse->json('payload.contact.id');
                Log::info("Contact created in Chatwoot with ID: {$chatwootContactId}");
            }

            $conversation->chatwoot_contact_id = $chatwootContactId;

            $conversationEndpoint = config('services.chatwoot.url') . '/api/v1/accounts/' . config('services.chatwoot.account_id') . '/conversations';
            $conversationPayload = [
                'inbox_id' => (int) config('services.chatwoot.inbox_id'),
                'contact_id' => $chatwootContactId,
                'source_id' => 'api'
            ];

            $conversationResponse = Http::withHeaders(['api_access_token' => config('services.chatwoot.api_token')])
                ->post($conversationEndpoint, $conversationPayload);

            if (!$conversationResponse->successful()) {
                throw new \Exception('Failed to create a conversation in Chatwoot: ' . $conversationResponse->body());
            }

            $chatwootConversationId = $conversationResponse->json('id');

            // Update conversation in our DB.
            $conversation->status = 'with_agent';
            $conversation->chatwoot_conversation_id = $chatwootConversationId;
            $conversation->save();

            Log::info("Conversation {$conversation->id} transferred to Chatwoot with ID {$chatwootConversationId}");
            $twiml->message("Ok, um momento enquanto eu te transfiro para um de nossos especialistas.");

        } catch (\Exception $e) {
            Log::error("Error in integration with Chatwoot: " . $e->getMessage());
            $twiml->message("Desculpe, estamos com um problema em nosso sistema de atendimento. Por favor, tente novamente em alguns instantes.");
        }

        return response($twiml, 200)->header('Content-Type', 'text/xml');
    }

    /**
     * Forwards subsequent messages to an existing Chatwoot conversation.
     */
    private function forwardMessageToChatwoot(string $messageBody, Conversation $conversation)
    {
        Log::info("Forwarding a message to the conversation {$conversation->chatwoot_conversation_id} in Chatwoot.");

        // @todo: Mover para ChatwootService
        try {
            $conversationEndpoint = config('services.chatwoot.url') . '/api/v1/accounts/' . config('services.chatwoot.account_id') . '/conversations/' . $conversation->chatwoot_conversation_id . '/messages';
            $conversationPayload = [
                'content' => $messageBody,
                'message_type' => 'incoming'
            ];
            $response = Http::withHeaders(['api_access_token' => config('services.chatwoot.api_token')])
                ->post($conversationEndpoint, $conversationPayload);

            if (!$response->successful()) {
                throw new \Exception('Failed to forward message to Chatwoot: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error("Error forwarding a message to Chatwoot: " . $e->getMessage());
        }
    }
}
