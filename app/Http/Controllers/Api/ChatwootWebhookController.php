<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Twilio\Rest\Client as TwilioClient;

class ChatwootWebhookController extends Controller
{
    protected $twilio;
    protected $accountSid;
    protected $twilioNumber;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->accountSid = config('services.twilio.sid');
        $this->twilioNumber = config('services.twilio.whatsapp_number');
        $twilioToken = config('services.twilio.token');

        $this->twilio = new TwilioClient($this->accountSid, $twilioToken);
    }

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

        $messageContent = $request->input('content');
        $attachments = $request->input('attachments', []);

        // Ignore empty message.
        if (empty($messageContent) && count($attachments) === 0) {
            return response()->json(['status' => 'empty_message_ignored']);
        }

        $chatwootConversationId = $request->input('conversation.id');
        $conversation = Conversation::where('chatwoot_conversation_id', $chatwootConversationId)->first();

        if (!$conversation) {
            Log::warning("Received Chatwoot message for an unknown conversation: {$chatwootConversationId}");
            return response()->json(['status' => 'conversation_not_found'], 404);
        }

        try {
            $mediaUrl = null;

            if (count($attachments) > 0) {
                $attachmentUrl = $attachments[0]['data_url'];

                $fileContent = Http::withHeaders(['api_access_token' => config('services.chatwoot.api_token')])
                    ->get($attachmentUrl)
                    ->body();

                $filename = 'from_chatwoot/' . uniqid() . '_' . basename($attachmentUrl);
                Storage::disk('public')->put($filename, $fileContent);

                $mediaUrl = Storage::disk('public')->url($filename);
                Log::info("Chatwoot file saved publicly for sending: {$mediaUrl}");
            }

            $messageData = [
                'from' => $this->twilioNumber,
                'body' => $messageContent,
            ];

            if ($mediaUrl) {
                $messageData['mediaUrl'] = [$mediaUrl];
            }

            $this->twilio->messages->create($conversation->from_number, $messageData);

            Log::info("Agent's message sent to {$conversation->from_number}");

            $conversation->messages()->create([
                'body' => $messageContent ?? '[Mídia enviada pelo agente]',
                'direction' => 'outbound'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to send message via Twilio: " . $e->getMessage());
            return response()->json(['status' => 'twilio_error'], 500);
        }

        return response()->json(['status' => 'success']);
    }
}
