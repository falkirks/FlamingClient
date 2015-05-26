<?php
namespace flamingclient;


abstract class ClientTask implements Tickable{
	/** @var Client */
	protected $client;

    public function setClient(Client $client){
        $this->client = $client;
    }
    public function getClient(){
        return $this->client;
    }

}