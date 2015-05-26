<?php
namespace flamingclient;


use pocketmine\block\Diamond;
use pocketmine\math\Vector3;
use pocketmine\network\protocol\AddMobPacket;
use pocketmine\network\protocol\AddPlayerPacket;
use pocketmine\network\protocol\ChatPacket;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\LoginStatusPacket;
use pocketmine\network\protocol\MessagePacket;
use pocketmine\network\protocol\MovePlayerPacket;
use pocketmine\network\protocol\RemoveBlockPacket;
use pocketmine\network\protocol\StartGamePacket;
use pocketmine\network\protocol\UpdateBlockPacket;
use raklib\protocol\CLIENT_CONNECT_DataPacket;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\Packet;
use raklib\protocol\PING_DataPacket;
use raklib\protocol\SERVER_HANDSHAKE_DataPacket;
use raklib\protocol\UNCONNECTED_PONG;
use serverviewer\client\protocol\CLIENT_HANDSHAKE_DataPacket;
use serverviewer\client\protocol\FullChunkDataPacket;
use serverviewer\client\protocol\LoginPacket;
use serverviewer\client\protocol\OPEN_CONNECTION_REQUEST_2;
use serverviewer\Tickable;

class FlamingClient implements Tickable{
    private $name;
    /** @var  ClientConnection[] */
    private $connections;
    /** @var ClientTask[] */
    private $tasks;
    private $isMessageWaiting = false;
    public function __construct($name = "Steve"){
        $this->name = $name;
        $this->connections = [];
        $this->tasks = [];
    }
    public function addConnection($ip, $port){
        if(!($ip instanceof ClientConnection))
        $ip = new ClientConnection($this, $ip, $port);
        $this->connections[] = $ip;
        return $ip;
    }
    public function addTask(ClientTask $task){
        $task->setClient($this);
        $this->tasks[] = $task;
    }
    public function handlePacket(ClientConnection $connection, Packet $packet){
        //print "S -> C " . get_class($packet) . "\n";
        switch(get_class($packet)){
            case UNCONNECTED_PONG::class:
                $connection->setName($packet->serverName);
                $connection->setIsConnected(true);
                $pk = new OPEN_CONNECTION_REQUEST_1();
                $pk->mtuSize = 1447;
                $connection->sendPacket($pk);
                break;
            case OPEN_CONNECTION_REPLY_1::class:
                $pk = new OPEN_CONNECTION_REQUEST_2();
                $pk->serverPort = $connection->getPort();
                $pk->mtuSize = $packet->mtuSize;
                $pk->clientID = 1;
                $connection->sendPacket($pk);
                break;
            case OPEN_CONNECTION_REPLY_2::class:
                $pk = new CLIENT_CONNECT_DataPacket();
                $pk->clientID = 1;
                $pk->session = 1;
                $connection->sendEncapsulatedPacket($pk);
                break;
            case SERVER_HANDSHAKE_DataPacket::class:
                $pk = new CLIENT_HANDSHAKE_DataPacket();
                $pk->cookie = 1;
                $pk->security = 1;
                $pk->port = $connection->getPort();
                $pk->timestamp = microtime(true);
                $pk->session = 0;
                $pk->session2 = 0;
                $connection->sendEncapsulatedPacket($pk);

                $pk = new LoginPacket();
                $pk->username = $this->name;
                $pk->protocol1 = 20;
                $pk->protocol2 = 20;
                $pk->clientId = 1;
                $pk->loginData = "--flaming-client--";
                $connection->sendEncapsulatedPacket($pk);

                $pk = new PING_DataPacket();
                $pk->pingID = rand(0, 100);
                $connection->sendEncapsulatedPacket($pk);
                break;
            default:
                //print get_class($pk);
                break;
        }
    }
    public function handleDataPacket(ClientConnection $connection, DataPacket $pk){

    }
    /**
     * @return mixed
     */
    public function getName(){
        return $this->name;
    }

    /**
     * @param mixed $name
     */
    public function setName($name){
        $this->name = $name;
    }
    public function tick(){
        foreach ($this->tasks as $task) {
            $task->tick();
        }
        foreach($this->connections as $connection){
            $connection->tick();
        }
    }

}