<?php
namespace flamingclient;

use flamingclient\protocol\OPEN_CONNECTION_REQUEST_2;
use pocketmine\network\protocol\LoginPacket;
use raklib\protocol\ACK;
use raklib\protocol\CLIENT_CONNECT_DataPacket;
use raklib\protocol\CLIENT_HANDSHAKE_DataPacket;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DataPacket;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\Packet;
use raklib\protocol\PING_DataPacket;
use raklib\protocol\PONG_DataPacket;
use raklib\protocol\SERVER_HANDSHAKE_DataPacket;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PONG;
use raklib\server\UDPServerSocket;
use pocketmine\network\protocol\DataPacket as PMDataPacket;

class Client extends UDPServerSocket implements Tickable{
    const START_PORT = 49666;
    private static $instanceId = 0;

    private $isRunning;

    private $name;
    private $serverIp;
    private $serverPort;

    private $isConnected;

    private $sequenceNumber;
    private $ackQueue;

    private $lastSendTime;
    private $pingCount;

    /** @var ClientTask[] */
    private $taskPool;
    public function __construct($ip, $port, $name = "FlamingClient"){
        $this->isRunning = false;
        $this->serverIp = $ip;
        $this->serverPort = $port;
        $this->name = $name;

        $this->taskPool = [];

        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        //socket_set_option($this->socket, SOL_SOCKET, SO_BROADCAST, 1); //Allow sending broadcast messages
        if(@socket_bind($this->socket, "0.0.0.0", Client::START_PORT + Client::$instanceId) === true){
            socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 0);
            $this->setSendBuffer(1024 * 1024 * 8)->setRecvBuffer(1024 * 1024 * 8);
        }
        socket_set_nonblock($this->socket);
        Client::$instanceId++;

        $this->name = "";
        $this->sequenceNumber = 0;
        $this->ackQueue = [];
        $this->isConnected = false;
        $this->lastSendTime = -1;
        $this->pingCount = 0;

    }
    public function sendPacket(Packet $packet){
        //print "C -> S " . get_class($packet) . "\n";
        $this->lastSendTime = time();
        $packet->encode();
        return $this->writePacket($packet->buffer, $this->serverIp, $this->serverPort);
    }
    public function sendEncapsulatedPacket($packet){
        if($packet instanceof Packet || $packet instanceof PMDataPacket) {
            //print "C -> S " . get_class($packet) . "\n";
            $packet->encode();
            $encapsulated = new EncapsulatedPacket();
            $encapsulated->reliability = 0;
            $encapsulated->buffer = $packet->buffer;

            $sendPacket = new DATA_PACKET_4();
            $sendPacket->seqNumber = $this->sequenceNumber++;
            $sendPacket->sendTime = microtime(true);
            $sendPacket->packets[] = $encapsulated->toBinary();

            return $this->sendPacket($sendPacket);
        }
        else{
            return false;
        }
    }
    public function receivePacket(){
        if ($this->readPacket($buffer, $this->serverIp, $this->serverPort) > 0) {
            if (($packet = StaticPacketPool::getPacketFromPool(ord($buffer{0}))) !== null) {
                $packet->buffer = $buffer;
                $packet->decode();
                if ($packet instanceof DataPacket) {
                    $this->ackQueue[$packet->seqNumber] = $packet->seqNumber;
                }
                return $packet;
            }
            return $buffer;
        }
        else{
            return false;
        }
    }
    public function networkTick(){
        if(!$this->isConnected && $this->lastSendTime !== time()){
            $ping = new UNCONNECTED_PING();
            $ping->pingID = $this->pingCount++;
            $this->sendPacket($ping);
        }
        if(count($this->ackQueue) > 0 && $this->lastSendTime !== time()){
            $ack = new ACK();
            $ack->packets = $this->ackQueue;
            $this->sendPacket($ack);
            $this->ackQueue = [];
        }
        if(($pk = $this->receivePacket()) instanceof Packet){
            if($pk instanceof DataPacket){
                foreach($pk->packets as $pk){
                    $id = ord($pk->buffer{0});
                    if(SERVER_HANDSHAKE_DataPacket::$ID === $id){
                        $new = new SERVER_HANDSHAKE_DataPacket();
                        $new->buffer = $pk->buffer;
                        $new->decode();
                        $this->handlePacket($this, $new);
                    }
                    elseif(PONG_DataPacket::$ID === $id){
                        $new = new PONG_DataPacket();
                        $new->buffer = $pk->buffer;
                        $new->decode();
                        $this->handlePacket($this, $new);
                    }
                    else {
                        $new = StaticDataPacketPool::getPacket($pk->buffer);
                        $new->decode();
                        $this->handleDataPacket($this, $new);
                    }
                }
            }
            else {
                $this->handlePacket($this, $pk);
            }
        }
        elseif($pk !== false){
            print $pk . "\n";
        }
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
                $pk->pingID = rand(0, 100); //TODO this is NOT GOOD
                $connection->sendEncapsulatedPacket($pk);
                break;
            default:
                //print get_class($pk);
                break;
        }
    }
    public function handleDataPacket(ClientConnection $connection, DataPacket $pk){

    }
    public function submitTask(ClientTask $task){
        $task = clone $task;
        $task->setClient($this);
        $this->taskPool[] = $task;
    }
    public function start(){
        $this->isRunning = true;
        while($this->isRunning){
            $this->tick();
        }
    }
    public function runTasks(){
        foreach($this->taskPool as $task){
            $task->tick();
        }
    }
    public function tick(){
        $this->runTasks();
        $this->networkTick();
    }

}