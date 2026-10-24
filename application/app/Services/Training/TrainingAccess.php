<?php

namespace App\Services\Training;

use App\Models\Club;
use App\Models\User;
use App\Services\Clubs\ClubAccess;

final class TrainingAccess
{
    public function __construct(private ClubAccess $access) {}

    public function canEdit(Club $club, User $user, object $training): bool
    {
        $member = $this->access->member($user, $club);

        return $training->responsible_membership_id === $member->id || in_array('schedule.manage', $this->access->capabilities($member, $club), true);
    }

    public function attendance(Club $club, User $user, object $training): void
    {
        abort_unless($this->canEdit($club, $user, $training), 403, 'Отмечать явку может ответственный этой тренировки или управляющий расписанием.');
    }

    public function canView(Club $club, User $user, object $training): bool
    {
        $member = $this->access->member($user, $club);

        return $this->canEdit($club, $user, $training) || in_array('finance.view', $this->access->capabilities($member, $club), true);
    }
}
