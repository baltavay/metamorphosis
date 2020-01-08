<?php
namespace Metamorphosis\Middlewares\Handler;

use Metamorphosis\Connectors\Producer\Connector;
use Metamorphosis\Facades\Manager;
use Metamorphosis\Middlewares\MiddlewareInterface;
use Metamorphosis\Record\RecordInterface;
use Metamorphosis\TopicHandler\Producer\HandlerInterface;
use RuntimeException;

class Producer implements MiddlewareInterface
{
    const MAX_POLL_RECORDS = 500;

    const FLUSH_ATTEMPTS = 10;

    /**
     * @var Connector
     */
    private $connector;

    /**
     * @var HandlerInterface
     */
    private $producerHandler;

    /**
     * @var \RdKafka\Producer
     */
    private $producer;

    /**
     * @var int
     */
    private $processMessageCount = 0;

    public function __construct(Connector $connector, HandlerInterface $producerHandler)
    {
        $this->connector = $connector;
        $this->producerHandler = $producerHandler;

        $this->producer = $this->connector->getProducerTopic($producerHandler);
        $this->topic = $this->producer->newTopic(Manager::get('topic_id'));
    }

    public function process(RecordInterface $record, MiddlewareHandlerInterface $handler): void
    {
        $this->topic->produce($record->getPartition(), 0, $record->getPayload(), $record->getKey());
        $this->handleResponse();
    }

    public function __destruct()
    {
        $this->flushMessage();
    }

    private function handleResponse(): void
    {
        $this->processMessageCount++;

        if (Manager::get('isAsync')) {
            $this->flushMessage();

            return;
        }

        if (0 === ($this->processMessageCount % self::MAX_POLL_RECORDS)) {
            $this->pollResponse();
        }
    }

    private function flushMessage(): void
    {
        if (!Manager::get('requiredAcknowledgment')) {
            return;
        }

        for ($flushAttempts = 0; $flushAttempts < self::FLUSH_ATTEMPTS; $flushAttempts++) {
            $result = $this->producer->flush(Manager::get('timeout'));
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                return;
            }
        }

        throw new RuntimeException('Was unable to flush, messages might be lost!');
    }

    private function pollResponse(): void
    {
        while ($this->producer->getOutQLen() > 0) {
            $this->producer->poll(Manager::get('timeout'));
        }
    }
}
