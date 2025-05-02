<?php

require_once 'entities/player.php';
require_once 'entities/opponent.php';

class Game {
    private $player;
    private $gameField;
    private array $opponents = [];

    public function __construct() {
        $this->gameField = array(
            array(1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1),
            array(1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1),
            array(1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1),
            array(1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1),
            array(1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1),
            array(1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1),
            array(1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1),
            array(1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1),
            array(1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1),
            array(1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1),
            array(1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0,1),
            array(1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1),
            array(1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1)
        );

        $this->player = new Player(30, 30);

        $this->fillGameField();
        $this->generateOpponents();
    }

    private function randomRoll(float $probability): bool {
        return rand(0, 100)/100 < $probability;
    }

    private function fillGameField(): void {
        for ($i = 0; $i < count($this->gameField); $i++) {
            for ($j = 0; $j < count($this->gameField[$i]); $j++) {
                if($this->gameField[$i][$j] == 0 && $this->randomRoll(0.3) && ($i != 1 || $j != 1) && ($i != 1 || $j != 2) && ($i != 2 || $j != 1)){
                    $this->gameField[$i][$j] = 2;
                }
            }
        }
    }

    private function generateOpponents(): void {
        for ($i = 0; $i < count($this->gameField); $i++) {
            for ($j = 0; $j < count($this->gameField[$i]); $j++) {
                if($this->gameField[$i][$j] == 0 && $this->randomRoll(0.05) && ($i != 1 || $j != 1) && ($i != 1 || $j != 2) && ($i != 2 || $j != 1)){
                    $this->opponents[] = new Opponent($j * 20 + 10, $i * 20 + 10);
                }
            }
        }
    }

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getOpponents(): array {
        return $this->opponents;
    }

    public function getGameField(): array {
        return $this->gameField;
    }

    public function getGameJSON(): string {
        return json_encode([
            "player" => $this->player->getObjectJSON(),
            "opponents" => array_map(function($opponent) { return $opponent->getObjectJSON(); }, $this->opponents),
            "gameField" => $this->gameField
        ]);
    }

    public function getSquareValue(float $x, float $y): int {
        $gridX = floor($x / 20);
        $gridY = floor($y / 20);
        
        if ($gridX >= 0 && $gridX < count($this->gameField[0]) && 
            $gridY >= 0 && $gridY < count($this->gameField)) {
            return $this->gameField[$gridY][$gridX];
        }
        return 1; // Return wall value if coordinates are out of bounds
    }

    public function handlePlayerMovement(array $movement): void {
        $speed = 5; // Adjust this value to control movement speed
        $playerX = $this->player->getX();
        $playerY = $this->player->getY();
        
        // Calculate potential new positions
        $newX = $playerX;
        $newY = $playerY;
        
        if ($movement['up']) {
            $newY -= $speed;
        }
        if ($movement['down']) {
            $newY += $speed;
        }
        if ($movement['left']) {
            $newX -= $speed;
        }
        if ($movement['right']) {
            $newX += $speed;
        }

        $radius = 9;

        // Check if all corners of the player (radius = 10) are walkable
        $topLeft = $this->getSquareValue($newX - $radius, $newY - $radius);
        $topRight = $this->getSquareValue($newX + $radius, $newY - $radius);
        $bottomLeft = $this->getSquareValue($newX - $radius, $newY + $radius);
        $bottomRight = $this->getSquareValue($newX + $radius, $newY + $radius);

        // Update player position only if all corners are walkable (value 0)
        if ($topLeft == 0 && $topRight == 0 && $bottomLeft == 0 && $bottomRight == 0) {
            $this->player->setX($newX);
            $this->player->setY($newY);
        }

        echo $topLeft == 0 && $topRight == 0 && $bottomLeft == 0 && $bottomRight == 0 ? "true" : "false";
    }
}
