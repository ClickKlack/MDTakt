<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status eines GTFS-Import-Laufs.
 */
enum GtfsImportStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
