# Laravel Twilio Bridge

A Laravel-based bridge to integrate WhatsApp (via Twilio) with Chatwoot, enabling seamless two-way communication between WhatsApp users and Chatwoot agents. This project is designed for customer support scenarios where WhatsApp users interact with automated menus or are transferred to human agents using Chatwoot.

## Features

- **WhatsApp Webhook Integration:** Receives and processes incoming WhatsApp messages via Twilio.
- **Automated Menu:** Presents users with a menu (e.g., check insurance status, talk to an agent) and handles their responses.
- **Chatwoot Handover:** Transfers conversations to Chatwoot agents when requested by the user.
- **Two-way Messaging:** Forwards messages and media between WhatsApp users and Chatwoot agents.
- **Attachment Support:** Handles media files sent from WhatsApp to Chatwoot and vice versa.
- **Conversation Tracking:** Stores conversation and message history in the database.

## How It Works

1. **User sends a WhatsApp message** to your Twilio number.
2. **Twilio forwards the message** to `/api/whatsapp/webhook`.
3. The system:
   - Greets the user and presents a menu (e.g., check insurance status, talk to an agent).
   - Handles menu responses. If the user requests an agent, a Chatwoot conversation is created or continued.
   - Forwards messages and attachments to Chatwoot if the conversation is with an agent.
4. **Chatwoot agents reply** in Chatwoot. Outgoing messages are sent to `/api/chatwoot/webhook` and relayed to the user's WhatsApp via Twilio.

## API Endpoints

- `POST /api/whatsapp/webhook` — Receives WhatsApp messages from Twilio.
- `POST /api/chatwoot/webhook` — Receives outgoing messages from Chatwoot to be sent to WhatsApp users.

## Main Components

- **Controllers:**
  - `WhatsAppController`: Handles WhatsApp webhook, menu logic, and forwards messages to Chatwoot.
  - `ChatwootWebhookController`: Handles Chatwoot webhook and sends agent replies to WhatsApp via Twilio.
- **Services:**
  - `ChatwootService`: Integrates with Chatwoot API for contact and conversation management, and message forwarding.
- **Models:**
  - `Conversation`: Tracks WhatsApp user sessions and Chatwoot linkage.
  - `Message`: Stores individual message history.

## Setup

1. **Clone the repository:**
   ```bash
   git clone <repo-url>
   cd laravel-twilio-bridge
   ```
2. **Install dependencies:**
   ```bash
   composer install
   ```
3. **Copy and configure environment:**
   ```bash
   cp .env.example .env
   # Edit .env with your Twilio and Chatwoot credentials
   ```
4. **Generate application key:**
   ```bash
   php artisan key:generate
   ```
5. **Run migrations:**
   ```bash
   php artisan migrate
   ```
6. **Set up webhooks:**
   - Configure Twilio to send WhatsApp messages to `/api/whatsapp/webhook`.
   - Configure Chatwoot to send outgoing messages to `/api/chatwoot/webhook`.

## Environment Variables

Set the following variables in your `.env` file:

```
# Twilio
TWILIO_ACCOUNT_SID=...
TWILIO_AUTH_TOKEN=...
TWILIO_WHATSAPP_NUMBER=whatsapp:+...

# Chatwoot
CHATWOOT_URL=https://your-chatwoot-instance.com
CHATWOOT_API_TOKEN=...
CHATWOOT_ACCOUNT_ID=...
CHATWOOT_INBOX_ID=...
```

## License

This project is open-sourced under the MIT license.
