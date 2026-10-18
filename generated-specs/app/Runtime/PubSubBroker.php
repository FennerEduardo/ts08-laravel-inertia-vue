<?php

declare(strict_types=1);

namespace App\Runtime;

/**
 * Google Cloud Pub/Sub over its REST API (no client library, ext-curl): topics for the exchange and the DLX, pull
 * subscriptions for the queue and the DLQ. publish() returns once Pub/Sub has stored the message. A failed message is
 * dead-lettered by the consumer: published to the DLX topic, then acknowledged.
 * Auth: none against the emulator (PUBSUB_EMULATOR_HOST); otherwise GOOGLE_OAUTH_ACCESS_TOKEN or the metadata-server
 * token of the workload's service account (Cloud Run, GKE workload identity).
 */
final class PubSubBroker implements Broker
{
    private const METADATA_TOKEN = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';
    private readonly string $root;
    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(private readonly string $project, private readonly ?string $emulatorHost = null)
    {
        $this->root = ($emulatorHost !== null ? "http://{$emulatorHost}" : 'https://pubsub.googleapis.com') . "/v1/projects/{$project}";
    }

    public static function fromEnv(): self
    {
        $project = Brokers::env('PUBSUB_PROJECT_ID') ?? Brokers::env('GOOGLE_CLOUD_PROJECT')
            ?? throw new \RuntimeException('PUBSUB_PROJECT_ID (or GOOGLE_CLOUD_PROJECT) is not set');
        return new self($project, Brokers::env('PUBSUB_EMULATOR_HOST'));
    }

    public function system(): string
    {
        return 'gcp_pubsub';
    }

    private function bearer(): ?string
    {
        if ($this->emulatorHost !== null) {
            return null;
        }
        if (Brokers::env('GOOGLE_OAUTH_ACCESS_TOKEN') !== null) {
            return Brokers::env('GOOGLE_OAUTH_ACCESS_TOKEN');
        }
        if ($this->token === null || time() > $this->tokenExpiresAt) {
            [$status, $response] = self::request('GET', self::METADATA_TOKEN, ['Metadata-Flavor: Google'], null, 5);
            if ($status !== 200) {
                throw new \RuntimeException("Metadata server token request failed: {$status}");
            }
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            $this->token = $data['access_token'];
            $this->tokenExpiresAt = time() + (int) $data['expires_in'] - 60;
        }
        return $this->token;
    }

    /**
     * One HTTP request with ext-curl (PHP's http:// stream wrapper waits for the server to close the connection).
     *
     * @param list<string> $headers
     * @return array{0: int, 1: string} status and body
     */
    private static function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        if ($response === false) {
            throw new \RuntimeException("{$method} {$url} failed: " . curl_error($curl));
        }
        return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $response];
    }

    /**
     * Calls the API; a status listed in $accepted returns null instead of failing.
     *
     * @param list<int> $accepted
     */
    public function call(string $method, string $path, array $body, array $accepted = []): ?array
    {
        $headers = ['Content-Type: application/json'];
        $token = $this->bearer();
        if ($token !== null) {
            $headers[] = "Authorization: Bearer {$token}";
        }
        [$status, $response] = self::request($method, $this->root . $path, $headers, json_encode((object) $body, JSON_THROW_ON_ERROR), 30);
        if ($status >= 200 && $status < 300) {
            return trim((string) $response) === '' ? [] : json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
        }
        if (in_array($status, $accepted, true)) {
            return null;
        }
        throw new \RuntimeException("Pub/Sub {$method} {$path} failed: {$status} {$response}");
    }

    public function declare(Messaging $t): void
    {
        // 409: it already exists (created earlier or by infrastructure as code).
        $this->call('PUT', "/topics/{$t->exchange}", [], [409]);
        $this->call('PUT', "/topics/{$t->dlx}", [], [409]);
        $this->call('PUT', "/subscriptions/{$t->queue}", ['topic' => "projects/{$this->project}/topics/{$t->exchange}", 'ackDeadlineSeconds' => 60], [409]);
        $this->call('PUT', "/subscriptions/{$t->dlq}", ['topic' => "projects/{$this->project}/topics/{$t->dlx}", 'ackDeadlineSeconds' => 60], [409]);
    }

    public function publishTo(string $topic, BrokerMessage $m): void
    {
        $attributes = array_filter(['ghk_message_id' => $m->messageId, 'event_type' => $m->eventType, 'tenant_id' => $m->tenantId, 'traceparent' => $m->traceparent], fn ($v) => $v !== null);
        $res = $this->call('POST', "/topics/{$topic}:publish", ['messages' => [['data' => base64_encode($m->body), 'attributes' => (object) $attributes]]]);
        if (count($res['messageIds'] ?? []) !== 1) {
            throw new \RuntimeException("Pub/Sub did not accept message {$m->messageId}");
        }
    }

    public function publisher(string $topic): EventPublisher
    {
        return new class ($this, $topic) implements EventPublisher {
            public function __construct(private readonly PubSubBroker $broker, private readonly string $topic)
            {
            }

            public function system(): string
            {
                return 'gcp_pubsub';
            }

            public function destination(): string
            {
                return $this->topic;
            }

            public function publish(BrokerMessage $message): void
            {
                $this->broker->publishTo($this->topic, $message);
            }

            public function close(): void
            {
            }
        };
    }

    public function subscribe(string $subscription, Messaging $topology): EventSubscription
    {
        return new class ($this, $subscription, $topology) implements EventSubscription {
            public function __construct(private readonly PubSubBroker $broker, private readonly string $subscription, private readonly Messaging $topology)
            {
            }

            public function system(): string
            {
                return 'gcp_pubsub';
            }

            public function source(): string
            {
                return $this->subscription;
            }

            public function poll(): ?Delivery
            {
                $res = $this->broker->call('POST', "/subscriptions/{$this->subscription}:pull", ['maxMessages' => 1, 'returnImmediately' => true]);
                $first = $res['receivedMessages'][0] ?? null;
                if ($first === null) {
                    return null;
                }
                $msg = $first['message'];
                $attrs = $msg['attributes'] ?? [];
                $message = new BrokerMessage($attrs['ghk_message_id'] ?? $msg['messageId'], $attrs['event_type'] ?? null, $attrs['tenant_id'] ?? null,
                    $attrs['traceparent'] ?? null, base64_decode($msg['data'] ?? '', true) ?: '');
                $ack = fn () => $this->broker->call('POST', "/subscriptions/{$this->subscription}:acknowledge", ['ackIds' => [$first['ackId']]]);
                return new Delivery($message, $ack, function () use ($message, $ack): void {
                    $this->broker->publishTo($this->topology->dlx, $message);
                    $ack();
                });
            }

            public function close(): void
            {
            }
        };
    }

    public function close(): void
    {
    }
}
