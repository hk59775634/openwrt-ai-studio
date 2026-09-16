<?php

namespace App\Enums;

enum BuildStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
