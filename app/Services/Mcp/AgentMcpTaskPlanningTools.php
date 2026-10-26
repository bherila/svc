<?php

namespace App\Services\Mcp;

use Mcp\Capability\Attribute\Schema;

/** Every planning tool enters the registered REST policy, receipt and response boundary. */
final class AgentMcpTaskPlanningTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string,mixed> */
    public function get(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id): array
    {
        return $this->rest->call('task_planning.get', ['workspace' => $workspace_id, 'task' => $task_id]);
    }

    /** @return array<string,mixed> */
    public function labelsList(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(enum: ['active', 'archived', 'all'])] string $status = 'active', #[Schema(maxLength: 100)] string $q = '', #[Schema(minimum: 1, maximum: 25)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('task_planning.labels.list', ['workspace' => $workspace_id], query: compact('status', 'q', 'limit', 'cursor'));
    }

    /** @return array<string,mixed> */
    public function labelsGet(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $label_id): array
    {
        return $this->rest->call('task_planning.labels.get', ['workspace' => $workspace_id, 'label' => $label_id]);
    }

    /** @return array<string,mixed> */
    public function labelsOptions(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id, #[Schema(maxLength: 100)] string $q = '', #[Schema(minimum: 1, maximum: 25)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('task_planning.labels.options', ['workspace' => $workspace_id, 'project' => $project_id], query: compact('q', 'limit', 'cursor'));
    }

    /** @return array<string,mixed> */
    public function dependenciesGet(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id, #[Schema(minimum: 1, maximum: 25)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('task_planning.dependencies.get', ['workspace' => $workspace_id, 'task' => $task_id], query: compact('limit', 'cursor'));
    }

    /** @return array<string,mixed> */
    public function dependenciesOptions(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id, #[Schema(maxLength: 100)] string $q = '', #[Schema(minimum: 1, maximum: 25)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('task_planning.dependencies.options', ['workspace' => $workspace_id, 'task' => $task_id], query: compact('q', 'limit', 'cursor'));
    }

    /** @return array<string,mixed> */
    public function contactsGet(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id): array
    {
        return $this->rest->call('task_planning.contacts.get', ['workspace' => $workspace_id, 'task' => $task_id]);
    }

    /** @return array<string,mixed> */
    public function contactsOptions(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id, #[Schema(maxLength: 100)] string $q = '', #[Schema(minimum: 1, maximum: 25)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('task_planning.contacts.options', ['workspace' => $workspace_id, 'task' => $task_id], query: compact('q', 'limit', 'cursor'));
    }

    /**
     * @return array<string,mixed> */
    public function labelsCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minLength: 1, maxLength: 80)] string $name, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_planning.labels.create', ['workspace' => $workspace_id], compact('name'), $idempotency_key);
    }

    /**
     * @return array<string,mixed> */
    public function labelsUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $label_id, #[Schema(minLength: 1, maxLength: 80)] string $name, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_planning.labels.update', ['workspace' => $workspace_id, 'label' => $label_id], compact('name', 'expected_version'), $idempotency_key);
    }

    /**
     * @return array<string,mixed> */
    public function labelsArchive(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $label_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(definition: ['type' => 'boolean', 'const' => true])] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_planning.labels.archive', ['workspace' => $workspace_id, 'label' => $label_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /**
     * @return array<string,mixed> */
    public function labelsRestore(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $label_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_planning.labels.restore', ['workspace' => $workspace_id, 'label' => $label_id], compact('expected_version'), $idempotency_key);
    }

    /**
     * @param list<string> $label_ids
     * @return array<string,mixed> */
    public function labelsReplace(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(maxItems: 20, uniqueItems: true, items: ['type' => 'string', 'format' => 'uuid'])] array $label_ids, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_planning.labels.replace', ['workspace' => $workspace_id, 'task' => $task_id], compact('expected_version', 'label_ids'), $idempotency_key);
    }

    /**
     * @param list<string> $prerequisite_ids
     * @return array<string,mixed> */
    public function dependenciesReplace(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 64, maxLength: 64)] string $expected_graph_version, #[Schema(maxItems: 25, uniqueItems: true, items: ['type' => 'string', 'format' => 'uuid'])] array $prerequisite_ids, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_planning.dependencies.replace', ['workspace' => $workspace_id, 'task' => $task_id], compact('expected_version', 'expected_graph_version', 'prerequisite_ids'), $idempotency_key);
    }

    /**
     * @param list<string> $contact_ids
     * @return array<string,mixed> */
    public function contactsReplace(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(maxItems: 10, uniqueItems: true, items: ['type' => 'string', 'format' => 'uuid'])] array $contact_ids, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_planning.contacts.replace', ['workspace' => $workspace_id, 'task' => $task_id], compact('expected_version', 'contact_ids'), $idempotency_key);
    }
}
