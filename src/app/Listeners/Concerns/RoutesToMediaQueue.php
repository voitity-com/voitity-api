<?php

namespace App\Listeners\Concerns;

trait RoutesToMediaQueue
{
    public function viaQueue(): string
    {
        return (string) config('queue.workloads.media', 'media');
    }
}
