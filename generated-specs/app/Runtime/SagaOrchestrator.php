<?php

declare(strict_types=1);

namespace App\Runtime;

/**
 * Orchestrated saga: progress is persisted after every step; when a step fails, the completed steps
 * are compensated in reverse order. Re-running a saga id resumes after its completed steps.
 */
final class SagaOrchestrator
{
    private readonly string $s;

    public function __construct(private readonly string $databaseUrl, string $schema = 'public')
    {
        $this->s = Schema::name($schema);
    }

    /** @param list<array{name: string, action: \Closure(): void, compensate: \Closure(): void}> $steps */
    public function run(string $sagaId, string $tenantId, array $steps): string
    {
        $pdo = Database::connect($this->databaseUrl);
        Database::exec($pdo, "INSERT INTO {$this->s}.ghk_sagas (id, tenant_id, status) VALUES (?, ?, 'RUNNING') ON CONFLICT (id) DO NOTHING", [$sagaId, $tenantId]);
        $completed = $this->status($sagaId)['completedSteps'] ?? [];
        foreach ($steps as $step) {
            if (in_array($step['name'], $completed, true)) {
                continue;
            }
            try {
                ($step['action'])();
            } catch (\Throwable) {
                $this->save($sagaId, 'COMPENSATING', $completed);
                foreach (array_reverse($completed) as $name) {
                    foreach ($steps as $candidate) {
                        if ($candidate['name'] === $name) {
                            ($candidate['compensate'])();
                        }
                    }
                    $completed = array_values(array_diff($completed, [$name]));
                    $this->save($sagaId, 'COMPENSATING', $completed);
                }
                $this->save($sagaId, 'COMPENSATED', $completed);
                return 'COMPENSATED';
            }
            $completed[] = $step['name'];
            $this->save($sagaId, 'RUNNING', $completed);
        }
        $this->save($sagaId, 'COMPLETED', $completed);
        return 'COMPLETED';
    }

    /** @return array{status: string, completedSteps: list<string>}|null */
    public function status(string $sagaId): ?array
    {
        $row = Database::rows(Database::connect($this->databaseUrl), "SELECT status, completed_steps FROM {$this->s}.ghk_sagas WHERE id = ?", [$sagaId])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $done = (string) $row['completed_steps'];
        return ['status' => (string) $row['status'], 'completedSteps' => $done === '' ? [] : explode(',', $done)];
    }

    /** @param list<string> $completed */
    private function save(string $sagaId, string $status, array $completed): void
    {
        Database::exec(Database::connect($this->databaseUrl), "UPDATE {$this->s}.ghk_sagas SET status = ?, completed_steps = ?, updated_at = now() WHERE id = ?",
            [$status, implode(',', $completed), $sagaId]);
    }
}
