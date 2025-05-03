<?php
require_once 'entity.php';

class Opponent extends Entity {
    private $lastDirection = null;
    private const DIRECTIONS = [
        'up' => ['x' => 0, 'y' => -1],
        'down' => ['x' => 0, 'y' => 1],
        'left' => ['x' => -1, 'y' => 0],
        'right' => ['x' => 1, 'y' => 0]
    ];

    public function moveOpponent(array $gameField, int $fieldSize = 20): void {
        $currentX = floor($this->getX() / $fieldSize);
        $currentY = floor($this->getY() / $fieldSize);
        
        // Calculate probabilities for each direction
        $probabilities = [];
        foreach (self::DIRECTIONS as $direction => $offset) {
            $newX = $currentX + $offset['x'];
            $newY = $currentY + $offset['y'];
            
            // Check if the new position is within bounds and walkable
            if ($newX >= 0 && $newX < count($gameField[0]) && 
                $newY >= 0 && $newY < count($gameField) && 
                $gameField[$newY][$newX] == 0) {
                
                // Higher probability for the last used direction
                $probabilities[$direction] = ($direction === $this->lastDirection) ? 0.52 : 0.16;
            }
        }
        
        // If no valid moves, stay in place
        if (empty($probabilities)) {
            return;
        }
        
        // Normalize probabilities
        $total = array_sum($probabilities);
        foreach ($probabilities as &$prob) {
            $prob /= $total;
        }
        
        // Choose direction based on probabilities
        $rand = mt_rand() / mt_getrandmax();
        $cumulative = 0;
        $chosenDirection = null;
        
        foreach ($probabilities as $direction => $probability) {
            $cumulative += $probability;
            if ($rand <= $cumulative) {
                $chosenDirection = $direction;
                break;
            }
        }
        
        if ($chosenDirection) {
            $this->lastDirection = $chosenDirection;
            $offset = self::DIRECTIONS[$chosenDirection];
            
            // Move the opponent
            $this->setX($this->getX() + ($offset['x'] * $fieldSize));
            $this->setY($this->getY() + ($offset['y'] * $fieldSize));
        }
    }
}
