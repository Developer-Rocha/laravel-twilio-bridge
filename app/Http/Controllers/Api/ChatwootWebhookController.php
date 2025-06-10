<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client as TwilioClient;

class ChatwootWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Chatwoot Webhook Received:', $request->all());

        if ($request->input('event') !== 'message_created' || $request->input('message_type') !== 'outgoing') {
            return response()->json(['status' => 'event_ignored']);
        }

        // Ignore private or note messages.
        if (str_starts_with($request->input('content', ''), 'note:') || $request->private) {
            return response()->json(['status' => 'private_note_ignored']);
        }

        $chatwootConversationId = $request->input('conversation.id');
        $messageContent = $request->input('content');

        $conversation = Conversation::where('chatwoot_conversation_id', $chatwootConversationId)->first();

        if (!$conversation) {
            Log::warning("Received Chatwoot message for an unknown conversation: {$chatwootConversationId}");
            return response()->json(['status' => 'conversation_not_found'], 404);
        }

        try {
            $twilio = new TwilioClient(config('services.twilio.sid'), config('services.twilio.token'));

            $twilio->messages->create(
                $conversation->from_number,
                [
                    'from' => config('services.twilio.whatsapp_number'),
                    'body' => $messageContent,
                ]
            );

            Log::info("Agent's message sent to {$conversation->from_number}");

            $conversation->messages()->create([
                'body' => $messageContent,
                'direction' => 'outbound'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to send message via Twilio: " . $e->getMessage());
            return response()->json(['status' => 'twilio_error'], 500);
        }

        return response()->json(['status' => 'success']);
    }
}
