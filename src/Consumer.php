<?php
namespace Metamorphosis;

use Exception;
use Metamorphosis\Exceptions\ResponseWarningException;
use Metamorphosis\Middlewares\Handler\Consumer as ConsumerMiddleware;
use Metamorphosis\Middlewares\Handler\Dispatcher;
use Metamorphosis\TopicHandler\Consumer\Handler;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;

class Consumer
{
    /**
     * @var Conf
     */
    public $conf;

    /**
     * @var string
     */
    public $consumerGroup;

    /**
     * @var string
     */
    public $offset;

    /**
     * @var string
     */
    public $topic;

    /**
     * @var int
     */
    public $timeout = 2000000;

    /**
     * @var Handler
     */
    protected $handler;

    public function __construct(Config $config)
    {
        $this->consumerGroup = $config->getConsumerGroupId();
        $this->offset = $config->getConsumerGroupOffset();
        $this->handler = $config->getConsumerGroupHandler();
        $this->topic = $config->getTopic();

        $connector = new Connector($config->getBrokerConfig());
        $this->conf = $connector->setup();

        $middlewares = $config->getMiddlewares();
        $middlewares[] = new ConsumerMiddleware($this->handler);
        $this->middlewareDispatcher = new Dispatcher($middlewares);
    }

    public function run(): void
    {
        $kafkaConsumer = $this->getConsumer();

        while (true) {
            $response = $kafkaConsumer->consume($this->timeout);

            try {
                $message = new Message($response);
                $this->middlewareDispatcher->handle($message);
            } catch (ResponseWarningException $exception) {
                $this->handler->warning($exception);
            } catch (Exception $exception) {
                $this->handler->failed($exception);
            }
        }
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function setOffset($offset): void
    {
        $this->offset = $offset;
    }

    protected function getConsumer(): KafkaConsumer
    {
        $this->conf->set('group.id', $this->consumerGroup);
        $this->conf->set('auto.offset.reset', $this->offset);

        $consumer = new KafkaConsumer($this->conf);
        $consumer->subscribe([$this->topic]);

        return $consumer;
    }
}
