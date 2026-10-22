<?php

namespace App\Services\Search;

use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAccess;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\TopicKind;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Bounded native text search, with the ordinary read policy applied in SQL first. */
final class CollaborationSearch
{
    private const PER_KIND = 5;

    public function __construct(private readonly CollaborationAccess $access) {}

    /** @param list<SearchResultKind> $kinds Empty means every kind.
     * @return list<SearchResult>
     */
    public function forWorkspace(User $actor, Workspace $workspace, string $term, array $kinds, CollaborationAudience $audience): array
    {
        $topics = [];
        $comments = [];
        $needle = '%'.WorkspaceSearch::escapeForLike($term).'%';
        foreach ([TopicKind::Note, TopicKind::Discussion] as $kind) {
            $searchKind = $kind === TopicKind::Note ? SearchResultKind::Note : SearchResultKind::Discussion;
            if ($kinds !== [] && ! in_array($searchKind, $kinds, true)) {
                continue;
            }
            $rows = $this->access->visibleTopicsInWorkspace($actor, $workspace, $audience)->where('kind', $kind->value)
                ->where(fn (Builder $match) => $match->whereRaw(SearchColumn::Title->likeSql(), [$needle])->orWhereRaw(SearchColumn::Body->likeSql(), [$needle]))
                ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::PER_KIND)->get();
            foreach ($rows as $topic) {
                $topics[] = $topic;
            }
        }
        if ($kinds === [] || in_array(SearchResultKind::Comment, $kinds, true)) {
            $comments = $this->access->visibleCommentsInWorkspace($actor, $workspace, $audience)
                ->whereRaw(SearchColumn::Body->likeSql(), [$needle])->orderByDesc('updated_at')->orderByDesc('id')->limit(self::PER_KIND)->get()->all();
        }
        $companyIds = array_unique(array_map(fn (ClientCollaborationTopic|ClientComment $record): int => $record->client_company_id, [...$topics, ...$comments]));
        if ($companyIds === []) {
            return [];
        }
        $companies = ClientCompany::query()->where('workspace_id', $workspace->id)->whereIn('id', $companyIds)->get()->keyBy('id');
        $taskIds = array_filter(array_map(fn (ClientComment $comment): ?int => $comment->client_task_id, $comments));
        $topicIds = array_filter(array_map(fn (ClientComment $comment): ?int => $comment->client_collaboration_topic_id, $comments));
        $tasks = $taskIds === [] ? collect() : ClientTask::query()->where('workspace_id', $workspace->id)->whereIn('id', $taskIds)->get()->keyBy('id');
        $parents = $topicIds === [] ? collect() : ClientCollaborationTopic::query()->where('workspace_id', $workspace->id)->whereIn('id', $topicIds)->get()->keyBy('id');
        $family = $audience === CollaborationAudience::Portal ? 'portal.' : '';
        $results = [];
        foreach ($topics as $topic) {
            $company = $companies->get($topic->client_company_id);
            if ($company === null) {
                continue;
            }
            $params = $audience === CollaborationAudience::Internal ? ['workspace' => $workspace] : [];
            $results[] = new SearchResult($topic->kind === TopicKind::Note ? SearchResultKind::Note : SearchResultKind::Discussion,
                $topic->public_id, $topic->title, $company->name.' — '.Str::limit($topic->body, 100),
                route($family.'collaboration.show', [...$params, 'clientCompany' => $company, 'topic' => $topic->public_id], absolute: false), $workspace->name);
        }
        foreach ($comments as $comment) {
            $company = $companies->get($comment->client_company_id);
            $parent = $comment->client_task_id === null ? $parents->get($comment->client_collaboration_topic_id) : $tasks->get($comment->client_task_id);
            if ($company === null || $parent === null) {
                continue;
            }
            $params = $audience === CollaborationAudience::Internal ? ['workspace' => $workspace] : [];
            $href = $parent instanceof ClientCollaborationTopic
                ? route($family.'collaboration.show', [...$params, 'clientCompany' => $company, 'topic' => $parent->public_id, 'comment' => $comment->public_id], absolute: false)
                : route($family.'collaboration.comments.index', [...$params, 'clientCompany' => $company, 'parentType' => 'task', 'parentPublicId' => $parent->public_id, 'comment' => $comment->public_id], absolute: false);
            $results[] = new SearchResult(SearchResultKind::Comment, $comment->public_id, 'Comment on '.$parent->title,
                $company->name.' — '.Str::limit($comment->body, 100), $href.'#comment-'.$comment->public_id, $workspace->name);
        }

        return $results;
    }
}
