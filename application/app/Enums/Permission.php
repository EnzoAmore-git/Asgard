<?php

namespace App\Enums;

enum Permission: string
{
    case MembersManage = 'members.manage';
    case ScheduleManage = 'schedule.manage';
    case FinanceManage = 'finance.manage';
    case CashReceive = 'cash.receive';
}
