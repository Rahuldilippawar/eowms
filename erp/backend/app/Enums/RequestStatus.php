<?php

namespace App\Enums;

/** Status of leave requests and attendance corrections. */
enum RequestStatus: string
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
