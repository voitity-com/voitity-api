<?php

namespace App\Observers;

use App\Models\Profile;
use App\Services\ProfileKnowledge\ProfileKnowledgeIndexScheduler;
use Illuminate\Database\Eloquent\Model;

class ProfileKnowledgeSourceObserver
{
    public function __construct(private readonly ProfileKnowledgeIndexScheduler $scheduler) {}

    public function created(Model $model): void
    {
        $this->schedule($model);
    }

    public function updated(Model $model): void
    {
        $this->schedule($model);
    }

    public function deleted(Model $model): void
    {
        if (! $model instanceof Profile) {
            $this->schedule($model);
        }
    }

    public function restored(Model $model): void
    {
        $this->schedule($model);
    }

    private function schedule(Model $model): void
    {
        $profileId = $model instanceof Profile
            ? (int) $model->getKey()
            : (int) $model->getAttribute('profile_id');

        $this->scheduler->schedule($profileId);
    }
}
