<?php

namespace Iyzico\IyzipayLaravel\Exceptions\Transaction;

use Throwable;

class TransactionSaveException extends \Exception
{
    protected $conversationId;

    public function __construct(string $message = "", $conversationId = null, int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->conversationId = $conversationId;
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
