<?php

namespace App\Support\SharedProjects;

/** Which side of a shared project a workspace stands on. There is no third side: anything else is "not found". */
enum ShareSide: string
{
    case Host = 'host';
    case Participant = 'participant';
}
