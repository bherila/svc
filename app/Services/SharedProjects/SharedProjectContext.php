<?php

namespace App\Services\SharedProjects;

use App\Models\ClientProject;
use App\Models\ClientProjectParticipation;
use App\Models\Workspace;
use App\Support\SharedProjects\SharedViewer;
use App\Support\SharedProjects\ShareSide;

/**
 * A shared project as one viewer is allowed to stand at it: the host's
 * project, the workspace the viewer is standing in, and the rules that follow
 * from where that is. Only {@see SharedProjectAccess::resolve()} builds one, so
 * holding one is the proof that "the host, or an active participation" was
 * decided.
 */
final readonly class SharedProjectContext
{
    public function __construct(
        public ClientProject $project,
        public Workspace $host,
        public Workspace $viewerWorkspace,
        public SharedViewer $viewer,
        public ?ClientProjectParticipation $participation,
    ) {}

    public function isHost(): bool
    {
        return $this->viewer->side === ShareSide::Host;
    }
}
