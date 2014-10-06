<?php

namespace Inck\RatchetBundle\Entity;

use Ratchet\ConnectionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class Client
{
    /**
     * @var ConnectionInterface $connection
     */
    private $connection;


    /**
     * @param ConnectionInterface $connection
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return ConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->connection->resourceId;
    }

    /**
     * @return UserInterface
     */
    public function getUser()
    {
        return $this->connection->Session->get('user');
    }

    /**
     * @param Message $message
     */
    public function send(Message $message)
    {
        $this->connection->send($message);
    }

    /**
     * @param string $method
     * @param array $parameters
     */
    public function sendMessage($method, $parameters = array())
    {
        $this->send(new Message($method, $parameters));
    }
}
