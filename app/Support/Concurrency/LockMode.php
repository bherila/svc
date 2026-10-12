<?php

namespace App\Support\Concurrency;

enum LockMode
{
    case Share;
    case Exclusive;
}
