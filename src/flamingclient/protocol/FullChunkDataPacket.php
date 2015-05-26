<?php
namespace flamingclient\protocol;

use pocketmine\utils\Binary;

class FullChunkDataPacket extends \pocketmine\network\protocol\FullChunkDataPacket{
    public function decode(){
        //print bin2hex($this->buffer) . "\n"
    }
}