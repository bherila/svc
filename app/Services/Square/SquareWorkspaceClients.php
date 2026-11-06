<?php

namespace App\Services\Square;

use App\Models\Workspace;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Makes the Square client for one workspace: the client that calls Square with
 * that workspace's own token and no other. It makes no Square request itself,
 * which is why it is not a method of the read-only {@see SquareClient}.
 */
final class SquareWorkspaceClients
{
    public function __construct(private readonly HttpFactory $http) {}

    public function for(Workspace $workspace): SquareClient
    {
        return new SquareClient($this->http, SquareConfiguration::for($workspace));
    }
}
