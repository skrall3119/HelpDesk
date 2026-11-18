<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

trait ConcurrentRequests
{
    protected function concurrentRequests(array $requests): array
    {
        $processes = [];
        try {
            foreach ($requests as $request) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/http-worker.php')], base_path(), timeout: 20);
                $process->setInput(json_encode(['database' => config('database.connections.pgsql'), ...$request], JSON_THROW_ON_ERROR));
                $process->start();
                $processes[] = $process;
            }
            $responses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $responses;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
