<?php

declare(strict_types=1);

// Worker process for the concurrency tests (IT2, IT3): php worker.php <handle|relay> '<json args>'
require __DIR__ . '/../../vendor/autoload.php';

use App\Runtime\Brokers;
use App\Runtime\CommandService;
use App\Runtime\OutboxRelay;

[$mode, $json] = [$argv[1], $argv[2]];
$args = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$databaseUrl = (string) getenv('DATABASE_URL');

if ($mode === 'handle') {
    $result = (new CommandService($databaseUrl, $args['schema']))->handle($args['tenantId'], $args['aggregateId'], $args['command'], [], $args['key'] ?? null);
    echo json_encode($result);
    exit(0);
}

$broker = Brokers::connect();
$publisher = $broker->publisher($args['exchange']);
$relay = new OutboxRelay($databaseUrl, $publisher, $args['schema']);
$total = 0;
while (($n = $relay->publishBatch($args['worker'], 3)) > 0) {
    $total += $n;
}
$publisher->close();
$broker->close();
echo json_encode(['published' => $total]);
