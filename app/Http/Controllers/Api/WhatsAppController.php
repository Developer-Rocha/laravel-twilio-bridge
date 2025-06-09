<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\TwiML\MessagingResponse;

class WhatsAppController extends Controller
{
    /**
     * Handle incoming WhatsApp messages from Twilio.
     */
    public function handleWebhook(Request $request)
    {
        // Passo 1: O mais importante para depuração!
        // Vamos registrar todos os dados que a Twilio nos enviou.
        // Você poderá ver isso no arquivo de log do Laravel.
        Log::info('WhatsApp Webhook Received:', $request->all());

        // Extrair informações úteis (opcional por enquanto, mas bom para ver)
        $from = $request->input('From'); // ex: 'whatsapp:+5521999998888'
        $body = $request->input('Body'); // ex: 'Olá, quero uma cotação'

        // Passo 2: Responder à Twilio para que ela saiba que recebemos.
        // Se não respondermos, a Twilio considerará que o webhook falhou.
        // Usamos TwiML (Twilio Markup Language) para construir a resposta.
        $twiml = new MessagingResponse();

        // Adiciona uma mensagem à nossa resposta.
        // Esta mensagem será enviada de volta para o usuário no WhatsApp.
        $twiml->message("Mensagem recebida! Em breve um de nossos consultores irá te atender.");

        // Retorna a resposta TwiML como um XML.
        return response($twiml, 200)->header('Content-Type', 'text/xml');
    }
}
