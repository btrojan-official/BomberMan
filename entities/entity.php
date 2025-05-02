<?php
abstract class Entity {
    protected $x;
    protected $y;
    protected $imgPath;

    public function __construct($x = 0, $y = 0, $imgPath = '') {
        $this->x = $x;
        $this->y = $y;
        $this->imgPath = $imgPath;
    }

    public function getX() {
        return $this->x;
    }

    public function getY() {
        return $this->y;
    }

    public function setX($x) {
        $this->x = $x;
    }

    public function setY($y) {
        $this->y = $y;
    }

    public function getImgPath() {
        return $this->imgPath;
    }

    public function setImgPath($imgPath) {
        $this->imgPath = $imgPath;
    }

    public function getObjectJSON() {
        return [
            "x" => $this->x,
            "y" => $this->y,
            "imgPath" => $this->imgPath
        ];
    }
}

