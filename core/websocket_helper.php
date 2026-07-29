<?php
/**
 * Helper function to send broadcast events to the local WebSocket (Node.js) server.
 * Usage: broadcast_ws_event('content_update', ['title' => 'New Movie!']);
 */

function broadcast_ws_event($event, $data = [], $room = null) {
    $ws_server_url = 'http://localhost:3000/broadcast';
    
    $payload = json_encode([
        'event' => $event,
        'data'  => $data,
        'room'  => $room
    ]);

    $ch = curl_init($ws_server_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Do not block PHP execution for too long

    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}
