<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListCommitRevisionsRequest;
use App\Models\Commit;
use App\Models\CommitRevision;
use App\Services\CommitBodies;
use Illuminate\Http\JsonResponse;

final readonly class CommitRevisionController
{
    /**
     * Every public body the commit has had, newest first, each with the moment it was committed.
     */
    public function index(ListCommitRevisionsRequest $request, Commit $commit, CommitBodies $bodies): JsonResponse
    {
        $current = new CommitRevision(['body' => $commit->body, 'mentions' => $commit->mentions, 'created_at' => $commit->amended_at ?? $commit->created_at]);

        return response()->json(['data' => $commit->revisions()->get()->prepend($current)->map(fn (CommitRevision $revision): array => [
            'body_segments' => $bodies->segments($revision->body)['body_segments'],
            'mentions' => $revision->mentions,
            'created_at' => $revision->created_at->toJSON(),
        ])]);
    }
}
