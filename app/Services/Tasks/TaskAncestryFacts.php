<?php

namespace App\Services\Tasks;

/** Database-free disclosure classification; never carries ancestor IDs/names. */
final readonly class TaskAncestryFacts
{
    public const int MAX_DEPTH = 8;

    private function __construct(public bool $validOrganization, public bool $visibleToClient) {}

    public static function invalid(): self
    {
        return new self(false, false);
    }

    /** @param array<int, array{id:int, project_id:int, parent_id:?int, list_id:?int, visible:bool, project_visible:bool}> $tasks
     * @param  array<int, array{project_id:int, visible:bool, deleted:bool}>  $lists
     */
    public static function classify(int $taskId, array $tasks, array $lists): self
    {
        $origin = $tasks[$taskId] ?? null;
        if ($origin === null) {
            return self::invalid();
        }
        $seen = [];
        $visible = $origin['project_visible'];
        for ($depth = 0; $depth <= self::MAX_DEPTH; $depth++) {
            $row = $tasks[$taskId] ?? null;
            if ($row === null || isset($seen[$taskId]) || $row['project_id'] !== $origin['project_id']) {
                return self::invalid();
            }
            $seen[$taskId] = true;
            $visible = $visible && $row['visible'];
            if ($row['parent_id'] !== null) {
                if ($row['list_id'] !== null) {
                    return self::invalid();
                }
                $taskId = $row['parent_id'];

                continue;
            }
            if ($row['list_id'] === null) {
                return new self(true, $visible);
            }
            $list = $lists[$row['list_id']] ?? null;
            if ($list === null || $list['deleted'] || $list['project_id'] !== $origin['project_id']) {
                return self::invalid();
            }

            return new self(true, $visible && $list['visible']);
        }

        return self::invalid();
    }

    public function organizationState(): string
    {
        return $this->validOrganization ? 'valid' : 'invalid';
    }
}
