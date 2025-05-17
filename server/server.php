<?php
require_once 'game.php';

$host = 'localhost';
$port = 46089;

// Start TCP socket server
$server = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$server) die("$errstr ($errno)");

// Store all active connections, start with server
$clients = [$server];

// Game state
$game = new Game();
$lastClientMessage = [];

echo "Server is running on port: $port\n";

// Timing control
$lastOpponentMove = microtime(true);
$lastPingTime = microtime(true);
$pingInterval = 5;           // seconds between pings
$opponentMoveInterval = 1;   // seconds between AI moves

while (true) {
    $changed = $clients;
    $write = $except = null;

    // Wait for socket activity with 10ms timeout
    stream_select($changed, $write, $except, 0, 10000);

    $now = microtime(true);

    // Periodic: move AI opponents
    if ($now - $lastOpponentMove >= $opponentMoveInterval) {
        $game->moveOpponents();
        $lastOpponentMove = $now;

        broadcast($clients, [
            "game" => $game->getGameJSON()
        ]);
    }

    // Periodic: send ping
    if ($now - $lastPingTime >= $pingInterval) {
        foreach ($clients as $client) {
            if ($client !== $server) {
                send_ping($client);
            }
        }
        $lastPingTime = $now;
    }

    // Handle new client connections
    if (in_array($server, $changed)) {
        $client = @stream_socket_accept($server);
        if ($client) {
            $clients[] = $client;
            $ip = stream_socket_get_name($client, true);
            echo "New Client connected from $ip\n";

            // Complete WebSocket handshake
            stream_set_blocking($client, true);
            $headers = fread($client, 1500);
            handshake($client, $headers, $host, $port);
            stream_set_blocking($client, false);

            // Send initial game state
            send($client, [
                "status" => "connected",
                "game" => $game->getGameJSON()
            ]);
        }
        unset($changed[array_search($server, $changed)]);
    }

    // Handle data from existing clients
    foreach ($changed as $client) {
        $ip = stream_socket_get_name($client, true);
        $buffer = stream_get_contents($client);

        // Client disconnected
        if ($buffer === false || $buffer === '') {
            echo "Client Disconnected from $ip\n";
            fclose($client);
            unset($clients[array_search($client, $clients)]);
            unset($lastClientMessage[$ip]);
            continue;
        }

        $unmasked = unmask($buffer);
        if ($unmasked === "") continue;

        $data = json_decode($unmasked, true);
        if (!$data) continue;

        // Ignore pong responses
        if (isset($data['type']) && $data['type'] === 'pong') continue;

        // Update player position if rate-limited
        if (isset($data['position'])) {
            if (!isset($lastClientMessage[$ip]) || ($now - $lastClientMessage[$ip]) >= 0.2) {
                $game->updatePlayerPosition($data['position']);
                $lastClientMessage[$ip] = $now;

                broadcast($clients, [
                    "type" => "position_update",
                    "position" => $data['position']
                ]);
            }
        }

        // Echo non-close messages
        if (!is_closing_frame($buffer)) {
            send($client, $data);
        }
    }
}

fclose($server);


// Utilities

function is_closing_frame($data) {
    return (ord($data[0]) & 0x0f) === 8;
}

function unmask($payload) {
    $length = ord($payload[1]) & 127;
    if ($length === 126) {
        $mask = substr($payload, 4, 4);
        $data = substr($payload, 8);
    } elseif ($length === 127) {
        $mask = substr($payload, 10, 4);
        $data = substr($payload, 14);
    } else {
        $mask = substr($payload, 2, 4);
        $data = substr($payload, 6);
    }
    $unmasked = '';
    for ($i = 0, $len = strlen($data); $i < $len; ++$i) {
        $unmasked .= $data[$i] ^ $mask[$i % 4];
    }
    return $unmasked;
}

function mask($text) {
    $b1 = 0x81; // FIN + text frame
    $len = strlen($text);
    if ($len <= 125) return pack('CC', $b1, $len) . $text;
    if ($len <= 65535) return pack('CCn', $b1, 126, $len) . $text;
    return pack('CCNN', $b1, 127, 0, $len) . $text;
}

function handshake($client, $request, $host, $port) {
    preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $request, $matches);
    if (!isset($matches[1])) return;

    $key = trim($matches[1]);
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

    $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                "Upgrade: websocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: $accept\r\n\r\n";
    fwrite($client, $response);
}

function send($client, $data) {
    if (is_resource($client)) {
        fwrite($client, mask(json_encode($data)));
    }
}

function broadcast($clients, $data) {
    $msg = mask(json_encode($data));
    foreach ($clients as $client) {
        if ($client !== $clients[0] && is_resource($client)) {
            fwrite($client, $msg);
        }
    }
}

function send_ping($client) {
    send($client, ["type" => "ping"]);
}
