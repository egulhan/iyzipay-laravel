<?php

namespace Iyzico\IyzipayLaravel\Exceptions\Transaction;

use Throwable;

class ThreedsTransactionException extends \Exception
{
    protected $conversationId;
    protected $step;

    public function __construct(string $message = "", $conversationId = null, $step = null, int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->conversationId = $conversationId;
        $this->step = $step;
    }

    /**
     * @return mixed
     */
    public function getConversationId()
    {
        return $this->conversationId;
    }

    /**
     * @param mixed $conversationId
     */
    public function setConversationId($conversationId)
    {
        $this->conversationId = $conversationId;
    }
}
