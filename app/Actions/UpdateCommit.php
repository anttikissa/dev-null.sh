<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Jobs\EmbedCommit;
use App\Jobs\ModerateCommit;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\Notification;
use App\Models\User;
use App\Queries\MentionedUsersQuery;
use App\Services\CommitTranslations;
use App\Services\EmojiShortcodes;
use App\Services\Urls;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCommit
{
    public function __construct(
        private UpdateCommitHotScores $updateCommitHotScores,
        private CreateMentionNotifications $createMentionNotifications,
        private CommitTranslations $commitTranslations,
        private EmojiShortcodes $emojiShortcodes,
        private Urls $urls,
        private MentionedUsersQuery $mentionedUsers,
    ) {
        //
    }

    /**
     * Amending works like deleting and committing again: the new text goes through moderation like a new commit, and
     * only text that was public is kept in the history. People still mentioned keep their notification, people no
     * longer mentioned lose it, and newly mentioned people are notified once the text is approved.
     */
    public function handle(Commit $commit, string $body): Commit
    {
        $body = $this->emojiShortcodes->replace($body);

        if ($body === $commit->body) {
            return $commit;
        }

        $user = $commit->user;
        assert($user instanceof User);

        return DB::transaction(function () use ($commit, $body, $user): Commit {
            $wasApproved = $commit->status === CommitStatus::Approved;
            $linkPreview = $this->linkPreviewFor($commit, $body);
            Cache::forget($this->commitTranslations->key($commit));

            if ($wasApproved) {
                $commit->revisions()->create([
                    'body' => $commit->body,
                    'mentions' => $commit->mentions,
                    'created_at' => $commit->amended_at ?? $commit->created_at,
                ]);
            }

            $commit->update([
                'body' => $body,
                'mentions' => $this->mentionedUsers->get($body),
                'status' => $user->isAdmin() || $user->hasGoodReputation() ? CommitStatus::Approved : CommitStatus::Pending,
                'link_preview_id' => $linkPreview?->id,
                'ai_decision' => null,
                'ai_categories' => null,
                'embedding' => null,
                'amended_at' => $wasApproved ? now() : $commit->amended_at,
            ]);

            Notification::query()->where('commit_id', $commit->id)->where('type', NotificationType::Mentioned)
                ->whereNotIn('recipient_id', array_values($commit->mentions ?? []))->delete();
            $this->createMentionNotifications->handle($commit);

            if ($commit->wasChanged('status')) {
                $this->updateCommitHotScores->handle([$commit->id]);
            }

            if ($linkPreview instanceof LinkPreview && $linkPreview->fetched_at === null) {
                dispatch(new UnfurlLink($linkPreview))->afterCommit();
            }

            if ($commit->status === CommitStatus::Pending && filled(config('services.openai.key'))) {
                dispatch(new ModerateCommit($commit))->afterCommit();
            }

            if ($body !== '' && filled(config('services.openai.key'))) {
                dispatch(new EmbedCommit($commit))->afterCommit();
            }

            return $commit;
        });
    }

    /**
     * The link card stays when its link is still in the text, follows the first link otherwise, and stays off when the
     * author turned it off.
     */
    private function linkPreviewFor(Commit $commit, string $body): ?LinkPreview
    {
        $current = $commit->linkPreview;
        $urls = $this->urls->all($body);

        if ($current instanceof LinkPreview && in_array($current->url, $urls, true)) {
            return $current;
        }

        if (! $current instanceof LinkPreview && $this->urls->all($commit->body) !== []) {
            return null;
        }

        $url = $urls[0] ?? null;

        return $url === null ? null : LinkPreview::query()->firstOrCreate(
            ['url_hash' => hash('sha256', $url)], ['url' => $url],
        );
    }
}
