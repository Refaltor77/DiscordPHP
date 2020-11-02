<?php

/*
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016-2020 David Cole <david.cole1340@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord;

use Discord\Exceptions\IntentException;
use Discord\Factory\Factory;
use Discord\Http\Http;
use Discord\Http\ReactDriver;
use Discord\Parts\Guild\Guild;
use Discord\Parts\OAuth\Application;
use Discord\Parts\Part;
use Discord\Repository\AbstractRepository;
use Discord\Repository\GuildRepository;
use Discord\Repository\PrivateChannelRepository;
use Discord\Repository\UserRepository;
use Discord\Wrapper\LoggerWrapper;
use Discord\Parts\Channel\Channel;
use Discord\Parts\User\Activity;
use Discord\Parts\User\Client;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Event;
use Discord\WebSockets\Events\GuildCreate;
use Discord\WebSockets\Handlers;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Op;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Message;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Discord\Helpers\Deferred;
use Evenement\EventEmitterTrait;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use function React\Promise\reject as Reject;
use function React\Promise\resolve as Resolve;

/**
 * The Discord client class.
 *
 * @property string                   $id            The unique identifier of the client.
 * @property string                   $username      The username of the client.
 * @property string                   $password      The password of the client (if they have provided it).
 * @property string                   $email         The email of the client.
 * @property bool                     $verified      Whether the client has verified their email.
 * @property string                   $avatar        The avatar URL of the client.
 * @property string                   $avatar_hash   The avatar hash of the client.
 * @property string                   $discriminator The unique discriminator of the client.
 * @property bool                     $bot           Whether the client is a bot.
 * @property User                     $user          The user instance of the client.
 * @property Application              $application   The OAuth2 application of the bot.
 * @property GuildRepository          $guilds
 * @property PrivateChannelRepository $private_channels
 * @property UserRepository           $users
 */
class Discord
{
    use EventEmitterTrait;

    /**
     * The gateway version the client uses.
     *
     * @var int Gateway version.
     */
    const GATEWAY_VERSION = 6;

    /**
     * The HTTP API version the client usees.
     *
     * @var int HTTP API version.
     */
    const HTTP_API_VERSION = 6;

    /**
     * The client version.
     *
     * @var string Version.
     */
    const VERSION = 'v5.0.11';

    /**
     * The logger.
     *
     * @var LoggerWrapper Logger.
     */
    protected $logger;

    /**
     * An array of loggers for voice clients.
     *
     * @var array Loggers.
     */
    protected $voiceLoggers = [];

    /**
     * An array of options passed to the client.
     *
     * @var array Options.
     */
    protected $options;

    /**
     * The authentication token.
     *
     * @var string Token.
     */
    protected $token;

    /**
     * The ReactPHP event loop.
     *
     * @var LoopInterface Event loop.
     */
    protected $loop;

    /**
     * The WebSocket client factory.
     *
     * @var Connector Factory.
     */
    protected $wsFactory;

    /**
     * The WebSocket instance.
     *
     * @var WebSocket Instance.
     */
    protected $ws;

    /**
     * The event handlers.
     *
     * @var Handlers Handlers.
     */
    protected $handlers;

    /**
     * The packet sequence that the client is up to.
     *
     * @var int Sequence.
     */
    protected $seq;

    /**
     * Whether the client is currently reconnecting.
     *
     * @var bool Reconnecting.
     */
    protected $reconnecting = false;

    /**
     * Whether the client is connected to the gateway.
     *
     * @var bool Connected.
     */
    protected $connected = false;

    /**
     * Whether the client is closing.
     *
     * @var bool Closing.
     */
    protected $closing = false;

    /**
     * The session ID of the current session.
     *
     * @var string Session ID.
     */
    protected $sessionId;

    /**
     * An array of voice clients that are currently connected.
     *
     * @var array Voice Clients.
     */
    protected $voiceClients = [];

    /**
     * An array of large guilds that need to be requested for
     * members.
     *
     * @var array Large guilds.
     */
    protected $largeGuilds = [];

    /**
     * An array of large guilds that have been requested for members.
     *
     * @var array Large guilds.
     */
    protected $largeSent = [];

    /**
     * An array of unparsed packets.
     *
     * @var array Unparsed packets.
     */
    protected $unparsedPackets = [];

    /**
     * How many times the client has reconnected.
     *
     * @var int Reconnect count.
     */
    protected $reconnectCount = 0;

    /**
     * The heartbeat interval.
     *
     * @var int Heartbeat interval.
     */
    protected $heartbeatInterval;

    /**
     * The timer that sends the heartbeat packet.
     *
     * @var TimerInterface Timer.
     */
    protected $heartbeatTimer;

    /**
     * The timer that resends the heartbeat packet if
     * a HEARTBEAT_ACK packet is not received in 5 seconds.
     *
     * @var TimerInterface Timer.
     */
    protected $heartbeatAckTimer;

    /**
     * The time that the last heartbeat packet was sent.
     *
     * @var int Epoch time.
     */
    protected $heartbeatTime;

    /**
     * Whether `ready` has been emitted.
     *
     * @var bool Emitted.
     */
    protected $emittedReady = false;

    /**
     * The gateway URL that the WebSocket client will connect to.
     *
     * @var string Gateway URL.
     */
    protected $gateway;

    /**
     * What encoding the client will use, either `json` or `etf`.
     *
     * @var string Encoding.
     */
    protected $encoding = 'json';

    /**
     * Tracks the number of payloads the client
     * has sent in the past 60 seconds.
     *
     * @var int
     */
    protected $payloadCount = 0;

    /**
     * Payload count reset timer.
     *
     * @var TimerInterface
     */
    protected $payloadTimer;

    /**
     * The HTTP client.
     *
     * @var Http Client.
     */
    protected $http;

    /**
     * The part/repository factory.
     *
     * @var Factory Part factory.
     */
    protected $factory;

    /**
     * The Client class.
     *
     * @var Client Discord client.
     */
    protected $client;

    /**
     * Creates a Discord client instance.
     *
     * @param  array           $options Array of options.
     * @throws IntentException
     */
    public function __construct(array $options = [])
    {
        if (php_sapi_name() !== 'cli') {
            trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);
        }

        $options = $this->resolveOptions($options);

        $this->options = $options;
        $this->token = $options['token'];
        $this->loop = $options['loop'];
        $this->logger = new LoggerWrapper($options['logger'], $options['logging']);
        $this->wsFactory = new Connector($this->loop);
        $this->handlers = new Handlers();

        foreach ($options['disabledEvents'] as $event) {
            $this->handlers->removeHandler($event);
        }

        $function = function () use (&$function) {
            $this->emittedReady = true;
            $this->removeListener('ready', $function);
        };

        $this->on('ready', $function);

        $this->http = new Http(
            'Bot '.$this->token,
            self::VERSION,
            new ReactDriver($this->loop)
        );
        $this->factory = new Factory($this, $this->http);

        $this->connectWs();
    }

    /**
     * Handles `VOICE_SERVER_UPDATE` packets.
     *
     * @param object $data Packet data.
     */
    protected function handleVoiceServerUpdate(object $data): void
    {
        if (isset($this->voiceClients[$data->d->guild_id])) {
            $this->logger->debug('voice server update received', ['guild' => $data->d->guild_id, 'data' => $data->d]);
            $this->voiceClients[$data->d->guild_id]->handleVoiceServerChange((array) $data->d);
        }
    }

    /**
     * Handles `RESUME` packets.
     *
     * @param object $data Packet data.
     */
    protected function handleResume(object $data): void
    {
        $this->logger->info('websocket reconnected to discord');
        $this->emit('reconnected', [$this]);
    }

    /**
     * Handles `READY` packets.
     *
     * @param object $data Packet data.
     *
     * @return false|void
     * @throws \Exception
     */
    protected function handleReady(object $data)
    {
        $this->logger->debug('ready packet received');

        // If this is a reconnect we don't want to
        // reparse the READY packet as it would remove
        // all the data cached.
        if ($this->reconnecting) {
            $this->reconnecting = false;
            $this->logger->debug('websocket reconnected to discord through identify');
            $this->emit('reconnected', [$this]);

            return;
        }

        $content = $data->d;
        $this->emit('trace', $data->d->_trace);
        $this->logger->debug('discord trace received', ['trace' => $content->_trace]);

        // Setup the user account
        $this->client = $this->factory->create(Client::class, $content->user, true);
        $this->sessionId = $content->session_id;

        $this->logger->debug('client created and session id stored', ['session_id' => $content->session_id, 'user' => $this->client->user->getPublicAttributes()]);

        // Private Channels
        if ($this->options['pmChannels']) {
            foreach ($content->private_channels as $channel) {
                $channelPart = $this->factory->create(Channel::class, $channel, true);
                $this->private_channels->push($channelPart);
            }

            $this->logger->info('stored private channels', ['count' => $this->private_channels->count()]);
        } else {
            $this->logger->info('did not parse private channels');
        }

        // Guilds
        $event = new GuildCreate(
            $this->http,
            $this->factory,
            $this
        );

        $unavailable = [];

        foreach ($content->guilds as $guild) {
            $deferred = new Deferred();

            $deferred->promise()->done(null, function ($d) use (&$unavailable) {
                list($status, $data) = $d;

                if ($status == 'unavailable') {
                    $unavailable[$data] = $data;
                }
            });

            $event->handle($deferred, $guild);
        }

        $this->logger->info('stored guilds', ['count' => $this->guilds->count(), 'unavailable' => count($unavailable)]);

        if (count($unavailable) < 1) {
            return $this->ready();
        }

        // Emit ready after 60 seconds
        $this->loop->addTimer(60, function () {
            $this->ready();
        });

        $function = function ($guild) use (&$function, &$unavailable) {
            $this->logger->debug('guild available', ['guild' => $guild->id, 'unavailable' => count($unavailable)]);
            if (array_key_exists($guild->id, $unavailable)) {
                unset($unavailable[$guild->id]);
            }

            // todo setup timer to continue after x amount of time
            if (count($unavailable) < 1) {
                $this->logger->info('all guilds are now available', ['count' => $this->guilds->count()]);
                $this->removeListener(Event::GUILD_CREATE, $function);

                $this->setupChunking();
            }
        };

        $this->on(Event::GUILD_CREATE, $function);
    }

    /**
     * Handles `GUILD_MEMBERS_CHUNK` packets.
     *
     * @param  object     $data Packet data.
     * @throws \Exception
     */
    protected function handleGuildMembersChunk(object $data): void
    {
        $guild = $this->guilds->offsetGet($data->d->guild_id);
        $members = $data->d->members;

        $this->logger->debug('received guild member chunk', ['guild_id' => $guild->id, 'guild_name' => $guild->name, 'chunk_count' => count($members), 'member_collection' => $guild->members->count(), 'member_count' => $guild->member_count]);

        $count = 0;
        $skipped = 0;
        foreach ($members as $member) {
            if ($guild->members->has($member->user->id)) {
                ++$skipped;
                continue;
            }

            $member = (array) $member;
            $member['guild_id'] = $guild->id;
            $member['status'] = 'offline';
            $member['game'] = null;

            if (! $this->users->has($member['user']->id)) {
                $userPart = $this->factory->create(User::class, $member['user'], true);
                $this->users->offsetSet($userPart->id, $userPart);
            }

            $memberPart = $this->factory->create(Member::class, $member, true);
            $guild->members->offsetSet($memberPart->id, $memberPart);

            ++$count;
        }

        $this->logger->debug('parsed '.$count.' members (skipped '.$skipped.')', ['repository_count' => $guild->members->count(), 'actual_count' => $guild->member_count]);

        if ($guild->members->count() >= $guild->member_count) {
            $this->largeSent = array_diff($this->largeSent, [$guild->id]);

            $this->logger->debug('all users have been loaded', ['guild' => $guild->id, 'member_collection' => $guild->members->count(), 'member_count' => $guild->member_count]);
            $this->guilds->offsetSet($guild->id, $guild);
        }

        if (count($this->largeSent) < 1) {
            $this->ready();
        }
    }

    /**
     * Handles `VOICE_STATE_UPDATE` packets.
     *
     * @param object $data Packet data.
     */
    protected function handleVoiceStateUpdate(object $data): void
    {
        if (isset($this->voiceClients[$data->d->guild_id])) {
            $this->logger->debug('voice state update received', ['guild' => $data->d->guild_id, 'data' => $data->d]);
            $this->voiceClients[$data->d->guild_id]->handleVoiceStateUpdate($data->d);
        }
    }

    /**
     * Handles WebSocket connections received by the client.
     *
     * @param WebSocket $ws WebSocket client.
     */
    public function handleWsConnection(WebSocket $ws): void
    {
        $this->ws = $ws;
        $this->connected = true;

        $this->logger->info('websocket connection has been created');

        $this->payloadCount = 0;
        $this->payloadTimer = $this->loop->addPeriodicTimer(60, function () {
            $this->logger->debug('resetting payload count', ['count' => $this->payloadCount]);
            $this->payloadCount = 0;
            $this->emit('payload_count_reset');
        });

        $ws->on('message', [$this, 'handleWsMessage']);
        $ws->on('close', [$this, 'handleWsClose']);
        $ws->on('error', [$this, 'handleWsError']);
    }

    /**
     * Handles WebSocket messages received by the client.
     *
     * @param Message $message Message object.
     */
    public function handleWsMessage(Message $message): void
    {
        if ($message->isBinary()) {
            $data = zlib_decode($message->getPayload());
        } else {
            $data = $message->getPayload();
        }

        $data = json_decode($data);
        $this->emit('raw', [$data, $this]);

        if (isset($data->s)) {
            $this->seq = $data->s;
        }

        $op = [
            Op::OP_DISPATCH => 'handleDispatch',
            Op::OP_HEARTBEAT => 'handleHeartbeat',
            Op::OP_RECONNECT => 'handleReconnect',
            Op::OP_INVALID_SESSION => 'handleInvalidSession',
            Op::OP_HELLO => 'handleHello',
            Op::OP_HEARTBEAT_ACK => 'handleHeartbeatAck',
        ];

        if (isset($op[$data->op])) {
            $this->{$op[$data->op]}($data);
        }
    }

    /**
     * Handles WebSocket closes received by the client.
     *
     * @param int    $op     The close code.
     * @param string $reason The reason the WebSocket closed.
     */
    public function handleWsClose(int $op, string $reason): void
    {
        $this->connected = false;

        if (! is_null($this->heartbeatTimer)) {
            $this->loop->cancelTimer($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        if (! is_null($this->heartbeatAckTimer)) {
            $this->loop->cancelTimer($this->heartbeatAckTimer);
            $this->heartbeatAckTimer = null;
        }

        if (! is_null($this->payloadTimer)) {
            $this->loop->cancelTimer($this->payloadTimer);
            $this->payloadTimer = null;
        }

        if ($this->closing) {
            return;
        }

        $this->logger->warning('websocket closed', ['op' => $op, 'reason' => $reason]);

        if (in_array($op, Op::getCriticalCloseCodes())) {
            $this->logger->error('not reconnecting - critical op code', ['op' => $op, 'reason' => $reason]);
        } else {
            $this->logger->warning('reconnecting in 2 seconds');

            $this->loop->addTimer(2, function () {
                ++$this->reconnectCount;
                $this->reconnecting = true;
                $this->logger->info('starting reconnect', ['reconnect_count' => $this->reconnectCount]);
                $this->connectWs();
            });
        }
    }

    /**
     * Handles WebSocket errors received by the client.
     *
     * @param \Exception $e The error.
     */
    public function handleWsError(\Exception $e): void
    {
        // Pawl pls
        if (strpos($e->getMessage(), 'Tried to write to closed stream') !== false) {
            return;
        }

        $this->logger->error('websocket error', ['e' => $e->getMessage()]);
        $this->emit('error', [$e, $this]);
    }

    /**
     * Handles dispatch events received by the WebSocket.
     *
     * @param object $data Packet data.
     */
    protected function handleDispatch(object $data): void
    {
        if (! is_null($hData = $this->handlers->getHandler($data->t))) {
            $handler = new $hData['class'](
                $this->http,
                $this->factory,
                $this
            );

            $deferred = new Deferred();
            $deferred->promise()->done(function ($d) use ($data, $hData) {
                if (is_array($d) && count($d) == 2) {
                    list($new, $old) = $d;
                } else {
                    $new = $d;
                    $old = null;
                }

                $this->emit($data->t, [$new, $this, $old]);

                foreach ($hData['alternatives'] as $alternative) {
                    $this->emit($alternative, [$d, $this]);
                }

                if ($data->t == Event::MESSAGE_CREATE && mentioned($this->client->user, $new)) {
                    $this->emit('mention', [$new, $this, $old]);
                }
            }, function ($e) use ($data) {
                $this->logger->warning('error while trying to handle dispatch packet', ['packet' => $data->t, 'error' => $e]);
            }, function ($d) use ($data) {
                $this->logger->warning('notified from event', ['data' => $d, 'packet' => $data->t]);
            });

            $parse = [
                Event::GUILD_CREATE,
            ];

            if (! $this->emittedReady && (array_search($data->t, $parse) === false)) {
                $this->unparsedPackets[] = function () use (&$handler, &$deferred, &$data) {
                    $handler->handle($deferred, $data->d);
                };
            } else {
                $handler->handle($deferred, $data->d);
            }
        }

        $handlers = [
            Event::VOICE_SERVER_UPDATE => 'handleVoiceServerUpdate',
            Event::RESUMED => 'handleResume',
            Event::READY => 'handleReady',
            Event::GUILD_MEMBERS_CHUNK => 'handleGuildMembersChunk',
            Event::VOICE_STATE_UPDATE => 'handleVoiceStateUpdate',
        ];

        if (isset($handlers[$data->t])) {
            $this->{$handlers[$data->t]}($data);
        }
    }

    /**
     * Handles heartbeat packets received by the client.
     *
     * @param object $data Packet data.
     */
    protected function handleHeartbeat(object $data): void
    {
        $this->logger->debug('received heartbeat', ['seq' => $data->d]);

        $payload = [
            'op' => Op::OP_HEARTBEAT,
            'd' => $data->d,
        ];

        $this->send($payload);
    }

    /**
     * Handles heartbeat ACK packets received by the client.
     *
     * @param object $data Packet data.
     */
    protected function handleHeartbeatAck(object $data): void
    {
        $received = microtime(true);
        $diff = $received - $this->heartbeatTime;
        $time = $diff * 1000;

        if (! is_null($this->heartbeatAckTimer)) {
            $this->loop->cancelTimer($this->heartbeatAckTimer);
            $this->heartbeatAckTimer = null;
        }

        $this->emit('heartbeat-ack', [$time, $this]);
        $this->logger->debug('received heartbeat ack', ['response_time' => $time]);
    }

    /**
     * Handles reconnect packets received by the client.
     *
     * @param object $data Packet data.
     */
    protected function handleReconnect(object $data): void
    {
        $this->logger->warning('received opcode 7 for reconnect');

        $this->ws->close(
            Op::CLOSE_UNKNOWN_ERROR,
            'gateway redirecting - opcode 7'
        );
    }

    /**
     * Handles invalid session packets received by the client.
     *
     * @param object $data Packet data.
     */
    protected function handleInvalidSession(object $data): void
    {
        $this->logger->warning('invalid session, re-identifying', ['resumable' => $data->d]);

        $this->loop->addTimer(2, function () use ($data) {
            $this->identify($data->d);
        });
    }

    /**
     * Handles HELLO packets received by the websocket.
     *
     * @param object $data Packet data.
     */
    protected function handleHello(object $data): void
    {
        $this->logger->info('received hello');
        $this->setupHeartbeat($data->d->heartbeat_interval);
        $this->identify();
    }

    /**
     * Identifies with the Discord gateway with `IDENTIFY` or `RESUME` packets.
     *
     * @param  bool $resume Whether resume should be enabled.
     * @return bool
     */
    protected function identify(bool $resume = true): bool
    {
        if ($resume && $this->reconnecting && ! is_null($this->sessionId)) {
            $payload = [
                'op' => Op::OP_RESUME,
                'd' => [
                    'session_id' => $this->sessionId,
                    'seq' => $this->seq,
                    'token' => $this->token,
                ],
            ];

            $this->logger->info('resuming connection', ['payload' => $payload]);
        } else {
            $payload = [
                'op' => Op::OP_IDENTIFY,
                'd' => [
                    'token' => $this->token,
                    'properties' => [
                        '$os' => PHP_OS,
                        '$browser' => $this->http->getUserAgent(),
                        '$device' => $this->http->getUserAgent(),
                        '$referrer' => 'https://github.com/teamreflex/DiscordPHP',
                        '$referring_domain' => 'https://github.com/teamreflex/DiscordPHP',
                    ],
                    'compress' => true,
                ],
            ];

            if ($this->options['intents'] !== false) {
                $payload['d']['intents'] = $this->options['intents'];
            }

            if (array_key_exists('shardId', $this->options) &&
                array_key_exists('shardCount', $this->options)) {
                $payload['d']['shard'] = [
                    (int) $this->options['shardId'],
                    (int) $this->options['shardCount'],
                ];
            }

            $this->logger->info('identifying', ['payload' => $payload]);
        }

        $this->send($payload);

        return $payload['op'] == Op::OP_RESUME;
    }

    /**
     * Sends a heartbeat packet to the Discord gateway.
     */
    public function heartbeat(): void
    {
        $this->logger->debug('sending heartbeat', ['seq' => $this->seq]);

        $payload = [
            'op' => Op::OP_HEARTBEAT,
            'd' => $this->seq,
        ];

        $this->send($payload, true);
        $this->heartbeatTime = microtime(true);
        $this->emit('heartbeat', [$this->seq, $this]);

        $this->heartbeatAckTimer = $this->loop->addTimer($this->heartbeatInterval / 1000, function () {
            if (! $this->connected) {
                return;
            }

            $this->logger->warning('did not receive heartbeat ACK within heartbeat interval, closing connection');
            $this->ws->close(1001, 'did not receive heartbeat ack');
        });
    }

    /**
     * Sets guild member chunking up.
     *
     * @return false|void
     */
    protected function setupChunking()
    {
        if (! $this->options['loadAllMembers']) {
            $this->logger->info('loadAllMembers option is disabled, not setting chunking up');

            return $this->ready();
        }

        $checkForChunks = function () {
            if ((count($this->largeGuilds) < 1) && (count($this->largeSent) < 1)) {
                $this->ready();

                return;
            }

            if (count($this->largeGuilds) < 1) {
                $this->logger->debug('unprocessed chunks', $this->largeSent);
                return;
            }

            $chunks = array_chunk($this->largeGuilds, 50);
            $this->logger->debug('sending '.count($chunks).' chunks with '.count($this->largeGuilds).' large guilds overall');
            $this->largeSent = array_merge($this->largeGuilds, $this->largeSent);
            $this->largeGuilds = [];

            $sendChunks = function () use (&$sendChunks, &$chunks) {
                $chunk = array_pop($chunks);

                if (is_null($chunk)) {
                    return;
                }

                $this->logger->debug('sending chunk with '.count($chunk).' large guilds');

                foreach ($chunk as $guild_id) {
                    $payload = [
                        'op' => Op::OP_GUILD_MEMBER_CHUNK,
                        'd' => [
                            'guild_id' => $guild_id,
                            'query' => '',
                            'limit' => 0,
                        ],
                    ];

                    $this->send($payload);
                }
                $this->loop->addTimer(1, $sendChunks);
            };

            $sendChunks();
        };

        $this->loop->addPeriodicTimer(5, $checkForChunks);
        $this->logger->info('set up chunking, checking for chunks every 5 seconds');
        $checkForChunks();
    }

    /**
     * Sets the heartbeat timer up.
     *
     * @param int $interval The heartbeat interval in milliseconds.
     */
    protected function setupHeartbeat(int $interval): void
    {
        $this->heartbeatInterval = $interval;
        if (isset($this->heartbeatTimer)) {
            $this->loop->cancelTimer($this->heartbeatTimer);
        }

        $interval = $interval / 1000;
        $this->heartbeatTimer = $this->loop->addPeriodicTimer($interval, [$this, 'heartbeat']);
        $this->heartbeat();

        $this->logger->info('heartbeat timer initilized', ['interval' => $interval * 1000]);
    }

    /**
     * Initilizes the connection with the Discord gateway.
     */
    protected function connectWs(): void
    {
        $this->setGateway()->done(function ($gateway) {
            if (isset($gateway['session']) && $session = $gateway['session']) {
                if ($session['remaining'] < 2) {
                    $this->logger->error('exceeded number of reconnects allowed, waiting before attempting reconnect', $session);
                    $this->loop->addTimer($session['reset_after'] / 1000, function () {
                        $this->connectWs();
                    });

                    return;
                }
            }

            $this->logger->info('starting connection to websocket', ['gateway' => $this->gateway]);

            /** @var ExtendedPromiseInterface */
            $promise = ($this->wsFactory)($this->gateway);
            $promise->done(
                [$this, 'handleWsConnection'],
                [$this, 'handleWsError']
            );
        });
    }

    /**
     * Sends a packet to the Discord gateway.
     *
     * @param array $data Packet data.
     */
    protected function send(array $data, bool $force = false): void
    {
        // Wait until payload count has been reset
        // Keep 5 payloads for heartbeats as required
        if ($this->payloadCount >= 115 && ! $force) {
            $this->logger->debug('payload not sent, waiting', ['payload' => $data]);
            $this->once('payload_count_reset', function () use ($data) {
                $this->send($data);
            });
        } else {
            ++$this->payloadCount;
            $data = json_encode($data);
            $this->ws->send($data);
        }
    }

    /**
     * Emits ready if it has not been emitted already.
     * @return false|void
     */
    protected function ready()
    {
        if ($this->emittedReady) {
            return false;
        }

        $this->logger->info('client is ready');
        $this->emit('ready', [$this]);

        foreach ($this->unparsedPackets as $parser) {
            $parser();
        }
    }

    /**
     * Updates the clients presence.
     *
     * @param  Activity|null $activity The current client activity, or null.
     *                                 Note: The activity type _cannot_ be custom, and the only valid fields are `name`, `type` and `url`.
     * @param  bool          $idle     Whether the client is idle.
     * @param  string        $status   The current status of the client.
     *                                 Must be one of the following:
     *                                 online, dnd, idle, invisible, offline
     * @param  bool          $afk      Whether the client is AFK.
     * @throws \Exception
     */
    public function updatePresence(Activity $activity = null, bool $idle = false, string $status = 'online', bool $afk = false): void
    {
        $idle = $idle ? time() * 1000 : null;

        if (! is_null($activity)) {
            $activity = $activity->getRawAttributes();

            if (! in_array($activity['type'], [Activity::TYPE_PLAYING, Activity::TYPE_STREAMING, Activity::TYPE_LISTENING])) {
                throw new \Exception("The given activity type ({$activity['type']}) is invalid.");

                return;
            }
        }

        if (! array_search($status, ['online', 'dnd', 'idle', 'invisible', 'offline'])) {
            $status = 'online';
        }

        $payload = [
            'op' => Op::OP_PRESENCE_UPDATE,
            'd' => [
                'since' => $idle,
                'game' => $activity,
                'status' => $status,
                'afk' => $afk,
            ],
        ];

        $this->send($payload);
    }

    /**
     * Gets a voice client from a guild ID.
     *
     * @param int $id The guild ID to look up.
     *
     * @return PromiseInterface
     */
    public function getVoiceClient(int $id): PromiseInterface
    {
        if (isset($this->voiceClients[$id])) {
            return Resolve($this->voiceClients[$id]);
        }

        return Reject(new \Exception('Could not find the voice client.'));
    }

    /**
     * Joins a voice channel.
     *
     * @param Channel      $channel The channel to join.
     * @param bool         $mute    Whether you should be mute when you join the channel.
     * @param bool         $deaf    Whether you should be deaf when you join the channel.
     * @param Monolog|null $monolog A Monolog logger to use.
     *
     * @return PromiseInterface
     */
    public function joinVoiceChannel(Channel $channel, $mute = false, $deaf = true, ?Monolog $monolog = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($channel->type != Channel::TYPE_VOICE) {
            $deferred->reject(new \Exception('You cannot join a text channel.'));

            return $deferred->promise();
        }

        if (isset($this->voiceClients[$channel->guild_id])) {
            $deferred->reject(new \Exception('You cannot join more than one voice channel per guild.'));

            return $deferred->promise();
        }

        $data = [
            'user_id' => $this->id,
            'deaf' => $deaf,
            'mute' => $mute,
        ];

        $voiceStateUpdate = function ($vs, $discord) use ($channel, &$data, &$voiceStateUpdate) {
            if ($vs->guild_id != $channel->guild_id) {
                return; // This voice state update isn't for our guild.
            }

            $data['session'] = $vs->session_id;
            $this->logger->info('received session id for voice sesion', ['guild' => $channel->guild_id, 'session_id' => $vs->session_id]);
            $this->removeListener(Event::VOICE_STATE_UPDATE, $voiceStateUpdate);
        };

        $voiceServerUpdate = function ($vs, $discord) use ($channel, &$data, &$voiceServerUpdate, $deferred, $monolog) {
            if ($vs->guild_id != $channel->guild_id) {
                return; // This voice server update isn't for our guild.
            }

            $data['token'] = $vs->token;
            $data['endpoint'] = $vs->endpoint;
            $this->logger->info('received token and endpoint for voice session', ['guild' => $channel->guild_id, 'token' => $vs->token, 'endpoint' => $vs->endpoint]);

            if (is_null($monolog)) {
                $monolog = new Monolog('Voice-'.$channel->guild_id);
                $monolog->pushHandler(new StreamHandler('php://stdout', $this->options['loggerLevel']));
            }

            $logger = new LoggerWrapper($monolog, $this->options['logging']);
            $vc = new VoiceClient($this->ws, $this->loop, $channel, $logger, $data);

            $vc->once('ready', function () use ($vc, $deferred, $channel, $logger) {
                $logger->info('voice client is ready');
                $this->voiceClients[$channel->guild_id] = $vc;

                $vc->setBitrate($channel->bitrate)->done(function () use ($vc, $deferred, $logger, $channel) {
                    $logger->info('set voice client bitrate', ['bitrate' => $channel->bitrate]);
                    $deferred->resolve($vc);
                });
            });
            $vc->once('error', function ($e) use ($deferred, $logger) {
                $logger->error('error initilizing voice client', ['e' => $e->getMessage()]);
                $deferred->reject($e);
            });
            $vc->once('close', function () use ($channel, $logger) {
                $logger->warning('voice client closed');
                unset($this->voiceClients[$channel->guild_id]);
            });

            $vc->start();

            $this->voiceLoggers[$channel->guild_id] = $logger;
            $this->removeListener(Event::VOICE_SERVER_UPDATE, $voiceServerUpdate);
        };

        $this->on(Event::VOICE_STATE_UPDATE, $voiceStateUpdate);
        $this->on(Event::VOICE_SERVER_UPDATE, $voiceServerUpdate);

        $payload = [
            'op' => Op::OP_VOICE_STATE_UPDATE,
            'd' => [
                'guild_id' => $channel->guild_id,
                'channel_id' => $channel->id,
                'self_mute' => $mute,
                'self_deaf' => $deaf,
            ],
        ];

        $this->send($payload);

        return $deferred->promise();
    }

    /**
     * Retrieves and sets the gateway URL for the client.
     *
     * @param string|null $gateway Gateway URL to set.
     *
     * @return ExtendedPromiseInterface
     */
    protected function setGateway(?string $gateway = null): ExtendedPromiseInterface
    {
        $deferred = new Deferred();

        $buildParams = function ($gateway, $response = null) use ($deferred) {
            $params = [
                'v' => self::GATEWAY_VERSION,
                'encoding' => $this->encoding,
            ];

            $query = http_build_query($params);
            $this->gateway = trim($gateway, '/').'/?'.$query;

            $deferred->resolve(['gateway' => $this->gateway, 'session' => (array) $response->session_start_limit]);
        };

        if (is_null($gateway)) {
            $this->http->get('gateway/bot')->done(function ($response) use ($buildParams) {
                $buildParams($response->url, $response);
            }, function ($e) use ($buildParams) {
                // Can't access the API server so we will use the default gateway.
                $buildParams('wss://gateway.discord.gg');
            });
        } else {
            $buildParams($gateway);
        }

        $deferred->promise()->done(function ($gateway) {
            $this->logger->info('gateway retrieved and set', $gateway);
        }, function ($e) {
            $this->logger->error('error obtaining gateway', ['e' => $e->getMessage()]);
        });

        return $deferred->promise();
    }

    /**
     * Resolves the options.
     *
     * @param array $options Array of options.
     *
     * @return array           Options.
     * @throws IntentException
     */
    protected function resolveOptions(array $options = []): array
    {
        $resolver = new OptionsResolver();
        $logger = new Monolog('DiscordPHP');

        $resolver
            ->setRequired('token')
            ->setAllowedTypes('token', 'string')
            ->setDefined([
                'token',
                'shardId',
                'shardCount',
                'loop',
                'logger',
                'loggerLevel',
                'logging',
                'loadAllMembers',
                'disabledEvents',
                'pmChannels',
                'storeMessages',
                'retrieveBans',
                'intents',
            ])
            ->setDefaults([
                'loop' => LoopFactory::create(),
                'logger' => null,
                'loggerLevel' => Monolog::INFO,
                'logging' => true,
                'loadAllMembers' => false,
                'disabledEvents' => [],
                'pmChannels' => false,
                'storeMessages' => false,
                'retrieveBans' => false,
                'intents' => false,
            ])
            ->setAllowedTypes('loop', LoopInterface::class)
            ->setAllowedTypes('logging', 'bool')
            ->setAllowedTypes('loadAllMembers', 'bool')
            ->setAllowedTypes('disabledEvents', 'array')
            ->setAllowedTypes('pmChannels', 'bool')
            ->setAllowedTypes('storeMessages', 'bool')
            ->setAllowedTypes('retrieveBans', 'bool')
            ->setAllowedTypes('intents', ['bool', 'array', 'int']);

        $options = $resolver->resolve($options);

        if (is_null($options['logger'])) {
            $logger->pushHandler(new StreamHandler('php://stdout', $options['loggerLevel']));
            $options['logger'] = $logger;
        }

        if ($options['intents'] !== false) {
            if (is_array($options['intents'])) {
                $intentVal = 0;
                $validIntents = Intents::getValidIntents();

                foreach ($options['intents'] as $intent) {
                    if (! in_array($intent, $validIntents)) {
                        throw new IntentException('Given intent is not valid: '.$intent);
                    }
                    $intentVal |= $intent;
                }

                $options['intents'] = $intentVal;
            }
        }

        return $options;
    }

    /**
     * Adds a large guild to the large guild array.
     *
     * @param Guild $guild The guild.
     */
    public function addLargeGuild(Part $guild): void
    {
        $this->largeGuilds[] = $guild->id;
    }

    /**
     * Starts the ReactPHP event loop.
     */
    public function run(): void
    {
        $this->loop->run();
    }

    /**
     * Closes the Discord client.
     *
     * @param bool $closeLoop Whether to close the loop as well. Default true.
     */
    public function close(bool $closeLoop = true): void
    {
        $this->closing = true;
        $this->ws->close(Op::CLOSE_UNKNOWN_ERROR, 'discordphp closing...');
        $this->emit('closed', [$this]);
        $this->logger->info('discord closed');

        if ($closeLoop) {
            $this->loop->stop();
        }
    }

    /**
     * Allows access to the part/repository factory.
     *
     * @param string $class   The class to build.
     * @param mixed  $data    Data to create the object.
     * @param bool   $created Whether the object is created (if part).
     *
     * @return Part|AbstractRepository
     *
     * @see Factory::create()
     */
    public function factory(string $class, $data = [], bool $created = false)
    {
        return $this->factory->create($class, $data, $created);
    }

    /**
     * Gets the loop being used by the client.
     *
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /**
     * Gets the logger being used.
     *
     * @return LoggerWrapper
     */
    public function getLogger(): LoggerWrapper
    {
        return $this->logger;
    }

    /**
     * Handles dynamic get calls to the client.
     *
     * @param string $name Variable name.
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        $allowed = ['loop', 'options', 'logger', 'http'];

        if (array_search($name, $allowed) !== false) {
            return $this->{$name};
        }

        if (is_null($this->client)) {
            return;
        }

        return $this->client->{$name};
    }

    /**
     * Handles dynamic set calls to the client.
     *
     * @param string $name  Variable name.
     * @param mixed  $value Value to set.
     */
    public function __set(string $name, $value)
    {
        if (is_null($this->client)) {
            return;
        }

        $this->client->{$name} = $value;
    }

    /**
     *
     * Gets a channel.
     *
     * @param string|int $channel_id Id of the channel.
     *
     * @return Channel
     */
    public function getChannel($channel_id): ?Channel
    {
        foreach ($this->guilds as $guild) {
            if ($channel = $guild->channels->get('id', $channel_id)) {
                return $channel;
            }
        }

        if ($channel = $this->private_channels->get('id', $channel_id)) {
            return $channel;
        }

        return null;
    }

    /**
     * Handles dynamic calls to the client.
     *
     * @param string $name   Function name.
     * @param array  $params Function paramaters.
     *
     * @return mixed
     */
    public function __call(string $name, array $params)
    {
        if (is_null($this->client)) {
            return;
        }

        return call_user_func_array([$this->client, $name], $params);
    }

    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        $secrets = [
            'token' => '*****',
        ];
        $replace = array_intersect_key($secrets, $this->options);
        $config = $replace + $this->options;

        unset($config['loop'], $config['logger']);

        return $config;
    }
}
