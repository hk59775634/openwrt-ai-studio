<?php

namespace App\Enums;

enum WorkspaceStatus: string
{
    case Created = 'created';
    case Initializing = 'initializing';
    case Ready = 'ready';
    case Coding = 'coding';
    case BuildQueued = 'build_queued';
    case Building = 'building';
    case Success = 'success';
    case Failed = 'failed';
    case Archived = 'archived';
}
