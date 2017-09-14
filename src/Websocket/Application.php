<?php

namespace Eole\Sandstone\Websocket;

use Psr\Log\LoggerAwareTrait;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface as WrongEventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\WampServerInterface;
use Eole\Sandstone\Logger\EchoLogger;
use Eole\Sandstone\OAuth2\Security\Authentication\Token\OAuth2Token;
use Eole\Sandstone\Application as SandstoneApplication;

final class Application implements WampServerInterface
{
    use LoggerAwareTrait;

    /**
     * @var SandstoneApplication
     */
    private $sandstoneApplication;

    /**
     * @var Topic[]
     */
    private $topics;

    /**
     * @param SandstoneApplication $sandstoneApplication
     */
    public function __construct(SandstoneApplication $sandstoneApplication)
    {
        $this->sandstoneApplication = $sandstoneApplication;
        $this->topics = array();
        $this->logger = new EchoLogger();
    }

    /**
     * @param ConnectionInterface $conn
     *
     * @return UserInterface|null
     *
     * @throws \Exception
     */
    private function authenticateUser(ConnectionInterface $conn)
    {
        $queryParameters = [];
        parse_str($conn->httpRequest->getUri()->getQuery(), $queryParameters);

        if (!isset($queryParameters['access_token'])) {
            return null;
        }

        $accessToken = $queryParameters['access_token'];
        $authenticationManager = $this->sandstoneApplication['security.authentication_manager'];

        $authenticatedToken = $authenticationManager->authenticate(new OAuth2Token($accessToken));
        $user = $authenticatedToken->getUser();
        $isUser = $user instanceof UserInterface;

        if (!$isUser) {
            throw new \Exception('User not found.');
        }

        return $user;
    }

    /**
     * {@InheritDoc}
     */
    public function onOpen(ConnectionInterface $conn)
    {
        $this->logger->info('Connection event', ['event' => 'open']);
        $this->logger->info('Authentication...');

        try {
            $user = $this->authenticateUser($conn);
            if (null === $user) {
                $this->logger->info('Anonymous connection');
            } else {
                $this->logger->info('User logged.', ['username' => $user->getUsername()]);
            }
        } catch (\Exception $e) {
            $this->logger->notice('Failed authentication', ['error_message' => $e->getMessage()]);
            $conn->send(json_encode('Could not authenticate client, closing connection.'));
            $conn->close();

            return;
        }

        $conn->user = $user;
    }

    /**
     * {@InheritDoc}
     */
    private function getTopic($topicPath)
    {
        if (!isset($this->topics[$topicPath])) {
            $this->topics[$topicPath] = $this->loadTopic($topicPath);
        }

        return $this->topics[$topicPath];
    }

    /**
     * @param string $topicPath
     *
     * @return Topic
     */
    private function loadTopic($topicPath)
    {
        $topic = $this->sandstoneApplication['sandstone.websocket.router']->loadTopic($topicPath);

        if (!$this->sandstoneApplication->offsetExists('serializer')) {
            throw new \RuntimeException('A serializer must be registered');
        }

        $topic->setNormalizer($this->sandstoneApplication['serializer']);

        if ($topic instanceof EventSubscriberInterface) {
            $this->sandstoneApplication['dispatcher']->addSubscriber($topic);
        }

        // debug purpose only. Sometimes I use the wrong namespace (JMS one), and it's hard to debug.
        if ($topic instanceof WrongEventSubscriberInterface) {
            throw new \LogicException(
                get_class($topic).' seems to implements the wrong EventSubscriberInterface. '.
                'Use the Symfony one, not the JMS one.'
            );
        }

        return $topic;
    }

    /**
     * {@InheritDoc}
     */
    public function onSubscribe(ConnectionInterface $conn, $topic)
    {
        $this->logger->info('Topic event', ['event' => 'subscribe', 'topic' => $topic]);

        $this->getTopic($topic)->onSubscribe($conn, $topic);
    }

    /**
     * {@InheritDoc}
     */
    public function onPublish(ConnectionInterface $conn, $topic, $event, array $exclude, array $eligible)
    {
        $this->logger->info('Topic event', ['event' => 'publish', 'topic' => $topic]);

        $this->getTopic($topic)->onPublish($conn, $topic, $event);
    }

    /**
     * {@InheritDoc}
     */
    public function onUnSubscribe(ConnectionInterface $conn, $topic)
    {
        $this->logger->info('Topic event', ['event' => 'unsubscribe', 'topic' => $topic]);

        $this->getTopic($topic)->onUnSubscribe($conn, $topic);
    }

    /**
     * {@InheritDoc}
     */
    public function onClose(ConnectionInterface $conn)
    {
        $this->logger->info('Connection event', ['event' => 'close']);

        foreach ($this->topics as $topic) {
            if ($topic->has($conn)) {
                $topic->onUnSubscribe($conn, $topic);
            }
        }
    }

    /**
     * {@InheritDoc}
     */
    public function onCall(ConnectionInterface $conn, $id, $topic, array $params)
    {
        $this->logger->info('Topic event', ['event' => 'call', 'topic' => $topic]);
    }

    /**
     * {@InheritDoc}
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->logger->info('Connection event', ['event' => 'error', 'message' => $e->getMessage()]);
    }
}
