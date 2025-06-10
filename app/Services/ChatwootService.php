<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatwootService
{
    protected string $baseUrl;
    protected string $accountId;
    protected string $inboxId;
    protected string $apiToken;

    public function __construct()
    {
        $this->baseUrl = config('services.chatwoot.url');
        $this->accountId = config('services.chatwoot.account_id');
        $this->inboxId = config('services.chatwoot.inbox_id');
        $this->apiToken = config('services.chatwoot.api_token');
    }

    /**
     * Search for a contact in Chatwoot by phone number
     */
    public function searchContact(string $phoneNumber): ?int
    {
        $searchEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts/search";
        $searchResponse = Http::withHeaders(['api_access_token' => $this->apiToken])
            ->get($searchEndpoint, ['q' => $phoneNumber]);

        if ($searchResponse->successful() && count($searchResponse->json('payload')) > 0) {
            $contactId = $searchResponse->json('payload.0.id');
            Log::info("Contact found on Chatwoot with ID: {$contactId}");
            return $contactId;
        }

        return null;
    }

    /**
     * Create a new contact in Chatwoot
     */
    public function createContact(string $phoneNumber): int
    {
        $contactEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/contacts";
        $contactResponse = Http::withHeaders(['api_access_token' => $this->apiToken])
            ->post($contactEndpoint, [
                'inbox_id' => $this->inboxId,
                'name' => 'Cliente WhatsApp ' . substr($phoneNumber, -4),
                'phone_number' => $phoneNumber
            ]);

        if (!$contactResponse->successful()) {
            throw new \Exception('Failed to create contact in Chatwoot: ' . $contactResponse->body());
        }

        $contactId = $contactResponse->json('payload.contact.id');
        Log::info("Contact created in Chatwoot with ID: {$contactId}");
        return $contactId;
    }

    /**
     * Create a new conversation in Chatwoot
     */
    public function createConversation(int $contactId): int
    {
        $conversationEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations";
        $conversationPayload = [
            'inbox_id' => (int) $this->inboxId,
            'contact_id' => $contactId,
            'source_id' => 'api'
        ];

        $conversationResponse = Http::withHeaders(['api_access_token' => $this->apiToken])
            ->post($conversationEndpoint, $conversationPayload);

        if (!$conversationResponse->successful()) {
            throw new \Exception('Failed to create a conversation in Chatwoot: ' . $conversationResponse->body());
        }

        return $conversationResponse->json('id');
    }

    /**
     * Forward a message to an existing Chatwoot conversation
     */
    public function forwardMessage(int $conversationId, string $messageBody): void
    {
        $conversationEndpoint = "{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages";
        $conversationPayload = [
            'content' => $messageBody,
            'message_type' => 'incoming'
        ];

        $response = Http::withHeaders(['api_access_token' => $this->apiToken])
            ->post($conversationEndpoint, $conversationPayload);

        if (!$response->successful()) {
            throw new \Exception('Failed to forward message to Chatwoot: ' . $response->body());
        }
    }
}