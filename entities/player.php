<?php
require_once 'entity.php';

class Player extends Entity {
    public function move(string $direction, float $value): void {
        switch($direction) {
            case "up":
                $this->y -= $value;
                break;
            case "down": 
                $this->y += $value;
                break;
            case "left":
                $this->x -= $value;
                break;
            case "right":
                $this->x += $value;
                break;
        }
    }
}
