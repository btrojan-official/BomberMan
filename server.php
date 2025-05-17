<?php
require_once 'game.php';

$host = 'localhost'; 
$port = 46089;
$transport = 'http';
$server = stream_socket_server("tcp://localhost:46089", $errno, $errstr);
if (!$server) {
    die("$errstr ($errno)");
}
$clients = array($server); 
$write  = NULL;
$except = NULL;

$game = new Game();
$lastClientMessage = [];

echo "Server is running on port: $port\n";

$lastOpponentMove = microtime(true);
$lastPingTime = microtime(true);
$pingInterval = 5;
$opponentMoveInterval = 1; 

while (true) {
    $changed = $clients;
    stream_select($changed, $write, $except, 0, 10000); // Add 10ms delay to prevent CPU overuse
 
    // Check if it's time to move opponents
    $currentTime = microtime(true);

    // Send ping frames every $pingInterval seconds
    if ($currentTime - $lastPingTime >= $pingInterval) {
        foreach ($clients as $client) {
            if ($client !== $server) { // skip the server socket
                send_ping($client);
            }
        }
        $lastPingTime = $currentTime;
    }

    if ($currentTime - $lastOpponentMove >= $opponentMoveInterval) {
        $game->moveOpponents();
        $lastOpponentMove = $currentTime;
        
        // Send game state to all clients after opponent movement
        send_message($clients, mask(json_encode([
            "game" => $game->getGameJSON()
        ])));
    }

    if (in_array($server, $changed)) {
        $client = @stream_socket_accept($server);
        if (!$client) {
            continue;
        }
        $clients[] = $client;
        $ip = stream_socket_get_name($client, true);
        echo "New Client connected from $ip\n";

        stream_set_blocking($client, true);
        $headers = fread($client, 1500);
        handshake($client, $headers, $host, $port);
        stream_set_blocking($client, false);

        $data=[
            "status" => "connected",
            "game" => $game->getGameJSON()
        ];

        send_message($clients, mask(json_encode($data))); //połączenie -> aktualne dane

        $found_socket = array_search($server, $changed);
        unset($changed[$found_socket]);
    }

    foreach ($changed as $changed_socket) {
        $ip = stream_socket_get_name($changed_socket, true);
        $buffer = stream_get_contents($changed_socket);
    
        if ($buffer === false || $buffer === '') {
            echo "Client Disconnected from $ip\n";
            @fclose($changed_socket);
            $found_socket = array_search($changed_socket, $clients);
            unset($clients[$found_socket]);
            unset($lastClientMessage[$ip]);
            continue;
        }
    
        $unmasked = unmask($buffer);
        if ($unmasked !== "") {
            $data = json_decode($unmasked, true);
    
            // Handle pong messages: ignore them
            if (isset($data['type']) && $data['type'] === 'pong') {
                continue;
            }
    
            if (isset($data['position'])) {
                if (!isset($lastClientMessage[$ip]) || ($currentTime - $lastClientMessage[$ip]) >= 0.2) {
                    $game->updatePlayerPosition($data['position']);
                    $lastClientMessage[$ip] = $currentTime;
                    
                    // Send position update back to all clients
                    send_message($clients, mask(json_encode([
                        "type" => "position_update",
                        "position" => $data['position']
                    ])));
                }
            }
    
            if (!is_closing_frame($buffer)) {
                $response = mask($unmasked);
                send_message($clients, $response);
            }
        }
    }
    
}
fclose($server);


function is_closing_frame($text) {
    $opcode = @ord($text[0]) & 0x0f;
    return $opcode == 8;
}

function unmask($text)
{
    $length = @ord($text[1]) & 127;
    if ($length == 126) {
        $masks = substr($text, 4, 4);
        $data = substr($text, 8);
    } elseif ($length == 127) {
        $masks = substr($text, 10, 4);
        $data = substr($text, 14);
    } else {
        $masks = substr($text, 2, 4);
        $data = substr($text, 6);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return $text;
}

function mask($text)
{
    $b1 = 0x80 | (0x1 & 0x0f);
    $length = strlen($text);
    if ($length <= 125)
        $header = pack('CC', $b1, $length);
    elseif ($length > 125 && $length < 65536)
        $header = pack('CCn', $b1, 126, $length);
    elseif ($length >= 65536)
        $header = pack('CCNN', $b1, 127, $length);
    return $header . $text;
}

function handshake($client, $rcvd, $host, $port)
{
    $headers = array();
    $lines = preg_split("/\r\n/", $rcvd);
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $headers[$matches[1]] = $matches[2];
        }
    }
    if (!isset($headers['Sec-WebSocket-Key'])) {
        return false;
    }
    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    
    $upgrade  = "HTTP/1.1 101 Switching Protocols\r\n" .
                "Upgrade: websocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: $secAccept\r\n\r\n";
    fwrite($client, $upgrade);
}


function send_message($clients, $msg)
{
    foreach ($clients as $changed_socket) {
        if (is_resource($changed_socket) && $changed_socket !== $clients[0]) { // Skip server socket
            @fwrite($changed_socket, $msg);
        }
    }
}

function send_ping($client) {
    $ping_msg = json_encode(["type" => "ping"]);
    @fwrite($client, mask($ping_msg));
}
